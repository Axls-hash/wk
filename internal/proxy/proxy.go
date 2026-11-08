// Package proxy HTTP 代理池：单网关 / API 拉取两种模式，账号固定分配 + 失败冷却。
// 行为对齐 Python 版 proxies.py。未配置时全部直连。
package proxy

import (
	"crypto/md5"
	"encoding/json"
	"fmt"
	"io"
	"math/big"
	"math/rand"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"daili-go/internal/config"
	"daili-go/internal/logger"
)

const (
	apiTimeout   = 15 * time.Second
	failCooldown = 60 * time.Second
)

type Pool struct {
	mu      sync.Mutex
	pool    []string             // 代理 URL 列表
	failed  map[string]time.Time // proxy url -> 冷却截止
	ts      time.Time
	enabled bool
}

var Default = &Pool{failed: map[string]time.Time{}}

func Init() {
	Default.enabled = config.ProxyURL != "" || config.ProxyAPIURL != ""
}

// Get 返回代理 URL（"" 表示直连）。同账号哈希固定到同一代理。
func (p *Pool) Get(accountKey string) (string, error) {
	if !p.enabled {
		return "", nil
	}
	if config.ProxyURL != "" {
		return config.ProxyURL, nil
	}
	p.ensureFresh()
	now := time.Now()
	p.mu.Lock()
	var available []string
	for _, u := range p.pool {
		if until, bad := p.failed[u]; !bad || until.Before(now) {
			available = append(available, u)
		}
	}
	p.mu.Unlock()

	if len(available) == 0 {
		if config.ProxyRequired {
			return "", fmt.Errorf("代理池为空（全部失效或未拉到），且 PROXY_REQUIRED=true 不允许直连")
		}
		return "", nil
	}
	if accountKey != "" {
		sum := md5.Sum([]byte(accountKey))
		n := new(big.Int).SetBytes(sum[:8])
		idx := new(big.Int).Mod(n, big.NewInt(int64(len(available)))).Int64()
		return available[idx], nil
	}
	return available[rand.Intn(len(available))], nil
}

// MarkFailed 网络异常的代理冷却一段时间（单代理模式不做剔除）。
func (p *Pool) MarkFailed(proxyURL string) {
	if proxyURL == "" || config.ProxyURL != "" {
		return
	}
	p.mu.Lock()
	p.failed[proxyURL] = time.Now().Add(failCooldown)
	p.mu.Unlock()
}

func (p *Pool) ensureFresh() {
	ttl := time.Duration(config.ProxyRefreshTTL) * time.Second
	p.mu.Lock()
	fresh := !p.ts.IsZero() && time.Since(p.ts) <= ttl
	p.mu.Unlock()
	if fresh {
		return
	}
	pool, err := fetchList()
	p.mu.Lock()
	defer p.mu.Unlock()
	if err != nil {
		logger.Err(err, "proxy refresh failed", "required", config.ProxyRequired)
		if config.ProxyRequired && len(p.pool) == 0 {
			return
		}
		if p.ts.IsZero() {
			p.pool = nil // 首次失败：空池兜底直连
		}
		return
	}
	p.pool = pool
	p.failed = map[string]time.Time{}
	p.ts = time.Now()
}

func fetchList() ([]string, error) {
	client := &http.Client{Timeout: apiTimeout}
	resp, err := client.Get(config.ProxyAPIURL)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 300 {
		return nil, fmt.Errorf("status %d", resp.StatusCode)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return nil, err
	}
	t := strings.TrimSpace(string(body))
	var out []string

	if strings.HasPrefix(t, "{") || strings.HasPrefix(t, "[") {
		var v interface{}
		if err := json.Unmarshal([]byte(t), &v); err == nil {
			out = parseJSONValue(v)
		}
	}
	if len(out) == 0 && (config.ProxyAPIFormat == "auto" || config.ProxyAPIFormat == "plain") {
		for _, line := range strings.Split(t, "\n") {
			line = strings.TrimSpace(line)
			if line == "" || strings.HasPrefix(line, "#") {
				continue
			}
			if u := lineToProxy(line); u != "" {
				out = append(out, u)
			}
		}
	}
	return out, nil
}

