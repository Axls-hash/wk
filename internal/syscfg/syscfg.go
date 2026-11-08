// Package syscfg 后台可改的运行时对接配置：易支付 + 天河课程 API。
//
// 优先级：数据库 system_settings > 启动时 .env/环境变量。
// 管理端保存后即时写入内存 config，无需重启。
package syscfg

import (
	"database/sql"
	"fmt"
	"strconv"
	"strings"
	"time"

	"daili-go/internal/config"
	"daili-go/internal/store"
)

// 密钥类字段：GET 时只返回是否已配置，不回传明文。
var secretKeys = map[string]bool{
	"epay_key":     true,
	"skyriver_key": true,
}

// 允许写入的键（防任意写库）。
var allowedKeys = map[string]bool{
	"site_url":      true,
	"epay_api_url":  true,
	"epay_pid":      true,
	"epay_key":      true,
	"epay_type":     true,
	"skyriver_url":  true,
	"skyriver_uid":  true,
	"skyriver_key":  true,
	"course_price":  true, // 元，写入后换算为 CoursePriceFen
}

// LoadFromDB 在 store 打开后调用：用库内值覆盖 env 默认值。
func LoadFromDB() {
	if store.DB == nil {
		return
	}
	rows, err := store.DB.Query(`SELECT name, value FROM system_settings`)
	if err != nil {
		return
	}
	defer rows.Close()
	m := map[string]string{}
	for rows.Next() {
		var name, value string
		if err := rows.Scan(&name, &value); err != nil {
			continue
		}
		m[name] = value
	}
	applyMap(m)
}

// View 管理端面板数据（密钥脱敏）。
type View struct {
	SiteURL     string `json:"site_url"`
	EpayAPIURL  string `json:"epay_api_url"`
	EpayPID     string `json:"epay_pid"`
	EpayKeySet  bool   `json:"epay_key_set"`
	EpayType    string `json:"epay_type"`
	PayEnabled  bool   `json:"pay_enabled"`
	SkyriverURL string `json:"skyriver_url"`
	SkyriverUID string `json:"skyriver_uid"`
	SkyriverKeySet bool `json:"skyriver_key_set"`
	SkyriverOK  bool   `json:"skyriver_ok"`
	CoursePrice string `json:"course_price"` // 元，两位小数文本
	Source      string `json:"source"`       // "db" | "env" 提示
}

// GetView 返回当前生效配置（内存值，已含 DB 覆盖）。
func GetView() View {
	courseYuan := fmt.Sprintf("%.2f", float64(config.CoursePriceFen)/100)
	src := "env"
	if hasAnyDB() {
		src = "db"
	}
	return View{
		SiteURL:        config.SiteURL,
		EpayAPIURL:     config.EpayAPIURL,
		EpayPID:        config.EpayPID,
		EpayKeySet:     strings.TrimSpace(config.EpayKey) != "",
		EpayType:       config.EpayType,
		PayEnabled:     config.PayEnabled,
		SkyriverURL:    config.SkyriverURL,
		SkyriverUID:    config.SkyriverUID,
		SkyriverKeySet: strings.TrimSpace(config.SkyriverKey) != "",
		SkyriverOK:     config.SkyriverURL != "" && config.SkyriverUID != "" && config.SkyriverKey != "",
		CoursePrice:    courseYuan,
		Source:         src,
	}
}

// SaveInput 管理端提交体；密钥字段留空表示「保持原值」。
type SaveInput struct {
	SiteURL     *string `json:"site_url"`
	EpayAPIURL  *string `json:"epay_api_url"`
	EpayPID     *string `json:"epay_pid"`
	EpayKey     *string `json:"epay_key"`
	EpayType    *string `json:"epay_type"`
	SkyriverURL *string `json:"skyriver_url"`
	SkyriverUID *string `json:"skyriver_uid"`
	SkyriverKey *string `json:"skyriver_key"`
	CoursePrice *string `json:"course_price"`
}

