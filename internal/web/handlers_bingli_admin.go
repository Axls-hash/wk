package web

import (
	"fmt"
	"net/http"
	"strings"

	"daili-go/internal/bingli"
	"daili-go/internal/logger"
)

// 病例管理端：运行开关 + 按模板定价。
// 这一层只做鉴权/参数校验/审计，价格与开关的业务规则都在 internal/bingli。

// HandleAdminBingSettings GET /api/admin/bing/settings
func HandleAdminBingSettings(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	writeJSON(w, 200, bingli.Settings())
}

// HandleAdminBingSetEnabled POST /api/admin/bing/settings {"enabled": true}
func HandleAdminBingSetEnabled(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	var body struct {
		Enabled *bool `json:"enabled"`
	}
	if !readJSON(w, r, &body) || body.Enabled == nil {
		err422(w)
		return
	}
	if err := bingli.SetEnabled(*body.Enabled); err != nil {
		logger.Err(err, "bing set enabled failed", "enabled", *body.Enabled)
		errJSON(w, 500, "保存失败，请稍后再试")
		return
	}
	action := "bing.disable"
	if *body.Enabled {
		action = "bing.enable"
	}
	audit(action, fmt.Sprintf(`{"enabled":%t}`, *body.Enabled))
	logger.Infof("bing feature switch: enabled=%t", *body.Enabled)
	writeJSON(w, 200, bingli.Settings())
}

// HandleAdminBingApplications GET /api/admin/bing/applications
// 返回上游模板 + 默认价/自定义价/当前售价，仅供管理端改价。
func HandleAdminBingApplications(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	if !bingli.Configured() {
		errJSON(w, 503, "病例上游未配置（BING_USER_EMAIL / BING_API_KEY）")
		return
	}
	items, err := bingli.ListApplicationsForAdmin()
	if err != nil {
		logger.Warnf("bing admin list applications failed: %v", err)
		errJSON(w, 502, "病例模板加载失败，请稍后再试")
		return
	}
	writeJSON(w, 200, map[string]interface{}{"items": items})
}

// HandleAdminBingSetPrice POST /api/admin/bing/applications/{id}/price {"price_yuan": 12.5}
// price_yuan > 0 设为自定义售价；= 0 删除覆盖、恢复 上游价×倍率。
func HandleAdminBingSetPrice(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id := strings.TrimSpace(r.PathValue("id"))
	if id == "" || len(id) > 128 {
		errJSON(w, 400, "模板参数不合法")
		return
	}
	var body struct {
		PriceYuan *float64 `json:"price_yuan"`
	}
	if !readJSON(w, r, &body) || body.PriceYuan == nil {
		err422(w)
		return
	}
	yuan := *body.PriceYuan
	if yuan < 0 || yuan > 10000 {
		errJSON(w, 400, "价格需在 0 ~ 10000 元之间（0=恢复默认价）")
		return
	}
	if yuan > 0 && yuan < 0.01 {
		errJSON(w, 400, "自定义价格不能低于 0.01 元（0=恢复默认价）")
		return
	}
	if !bingli.Configured() {
		errJSON(w, 503, "病例上游未配置（BING_USER_EMAIL / BING_API_KEY）")
		return
	}
	// 模板必须来自上游当前列表，避免写入无意义的 application_id。
	list, err := bingli.Applications()
	if err != nil {
		logger.Warnf("bing admin price list failed: %v", err)
		errJSON(w, 502, "病例模板加载失败，请稍后再试")
		return
	}
	var upstreamPrice float64
	found := false
	for _, a := range list {
		if a.ID == id {
			upstreamPrice = a.Price
			found = true
			break
		}
	}
	if !found {
		errJSON(w, 404, "模板不存在或已下架，请刷新后重试")
		return
	}

	fen := int64(yuan*100 + 0.5)
	if err := bingli.SetApplicationPrice(id, fen); err != nil {
		logger.Err(err, "bing set price failed", "application_id", id, "price_fen", fen)
		errJSON(w, 500, "保存失败，请稍后再试")
		return
	}
	if fen > 0 {
		audit("bing.price.set", fmt.Sprintf(`{"application_id":%q,"price_fen":%d}`, id, fen))
		logger.Infof("bing price changed: application=%s fen=%d", id, fen)
	} else {
		audit("bing.price.reset", fmt.Sprintf(`{"application_id":%q}`, id))
		logger.Infof("bing price reset: application=%s", id)
	}

	priceFen, source := bingli.ApplicationPrice(id, upstreamPrice)
	writeJSON(w, 200, map[string]interface{}{
		"application_id":    id,
		"default_price_fen": bingli.PriceFen(upstreamPrice),
		"price_fen":         priceFen,
		"price_points":      float64(priceFen) / 100,
		"source":            source,
	})
}
