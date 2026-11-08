// Package qd 学习通自动签到（独立模块）：对接本机签到引擎（29 协议 /api.php）。
//
// 业务链路（对齐签到引擎工作台 qdapi_auto.php 的既定流程）：
//
//	用户选卡型(周/月) → 本站扣积分(零售价) → 引擎 act=add 下单(引擎侧扣批发价)
//	→ 引擎 act=login 上号 → 引擎自动监控签到 → 本站转发 status 查看日志/记录
//	→ photo 上传人脸、logout 注销、重新上号(服务期内免费)
//
// 幂等与资金安全：
//   - 下单前先 act=chadan 查该手机号在引擎侧是否有生效订单，有则拒绝（防跨渠道
//     白嫖/重复开通）；引擎侧 act=add 自带同号幂等（同商户+手机号+课程名活跃
//     订单直接返回原单），网络抖动重放不会重复扣批发费
//   - 引擎明确拒绝（余额不足/参数错误等）：本站自动退积分
//   - 登录失败（密码错误等）：订单已建（服务期从 add 起算），本地标记"待上号"，
//     用户在订单详情免费重试登录，不退积分
package qd

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"strconv"
	"strings"
	"time"

	"daili-go/internal/config"
	"daili-go/internal/store"
)

// ErrNotConfigured 模块未配置或管理端关闭（用户端 503）。
var ErrNotConfigured = errors.New("自动签到功能暂未开放")

// InputError 参数/余额类错误（可直接展示给用户）。
type InputError struct{ Msg string }

func (e *InputError) Error() string { return e.Msg }

// NotFoundError 资源不存在或不属于当前用户。
type NotFoundError struct{ Msg string }

func (e *NotFoundError) Error() string { return e.Msg }

// RefundedError 引擎明确拒绝且已自动退回积分。
type RefundedError struct{ Msg string }

func (e *RefundedError) Error() string { return e.Msg }

// RefundFailedError 引擎明确拒绝但自动退款也失败，需要人工介入。
type RefundFailedError struct{ Msg string }

func (e *RefundFailedError) Error() string { return e.Msg }

// PriceChangedError 用户看到的售价与实时价不一致（管理端刚改价）。
// 在扣积分之前返回，绝不按新价静默扣款。
type PriceChangedError struct{ Points float64 }

func (e *PriceChangedError) Error() string {
	return fmt.Sprintf("价格已更新（当前 %s 积分），请刷新后重新确认", trimPoints(e.Points))
}

// trimPoints 积分展示：去掉多余的 0（3.00→3，3.50→3.5）。
func trimPoints(v float64) string {
	s := strconv.FormatFloat(v, 'f', 2, 64)
	s = strings.TrimRight(s, "0")
	s = strings.TrimRight(s, ".")
	if s == "" {
		return "0"
	}
	return s
}

// ---------------- 引擎客户端 ----------------

var httpClient = &http.Client{Timeout: 30 * time.Second}

// engineResp 引擎 JSON 应答（code: 0/1 成功类，负数失败）。
type engineResp struct {
	Code    int            `json:"code"`
	Msg     string         `json:"msg"`
	Extra   map[string]any `json:"-"`
	RawBody []byte         `json:"-"`
}

func parseEngineResp(body []byte) (*engineResp, error) {
	var out struct {
		Code int    `json:"code"`
		Msg  string `json:"msg"`
	}
	if err := json.Unmarshal(body, &out); err != nil {
		return nil, fmt.Errorf("引擎响应解析失败")
	}
	var raw map[string]any
	_ = json.Unmarshal(body, &raw)
	delete(raw, "code")
	delete(raw, "msg")
	return &engineResp{Code: out.Code, Msg: out.Msg, Extra: raw, RawBody: body}, nil
}

// strExtra 从引擎应答取字符串字段（兼容字符串/数字）。
func (r *engineResp) strExtra(key string) string {
	if r.Extra == nil {
		return ""
	}
	switch v := r.Extra[key].(type) {
	case string:
		return v
	case float64:
		return strconv.FormatFloat(v, 'f', -1, 64)
	}
	return ""
}

// enginePost 引擎表单 POST（act 放 query，与 29 协议一致）。
func enginePost(act string, params map[string]string) (*engineResp, error) {
	if strings.TrimSpace(config.QdEngineURL) == "" {
		return nil, ErrNotConfigured
	}
	form := make(map[string]string, len(params)+2)
	for k, v := range params {
		form[k] = v
	}
	req, err := http.NewRequest(http.MethodPost, config.QdEngineURL+"?act="+act, strings.NewReader(encodeForm(form)))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("引擎连接失败")
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return nil, fmt.Errorf("引擎响应读取失败")
	}
	return parseEngineResp(body)
}

