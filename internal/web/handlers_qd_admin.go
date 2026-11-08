package web

import (
	"errors"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"daili-go/internal/logger"
	"daili-go/internal/qd"
)

// 学习通自动签到管理端：运行开关、卡型零售价、订单列表。
// 这一层只做鉴权/参数校验/审计，业务规则都在 internal/qd。

// HandleAdminQdSettings GET /api/admin/qd/settings
func HandleAdminQdSettings(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	writeJSON(w, 200, qd.Settings())
}

// HandleAdminQdSetEnabled POST /api/admin/qd/settings {"enabled": true}
func HandleAdminQdSetEnabled(w http.ResponseWriter, r *http.Request) {
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
	if err := qd.SetEnabled(*body.Enabled); err != nil {
		logger.Err(err, "qd set enabled failed", "enabled", *body.Enabled)
		errJSON(w, 500, "保存失败，请稍后再试")
		return
	}
	action := "qd.disable"
	if *body.Enabled {
		action = "qd.enable"
	}
	audit(action, fmt.Sprintf(`{"enabled":%t}`, *body.Enabled))
	logger.Infof("qd feature switch: enabled=%t", *body.Enabled)
	writeJSON(w, 200, qd.Settings())
}

// HandleAdminQdSetPrice POST /api/admin/qd/price {"plan": "week", "points": 3}
func HandleAdminQdSetPrice(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	var body struct {
		Plan   string   `json:"plan"`
		Points *float64 `json:"points"`
	}
	if !readJSON(w, r, &body) || body.Points == nil {
		err422(w)
		return
	}
	if err := qd.SetPrice(strings.TrimSpace(body.Plan), *body.Points); err != nil {
		var inErr *qd.InputError
		if errors.As(err, &inErr) {
			errJSON(w, 400, inErr.Msg)
			return
		}
		logger.Err(err, "qd set price failed")
		errJSON(w, 500, "保存失败，请稍后再试")
		return
	}
	audit("qd.price.set", fmt.Sprintf(`{"plan":%q,"points":%s}`,
		strings.TrimSpace(body.Plan), strconv.FormatFloat(*body.Points, 'f', 2, 64)))
	writeJSON(w, 200, qd.Settings())
}

// HandleAdminQdOrders GET /api/admin/qd/orders?limit=200
func HandleAdminQdOrders(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	limit := 200
	if v := strings.TrimSpace(r.URL.Query().Get("limit")); v != "" {
		if n, err := strconv.Atoi(v); err == nil && n > 0 && n <= 500 {
			limit = n
		}
	}
	items, err := qd.AdminListOrders(limit)
	if err != nil {
		logger.Err(err, "qd admin list failed")
		errJSON(w, 500, "查询失败，请稍后再试")
		return
	}
	writeJSON(w, 200, map[string]any{"items": items})
}
