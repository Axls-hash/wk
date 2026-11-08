// Package sync 冷门网课商品自动同步：按计划从天河 getclass 拉全量商品，
// 把配置分类下的项目差量 upsert 到 more_products（自动上新/改价/改内容/下架），
// 并在内存重建 fetchers 商品目录供查课/下单路由使用。
//
// 对齐天河的 skyrivercron.php 思路，但换成我们自己的库（SQLite）。
package sync

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"sync"
	"time"

	"daili-go/internal/config"
	"daili-go/internal/fetchers"
	"daili-go/internal/logger"
	"daili-go/internal/productcache"
	"daili-go/internal/store"
)

const (
	settingFenleiNames  = "more_fenlei_names"
	settingFenleiHidden = "more_fenlei_hidden" // JSON 数组：前台不展示、同步也不自动上架的分类 ID
)

// Result 一次同步的结果统计（供管理后台手动触发展示）。
type Result struct {
	Total    int    `json:"total"`    // 上游拉取到的商品总数
	Inserted int    `json:"inserted"` // 本轮新上架
	Updated  int    `json:"updated"`  // 本轮更新
	Removed  int    `json:"removed"`  // 本轮下架（上游已撤）
	Catalog  int    `json:"catalog"`  // 可查课/下单的商品目录条目数
	Dur      string `json:"dur"`      // 耗时
}

// Start 启动同步循环：启动立即执行一次，之后按配置间隔周期执行。
// 所有异常均被 recover 吞掉，绝不影响服务正常启动。
func Start() {
	go func() {
		defer func() { recover() }()
		RunOnce()
		interval := time.Duration(config.SyncIntervalHours) * time.Hour
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		for range ticker.C {
			RunOnce()
		}
	}()
}

