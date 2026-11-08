// Package bingli 病例生成独立对接模块。
//
// 上游：https://case.zmoee.com/api/v1
// 鉴权：X-User-Email + Authorization: Bearer <API_KEY>
//
// 本包只服务「病例」业务：模板列表/详情/预览图、提交订单、订单图片代理。
// 与课程、综合网课、实习打卡、我爱运动完全隔离，不修改任何既有业务逻辑。
package bingli

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"daili-go/internal/config"
)

const (
	maxJSONBytes  = 16 << 20 // 上游 JSON 响应上限
	maxImageBytes = 24 << 20 // 图片响应上限
	browserUA     = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36"
)

// ErrNotConfigured 上游邮箱/密钥未配置，病例模块自动停用。
var ErrNotConfigured = errors.New("病例功能未配置")

// APIError 上游返回的明确 HTTP 错误。
type APIError struct {
	Status  int
	Message string
}

func (e *APIError) Error() string { return e.Message }

// IsAPIError 判断是否为上游明确返回的错误（区别于网络错误/超时）。
func IsAPIError(err error) bool {
	var e *APIError
	return errors.As(err, &e)
}

// IsDefinitive 上游明确拒绝：可以安全退款、不需要重试。
// 400/401/403/404/410 都表示订单没有成立；500/网络错误则状态未知。
func IsDefinitive(err error) bool {
	var e *APIError
	if !errors.As(err, &e) {
		return false
	}
	switch e.Status {
	case http.StatusBadRequest, http.StatusUnauthorized, http.StatusForbidden,
		http.StatusNotFound, http.StatusGone:
		return true
	}
	return false
}

// ---------------- 上游响应结构 ----------------

// flexString 兼容上游把 ID 返回成字符串或数字。
type flexString string

func (f *flexString) UnmarshalJSON(b []byte) error {
	s := strings.TrimSpace(string(b))
	if s == "" || s == "null" {
		*f = ""
		return nil
	}
	if s[0] == '"' {
		var v string
		if err := json.Unmarshal(b, &v); err != nil {
			return err
		}
		*f = flexString(v)
		return nil
	}
	*f = flexString(strings.Trim(s, `"`))
	return nil
}

// flexFloat 兼容上游把价格返回成数字或字符串。
type flexFloat float64

func (f *flexFloat) UnmarshalJSON(b []byte) error {
	s := strings.TrimSpace(string(b))
	if s == "" || s == "null" {
		*f = 0
		return nil
	}
	s = strings.Trim(s, `"`)
	v, err := strconv.ParseFloat(s, 64)
	if err != nil {
		return err
	}
	*f = flexFloat(v)
	return nil
}

type applicationJSON struct {
	ID          flexString  `json:"id"`
	Name        string      `json:"name"`
	Description string      `json:"description"`
	Price       flexFloat   `json:"price"`
	PageSize    string      `json:"pageSize"`
	Category    string      `json:"category"`
	Fields      []fieldJSON `json:"fields"`
	Stamp       *stampJSON  `json:"stamp"`
}

type fieldJSON struct {
	FieldKey  string `json:"fieldKey"`
	Label     string `json:"label"`
	Type      string `json:"type"`
	Required  bool   `json:"required"`
	MaxLength int    `json:"maxLength"`
}

type stampJSON struct {
	OrgField      string             `json:"orgField"`
	CodeField     string             `json:"codeField"`
	DefaultOrg    string             `json:"defaultOrg"`
	DefaultCode   string             `json:"defaultCode"`
	EngineOptions []engineOptionJSON `json:"engineOptions"`
}

type engineOptionJSON struct {
	ID  string    `json:"id"`
	Min flexFloat `json:"min"`
	Max flexFloat `json:"max"`
}

type previewJSON struct {
	ApplicationID flexString `json:"applicationId"`
	Name          string     `json:"name"`
	ImageURL      string     `json:"imageUrl"`
	UpdatedAt     string     `json:"updatedAt"`
}

