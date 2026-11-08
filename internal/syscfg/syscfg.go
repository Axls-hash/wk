// Package syscfg 后台可改的运行时对接配置：易支付 + 天河课程 API + 实习打卡。
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
	"sxdk_token":   true,
}

// 允许写入的键（防任意写库）。
var allowedKeys = map[string]bool{
	"site_url":          true,
	"epay_api_url":      true,
	"epay_pid":          true,
	"epay_key":          true,
	"epay_type":         true,
	"skyriver_url":      true,
	"skyriver_uid":      true,
	"skyriver_key":      true,
	"course_price":      true, // 元，写入后换算为 CoursePriceFen
	"sxdk_upstream_url": true,
	"sxdk_admin":        true,
	"sxdk_token":        true,
	"sxdk_price_yuan":   true,
	"register_open":     true, // "1"/"0"
	"register_code":     true, // 邀请码；空字符串表示清空（开放时不要求邀请码）
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
	SkyriverOK      bool   `json:"skyriver_ok"`
	CoursePrice     string `json:"course_price"` // 元，两位小数文本
	SxdkUpstreamURL string `json:"sxdk_upstream_url"`
	SxdkAdmin       string `json:"sxdk_admin"`
	SxdkTokenSet    bool   `json:"sxdk_token_set"`
	SxdkPriceYuan   string `json:"sxdk_price_yuan"`
	SxdkOK          bool   `json:"sxdk_ok"`
	RegisterOpen bool   `json:"register_open"`
	RegisterCode string `json:"register_code"` // 邀请码明文（仅管理端可见，方便复制发给用户）
	Source       string `json:"source"`        // "db" | "env" 提示
}

// GetView 返回当前生效配置（内存值，已含 DB 覆盖）。
func GetView() View {
	courseYuan := fmt.Sprintf("%.2f", float64(config.CoursePriceFen)/100)
	sxdkPrice := strconv.FormatFloat(config.SxdkPriceYuan, 'f', -1, 64)
	src := "env"
	if hasAnyDB() {
		src = "db"
	}
	return View{
		SiteURL:         config.SiteURL,
		EpayAPIURL:      config.EpayAPIURL,
		EpayPID:         config.EpayPID,
		EpayKeySet:      strings.TrimSpace(config.EpayKey) != "",
		EpayType:        config.EpayType,
		PayEnabled:      config.PayEnabled,
		SkyriverURL:     config.SkyriverURL,
		SkyriverUID:     config.SkyriverUID,
		SkyriverKeySet:  strings.TrimSpace(config.SkyriverKey) != "",
		SkyriverOK:      config.SkyriverURL != "" && config.SkyriverUID != "" && config.SkyriverKey != "",
		CoursePrice:     courseYuan,
		SxdkUpstreamURL: config.SxdkUpstreamURL,
		SxdkAdmin:       config.SxdkAdmin,
		SxdkTokenSet:    strings.TrimSpace(config.SxdkToken) != "",
		SxdkPriceYuan:   sxdkPrice,
		SxdkOK:          config.SxdkUpstreamURL != "" && config.SxdkAdmin != "" && config.SxdkToken != "",
		RegisterOpen: config.RegisterOpen,
		RegisterCode: config.RegisterCode,
		Source:       src,
	}
}

