package sync

import (
	"os"
	"testing"

	"daili-go/internal/fetchers"
	"daili-go/internal/store"
)

// TestLoadCatalogFromDB 服务启动时用库里在售商品恢复内存目录：
// 上架且有价的商品必须可识别，已下架/零价商品不得进入目录。
func TestLoadCatalogFromDB(t *testing.T) {
	path := "./test_sync_catalog.db"
	for _, p := range []string{path, path + "-wal", path + "-shm"} {
		_ = os.Remove(p)
	}
	if err := store.Open(path); err != nil {
		t.Fatalf("open test db: %v", err)
	}
	t.Cleanup(func() {
		if store.DB != nil {
			_ = store.DB.Close()
		}
		for _, p := range []string{path, path + "-wal", path + "-shm"} {
			_ = os.Remove(p)
		}
	})

	for _, row := range []struct {
		cid, fenlei int64
		name        string
		price       float64
		status      int
	}{
		{101, 8, "商品A", 5, 1},
		{102, 8, "已下架商品", 5, 0},
		{103, 8, "零价商品", 0, 1},
	} {
		if _, err := store.DB.Exec(
			`INSERT INTO more_products(cid, fenlei, name, price_yuan, base_yuan, price_override, content, status, updated_at)
			 VALUES(?,?,?,?,?,0,'',?,1)`,
			row.cid, row.fenlei, row.name, row.price, row.price, row.status); err != nil {
			t.Fatalf("seed product: %v", err)
		}
	}

	fetchers.SetProductCatalog(map[string]fetchers.CatalogEntry{})
	if err := LoadCatalogFromDB(); err != nil {
		t.Fatalf("load catalog: %v", err)
	}
	entry, ok := fetchers.ProductEntry("商品A")
	if !ok || entry.Noun != "101" || entry.Category != "8" {
		t.Fatalf("在售商品未恢复: ok=%v entry=%+v", ok, entry)
	}
	if !fetchers.IsPlatform("商品A") {
		t.Fatal("在售商品应可下单/查课")
	}
	if fetchers.IsPlatform("已下架商品") || fetchers.IsPlatform("零价商品") {
		t.Fatal("下架/零价商品不应进入目录")
	}
}