type submitJSON struct {
	OrderID          flexString             `json:"orderId"`
	ApplicationID    flexString             `json:"applicationId"`
	Status           string                 `json:"status"`
	ChargedPoints    flexFloat              `json:"chargedPoints"`
	Fields           map[string]interface{} `json:"fields"`
	ImageURL         string                 `json:"imageUrl"`
	ImageExpiresAt   string                 `json:"imageExpiresAt"`
	ImageData        string                 `json:"imageData"`
	CreatedAt        string                 `json:"createdAt"`
	UpdatedAt        string                 `json:"updatedAt"`
	IdempotentReplay bool                   `json:"idempotentReplay"`
	RenderError      string                 `json:"renderError"`
}

// Application 模板列表项（只暴露给本站用户必要字段，不暴露上游成本价）。
type Application struct {
	ID          string
	Name        string
	Description string
	Price       float64 // 上游单价（积分/元，作为成本参考）
	PageSize    string
	Category    string
}

// Field 模板字段定义。
type Field struct {
	FieldKey  string
	Label     string
	Type      string
	Required  bool
	MaxLength int
}

// EngineOption 印章高级参数（当前前端不逐单调参，保留解析能力）。
type EngineOption struct {
	ID  string
	Min float64
	Max float64
}

// Stamp 模板印章配置。
type Stamp struct {
	OrgField      string
	CodeField     string
	DefaultOrg    string
	DefaultCode   string
	EngineOptions []EngineOption
}

// ApplicationDetail 模板详情。
type ApplicationDetail struct {
	Application
	Fields []Field
	Stamp  *Stamp
}

func (a applicationJSON) toApplication() Application {
	return Application{
		ID:          string(a.ID),
		Name:        a.Name,
		Description: a.Description,
		Price:       float64(a.Price),
		PageSize:    a.PageSize,
		Category:    a.Category,
	}
}

func (a applicationJSON) toDetail() *ApplicationDetail {
	d := &ApplicationDetail{Application: a.toApplication()}
	if a.Fields != nil {
		d.Fields = make([]Field, 0, len(a.Fields))
		for _, f := range a.Fields {
			d.Fields = append(d.Fields, Field{
				FieldKey:  f.FieldKey,
				Label:     f.Label,
				Type:      f.Type,
				Required:  f.Required,
				MaxLength: f.MaxLength,
			})
		}
	}
	if a.Stamp != nil {
		s := &Stamp{
			OrgField:    a.Stamp.OrgField,
			CodeField:   a.Stamp.CodeField,
			DefaultOrg:  a.Stamp.DefaultOrg,
			DefaultCode: a.Stamp.DefaultCode,
		}
		for _, eo := range a.Stamp.EngineOptions {
			s.EngineOptions = append(s.EngineOptions, EngineOption{
				ID: eo.ID, Min: float64(eo.Min), Max: float64(eo.Max),
			})
		}
		d.Stamp = s
	}
	return d
}

// ---------------- 客户端 ----------------

// Client 病例上游客户端。
type Client struct {
	base   string
	origin string
	email  string
	key    string
	http   *http.Client
}

// Enabled 病例模块是否已配置完整（URL + 邮箱 + 密钥）。
// NewClient 按配置构建客户端；未配置完整时返回 nil。
// 注意只看 Configured（上游凭据），不看管理端业务开关：
// 关闭用户入口后，已支付订单的看图/重试仍需访问上游收尾。
func NewClient() *Client {
	if !Configured() {
		return nil
	}
	base := strings.TrimRight(strings.TrimSpace(config.BingAPIURL), "/")
	u, err := url.Parse(base)
	if err != nil || u.Scheme == "" || u.Host == "" {
		return nil
	}
	timeout := time.Duration(config.BingTimeoutSeconds) * time.Second
	if timeout < 10*time.Second {
		timeout = 90 * time.Second
	}
	tr := http.DefaultTransport.(*http.Transport).Clone()
	if p := strings.TrimSpace(config.BingProxyURL); p != "" {
		if pu, perr := url.Parse(p); perr == nil {
			tr.Proxy = http.ProxyURL(pu)
		}
	}
	return &Client{
		base:   base,
		origin: u.Scheme + "://" + u.Host,
		email:  strings.TrimSpace(config.BingUserEmail),
		key:    strings.TrimSpace(config.BingAPIKey),
		http: &http.Client{
			Timeout:   timeout,
			Transport: tr,
		},
	}
}

