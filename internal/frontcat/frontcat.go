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
	ID        int64  `json:"id"`
	Name      string `json:"name"`
	SortOrder int    `json:"sort_order"`
	Count     int64  `json:"count"` // 已上架挂载数
	Total     int64  `json:"total"` // 挂载总数（含下架）
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

// List 列出前台分类（按 sort_order、id）。
func List() ([]Category, error) {
	if store.DB == nil {
		return nil, fmt.Errorf("db not ready")
	}
	rows, err := store.DB.Query(`
		SELECT c.id, c.name, c.sort_order,
		       COALESCE(SUM(CASE WHEN p.status=1 AND p.price_yuan>0 THEN 1 ELSE 0 END),0),
		       COUNT(fi.cid)
		FROM front_categories c
		LEFT JOIN front_category_items fi ON fi.category_id=c.id
		LEFT JOIN more_products p ON p.cid=fi.cid
		GROUP BY c.id, c.name, c.sort_order
		ORDER BY c.sort_order ASC, c.id ASC`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := make([]Category, 0)
	for rows.Next() {
		var c Category
		if rows.Scan(&c.ID, &c.Name, &c.SortOrder, &c.Count, &c.Total) != nil {
			continue
		}
		out = append(out, c)
	}
	return out, nil
}

// Create 新建前台分类。
func Create(name string, sortOrder int) (Category, error) {
	name = strings.TrimSpace(name)
	if name == "" || len([]rune(name)) > 40 {
		return Category{}, fmt.Errorf("分类名需 1~40 字")
	}
	now := float64(time.Now().UnixNano()) / 1e9
	res, err := store.DB.Exec(
		`INSERT INTO front_categories(name, sort_order, created_at) VALUES(?,?,?)`,
		name, sortOrder, now)
	if err != nil {
		return Category{}, err
	}
	id, _ := res.LastInsertId()
	return Category{ID: id, Name: name, SortOrder: sortOrder}, nil
}

// Rename 改名 / 排序。
func Rename(id int64, name string, sortOrder *int) error {
	if id <= 0 {
		return fmt.Errorf("分类无效")
	}
	name = strings.TrimSpace(name)
	if name == "" || len([]rune(name)) > 40 {
		return fmt.Errorf("分类名需 1~40 字")
	}
	if sortOrder != nil {
		_, err := store.DB.Exec(`UPDATE front_categories SET name=?, sort_order=? WHERE id=?`, name, *sortOrder, id)
		return err
	}
	_, err := store.DB.Exec(`UPDATE front_categories SET name=? WHERE id=?`, name, id)
	return err
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
	if _, err := tx.Exec(`DELETE from front_categories WHERE id=?`, id); err != nil {
		return err
	}
	if err := tx.Commit(); err != nil {
		return err
	}
	recomputeStatus()
	productcache.Invalidate()
	sync.LoadCatalogFromDB()
	return nil
}

// SetItems 覆盖某分类的挂载列表；挂上的商品自动上架，并从「仅属于本分类且被移除」的商品下架。
func SetItems(categoryID int64, cids []int64) error {
	if categoryID <= 0 {
		return fmt.Errorf("分类无效")
	}
	var n int
	if err := store.DB.QueryRow(`SELECT COUNT(*) FROM front_categories WHERE id=?`, categoryID).Scan(&n); err != nil || n == 0 {
		return fmt.Errorf("分类不存在")
	}
	want := map[int64]bool{}
	clean := make([]int64, 0, len(cids))
	for _, cid := range cids {
		if cid <= 0 || want[cid] {
			continue
		}
		want[cid] = true
		clean = append(clean, cid)
	}

	tx, err := store.DB.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	old := map[int64]bool{}
	rows, err := tx.Query(`SELECT cid FROM front_category_items WHERE category_id=?`, categoryID)
	if err != nil {
		return err
	}
	for rows.Next() {
		var cid int64
		if rows.Scan(&cid) == nil {
			old[cid] = true
		}
	}
	rows.Close()

	if _, err := tx.Exec(`DELETE FROM front_category_items WHERE category_id=?`, categoryID); err != nil {
		return err
	}
	for _, cid := range clean {
		if _, err := tx.Exec(`INSERT INTO front_category_items(category_id, cid) VALUES(?,?)`, categoryID, cid); err != nil {
			return err
		}
		if _, err := tx.Exec(`UPDATE more_products SET status=1 WHERE cid=? AND price_yuan>0`, cid); err != nil {
			return err
		}
	}
	if err := tx.Commit(); err != nil {
		return err
	}

	// 被移出且不再属于任何前台分类的商品 → 下架
	for cid := range old {
		if want[cid] {
			continue
		}
		var left int
		_ = store.DB.QueryRow(`SELECT COUNT(*) FROM front_category_items WHERE cid=?`, cid).Scan(&left)
		if left == 0 {
			_, _ = store.DB.Exec(`UPDATE more_products SET status=0 WHERE cid=?`, cid)
		}
	}
	recomputeStatus()
	productcache.Invalidate()
	sync.LoadCatalogFromDB()
	return nil
}

// AddItems 追加挂载并上架。
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
		_, _ = store.DB.Exec(`UPDATE more_products SET status=1, updated_at=? WHERE cid=? AND price_yuan>0`, now, cid)
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

func recomputeStatus() {}