func encodeForm(params map[string]string) string {
	var buf bytes.Buffer
	first := true
	for k, v := range params {
		if !first {
			buf.WriteByte('&')
		}
		first = false
		buf.WriteString(k)
		buf.WriteByte('=')
		buf.WriteString(v)
	}
	return buf.String()
}

// enginePostFile multipart 转发（人脸上传）。
func enginePostFile(act string, params map[string]string, fieldName string, fileName string, fileData []byte, contentType string) (*engineResp, error) {
	if strings.TrimSpace(config.QdEngineURL) == "" {
		return nil, ErrNotConfigured
	}
	var buf bytes.Buffer
	w := multipart.NewWriter(&buf)
	for k, v := range params {
		_ = w.WriteField(k, v)
	}
	if fileData != nil {
		ct := contentType
		if ct == "" {
			ct = "image/jpeg"
		}
		part, err := w.CreateFormFile(fieldName, fileName)
		if err == nil {
			_, _ = part.Write(fileData)
		}
	}
	_ = w.Close()
	req, err := http.NewRequest(http.MethodPost, config.QdEngineURL+"?act="+act, &buf)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", w.FormDataContentType())
	resp, err := httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("引擎连接失败")
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return nil, fmt.Errorf("引擎响应读取失败")
	}
	return parseEngineResp(body)
}

// ---------------- 卡型 ----------------

// Plan 卡型：week=周卡(7天) / month=月卡(30天)。
type Plan struct {
	Key   string `json:"key"`
	Name  string `json:"name"`
	Days  int    `json:"days"`
	Price float64 `json:"price"` // 零售积分价
}

const (
	PlanWeek  = "week"
	PlanMonth = "month"
)

func validPlan(p string) bool { return p == PlanWeek || p == PlanMonth }

// engineAccount 引擎商户号（一个商户号只带一种套餐，周/月分开配置）。
func engineAccount(plan string) (uid, key string, ok bool) {
	switch plan {
	case PlanWeek:
		uid, key = config.QdUIDWeek, config.QdKeyWeek
	case PlanMonth:
		uid, key = config.QdUIDMonth, config.QdKeyMonth
	}
	uid, key = strings.TrimSpace(uid), strings.TrimSpace(key)
	return uid, key, uid != "" && key != ""
}

// Configured 引擎地址与至少一个卡型的商户号配置齐全。
func Configured() bool {
	if strings.TrimSpace(config.QdEngineURL) == "" {
		return false
	}
	_, _, okW := engineAccount(PlanWeek)
	_, _, okM := engineAccount(PlanMonth)
	return okW || okM
}

// ---------------- 管理端开关 ----------------

const settingEnabled = "enabled"

// FeatureSwitchOn 管理端开关。缺行/库不可用时按开启处理（由 Configured 兜底）。
func FeatureSwitchOn() bool {
	if store.DB == nil {
		return true
	}
	var value string
	err := store.DB.QueryRow(`SELECT value FROM qd_settings WHERE name=?`, settingEnabled).Scan(&value)
	if err != nil {
		return true
	}
	return strings.TrimSpace(value) != "0"
}

// Enabled 用户端是否可用：上游已配置且管理端开关开启。
func Enabled() bool { return Configured() && FeatureSwitchOn() }

// SetEnabled 保存管理端开关（落库，重启仍生效）。
func SetEnabled(enabled bool) error {
	value := "0"
	if enabled {
		value = "1"
	}
	upsert := `INSERT INTO qd_settings(name, value, updated_at) VALUES(?,?,?)`
	if store.IsMySQL() {
		upsert += ` ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)`
	} else {
		upsert += ` ON CONFLICT(name) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at`
	}
	_, err := store.DB.Exec(upsert, settingEnabled, value, nowQd())
	return err
}

// ---------------- 定价 ----------------

// priceSettingKey 各卡型零售价在 qd_settings 里的键。
const (
	priceWeekKey  = "price_week"
	priceMonthKey = "price_month"
)

// settingPrice 读单个价格设置（缺行/解析失败回退 .env 默认）。
func settingPrice(key, fallback string) float64 {
	if store.DB != nil {
		var v string
		if err := store.DB.QueryRow(`SELECT value FROM qd_settings WHERE name=?`, key).Scan(&v); err == nil {
			if f, perr := strconv.ParseFloat(strings.TrimSpace(v), 64); perr == nil && f >= 0 {
				return f
			}
		}
	}
	if f, perr := strconv.ParseFloat(strings.TrimSpace(fallback), 64); perr == nil && f >= 0 {
		return f
	}
	if key == priceWeekKey {
		return 3
	}
	return 7
}

