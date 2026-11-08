package bingli

import (
	"database/sql"
	"strings"

	"daili-go/internal/config"
	"daili-go/internal/store"
)

// 病例模块运行设置：上游是否配置 + 管理端开关。
//
// 两层判定必须分开：
//   - Configured：.env 里上游邮箱/密钥齐全，客户端可以发请求；
//   - Enabled：用户端是否允许“新建/浏览病例” = Configured && 管理端开关。
//
// 后台重试 processing 订单、已支付订单看图/重试只依赖 Configured，
// 这样管理员临时关闭入口不会影响已付费订单的收尾。

const settingEnabled = "enabled"

// Configured 上游邮箱/密钥是否已配置完整（与业务开关无关）。
func Configured() bool {
	return strings.TrimSpace(config.BingAPIURL) != "" &&
		strings.TrimSpace(config.BingUserEmail) != "" &&
		strings.TrimSpace(config.BingAPIKey) != ""
}

// FeatureSwitchOn 管理端开关状态。缺行/数据库不可用时按开启处理：
// 此时是否真正可用仍由 Configured 决定，不会因为读设置失败误伤正常业务。
func FeatureSwitchOn() bool {
	if store.DB == nil {
		return true
	}
	var value string
	err := store.DB.QueryRow(
		`SELECT value FROM bing_settings WHERE name=?`, settingEnabled).Scan(&value)
	if err == sql.ErrNoRows {
		return true
	}
	if err != nil {
		return true
	}
	return strings.TrimSpace(value) != "0"
}

// Enabled 用户端是否可用：上游已配置且管理端开关开启。
func Enabled() bool {
	return Configured() && FeatureSwitchOn()
}

// SetEnabled 保存管理端开关。开关写入数据库，重启后仍然生效。
func SetEnabled(enabled bool) error {
	value := "0"
	if enabled {
		value = "1"
	}
	upsert := `INSERT INTO bing_settings(name, value, updated_at) VALUES(?,?,?)`
	if store.IsMySQL() {
		upsert += ` ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)`
	} else {
		upsert += ` ON CONFLICT(name) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at`
	}
	_, err := store.DB.Exec(upsert, settingEnabled, value, nowBing())
	return err
}

// SettingsView 管理端开关面板数据。
type SettingsView struct {
	Configured bool    `json:"configured"` // 上游账号/密钥是否配置
	SwitchOn   bool    `json:"switch_on"`  // 管理端开关原始状态
	Available  bool    `json:"available"`  // 用户端当前是否真正可用
	PriceMult  float64 `json:"price_mult"` // 默认售价倍率
}

// Settings 返回管理端开关面板数据。
func Settings() SettingsView {
	on := FeatureSwitchOn()
	return SettingsView{
		Configured: Configured(),
		SwitchOn:   on,
		Available:  Configured() && on,
		PriceMult:  config.BingPriceMult,
	}
}
