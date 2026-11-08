// Package frontcat 前台自定义分类：管理员自建分类并挂载同步商品，前台只展示已挂载且上架的项。
package frontcat

import (
	"fmt"
	"strings"
	"time"

	"daili-go/internal/productcache"
	"daili-go/internal/store"
	"daili-go/internal/sync"
)

// Category 前台分类。
type Category struct {
	ID        int64   `json:"id"`
	Name      string  `json:"name"`
	SortOrder int     `json:"sort_order"`
	PriceMult float64 `json:"price_mult"` // 倍速：售价 = 进价 × 倍率
	Count     int64   `json:"count"`      // 已上架挂载数
	Total     int64   `json:"total"`      // 挂载总数（含下架）
}

// Prefix 前台分类在 /more-platforms 的 category 字段前缀，避免与上游 fenlei 数字撞车。
const Prefix = "fc_"

// Key 返回公开 API 用的 category 字符串。
func Key(id int64) string {
	return fmt.Sprintf("%s%d", Prefix, id)
}

// ParseKey 解析 fc_12 → 12；非前缀返回 0,false。
func ParseKey(s string) (int64, bool) {
	s = strings.TrimSpace(s)
	if !strings.HasPrefix(s, Prefix) {
		return 0, false
	}
	var id int64
	_, err := fmt.Sscanf(s[len(Prefix):], "%d", &id)
	return id, err == nil && id > 0
}

func clampMult(m float64) float64 {
	if m < 0.01 {
		return 0.01
	}
	if m > 100 {
		return 100
	}
	return m
}

