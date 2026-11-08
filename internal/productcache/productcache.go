// Package productcache 综合网课商品列表的读缓存。
//
// 用户端列表只展示「前台分类」里已挂载且上架的商品。
package productcache

import (
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

// Items 返回在售商品。fenlei 为空=全部已挂前台分类的商品；
// fenlei 为 "fc_12" 时只返回该前台分类。
func Items(fenlei string) ([]map[string]interface{}, error) {
	mu.Lock()
	defer mu.Unlock()
	if e, ok := entries[fenlei]; ok && time.Since(e.at) < ttl {
		return e.items, nil
	}

	fenlei = strings.TrimSpace(fenlei)
	var (
		q    string
		args []interface{}
	)
	switch {
	case fenlei == "":
		q = `
			SELECT DISTINCT p.name, p.price_yuan, p.fenlei, substr(p.content,1,500)
			FROM more_products p
			INNER JOIN front_category_items fi ON fi.cid=p.cid
			WHERE p.status=1 AND p.price_yuan>0
			ORDER BY p.name`
	case strings.HasPrefix(fenlei, "fc_"):
		catID, err := strconv.ParseInt(fenlei[3:], 10, 64)
		if err != nil || catID <= 0 {
			entries[fenlei] = entry{items: []map[string]interface{}{}, at: time.Now()}
			return []map[string]interface{}{}, nil
		}
		q = `
			SELECT p.name, p.price_yuan, p.fenlei, substr(p.content,1,500)
			FROM more_products p
			INNER JOIN front_category_items fi ON fi.cid=p.cid AND fi.category_id=?
			WHERE p.status=1 AND p.price_yuan>0
			ORDER BY p.name`
		args = []interface{}{catID}
	default:
		q = `
			SELECT DISTINCT p.name, p.price_yuan, p.fenlei, substr(p.content,1,500)
			FROM more_products p
			INNER JOIN front_category_items fi ON fi.cid=p.cid
			WHERE p.status=1 AND p.price_yuan>0 AND p.fenlei=?
			ORDER BY p.name`
		args = []interface{}{fenlei}
	}

	rows, err := store.DB.Query(q, args...)
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
