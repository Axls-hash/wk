// Package sync 冷门网课商品自动同步：按计划从天河 getclass 拉全量商品，
// 把配置分类下的项目差量 upsert 到 more_products（自动上新/改价/改内容/下架），
// 并在内存重建 fetchers 商品目录供查课/下单路由使用。
//
// 对齐天河的 skyrivercron.php 思路，但换成我们自己的库（SQLite）。
package sync

import (
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
	rows, err := store.DB.Query(
		`SELECT cid, name, fenlei FROM more_products WHERE status=1 AND price_yuan>0 ORDER BY cid`)
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
	uniq := make([]fetchers.CatalogRow, 0, len(rows))
	seen := map[int64]bool{}
	for _, row := range rows {
		if len(want) > 0 && !want[row.Fenlei] {
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
		if seen[row.Cid] {
			continue
		}
		seen[row.Cid] = true
		uniq = append(uniq, row)
	}

	syncedCids := map[int64]bool{}
	productMap := map[string]fetchers.CatalogEntry{}

	for _, row := range uniq {
		price := row.Price * config.SyncPriceMult
		// base_yuan=进货价（天河价×倍率，始终随同步刷新）；price_yuan=当售售价。
		// 管理员在后台手动改价后 price_override=1，同步不得覆盖 price_yuan（否则改价失去意义），
		// 只更新 base_yuan，便于后台随时「恢复天河默认价」。
		_, uerr := store.DB.Exec(
			`INSERT INTO more_products(cid, fenlei, name, price_yuan, base_yuan, price_override, content, status, updated_at)
			 VALUES(?,?,?,?,?,0,?,1,?)
			 ON DUPLICATE KEY UPDATE fenlei=VALUES(fenlei), name=VALUES(name),
			   base_yuan=VALUES(base_yuan),
			   price_yuan=CASE WHEN price_override=1 THEN price_yuan ELSE VALUES(price_yuan) END,
			   content=VALUES(content), status=1,
			   updated_at=VALUES(updated_at)`,
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
		// 商品名冲突时保留首次出现（同名但 cid 不同，用前面那个）
		if _, dup := productMap[row.Name]; !dup {
			productMap[row.Name] = fetchers.CatalogEntry{
				Name:     row.Name,
				Noun:     strconv.FormatInt(row.Cid, 10),
				Category: row.Fenlei,
			}
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
