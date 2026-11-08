// Package productcache 综合网课商品列表的读缓存。
//
// 用户端列表来自 more_products，而下单扣费按同一张表的实时价计算。
// 若列表缓存过期时间较长，管理员改价或同步更新价格后，用户会看到旧价、
// 实际却按新价扣积分。因此所有会改动商品价格/上下架的写路径
// （管理端改价、同步任务）结束后必须调用 Invalidate()。
package productcache

import (
	"encoding/json"
	"strconv"
	"strings"
	"sync"
	"time"

	"daili-go/internal/store"
)

type entry struct {
	items []map[string]interface{}
	at    time.Time
}

var (
	mu      sync.Mutex
	entries = map[string]entry{}
)

const ttl = 60 * time.Second

// Items 返回指定分类（fenlei 为空表示全部）的在售商品列表。
// 结果按 fenlei 缓存 60 秒；查询失败不缓存，避免把一次抖动放大成 60 秒不可用。
func Items(fenlei string) ([]map[string]interface{}, error) {
	mu.Lock()
	defer mu.Unlock()
	if e, ok := entries[fenlei]; ok && time.Since(e.at) < ttl {
		return e.items, nil
	}
	hidden := loadHiddenFenlei()
	if fenlei != "" && hidden[fenlei] {
		entries[fenlei] = entry{items: []map[string]interface{}{}, at: time.Now()}
		return []map[string]interface{}{}, nil
	}
	where := " WHERE status=1 AND price_yuan>0"
	var args []interface{}
	if fenlei != "" {
		where += " AND fenlei=?"
		args = append(args, fenlei)
	}
	rows, err := store.DB.Query(
		`SELECT name, price_yuan, fenlei, substr(content,1,500) FROM more_products`+where+` ORDER BY name`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := []map[string]interface{}{}
	for rows.Next() {
		var name string
		var yuan float64
		var fl int64
		var note string
		if rows.Scan(&name, &yuan, &fl, &note) == nil && name != "" {
			if fenlei == "" && hidden[strconv.FormatInt(fl, 10)] {
				continue
			}
			items = append(items, map[string]interface{}{
				"name":      name,
				"price_fen": int64(yuan*100 + 0.5),
				"note":      note,
				"fenlei":    fl,
			})
		}
	}
	entries[fenlei] = entry{items: items, at: time.Now()}
	return items, nil
}

// Invalidate 清空全部缓存，下一次读取重新查库。
func Invalidate() {
	mu.Lock()
	entries = map[string]entry{}
	mu.Unlock()
}

// loadHiddenFenlei 与 sync.LoadHiddenFenlei 同源（system_settings.more_fenlei_hidden），
// 本地读一份避免 productcache↔sync 循环依赖。
func loadHiddenFenlei() map[string]bool {
	out := map[string]bool{}
	if store.DB == nil {
		return out
	}
	var raw string
	err := store.DB.QueryRow(`SELECT value FROM system_settings WHERE name=?`, "more_fenlei_hidden").Scan(&raw)
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