func parseJSONValue(v interface{}) []string {
	switch val := v.(type) {
	case []interface{}:
		var out []string
		for _, item := range val {
			if u := itemToProxy(item); u != "" {
				out = append(out, u)
			}
		}
		return out
	case map[string]interface{}:
		if hasAny(val, "http", "ip", "proxy", "url", "host") {
			if u := itemToProxy(val); u != "" {
				return []string{u}
			}
			return nil
		}
		for _, k := range []string{"data", "proxies", "list", "result", "rows", "items"} {
			sub, ok := val[k]
			if !ok {
				continue
			}
			switch sv := sub.(type) {
			case []interface{}:
				var out []string
				for _, item := range sv {
					if u := itemToProxy(item); u != "" {
						out = append(out, u)
					}
				}
				if len(out) > 0 {
					return out
				}
			case string:
				if u := lineToProxy(strings.TrimSpace(sv)); u != "" {
					return []string{u}
				}
			}
		}
	}
	return nil
}

func itemToProxy(item interface{}) string {
	switch v := item.(type) {
	case string:
		return lineToProxy(strings.TrimSpace(v))
	case map[string]interface{}:
		if h, ok := v["http"].(string); ok && h != "" {
			return normalize(h)
		}
		ip, _ := v["ip"].(string)
		if s, ok := v["host"].(string); ok && ip == "" {
			ip = s
		}
		port, _ := v["port"].(string)
		if f, ok := v["port"].(float64); ok {
			port = fmt.Sprintf("%d", int(f))
		}
		if ip != "" && port != "" {
			return normalize(fmt.Sprintf("http://%s:%s", ip, port))
		}
		for _, k := range []string{"proxy", "url"} {
			if u, ok := v[k].(string); ok && u != "" {
				return lineToProxy(u)
			}
		}
	}
	return ""
}

func lineToProxy(line string) string {
	if line == "" {
		return ""
	}
	if !strings.Contains(line, "://") {
		line = "http://" + line
	}
	if strings.HasPrefix(line, "socks") {
		return ""
	}
	if strings.HasPrefix(line, "http://") || strings.HasPrefix(line, "https://") {
		return normalize(line)
	}
	return ""
}

func normalize(u string) string {
	parsed, err := url.Parse(u)
	if err != nil {
		return u
	}
	return parsed.String()
}

func hasAny(m map[string]interface{}, keys ...string) bool {
	for _, k := range keys {
		if _, ok := m[k]; ok {
			return true
		}
	}
	return false
}

// transportCache 全局共享 Transport：同一代理 URL 复用同一组连接（隧道）。
// 实测走代理时冷隧道 CONNECT+TLS 需 6~9s，热复用仅 ~1.5s；每任务新建
// Transport 会让每个抓取任务都付一次冷启动，故按 proxyURL 缓存共享。
// cookie 在各 Session 自己的 jar 里，跨任务复用连接不串数据。
var (
	transportMu sync.Mutex
	transports  = map[string]*http.Transport{}
)

// TransportFor 返回走指定代理的 Transport（按 proxyURL 缓存复用）；proxyURL 为空则直连。
func TransportFor(proxyURL string) *http.Transport {
	transportMu.Lock()
	defer transportMu.Unlock()
	if proxyURL != "" {
		if tr, ok := transports[proxyURL]; ok {
			return tr
		}
	}
	tr := &http.Transport{
		MaxIdleConns:        40,
		MaxIdleConnsPerHost: 8,
		IdleConnTimeout:     6 * time.Minute,
		TLSHandshakeTimeout: 10 * time.Second,
	}
	if proxyURL != "" {
		if pu, err := url.Parse(proxyURL); err == nil {
			tr.Proxy = http.ProxyURL(pu)
		}
		transports[proxyURL] = tr
	}
	return tr
}