// SaveInput 管理端提交体；密钥字段留空表示「保持原值」。
type SaveInput struct {
	SiteURL         *string `json:"site_url"`
	EpayAPIURL      *string `json:"epay_api_url"`
	EpayPID         *string `json:"epay_pid"`
	EpayKey         *string `json:"epay_key"`
	EpayType        *string `json:"epay_type"`
	SkyriverURL     *string `json:"skyriver_url"`
	SkyriverUID     *string `json:"skyriver_uid"`
	SkyriverKey     *string `json:"skyriver_key"`
	CoursePrice     *string `json:"course_price"`
	SxdkUpstreamURL *string `json:"sxdk_upstream_url"`
	SxdkAdmin       *string `json:"sxdk_admin"`
	SxdkToken       *string `json:"sxdk_token"`
	SxdkPriceYuan   *string `json:"sxdk_price_yuan"`
	RegisterOpen *bool   `json:"register_open"`
	RegisterCode *string `json:"register_code"` // 指针存在即覆盖（可空=不要求邀请码）
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
	put("sxdk_upstream_url", in.SxdkUpstreamURL, true)
	put("sxdk_admin", in.SxdkAdmin, false)
	put("sxdk_token", in.SxdkToken, false)

	if in.CoursePrice != nil {
		v := strings.TrimSpace(*in.CoursePrice)
		if v != "" {
			if _, err := strconv.ParseFloat(v, 64); err != nil {
				return View{}, fmt.Errorf("默认课程单价必须是数字（元）")
			}
			updates["course_price"] = v
		}
	}
	if in.SxdkPriceYuan != nil {
		v := strings.TrimSpace(*in.SxdkPriceYuan)
		if v != "" {
			f, err := strconv.ParseFloat(v, 64)
			if err != nil || f <= 0 {
				return View{}, fmt.Errorf("打卡单价必须是大于 0 的数字（积分/天）")
			}
			updates["sxdk_price_yuan"] = v
		}
	}

	if in.RegisterOpen != nil {
		if *in.RegisterOpen {
			updates["register_open"] = "1"
		} else {
			updates["register_open"] = "0"
		}
	}
	if in.RegisterCode != nil {
		updates["register_code"] = strings.TrimSpace(*in.RegisterCode)
	}

	// 开放注册时必须有邀请码，避免无门槛注册白嫖
	wantOpen := config.RegisterOpen
	if v, ok := updates["register_open"]; ok {
		wantOpen = v == "1"
	}
	codeAfter := config.RegisterCode
	if v, ok := updates["register_code"]; ok {
		codeAfter = v
	}
	if wantOpen && strings.TrimSpace(codeAfter) == "" {
		return View{}, fmt.Errorf("开放注册时必须设置邀请码")
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

	if v, ok := m["sxdk_upstream_url"]; ok {
		config.SxdkUpstreamURL = strings.TrimRight(strings.TrimSpace(v), "/")
	}
	if v, ok := m["sxdk_admin"]; ok {
		config.SxdkAdmin = strings.TrimSpace(v)
	}
	if v, ok := m["sxdk_token"]; ok {
		config.SxdkToken = strings.TrimSpace(v)
	}
	if v, ok := m["sxdk_price_yuan"]; ok && strings.TrimSpace(v) != "" {
		if f, err := strconv.ParseFloat(strings.TrimSpace(v), 64); err == nil && f > 0 {
			config.SxdkPriceYuan = f
		}
	}

	if v, ok := m["register_open"]; ok {
		vv := strings.ToLower(strings.TrimSpace(v))
		config.RegisterOpen = vv == "1" || vv == "true" || vv == "yes"
	}
	if v, ok := m["register_code"]; ok {
		config.RegisterCode = strings.TrimSpace(v)
	}
	// 开放但邀请码为空时强制关闭（与启动校验一致）
	if config.RegisterOpen && strings.TrimSpace(config.RegisterCode) == "" {
		config.RegisterOpen = false
	}

	if v, ok := m["admin_password_hash"]; ok {
		v = strings.TrimSpace(v)
		if strings.HasPrefix(v, "pbkdf2$") {
			config.AdminPasswordHash = v
		}
	}
}

// SetAdminPasswordHash 保存管理员密码哈希到库并热更新内存（改密用）。
func SetAdminPasswordHash(hash string) error {
	hash = strings.TrimSpace(hash)
	if !strings.HasPrefix(hash, "pbkdf2$") {
		return fmt.Errorf("密码哈希无效")
	}
	if store.DB == nil {
		return fmt.Errorf("数据库未就绪")
	}
	now := float64(time.Now().UnixNano()) / 1e9
	if err := upsert("admin_password_hash", hash, now); err != nil {
		return err
	}
	config.AdminPasswordHash = hash
	return nil
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