// SetPrice 保存卡型零售积分价（管理端）。
func SetPrice(plan string, points float64) error {
	if !validPlan(plan) {
		return &InputError{Msg: "卡型不合法"}
	}
	if points < 0 || points > 100000 {
		return &InputError{Msg: "价格超出范围"}
	}
	key := priceWeekKey
	if plan == PlanMonth {
		key = priceMonthKey
	}
	upsert := `INSERT INTO qd_settings(name, value, updated_at) VALUES(?,?,?)`
	if store.IsMySQL() {
		upsert += ` ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=VALUES(updated_at)`
	} else {
		upsert += ` ON CONFLICT(name) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at`
	}
	_, err := store.DB.Exec(upsert, key, strconv.FormatFloat(points, 'f', 2, 64), nowQd())
	return err
}

// Plans 卡型列表（含当前零售价）。
func Plans() []Plan {
	return []Plan{
		{Key: PlanWeek, Name: "周卡（7 天）", Days: 7, Price: settingPrice(priceWeekKey, config.QdPriceWeek)},
		{Key: PlanMonth, Name: "月卡（30 天）", Days: 30, Price: settingPrice(priceMonthKey, config.QdPriceMonth)},
	}
}

// planPrice 单卡型零售价（分）。
func planPriceFen(plan string) int64 {
	var pts float64
	if plan == PlanMonth {
		pts = settingPrice(priceMonthKey, config.QdPriceMonth)
	} else {
		pts = settingPrice(priceWeekKey, config.QdPriceWeek)
	}
	fen := int64(pts*100 + 0.5)
	if fen < 1 {
		fen = 1
	}
	return fen
}

// ---------------- 管理端视图 ----------------

// SettingsView 管理端开关面板数据。
type SettingsView struct {
	Configured bool    `json:"configured"` // 引擎地址/商户号是否配置
	SwitchOn   bool    `json:"switch_on"`  // 管理端开关原始状态
	Available  bool    `json:"available"`  // 用户端当前是否真正可用
	PriceWeek  float64 `json:"price_week"`
	PriceMonth float64 `json:"price_month"`
	EngineURL  string  `json:"engine_url"`
	Balance    string  `json:"balance,omitempty"` // 引擎侧批发余额（两卡型同号时一个值）
	BalanceW   string  `json:"balance_week,omitempty"`
	BalanceM   string  `json:"balance_month,omitempty"`
}

// Settings 管理端面板数据（余额尽力查询，失败留空）。
func Settings() SettingsView {
	v := SettingsView{
		Configured: Configured(),
		SwitchOn:   FeatureSwitchOn(),
		Available:  Enabled(),
		PriceWeek:  settingPrice(priceWeekKey, config.QdPriceWeek),
		PriceMonth: settingPrice(priceMonthKey, config.QdPriceMonth),
		EngineURL:  config.QdEngineURL,
	}
	uidW, keyW, _ := engineAccount(PlanWeek)
	uidM, keyM, _ := engineAccount(PlanMonth)
	if uidW != "" && uidW == uidM {
		if bal, err := EngineBalance(uidW, keyW); err == nil {
			v.Balance = bal
		}
	} else {
		if uidW != "" {
			if bal, err := EngineBalance(uidW, keyW); err == nil {
				v.BalanceW = bal
			}
		}
		if uidM != "" {
			if bal, err := EngineBalance(uidM, keyM); err == nil {
				v.BalanceM = bal
			}
		}
	}
	return v
}

// EngineBalance 查引擎侧商户余额（getmoney）。
func EngineBalance(uid, key string) (string, error) {
	rs, err := enginePost("getmoney", map[string]string{"uid": uid, "key": key})
	if err != nil {
		return "", err
	}
	if rs.Code != 1 {
		return "", fmt.Errorf("%s", rs.Msg)
	}
	return rs.strExtra("money"), nil
}

// SettingsTableExists 供测试/启动前判断表是否已迁移（库不可用时不阻塞主流程）。
func SettingsTableExists() bool {
	if store.DB == nil {
		return false
	}
	var one int
	if store.IsMySQL() {
		if err := store.DB.QueryRow(
			`SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='qd_settings'`).Scan(&one); err != nil {
			return false
		}
		return true
	}
	err := store.DB.QueryRow(`SELECT 1 FROM sqlite_master WHERE type='table' AND name='qd_settings'`).Scan(&one)
	return err == nil
}

func nowQd() float64 { return float64(time.Now().UnixNano()) / 1e9 }