// List 列出前台分类（按 sort_order、id）。
func List() ([]Category, error) {
	if store.DB == nil {
		return nil, fmt.Errorf("db not ready")
	}
	rows, err := store.DB.Query(`
		SELECT c.id, c.name, c.sort_order, COALESCE(c.price_mult,1),
		       COALESCE(SUM(CASE WHEN p.status=1 AND p.price_yuan>0 THEN 1 ELSE 0 END),0),
		       COUNT(fi.cid)
		FROM front_categories c
		LEFT JOIN front_category_items fi ON fi.category_id=c.id
		LEFT JOIN more_products p ON p.cid=fi.cid
		GROUP BY c.id, c.name, c.sort_order, c.price_mult
		ORDER BY c.sort_order ASC, c.id ASC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := make([]Category, 0)
	for rows.Next() {
		var c Category
		if rows.Scan(&c.ID, &c.Name, &c.SortOrder, &c.PriceMult, &c.Count, &c.Total) != nil {
			continue
		}
		if c.PriceMult <= 0 {
			c.PriceMult = 1
		}
		out = append(out, c)
	}
	return out, nil
}

// Create 新建前台分类；priceMult<=0 时按 1。
func Create(name string, sortOrder int, priceMult float64) (Category, error) {
	name = strings.TrimSpace(name)
	if name == "" || len([]rune(name)) > 40 {
		return Category{}, fmt.Errorf("分类名需 1~40 字")
	}
	if priceMult <= 0 {
		priceMult = 1
	}
	priceMult = clampMult(priceMult)
	now := float64(time.Now().UnixNano()) / 1e9
	res, err := store.DB.Exec(
		`INSERT INTO front_categories(name, sort_order, price_mult, created_at) VALUES(?,?,?,?)`,
		name, sortOrder, priceMult, now)
	if err != nil {
		return Category{}, err
	}
	id, _ := res.LastInsertId()
	return Category{ID: id, Name: name, SortOrder: sortOrder, PriceMult: priceMult}, nil
}

// Update 改名 / 排序 / 倍速；改倍速时重算已挂载商品售价=进价×倍率。
func Update(id int64, name string, sortOrder *int, priceMult *float64) error {
	if id <= 0 {
		return fmt.Errorf("分类无效")
	}
	name = strings.TrimSpace(name)
	if name == "" || len([]rune(name)) > 40 {
		return fmt.Errorf("分类名需 1~40 字")
	}
	if sortOrder != nil && priceMult != nil {
		m := clampMult(*priceMult)
		_, err := store.DB.Exec(`UPDATE front_categories SET name=?, sort_order=?, price_mult=? WHERE id=?`, name, *sortOrder, m, id)
		if err != nil {
			return err
		}
		return ApplyMultToItems(id)
	}
	if priceMult != nil {
		m := clampMult(*priceMult)
		_, err := store.DB.Exec(`UPDATE front_categories SET name=?, price_mult=? WHERE id=?`, name, m, id)
		if err != nil {
			return err
		}
		return ApplyMultToItems(id)
	}
	if sortOrder != nil {
		_, err := store.DB.Exec(`UPDATE front_categories SET name=?, sort_order=? WHERE id=?`, name, *sortOrder, id)
		return err
	}
	_, err := store.DB.Exec(`UPDATE front_categories SET name=? WHERE id=?`, name, id)
	return err
}

// ApplyMultToItems 把分类倍速写到已挂载商品：price_yuan = base_yuan × mult，并标记自定义价。
func ApplyMultToItems(categoryID int64) error {
	var mult float64
	err := store.DB.QueryRow(`SELECT COALESCE(price_mult,1) FROM front_categories WHERE id=?`, categoryID).Scan(&mult)
	if err != nil {
		return fmt.Errorf("分类不存在")
	}
	mult = clampMult(mult)
	now := float64(time.Now().UnixNano()) / 1e9
	_, err = store.DB.Exec(`
		UPDATE more_products SET
			price_yuan = ROUND(base_yuan * ?, 2),
			price_override = 1,
			status = CASE WHEN base_yuan>0 THEN 1 ELSE status END,
			updated_at = ?
		WHERE cid IN (SELECT cid FROM front_category_items WHERE category_id=?)
		  AND base_yuan > 0`, mult, now, categoryID)
	if err != nil {
		return err
	}
	productcache.Invalidate()
	sync.LoadCatalogFromDB()
	return nil
}

// Delete 删除分类及其挂载（商品本身保留，仅从前台拿掉）。
func Delete(id int64) error {
	if id <= 0 {
		return fmt.Errorf("分类无效")
	}
	tx, err := store.DB.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err := tx.Exec(`DELETE FROM front_category_items WHERE category_id=?`, id); err != nil {
		return err
	}
	if _, err := tx.Exec(`DELETE FROM front_categories WHERE id=?`, id); err != nil {
		return err
	}
	if err := tx.Commit(); err != nil {
		return err
	}
	productcache.Invalidate()
	sync.LoadCatalogFromDB()
	return nil
}

func categoryMult(categoryID int64) float64 {
	var m float64
	if store.DB.QueryRow(`SELECT COALESCE(price_mult,1) FROM front_categories WHERE id=?`, categoryID).Scan(&m) != nil || m <= 0 {
		return 1
	}
	return clampMult(m)
}

// AddItems 追加挂载并上架；不改售价（由管理员在上架时自行填写）。
func AddItems(categoryID int64, cids []int64) error {
	if categoryID <= 0 {
		return fmt.Errorf("分类无效")
	}
	var n int
	if err := store.DB.QueryRow(`SELECT COUNT(*) FROM front_categories WHERE id=?`, categoryID).Scan(&n); err != nil || n == 0 {
		return fmt.Errorf("分类不存在")
	}
	now := float64(time.Now().UnixNano()) / 1e9
	ins := `INSERT OR IGNORE INTO front_category_items(category_id, cid) VALUES(?,?)`
	if store.IsMySQL() {
		ins = `INSERT IGNORE INTO front_category_items(category_id, cid) VALUES(?,?)`
	}
	for _, cid := range cids {
		if cid <= 0 {
			continue
		}
		_, _ = store.DB.Exec(ins, categoryID, cid)
		_, _ = store.DB.Exec(`
			UPDATE more_products SET
				status = CASE WHEN price_yuan>0 THEN 1 ELSE status END,
				updated_at = ?
			WHERE cid=?`, now, cid)
	}
	productcache.Invalidate()
	sync.LoadCatalogFromDB()
	return nil
}

// RemoveItems 从分类移除；若不再属于任何分类则下架。
func RemoveItems(categoryID int64, cids []int64) error {
	for _, cid := range cids {
		if cid <= 0 {
			continue
		}
		_, _ = store.DB.Exec(`DELETE FROM front_category_items WHERE category_id=? AND cid=?`, categoryID, cid)
		var left int
		_ = store.DB.QueryRow(`SELECT COUNT(*) FROM front_category_items WHERE cid=?`, cid).Scan(&left)
		if left == 0 {
			_, _ = store.DB.Exec(`UPDATE more_products SET status=0 WHERE cid=?`, cid)
		}
	}
	productcache.Invalidate()
	sync.LoadCatalogFromDB()
	return nil
}

// ItemCIDs 某分类已挂载的 cid 列表。
func ItemCIDs(categoryID int64) ([]int64, error) {
	rows, err := store.DB.Query(`SELECT cid FROM front_category_items WHERE category_id=? ORDER BY cid`, categoryID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := make([]int64, 0)
	for rows.Next() {
		var cid int64
		if rows.Scan(&cid) == nil {
			out = append(out, cid)
		}
	}
	return out, nil
}