func (c *Client) newRequest(method, path string, body interface{}) (*http.Request, error) {
	var reader io.Reader
	if body != nil {
		raw, err := json.Marshal(body)
		if err != nil {
			return nil, err
		}
		reader = bytes.NewReader(raw)
	}
	req, err := http.NewRequest(method, c.base+path, reader)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+c.key)
	req.Header.Set("X-User-Email", c.email)
	req.Header.Set("Accept", "application/json, text/plain, */*")
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", browserUA)
	req.Header.Set("Referer", c.origin+"/")
	req.Header.Set("Origin", c.origin)
	return req, nil
}

func parseAPIError(status int, raw []byte) error {
	var body struct {
		StatusCode int    `json:"statusCode"`
		Message    string `json:"message"`
		Error      string `json:"error"`
	}
	msg := ""
	if json.Unmarshal(raw, &body) == nil {
		msg = strings.TrimSpace(body.Message)
	}
	if msg == "" {
		msg = strings.TrimSpace(string(raw))
		if len(msg) > 300 {
			msg = msg[:300]
		}
	}
	lower := strings.ToLower(msg)
	if strings.Contains(lower, "error code: 1010") || strings.Contains(lower, "cloudflare") && status == http.StatusForbidden {
		msg = "上游拒绝服务器访问（Cloudflare 1010），请配置 BING_PROXY_URL 后重试"
	}
	if msg == "" {
		msg = fmt.Sprintf("病例上游 HTTP %d", status)
	}
	return &APIError{Status: status, Message: msg}
}

func (c *Client) doJSON(method, path string, body, out interface{}) error {
	req, err := c.newRequest(method, path, body)
	if err != nil {
		return fmt.Errorf("病例上游请求构造失败")
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("病例上游请求失败，请稍后再试")
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, maxJSONBytes))
	if err != nil {
		return fmt.Errorf("读取病例上游响应失败")
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return parseAPIError(resp.StatusCode, raw)
	}
	if out == nil {
		return nil
	}
	if err := json.Unmarshal(raw, out); err != nil {
		return fmt.Errorf("病例上游返回异常，请稍后再试")
	}
	return nil
}

// ---------------- 只读接口 ----------------

var (
	appsMu      sync.Mutex
	appsCache   []Application
	appsCacheAt time.Time
)

// Applications 查询已发布病例模板（60 秒内存缓存；上游抖动时沿用上次结果）。
func Applications() ([]Application, error) {
	appsMu.Lock()
	defer appsMu.Unlock()
	if appsCache != nil && time.Since(appsCacheAt) < 60*time.Second {
		return appsCache, nil
	}
	c := NewClient()
	if c == nil {
		return nil, ErrNotConfigured
	}
	var raw []applicationJSON
	if err := c.doJSON(http.MethodGet, "/applications", nil, &raw); err != nil {
		if appsCache != nil {
			return appsCache, nil
		}
		return nil, err
	}
	out := make([]Application, 0, len(raw))
	for _, a := range raw {
		app := a.toApplication()
		if app.ID == "" {
			continue
		}
		out = append(out, app)
	}
	appsCache = out
	appsCacheAt = time.Now()
	return out, nil
}

// ApplicationByID 查询单个模板详情（含字段定义与印章配置）。
func ApplicationByID(id string) (*ApplicationDetail, error) {
	c := NewClient()
	if c == nil {
		return nil, ErrNotConfigured
	}
	var raw applicationJSON
	if err := c.doJSON(http.MethodGet, "/applications/"+url.PathEscape(id), nil, &raw); err != nil {
		return nil, err
	}
	d := raw.toDetail()
	if d.ID == "" {
		d.ID = id
	}
	return d, nil
}

