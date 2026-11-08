package fetchers

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// 抓取器共用 HTTP 小工具。

// postJSON 发送 JSON 体 POST。
func (s *Session) postJSON(endpoint, jsonBody string) ([]byte, int, error) {
	return s.postJSONWithHeaders(endpoint, jsonBody, nil)
}

// postJSONWithHeaders 带 headers 的 JSON POST。
func (s *Session) postJSONWithHeaders(endpoint, jsonBody string, headers map[string]string) ([]byte, int, error) {
	req, err := http.NewRequest("POST", endpoint, bytes.NewReader([]byte(jsonBody)))
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("Content-Type", "application/json")
	if s.UserAgent != "" {
		req.Header.Set("User-Agent", s.UserAgent)
	}
	for k, v := range s.extraHeader {
		req.Header.Set(k, v)
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	resp, err := s.Client.Do(req)
	if err != nil {
		s.MarkProxyFailed()
		return nil, 0, err
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	return body, resp.StatusCode, err
}

// getWithHeaders 同 get 但返回完整响应头（检查 Set-Cookie 用）。
func (s *Session) getWithHeaders(endpoint string, headers map[string]string) ([]byte, int, http.Header, error) {
	req, err := http.NewRequest("GET", endpoint, nil)
	if err != nil {
		return nil, 0, nil, err
	}
	if s.UserAgent != "" {
		req.Header.Set("User-Agent", s.UserAgent)
	}
	for k, v := range s.extraHeader {
		req.Header.Set(k, v)
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	resp, err := s.Client.Do(req)
	if err != nil {
		s.MarkProxyFailed()
		return nil, 0, nil, err
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	return body, resp.StatusCode, resp.Header, err
}

// joinURL 对齐 Python urljoin 的常见场景（相对路径拼接）。
func joinURL(base, ref string) string {
	rb, err := url.Parse(base)
	if err != nil {
		return ref
	}
	rr, err := url.Parse(ref)
	if err != nil {
		return base
	}
	return rb.ResolveReference(rr).String()
}

func mustParse(rawurl string) *url.URL {
	u, _ := url.Parse(rawurl)
	return u
}

func queryUnescape(s string) string {
	out, err := url.QueryUnescape(s)
	if err != nil {
		return s
	}
	return out
}

// idString 把 JSON 解出的 ID 值规范为十进制字符串。
// 关键：encoding/json 把数字解成 float64，fmt %v 会输出科学计数法
// （150264831 -> "1.50264831e+08"），导致与 HTML/API 里的字符串 ID 匹配不上。
func idString(v interface{}) string {
	switch n := v.(type) {
	case nil:
		return ""
	case string:
		return n
	case float64:
		if n == float64(int64(n)) {
			return strconv.FormatInt(int64(n), 10)
		}
		return strconv.FormatFloat(n, 'f', -1, 64)
	case json.Number:
		return string(n)
	default:
		return fmt.Sprintf("%v", v)
	}
}

func toQuery(m map[string]string) url.Values {
	v := url.Values{}
	for k, val := range m {
		v.Set(k, val)
	}
	return v
}

func marshalForm(m map[string]string) string {
	b, _ := json.Marshal(m)
	return string(b)
}

var _ = fmt.Sprintf
var _ = time.Second
var _ = strings.Contains
