package fetchers

import "sync"

// 同步商品目录：由 internal/sync 周期性重建（项目名→天河对接信息）。
// 冷门网课页面展示的商品项目，查课/下单时项目名直接作为“平台名”使用。

var (
	productMu      sync.RWMutex
	productCatalog = map[string]CatalogEntry{}
)

// SetProductCatalog 由同步器一次性替换目录。
func SetProductCatalog(m map[string]CatalogEntry) {
	productMu.Lock()
	productCatalog = m
	productMu.Unlock()
}

// ProductCatalogLength 当前目录条目数。
func ProductCatalogLength() int {
	productMu.RLock()
	defer productMu.RUnlock()
	return len(productCatalog)
}

func productBy(name string) (CatalogEntry, bool) {
	productMu.RLock()
	defer productMu.RUnlock()
	e, ok := productCatalog[name]
	return e, ok
}

// ProductEntry 查同步商品目录（导出给 web 层计价/下单用）。
func ProductEntry(name string) (CatalogEntry, bool) {
	return productBy(name)
}