// resolveURL 把上游预览图相对地址拼成绝对地址；只允许上游同源，防 SSRF。
func (c *Client) resolveURL(raw string) (string, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return "", fmt.Errorf("上游未返回图片地址")
	}
	u, err := url.Parse(raw)
	if err != nil {
		return "", fmt.Errorf("上游图片地址无效")
	}
	base, _ := url.Parse(c.base)
	if u.IsAbs() {
		if !strings.EqualFold(u.Host, base.Host) {
			return "", fmt.Errorf("上游图片地址不在可信域名")
		}
		return u.String(), nil
	}
	if strings.HasPrefix(raw, "/") {
		return base.Scheme + "://" + base.Host + raw, nil
	}
	basePath := strings.TrimSuffix(base.Path, "/")
	return base.Scheme + "://" + base.Host + basePath + "/" + raw, nil
}

func isPNG(raw []byte) bool {
	return len(raw) >= 8 && raw[0] == 0x89 && raw[1] == 0x50 && raw[2] == 0x4e && raw[3] == 0x47
}

func (c *Client) fetchImage(rawURL string) ([]byte, string, error) {
	req, err := http.NewRequest(http.MethodGet, rawURL, nil)
	if err != nil {
		return nil, "", fmt.Errorf("图片地址无效")
	}
	req.Header.Set("Authorization", "Bearer "+c.key)
	req.Header.Set("X-User-Email", c.email)
	req.Header.Set("Accept", "image/png,image/*;q=0.8,*/*;q=0.5")
	req.Header.Set("User-Agent", browserUA)
	req.Header.Set("Referer", c.origin+"/")
	resp, err := c.http.Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("病例上游图片请求失败，请稍后再试")
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, maxImageBytes+1))
	if err != nil {
		return nil, "", fmt.Errorf("读取病例图片失败")
	}
	if len(raw) > maxImageBytes {
		return nil, "", fmt.Errorf("病例图片过大")
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, "", parseAPIError(resp.StatusCode, raw)
	}
	ct := strings.TrimSpace(resp.Header.Get("Content-Type"))
	if ct == "" {
		ct = http.DetectContentType(raw)
	}
	if !strings.HasPrefix(ct, "image/") && !isPNG(raw) {
		return nil, "", fmt.Errorf("病例上游返回的不是图片")
	}
	if !strings.HasPrefix(ct, "image/") {
		ct = "image/png"
	}
	return raw, ct, nil
}

// PreviewImage 获取模板预览图（PNG 字节）。
func (c *Client) PreviewImage(id string) ([]byte, string, error) {
	var p previewJSON
	if err := c.doJSON(http.MethodGet, "/applications/"+url.PathEscape(id)+"/preview", nil, &p); err != nil {
		return nil, "", err
	}
	imgURL, err := c.resolveURL(p.ImageURL)
	if err != nil {
		return nil, "", err
	}
	return c.fetchImage(imgURL)
}

// OrderImage 按上游订单号取回 PNG（3 天内可重复取）。
func (c *Client) OrderImage(orderID string) ([]byte, string, error) {
	return c.fetchImage(c.base + "/orders/" + url.PathEscape(orderID) + "/image")
}

// SubmitOrder 提交字段内容生成图片。
// externalRequestId 用本站订单号：上游同一用户+模板下幂等，网络/500 重试不会重复扣费。
func (c *Client) SubmitOrder(externalID, applicationID string, fields map[string]string) (*submitJSON, error) {
	body := map[string]interface{}{
		"applicationId":     applicationID,
		"fields":            fields,
		"externalRequestId": externalID,
	}
	var out submitJSON
	if err := c.doJSON(http.MethodPost, "/orders", body, &out); err != nil {
		return nil, err
	}
	return &out, nil
}
