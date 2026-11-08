// Package syy 我爱运动：作为套娃下游对接天河 syytianheban/openapi.php。
//
// 协议对齐上游 openapi.php 实现（同仓库 run_duijie/syytianheban）：
//   - 除 adjustCustomerBalance 走 POST 外，其余动作走 GET
//   - api_key 走 X-API-Key 头（也支持参数，此处用头）
//   - 所有资金动作携带 transaction_id，上游幂等：重放返回原结果
//   - 响应统一 {status: "success"|"error", message, ...}
package syy

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"daili-go/internal/config"
)

// Project 上游项目（getUserApiKeys 返回项）。
type Project struct {
	ProjectID   int64   `json:"project_id"`
	Name        string  `json:"project_name"`
	Description string  `json:"description"`
	RedirectURL string  `json:"redirect_url"`
	DocsURL     string  `json:"docs_url"`
	BasePrice   float64 `json:"price"` // 上游基础单价（元/单位）；本站实际成本=单价×我方等级费率÷基准费率
}

// Customer 上游客户（开户/查询返回项）。
type Customer struct {
	ID            int64   `json:"id"`
	APIKey        string  `json:"api_key"`
	RealAccessKey string  `json:"real_access_key"`
	ProjectID     int64   `json:"project_id"`
	ProjectName   string  `json:"project_name"`
	Balance       float64 `json:"balance"`
	CustomerLink  string  `json:"customer_link"`
	Status        int     `json:"status"`
}

// APIError 上游明确返回的错误（区分于网络错误：明确错误才允许本地退款）。
type APIError struct{ Msg string }

func (e *APIError) Error() string { return e.Msg }

// IsAPIError 判断是否上游明确拒绝（可安全退款/提示），网络类错误返回 false。
func IsAPIError(err error) bool {
	_, ok := err.(*APIError)
	return ok
}

var client = &http.Client{Timeout: 25 * time.Second}

// UpstreamName 展示用上游名（.env SYY_UPSTREAM_NAME，默认"运动平台"）。
func UpstreamName() string { return config.SyyUpstreamName }

func call(action string, params map[string]string, post bool) (map[string]interface{}, error) {
	if config.SyyOpenapiURL == "" || config.SyyAPIKey == "" {
		return nil, fmt.Errorf("运动业务未配置")
	}
	form := url.Values{}
	for k, v := range params {
		form.Set(k, v)
	}
	var (
		req *http.Request
		err error
	)
	u := config.SyyOpenapiURL + "?action=" + url.QueryEscape(action)
	if post {
		req, err = http.NewRequest(http.MethodPost, u, strings.NewReader(form.Encode()))
		if err != nil {
			return nil, err
		}
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	} else {
		req, err = http.NewRequest(http.MethodGet, u+"&"+form.Encode(), nil)
		if err != nil {
			return nil, err
		}
	}
	req.Header.Set("X-API-Key", config.SyyAPIKey)
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36")

	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("上游请求失败，请稍后再试")
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return nil, fmt.Errorf("读取上游响应失败")
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("上游 HTTP 错误: %d", resp.StatusCode)
	}
	var out map[string]interface{}
	if err := json.Unmarshal(body, &out); err != nil {
		return nil, fmt.Errorf("上游返回异常，请稍后再试")
	}
	return out, nil
}

func fail(m map[string]interface{}) error {
	msg := "上游操作失败"
	if s, ok := m["message"].(string); ok && strings.TrimSpace(s) != "" {
		msg = s
	}
	return &APIError{Msg: msg}
}

func str(m map[string]interface{}, key string) string {
	if v, ok := m[key]; ok && v != nil {
		return fmt.Sprint(v)
	}
	return ""
}

func flt(m map[string]interface{}, key string) float64 {
	switch v := m[key].(type) {
	case float64:
		return v
	case string:
		var f float64
		fmt.Sscanf(strings.TrimSpace(v), "%g", &f)
		return f
	}
	return 0
}

// ---------------- 项目列表（60s 内存缓存） ----------------

var (
	projMu       sync.Mutex
	projCache    []Project
	projCacheErr error
	projCacheAt  time.Time
)

