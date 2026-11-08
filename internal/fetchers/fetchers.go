// Package fetchers 平台抓取器：登录 + 解析课程进度，行为对齐 Python 版。
package fetchers

import (
	"fmt"
	"net/http"
	"net/http/cookiejar"
	"time"

	"daili-go/internal/proxy"
)

// Course 抓取结果条目（JSON 字段与 Python ScrapedCourse 一致）。
type Course struct {
	Title          string  `json:"title"`
	Link           string  `json:"link"`
	Teacher        string  `json:"teacher"`
	School         string  `json:"school"`
	TotalHours     float64 `json:"total_hours"`
	CompletedHours float64 `json:"completed_hours"`
	Status         string  `json:"status"`
	Deadline       string  `json:"deadline"`
	Progress       float64 `json:"progress"`
	Kcid           string  `json:"kcid"`
}

// LoginError 登录/查课失败（错误文案直接透传给用户）。
type LoginError struct{ Msg string }

func (e *LoginError) Error() string { return e.Msg }

func loginErr(format string, a ...interface{}) *LoginError {
	return &LoginError{Msg: fmt.Sprintf(format, a...)}
}

// Session 等价于 Python 的 ProxySession：cookiejar + 走代理的 client。
// 网络异常自动把代理标记冷却。NoRedirect 返回一个共享同一 cookie
// 会话但不跟随重定向的 client（welearn 授权链用）。
type Session struct {
	Client      *http.Client
	proxyURL    string
	accountKey  string
	UserAgent   string
	extraHeader map[string]string
}

const DefaultUA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 " +
	"(KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36"

// NewSession 创建带代理池分配的会话。
func NewSession(accountKey string) *Session {
	proxyURL, _ := proxy.Default.Get(accountKey)
	jar, _ := cookiejar.New(nil)
	return &Session{
		Client: &http.Client{
			Jar:       jar,
			Transport: proxy.TransportFor(proxyURL),
			Timeout:   20 * time.Second,
		},
		proxyURL:   proxyURL,
		accountKey: accountKey,
	}
}

// NoRedirect 共享 cookie 的不跟随重定向 client（302 链手工控制）。
func (s *Session) NoRedirect(timeout time.Duration) *http.Client {
	return &http.Client{
		Jar:       s.Client.Jar,
		Transport: s.Client.Transport,
		Timeout:   timeout,
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}
}

// MarkProxyFailed 请求失败时上报代理冷却。
func (s *Session) MarkProxyFailed() {
	if s.proxyURL != "" {
		proxy.Default.MarkFailed(s.proxyURL)
	}
}

// SetHeader 设置后续请求的公共头（简化抓取器代码；逐请求也可覆盖）。
func (s *Session) SetHeader(k, v string) {
	if s.extraHeader == nil {
		s.extraHeader = map[string]string{}
	}
	s.extraHeader[k] = v
}
