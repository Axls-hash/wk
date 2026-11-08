package web

import (
	"fmt"
	"net/http"

	"daili-go/internal/config"
	"daili-go/internal/fetchers"
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
		`{"pay_enabled":%t,"skyriver_ok":%t,"sxdk_ok":%t,"site_url":%q}`,
		view.PayEnabled, view.SkyriverOK, view.SxdkOK, view.SiteURL))
	logger.Infof("syscfg saved: pay=%t skyriver=%t sxdk=%t register=%t",
		view.PayEnabled, view.SkyriverOK, view.SxdkOK, view.RegisterOpen)
	writeJSON(w, 200, view)
}

// HandleAdminSkyriverBalance GET /api/admin/syscfg/skyriver-balance —— 查上游余额（act=money）。
func HandleAdminSkyriverBalance(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	if config.SkyriverURL == "" || config.SkyriverUID == "" || config.SkyriverKey == "" {
		errJSON(w, 400, "请先配置并保存对接（接口地址 / UID / API Key）")
		return
	}
	bal, err := fetchers.SkyriverMoney()
	if err != nil {
		errJSON(w, 502, err.Error())
		return
	}
	writeJSON(w, 200, map[string]any{"balance": bal, "ok": true})
}