// LoadCatalogFromDB 用数据库里已上架商品恢复内存商品目录。
//
// 启动时首次同步是异步的（且可能因上游/密钥异常持续失败），而判断“平台是否
// 支持下单/查课”的 IsPlatform 只认内存目录。若不等同步成功，重启后的一段时间
// 里用户能看到商品列表、下单却提示“不支持的平台”。所以在 HTTP 服务启动前
// 先按库里的 status=1 商品同步一次目录；之后同步成功会整体覆盖为最新目录。
func LoadCatalogFromDB() error {
	// 仅「挂在前台分类 + 已上架」的商品进查课/下单目录，避免同步全量误开放。
	rows, err := store.DB.Query(`
		SELECT DISTINCT p.cid, p.name, p.fenlei FROM more_products p
		INNER JOIN front_category_items fi ON fi.cid=p.cid
		WHERE p.status=1 AND p.price_yuan>0 ORDER BY p.cid`)
	if err != nil {
		return err
	}
	defer rows.Close()
	catalog := map[string]fetchers.CatalogEntry{}
	for rows.Next() {
		var cid, fenlei int64
		var name string
		if rows.Scan(&cid, &name, &fenlei) != nil || cid == 0 || name == "" {
			continue
		}
		if _, dup := catalog[name]; dup {
			continue // 同名商品保留 cid 较小的一条，与按名查价的 ORDER BY cid 一致
		}
		catalog[name] = fetchers.CatalogEntry{
			Name:     name,
			Noun:     strconv.FormatInt(cid, 10),
			Category: strconv.FormatInt(fenlei, 10),
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	fetchers.SetProductCatalog(catalog)
	return nil
}

// runMu 串行化启动/定时/手动触发的同步：apply 会做全量读改写（more_products + 商品目录），
// 并发执行会互相踩（写库竞态、下架计数错乱、productCatalog 被旧轮覆盖）。
var runMu sync.Mutex

// RunOnce 立即执行一次同步并返回统计（供管理后台手动触发）。
// 互斥锁保证同一时刻只有一个同步在跑；手动触发若撞上定时轮次会等其完成。
func RunOnce() (res Result, rerr error) {
	runMu.Lock()
	defer runMu.Unlock()
	defer func() {
		if r := recover(); r != nil {
			logger.Errorf("sync: panic recovered: %v", r)
			rerr = fmt.Errorf("同步异常：%v", r)
		}
	}()
	start := time.Now()
	rows, err := fetchers.FetchSkyriverCatalog()
	if err != nil {
		logger.Err(err, "sync: fetch catalog failed")
		return res, err
	}
	inserted, updated, removed, err := apply(rows)
	if err != nil {
		logger.Err(err, "sync: apply failed")
		return res, err
	}
	res = Result{
		Total:    len(rows),
		Inserted: inserted,
		Updated:  updated,
		Removed:  removed,
		Catalog:  fetchers.ProductCatalogLength(),
		Dur:      time.Since(start).Round(time.Millisecond).String(),
	}
	logger.Infof("sync done: total=%d inserted=%d updated=%d removed=%d catalog=%d dur=%s",
		res.Total, res.Inserted, res.Updated, res.Removed, res.Catalog, res.Dur)
	return res, nil
}

// excludedCatalogRow 分类定制排除规则：分类42（国开）按要求不接「天河独家优质」货源。
// 同步时跳过这些项目；已入库且上架的会在本轮「上游已撤」逻辑里自动置为 status=0。
func excludedCatalogRow(row fetchers.CatalogRow) bool {
	return row.Fenlei == "42" && strings.HasPrefix(row.Name, "天河独家优质")
}

// apply 把目录差量 upsert 到 more_products，并重建商品目录。
func apply(rows []fetchers.CatalogRow) (inserted, updated, removed int, err error) {
	// 目标分类集合（.env 配置；为空则同步全部）
	want := map[string]bool{}
	for _, c := range config.SyncCategories {
		want[c] = true
	}

	// 已有 cid 全集（判断新增/更新/下架）；另记下上轮有效（status=1）的集合。
	// 「下架」只统计从上轮有效变为本次缺失的项，已下架的项不重复计数
	// （否则每轮同步都会把 status=0 的行再 UPDATE 一遍并 removed++，计数虚增）。
	existing := map[int64]bool{}
	activeBefore := map[int64]bool{}
	{
		r, qerr := store.DB.Query(`SELECT cid, status FROM more_products`)
		if qerr != nil {
			return 0, 0, 0, qerr
		}
		for r.Next() {
			var c int64
			var st int
			if r.Scan(&c, &st) == nil {
				existing[c] = true
				if st == 1 {
					activeBefore[c] = true
				}
			}
		}
		r.Close()
	}

	now := float64(time.Now().UnixNano()) / 1e9

	// 同 cid 去重（上游全量目录可能重复返回同一项目，保留首条），
	// 并在此完成过滤：目标分类 + 有效字段 + 价格>0（0 价商品不上架，等上游修复后自动恢复）。
	// 计数以「真实落库成功」为准——旧版按上游行数计数，8 份重复目录会把
	// inserted/updated 虚报 8 倍（657 行商品报成 5256）。
	filterRows := func(useCat bool) []fetchers.CatalogRow {
		out := make([]fetchers.CatalogRow, 0, len(rows))
		seenLocal := map[int64]bool{}
		for _, row := range rows {
			if useCat && len(want) > 0 && !want[row.Fenlei] {
				continue
			}
			if row.Cid == 0 || row.Name == "" {
				continue
			}
			if row.Price <= 0 {
				continue
			}
			if excludedCatalogRow(row) {
				continue
			}
			if seenLocal[row.Cid] {
				continue
			}
			seenLocal[row.Cid] = true
			out = append(out, row)
		}
		return out
	}
	uniq := filterRows(true)
	// 配置的分类 ID 与上游（丸子/天河）不一致时，按分类过滤会得到 0 条。
	// 此时回退同步全部分类，避免「上游有货但后台一个都没有」。
	if len(uniq) == 0 && len(rows) > 0 && len(want) > 0 {
		logger.Warnf("sync: SyncCategories=%v matched 0/%d upstream rows; falling back to all categories",
			config.SyncCategories, len(rows))
		uniq = filterRows(false)
	}

	syncedCids := map[int64]bool{}
	productMap := map[string]fetchers.CatalogEntry{}

	// 保存上游分类名（fenleiname），前台平台卡片显示「龙龙项目」而不是「分类31」
	fenleiNames := map[string]string{}
	for _, row := range rows {
		if row.Fenlei == "" || row.FenleiName == "" {
			continue
		}
		if _, ok := fenleiNames[row.Fenlei]; !ok {
			fenleiNames[row.Fenlei] = row.FenleiName
		}
	}
	if err := saveFenleiNames(fenleiNames); err != nil {
		logger.Warnf("sync: save fenlei names failed: %v", err)
	}

	// SQLite 不支持 ON DUPLICATE KEY / VALUES()，必须分支写；线上 ALLOW_SQLITE=1 时
	// 若仍用 MySQL 语法，8144 条会全部 upsert 失败 → inserted=0（用户看到「一个都拉不到」）。
	// 同步只拉目录/改价：新商品默认下架(status=0)；已有商品保留管理员上架状态，不自动全开。
	upsertSQL := `INSERT INTO more_products(cid, fenlei, name, price_yuan, base_yuan, price_override, content, content_override, status, updated_at)
		 VALUES(?,?,?,?,?,0,?,0,0,?)`
	if store.IsMySQL() {
		upsertSQL += ` ON DUPLICATE KEY UPDATE fenlei=VALUES(fenlei), name=VALUES(name),
			   base_yuan=VALUES(base_yuan),
			   price_yuan=CASE WHEN price_override=1 THEN price_yuan ELSE VALUES(price_yuan) END,
			   content=CASE WHEN content_override=1 THEN content ELSE VALUES(content) END,
			   updated_at=VALUES(updated_at)`
	} else {
		upsertSQL += ` ON CONFLICT(cid) DO UPDATE SET fenlei=excluded.fenlei, name=excluded.name,
			   base_yuan=excluded.base_yuan,
			   price_yuan=CASE WHEN more_products.price_override=1 THEN more_products.price_yuan ELSE excluded.price_yuan END,
			   content=CASE WHEN more_products.content_override=1 THEN more_products.content ELSE excluded.content END,
			   updated_at=excluded.updated_at`
	}

	for _, row := range uniq {
		price := row.Price * config.SyncPriceMult
		// base_yuan=进货价（天河价×倍率，始终随同步刷新）；price_yuan=当售售价。
		// 管理员在后台手动改价后 price_override=1，同步不得覆盖 price_yuan（否则改价失去意义），
		// 只更新 base_yuan，便于后台随时「恢复天河默认价」。
		_, uerr := store.DB.Exec(upsertSQL,
			row.Cid, fenleiInt(row.Fenlei), truncate(row.Name, 200), price, price,
			truncate(row.Content, 4000), now)
		if uerr != nil {
			logger.Err(uerr, "sync: upsert failed", "cid", row.Cid, "name", row.Name)
			continue // 落库失败不进 syncedCids：若持续失败，下轮会被视为上游已撤而下架
		}
		syncedCids[row.Cid] = true
		if existing[row.Cid] {
			updated++
		} else {
			inserted++
		}
	}

	// 查课目录：仅已上架且挂在前台分类里的商品
	{
		r, qerr := store.DB.Query(`
			SELECT p.cid, p.name, p.fenlei FROM more_products p
			INNER JOIN front_category_items fi ON fi.cid=p.cid
			WHERE p.status=1 AND p.price_yuan>0`)
		if qerr == nil {
			for r.Next() {
				var cid, fenlei int64
				var name string
				if r.Scan(&cid, &name, &fenlei) != nil || cid == 0 || name == "" {
					continue
				}
				if _, dup := productMap[name]; dup {
					continue
				}
				productMap[name] = fetchers.CatalogEntry{
					Name:     name,
					Noun:     strconv.FormatInt(cid, 10),
					Category: strconv.FormatInt(fenlei, 10),
				}
			}
			r.Close()
		}
	}

	// 下架：上轮有效(status=1)、本次同步集里没有的项目（上游已撤掉）
	for cid := range activeBefore {
		if !syncedCids[cid] {
			if _, derr := store.DB.Exec(`UPDATE more_products SET status=0, updated_at=? WHERE cid=? AND status=1`, now, cid); derr == nil {
				removed++
			}
		}
	}

	// 未挂到任何前台分类的商品强制下架：同步不再自动全站开放。
	if _, derr := store.DB.Exec(`
		UPDATE more_products SET status=0, updated_at=?
		WHERE status=1 AND cid NOT IN (SELECT cid FROM front_category_items)`, now); derr != nil {
		logger.Warnf("sync: unshelf unassigned failed: %v", derr)
	}

	fetchers.SetProductCatalog(productMap)
	// 价格/上下架已批量变化，立即让用户端商品列表缓存失效：
	// 否则缓存里的旧价会与下单时按库现价扣的积分不一致。
	productcache.Invalidate()
	return inserted, updated, removed, nil
}

func fenleiInt(s string) int64 {
	v, _ := strconv.ParseInt(s, 10, 64)
	return v
}

func saveFenleiNames(names map[string]string) error {
	if store.DB == nil || len(names) == 0 {
		return nil
	}
	// 合并旧映射，避免某轮上游漏字段把已有名字冲掉
	merged := LoadFenleiNames()
	for k, v := range names {
		if strings.TrimSpace(v) != "" {
			merged[k] = strings.TrimSpace(v)
		}
	}
	raw, err := json.Marshal(merged)
	if err != nil {
		return err
	}
	now := float64(time.Now().UnixNano()) / 1e9
	q := `INSERT INTO system_settings(name, value, updated_at) VALUES(?,?,?)`
	if store.IsMySQL() {
		q += ` ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)`
	} else {
		q += ` ON CONFLICT(name) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at`
	}
	_, err = store.DB.Exec(q, settingFenleiNames, string(raw), now)
	return err
}

// LoadFenleiNames 读取同步时落库的分类名映射（fenlei → fenleiname）。
func LoadFenleiNames() map[string]string {
	out := map[string]string{}
	if store.DB == nil {
		return out
	}
	var raw string
	err := store.DB.QueryRow(`SELECT value FROM system_settings WHERE name=?`, settingFenleiNames).Scan(&raw)
	if err != nil || strings.TrimSpace(raw) == "" {
		return out
	}
	_ = json.Unmarshal([]byte(raw), &out)
	if out == nil {
		out = map[string]string{}
	}
	return out
}

// LoadHiddenFenlei 返回管理员删除/隐藏的分类 ID 集合。
func LoadHiddenFenlei() map[string]bool {
	out := map[string]bool{}
	if store.DB == nil {
		return out
	}
	var raw string
	err := store.DB.QueryRow(`SELECT value FROM system_settings WHERE name=?`, settingFenleiHidden).Scan(&raw)
	if err != nil || strings.TrimSpace(raw) == "" {
		return out
	}
	var list []string
	if json.Unmarshal([]byte(raw), &list) != nil {
		return out
	}
	for _, id := range list {
		id = strings.TrimSpace(id)
		if id != "" {
			out[id] = true
		}
	}
	return out
}

func saveHiddenFenlei(hidden map[string]bool) error {
	list := make([]string, 0, len(hidden))
	for id, on := range hidden {
		if on && strings.TrimSpace(id) != "" {
			list = append(list, strings.TrimSpace(id))
		}
	}
	raw, err := json.Marshal(list)
	if err != nil {
		return err
	}
	now := float64(time.Now().UnixNano()) / 1e9
	q := `INSERT INTO system_settings(name, value, updated_at) VALUES(?,?,?)`
	if store.IsMySQL() {
		q += ` ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)`
	} else {
		q += ` ON CONFLICT(name) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at`
	}
	_, err = store.DB.Exec(q, settingFenleiHidden, string(raw), now)
	return err
}

// FenleiStat 后台「货源分类」一行。
type FenleiStat struct {
	Fenlei   string `json:"fenlei"`
	Name     string `json:"name"`
	Total    int64  `json:"total"`
	OnSale   int64  `json:"on_sale"`
	Hidden   bool   `json:"hidden"`
	MinPrice float64 `json:"min_price"`
	MaxPrice float64 `json:"max_price"`
}

// ListFenleiStats 汇总库里各分类商品数/上架数/价区间，供后台删分类与上架。
func ListFenleiStats() ([]FenleiStat, error) {
	if store.DB == nil {
		return nil, fmt.Errorf("db not ready")
	}
	names := LoadFenleiNames()
	hidden := LoadHiddenFenlei()
	rows, err := store.DB.Query(`
		SELECT fenlei,
		       COUNT(*) AS total,
		       SUM(CASE WHEN status=1 AND price_yuan>0 THEN 1 ELSE 0 END) AS on_sale,
		       COALESCE(MIN(CASE WHEN price_yuan>0 THEN price_yuan END), 0),
		       COALESCE(MAX(CASE WHEN price_yuan>0 THEN price_yuan END), 0)
		FROM more_products
		GROUP BY fenlei
		ORDER BY on_sale DESC, total DESC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	out := make([]FenleiStat, 0)
	seen := map[string]bool{}
	for rows.Next() {
		var st FenleiStat
		if rows.Scan(&st.Fenlei, &st.Total, &st.OnSale, &st.MinPrice, &st.MaxPrice) != nil {
			continue
		}
		st.Fenlei = strings.TrimSpace(st.Fenlei)
		if st.Fenlei == "" {
			continue
		}
		if n := strings.TrimSpace(names[st.Fenlei]); n != "" {
			st.Name = n
		} else {
			st.Name = "分类" + st.Fenlei
		}
		st.Hidden = hidden[st.Fenlei]
		out = append(out, st)
		seen[st.Fenlei] = true
	}
	// 名字表里有、但库里还没商品的分类也列出来（方便对照上游）
	for id, name := range names {
		if seen[id] {
			continue
		}
		out = append(out, FenleiStat{
			Fenlei: id, Name: name, Hidden: hidden[id],
		})
	}
	return out, nil
}

// HideFenlei 从前台删除该分类：记入隐藏表 + 全部分类商品下架。
func HideFenlei(fenlei string) error {
	fenlei = strings.TrimSpace(fenlei)
	if fenlei == "" {
		return fmt.Errorf("分类为空")
	}
	hidden := LoadHiddenFenlei()
	hidden[fenlei] = true
	if err := saveHiddenFenlei(hidden); err != nil {
		return err
	}
	now := float64(time.Now().UnixNano()) / 1e9
	_, err := store.DB.Exec(`UPDATE more_products SET status=0, updated_at=? WHERE fenlei=? AND status=1`, now, fenlei)
	if err != nil {
		return err
	}
	LoadCatalogFromDB()
	productcache.Invalidate()
	return nil
}

// clearFenleiHidden 只从隐藏表去掉分类，不动商品上下架。
func clearFenleiHidden(fenlei string) error {
	hidden := LoadHiddenFenlei()
	if !hidden[fenlei] {
		return nil
	}
	delete(hidden, fenlei)
	return saveHiddenFenlei(hidden)
}

// ShowFenlei 恢复分类到前台：取消隐藏 + 有价商品重新上架。
func ShowFenlei(fenlei string) error {
	fenlei = strings.TrimSpace(fenlei)
	if fenlei == "" {
		return fmt.Errorf("分类为空")
	}
	if err := clearFenleiHidden(fenlei); err != nil {
		return err
	}
	now := float64(time.Now().UnixNano()) / 1e9
	_, err := store.DB.Exec(
		`UPDATE more_products SET status=1, updated_at=? WHERE fenlei=? AND price_yuan>0 AND status=0`,
		now, fenlei)
	if err != nil {
		return err
	}
	LoadCatalogFromDB()
	productcache.Invalidate()
	return nil
}

// SetProductStatus 单商品上架(1)/下架(0)；上架时若分类被删只取消隐藏，不连带整类上架。
func SetProductStatus(cid int64, status int) error {
	if cid <= 0 || (status != 0 && status != 1) {
		return fmt.Errorf("参数无效")
	}
	var fenlei string
	var price float64
	err := store.DB.QueryRow(`SELECT fenlei, price_yuan FROM more_products WHERE cid=?`, cid).Scan(&fenlei, &price)
	if err != nil {
		return fmt.Errorf("商品不存在")
	}
	fenlei = strings.TrimSpace(fenlei)
	if status == 1 {
		if price <= 0 {
			return fmt.Errorf("售价为 0 不能上架，请先改价")
		}
		if err := clearFenleiHidden(fenlei); err != nil {
			return err
		}
	}
	now := float64(time.Now().UnixNano()) / 1e9
	if _, err := store.DB.Exec(`UPDATE more_products SET status=?, updated_at=? WHERE cid=?`, status, now, cid); err != nil {
		return err
	}
	LoadCatalogFromDB()
	productcache.Invalidate()
	return nil
}

// SetProductContent 改商品介绍；content_override=1 后同步不再覆盖。
// content 空串表示恢复上游介绍（清覆盖标记，下次同步会拉回）。
func SetProductContent(cid int64, content string) error {
	if cid <= 0 {
		return fmt.Errorf("商品无效")
	}
	var n int
	if err := store.DB.QueryRow(`SELECT COUNT(*) FROM more_products WHERE cid=?`, cid).Scan(&n); err != nil || n == 0 {
		return fmt.Errorf("商品不存在")
	}
	now := float64(time.Now().UnixNano()) / 1e9
	content = strings.TrimSpace(content)
	if len([]rune(content)) > 4000 {
		return fmt.Errorf("介绍最多 4000 字")
	}
	if content == "" {
		_, err := store.DB.Exec(`UPDATE more_products SET content_override=0, updated_at=? WHERE cid=?`, now, cid)
		if err != nil {
			return err
		}
	} else {
		_, err := store.DB.Exec(
			`UPDATE more_products SET content=?, content_override=1, updated_at=? WHERE cid=?`,
			content, now, cid)
		if err != nil {
			return err
		}
	}
	productcache.Invalidate()
	return nil
}

func truncate(s string, n int) string {
	b := []byte(s)
	if len(b) <= n {
		return s
	}
	for n > 0 && (b[n]&0xC0) == 0x80 {
		n--
	}
	return string(b[:n])
}