// Projects 拉取可售项目（过滤管理端下架项）；60s 缓存，失败返回上次成功结果。
func Projects() ([]Project, error) {
	projMu.Lock()
	defer projMu.Unlock()
	if projCache != nil && time.Since(projCacheAt) < 60*time.Second {
		return projCache, nil
	}
	m, err := call("getUserApiKeys", nil, false)
	if err != nil {
		if projCache != nil {
			return projCache, nil // 上游抖动时沿用上次列表
		}
		return nil, err
	}
	if s := str(m, "status"); s != "success" {
		if projCache != nil {
			return projCache, nil
		}
		return nil, fail(m)
	}
	raw, _ := m["apiKeys"].([]interface{})
	out := make([]Project, 0, len(raw))
	disabled := map[string]bool{}
	for _, id := range config.SyyDisabledProject {
		disabled[strings.TrimSpace(id)] = true
	}
	for _, it := range raw {
		p, ok := it.(map[string]interface{})
		if !ok {
			continue
		}
		pid := int64(flt(p, "project_id"))
		if pid <= 0 || disabled[fmt.Sprint(pid)] {
			continue
		}
		out = append(out, Project{
			ProjectID:   pid,
			Name:        str(p, "project_name"),
			Description: str(p, "description"),
			RedirectURL: str(p, "redirect_url"),
			DocsURL:     str(p, "docs_url"),
			BasePrice:   flt(p, "price"),
		})
	}
	projCache = out
	projCacheErr = nil
	projCacheAt = time.Now()
	return out, nil
}

// ProjectByID 取单个项目（走缓存）。
func ProjectByID(id int64) (*Project, error) {
	list, err := Projects()
	if err != nil {
		return nil, err
	}
	for i := range list {
		if list[i].ProjectID == id {
			return &list[i], nil
		}
	}
	return nil, &APIError{Msg: "该项目不存在或已下架"}
}

// ---------------- 资金动作 ----------------

// GenerateCustomer 开客户（可带初始公里数）；幂等：同 transaction_id 重放返回原客户。
func GenerateCustomer(projectID int64, balance float64, transactionID string) (*Customer, error) {
	m, err := call("generateCustomerApi", map[string]string{
		"project_id":     fmt.Sprint(projectID),
		"balance":        fmtFloat(balance),
		"transaction_id": transactionID,
	}, false)
	if err != nil {
		return nil, err
	}
	if str(m, "status") != "success" {
		return nil, fail(m)
	}
	c, ok := m["customer"].(map[string]interface{})
	if !ok || int64(flt(c, "id")) <= 0 {
		return nil, fmt.Errorf("上游开户成功但未返回客户信息，请稍后到个人中心查看")
	}
	return parseCustomer(c), nil
}

// AdjustBalance 调整客户公里数（正增负减）；幂等同上。
func AdjustBalance(customerID int64, amount float64, transactionID string) (before, after float64, err error) {
	m, err := call("adjustCustomerBalance", map[string]string{
		"customer_id":    fmt.Sprint(customerID),
		"amount":         fmtFloat(amount),
		"transaction_id": transactionID,
	}, true)
	if err != nil {
		return 0, 0, err
	}
	if str(m, "status") != "success" {
		return 0, 0, fail(m)
	}
	return flt(m, "balance_before"), flt(m, "balance_after"), nil
}

// GetCustomer 查询客户实时余额/链接。
func GetCustomer(customerID int64) (*Customer, error) {
	m, err := call("getCustomerInfo", map[string]string{"customer_id": fmt.Sprint(customerID)}, false)
	if err != nil {
		return nil, err
	}
	if str(m, "status") != "success" {
		return nil, fail(m)
	}
	c, ok := m["customer"].(map[string]interface{})
	if !ok {
		return nil, fmt.Errorf("上游未返回客户信息")
	}
	return parseCustomer(c), nil
}

// DeleteCustomer 禁用客户并退回剩余公里数（上游自动把钱退回我们的对接账户）。
func DeleteCustomer(customerID int64) (refunded float64, err error) {
	m, err := call("deleteCustomerApi", map[string]string{"customer_id": fmt.Sprint(customerID)}, false)
	if err != nil {
		return 0, err
	}
	if str(m, "status") != "success" {
		return 0, fail(m)
	}
	return flt(m, "refunded"), nil
}

// EnableCustomer 重新启用已禁用客户。
func EnableCustomer(customerID int64) error {
	m, err := call("enableCustomer", map[string]string{"customer_id": fmt.Sprint(customerID)}, false)
	if err != nil {
		return err
	}
	if str(m, "status") != "success" {
		return fail(m)
	}
	return nil
}

func parseCustomer(c map[string]interface{}) *Customer {
	return &Customer{
		ID:            int64(flt(c, "id")),
		APIKey:        str(c, "api_key"),
		RealAccessKey: str(c, "real_access_key"),
		ProjectID:     int64(flt(c, "project_id")),
		ProjectName:   str(c, "project_name"),
		Balance:       flt(c, "balance"),
		CustomerLink:  str(c, "customer_link"),
		Status:        int(flt(c, "status")),
	}
}

// FmtFloat 公里数格式化：最多 4 位小数、去尾零（导出给 web 层拼提示文案）。
func FmtFloat(v float64) string { return fmtFloat(v) }

func fmtFloat(v float64) string {
	s := fmt.Sprintf("%.4f", v)
	s = strings.TrimRight(s, "0")
	return strings.TrimSuffix(s, ".")
}
