package web

import (
	"fmt"
	"net/http"

	"daili-go/internal/logger"
	"daili-go/internal/syscfg"
)

// HandleAdminSyscfg GET /api/admin/syscfg —— 支付 + 天河对接当前生效配置。
func HandleAdminSyscfg(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	writeJSON(w, 200, syscfg.GetView())
}

// HandleAdminSyscfgSave POST /api/admin/syscfg —— 保存到库并热更新；密钥留空表示不改。
func HandleAdminSyscfgSave(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	var body syscfg.SaveInput
	if !readJSON(w, r, &body) {
		return
	}
	view, err := syscfg.Save(body)
	if err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	audit("syscfg.save", fmt.Sprintf(
		`{"pay_enabled":%t,"skyriver_ok":%t,"site_url":%q}`,
		view.PayEnabled, view.SkyriverOK, view.SiteURL))
	logger.Infof("syscfg saved: pay=%t skyriver=%t", view.PayEnabled, view.SkyriverOK)
	writeJSON(w, 200, view)
}