// Save 校验并落库，同时热更新内存 config。
func Save(in SaveInput) (View, error) {
	if store.DB == nil {
		return View{}, fmt.Errorf("数据库未就绪")
	}
	updates := map[string]string{}

	put := func(key string, ptr *string, trimSlash bool) {
		if ptr == nil {
			return
		}
		v := strings.TrimSpace(*ptr)
		if trimSlash {
			v = strings.TrimRight(v, "/")
		}
		if secretKeys[key] && v == "" {
			return // 密钥留空 = 不改
		}
		updates[key] = v
	}

	put("site_url", in.SiteURL, true)
	put("epay_api_url", in.EpayAPIURL, true)
	put("epay_pid", in.EpayPID, false)
	put("epay_key", in.EpayKey, false)
	put("epay_type", in.EpayType, false)
	put("skyriver_url", in.SkyriverURL, true)
	put("skyriver_uid", in.SkyriverUID, false)
	put("skyriver_key", in.SkyriverKey, false)

	if in.CoursePrice != nil {
		v := strings.TrimSpace(*in.CoursePrice)
		if v != "" {
			if _, err := strconv.ParseFloat(v, 64); err != nil {
				return View{}, fmt.Errorf("默认课程单价必须是数字（元）")
			}
			updates["course_price"] = v
		}
	}

	if t, ok := updates["epay_type"]; ok && t != "" {
		t = strings.ToLower(t)
		if t != "wxpay" && t != "alipay" && t != "qqpay" {
			return View{}, fmt.Errorf("支付方式仅支持 wxpay / alipay / qqpay")
		}
		updates["epay_type"] = t
	}

	now := float64(time.Now().Unix()) + float64(time.Now().Nanosecond())/1e9
	for name, value := range updates {
		if !allowedKeys[name] {
			continue
		}
		if err := upsert(name, value, now); err != nil {
			return View{}, err
		}
	}
	applyMap(updates)
	return GetView(), nil
}

func upsert(name, value string, now float64) error {
	q := `INSERT INTO system_settings(name, value, updated_at) VALUES(?,?,?)`
	if store.IsMySQL() {
		q += ` ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)`
	} else {
		q += ` ON CONFLICT(name) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at`
	}
	_, err := store.DB.Exec(q, name, value, now)
	return err
}

func hasAnyDB() bool {
	if store.DB == nil {
		return false
	}
	var n int
	err := store.DB.QueryRow(`SELECT COUNT(1) FROM system_settings`).Scan(&n)
	return err == nil && n > 0
}

// applyMap 把传入键落到 config 包变量；未出现的键保持不变（env 或此前值）。
func applyMap(m map[string]string) {
	if v, ok := m["site_url"]; ok {
		config.SiteURL = strings.TrimRight(strings.TrimSpace(v), "/")
	}
	if v, ok := m["epay_api_url"]; ok {
		config.EpayAPIURL = strings.TrimRight(strings.TrimSpace(v), "/")
	}
	if v, ok := m["epay_pid"]; ok {
		config.EpayPID = strings.TrimSpace(v)
	}
	if v, ok := m["epay_key"]; ok {
		config.EpayKey = strings.TrimSpace(v)
	}
	if v, ok := m["epay_type"]; ok {
		t := strings.TrimSpace(v)
		if t == "" {
			t = "wxpay"
		}
		config.EpayType = t
	}
	config.PayEnabled = config.EpayAPIURL != "" && config.EpayPID != "" && config.EpayKey != ""

	if v, ok := m["skyriver_url"]; ok {
		config.SkyriverURL = strings.TrimRight(strings.TrimSpace(v), "/")
	}
	if v, ok := m["skyriver_uid"]; ok {
		config.SkyriverUID = strings.TrimSpace(v)
	}
	if v, ok := m["skyriver_key"]; ok {
		config.SkyriverKey = strings.TrimSpace(v)
	}
	if v, ok := m["course_price"]; ok && strings.TrimSpace(v) != "" {
		config.CoursePriceFen = yuanToFen(v)
	}
}

func yuanToFen(yuan string) int {
	f, err := strconv.ParseFloat(strings.TrimSpace(yuan), 64)
	if err != nil || f <= 0 {
		return config.CoursePriceFen
	}
	fen := int(f*100 + 0.5)
	if fen <= 0 {
		return 300
	}
	return fen
}

// GetRaw 测试/调试用：读库单键。
func GetRaw(name string) (string, bool) {
	if store.DB == nil {
		return "", false
	}
	var v string
	err := store.DB.QueryRow(`SELECT value FROM system_settings WHERE name=?`, name).Scan(&v)
	if err == sql.ErrNoRows || err != nil {
		return "", false
	}
	return v, true
}
