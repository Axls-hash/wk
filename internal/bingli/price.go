package bingli

import (
	"strings"

	"daili-go/internal/store"
)

// 病例模板售价：管理端可按模板覆盖默认价（上游模板价 × 倍率）。
// 用户端展示与下单扣费都必须走同一套价格函数，避免“看到的价格 ≠ 扣费”。

// AdminApplicationOut 管理端模板价格视图（含上游成本价，不下发给普通用户）。
type AdminApplicationOut struct {
	ID              string  `json:"id"`
	Name            string  `json:"name"`
	Category        string  `json:"category"`
	Description     string  `json:"description"`
	UpstreamPrice   float64 `json:"upstream_price"`    // 上游模板价（积分/元）
	DefaultPriceFen int64   `json:"default_price_fen"` // 默认售价 = 上游价 × 倍率
	PriceFen        int64   `json:"price_fen"`         // 当前售价（自定义优先）
	PricePoints     float64 `json:"price_points"`
	Source          string  `json:"source"` // custom / default
}

// loadPriceOverrides 一次读出全部自定义价，避免模板列表 N+1 查询。
func loadPriceOverrides() map[string]int64 {
	out := map[string]int64{}
	if store.DB == nil {
		return out
	}
	rows, err := store.DB.Query(`SELECT application_id, price_fen FROM bing_prices WHERE price_fen>0`)
	if err != nil {
		return out
	}
	defer rows.Close()
	for rows.Next() {
		var id string
		var fen int64
		if rows.Scan(&id, &fen) == nil && id != "" && fen > 0 {
			out[id] = fen
		}
	}
	return out
}

// effectivePriceFen 计算模板当前售价：自定义价优先，否则用默认倍率价。
func effectivePriceFen(appID string, upstreamPrice float64, overrides map[string]int64) (int64, string) {
	if fen, ok := overrides[appID]; ok && fen > 0 {
		return fen, "custom"
	}
	return PriceFen(upstreamPrice), "default"
}

// ApplicationPrice 单个模板的售价（下单/详情用）。
func ApplicationPrice(appID string, upstreamPrice float64) (int64, string) {
	if store.DB != nil {
		var fen int64
		err := store.DB.QueryRow(
			`SELECT price_fen FROM bing_prices WHERE application_id=?`, appID).Scan(&fen)
		if err == nil && fen > 0 {
			return fen, "custom"
		}
	}
	return PriceFen(upstreamPrice), "default"
}

// SetApplicationPrice 设置模板自定义售价；priceFen<=0 表示删除覆盖、恢复默认价。
func SetApplicationPrice(appID string, priceFen int64) error {
	appID = strings.TrimSpace(appID)
	if priceFen <= 0 {
		_, err := store.DB.Exec(`DELETE FROM bing_prices WHERE application_id=?`, appID)
		return err
	}
	upsert := `INSERT INTO bing_prices(application_id, price_fen, updated_at) VALUES(?,?,?)`
	if store.IsMySQL() {
		upsert += ` ON DUPLICATE KEY UPDATE price_fen=VALUES(price_fen), updated_at=VALUES(updated_at)`
	} else {
		upsert += ` ON CONFLICT(application_id) DO UPDATE SET price_fen=excluded.price_fen, updated_at=excluded.updated_at`
	}
	_, err := store.DB.Exec(upsert, appID, priceFen, nowBing())
	return err
}

// ListApplicationsForAdmin 管理端模板价格一览。
func ListApplicationsForAdmin() ([]AdminApplicationOut, error) {
	list, err := Applications()
	if err != nil {
		return nil, err
	}
	overrides := loadPriceOverrides()
	out := make([]AdminApplicationOut, 0, len(list))
	for _, a := range list {
		defaultFen := PriceFen(a.Price)
		fen, source := effectivePriceFen(a.ID, a.Price, overrides)
		out = append(out, AdminApplicationOut{
			ID:              a.ID,
			Name:            a.Name,
			Category:        a.Category,
			Description:     a.Description,
			UpstreamPrice:   a.Price,
			DefaultPriceFen: defaultFen,
			PriceFen:        fen,
			PricePoints:     pricePoints(fen),
			Source:          source,
		})
	}
	return out, nil
}
