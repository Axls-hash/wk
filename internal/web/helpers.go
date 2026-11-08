// Package web HTTP 路由与处理器（对齐 Python 各 routers 的行为与响应结构）。
package web

import (
	"encoding/json"
	"net"
	"net/http"
	"strings"
	"time"

	"daili-go/internal/auth"
	"daili-go/internal/config"
	"daili-go/internal/fetchers"
	"daili-go/internal/logger"
	"daili-go/internal/store"
)

// ---------------- JSON 响应助手 ----------------

func writeJSON(w http.ResponseWriter, status int, v interface{}) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

// errJSON {"detail": "..."}，对齐 FastAPI 错误结构。
func errJSON(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, map[string]string{"detail": msg})
}

type fieldErr struct {
	Field string `json:"loc"`
	Msg   string `json:"msg"`
}

func err422(w http.ResponseWriter) {
	errJSON(w, 422, "请求参数不合法")
}

var _ = fieldErr{}

// readJSON 解析 JSON body 到 v。
func readJSON(w http.ResponseWriter, r *http.Request, v interface{}) bool {
	if err := json.NewDecoder(http.MaxBytesReader(w, r.Body, 2<<20)).Decode(v); err != nil {
		errJSON(w, 400, "请求体不是合法 JSON")
		return false
	}
	return true
}

// ---------------- 客户端 IP ----------------

func clientIP(r *http.Request) string {
	ip := ""
	if host, _, err := net.SplitHostPort(r.RemoteAddr); err == nil {
		ip = host
	} else {
		ip = r.RemoteAddr
	}
	if config.TrustProxy && isTrustedProxy(ip) {
		xff := strings.TrimSpace(strings.Split(r.Header.Get("X-Forwarded-For"), ",")[0])
		if parsed := net.ParseIP(xff); parsed != nil {
			ip = parsed.String()
		}
	}
	return ip
}

// ---------------- 登录态 ----------------

// currentUser 校验 cookie 并返回库中最新用户信息。
// 未登录/版本不符/被封禁 → ok=false（由调用方决定响应码）。
type userInfo struct {
	ID           int64
	Version      int
	Username     string
	Contact      string
	Points       float64
	TokenVersion int64
	Status       string
	CreatedAt    float64
}

func isTrustedProxy(ip string) bool {
	parsed := net.ParseIP(ip)
	if parsed == nil {
		return false
	}
	for _, raw := range config.TrustedProxyCIDRs {
		_, network, err := net.ParseCIDR(raw)
		if err == nil && network.Contains(parsed) {
			return true
		}
	}
	return false
}

func loadUser(id int64) *userInfo {
	row := store.DB.QueryRow(
		`SELECT id, username, contact, points, token_version, status, created_at FROM agent_users WHERE id=?`, id)
	var u userInfo
	var status string
	var tv int64
	err := row.Scan(&u.ID, &u.Username, &u.Contact, &u.Points, &tv, &status, &u.CreatedAt)
	if err != nil {
		return nil
	}
	u.Status = status
	u.TokenVersion = tv
	return &u
}

// requireAgent 登录制门禁：未登录/失效/封禁一律 401。返回用户或 ok=false。
func requireAgent(w http.ResponseWriter, r *http.Request) (*userInfo, bool) {
	ck, err := r.Cookie(auth.AgentCookie)
	if err != nil || ck.Value == "" {
		errJSON(w, 401, "请先登录后再查课")
		return nil, false
	}
	claims := auth.ParseAgentToken(ck.Value)
	if claims == nil {
		errJSON(w, 401, "请先登录后再查课")
		return nil, false
	}
	u := loadUser(claims.ID)
	if u == nil || u.TokenVersion != claims.Version {
		errJSON(w, 401, "登录态已失效，请重新登录")
		return nil, false
	}
	if u.Status == "banned" {
		errJSON(w, 401, "账号已被封禁")
		return nil, false
	}
	return u, true
}

// currentAgent 可选登录态：有效且未封禁返回用户，否则 nil。
func currentAgent(r *http.Request) *userInfo {
	ck, err := r.Cookie(auth.AgentCookie)
	if err != nil || ck.Value == "" {
		return nil
	}
	claims := auth.ParseAgentToken(ck.Value)
	if claims == nil {
		return nil
	}
	u := loadUser(claims.ID)
	if u == nil || u.TokenVersion != claims.Version || u.Status == "banned" {
		return nil
	}
	return u
}

// isAdminReq 管理员会话校验。
func isAdminReq(r *http.Request) bool {
	ck, err := r.Cookie(auth.AdminCookie)
	if err != nil {
		return false
	}
	return auth.ParseAdminToken(ck.Value)
}

// requireAdmin 未登录直接 401；会话剩余不足一半时自动滑动续期
// （活跃管理员不再每 12 小时被强制踢回登录页）。
func requireAdmin(w http.ResponseWriter, r *http.Request) bool {
	ck, err := r.Cookie(auth.AdminCookie)
	if err != nil || ck.Value == "" || !auth.ParseAdminToken(ck.Value) {
		errJSON(w, 401, "未登录")
		return false
	}
	if _, expires := auth.ParseAdminTokenEx(ck.Value); time.Now().Unix()+(auth.AdminMaxAge/2) >= expires {
		setAdminCookie(w) // 重发完整有效期的新 cookie
	}
	return true
}

// setCookie / deleteCookie 助手。
func setAgentCookie(w http.ResponseWriter, id, version int64) {
	token, maxAge := auth.MakeAgentToken(id, version)
	http.SetCookie(w, &http.Cookie{
		Name:     auth.AgentCookie,
		Value:    token,
		Path:     "/",
		MaxAge:   maxAge,
		HttpOnly: true,
		SameSite: http.SameSiteLaxMode,
		Secure:   config.SecureCookie,
	})
}

func clearAgentCookie(w http.ResponseWriter) {
	http.SetCookie(w, &http.Cookie{
		Name: auth.AgentCookie, Value: "", Path: "/", MaxAge: -1,
		HttpOnly: true, SameSite: http.SameSiteLaxMode, Secure: config.SecureCookie,
	})
}

func setAdminCookie(w http.ResponseWriter) {
	token, maxAge := auth.MakeAdminToken()
	http.SetCookie(w, &http.Cookie{
		Name: auth.AdminCookie, Value: token, Path: "/", MaxAge: maxAge,
		HttpOnly: true, SameSite: http.SameSiteLaxMode, Secure: config.SecureCookie,
	})
}

func clearAdminCookie(w http.ResponseWriter) {
	http.SetCookie(w, &http.Cookie{
		Name: auth.AdminCookie, Value: "", Path: "/", MaxAge: -1,
		HttpOnly: true, SameSite: http.SameSiteLaxMode, Secure: config.SecureCookie,
	})
}

// logLogin 记录登录日志（只保留最近 500 条）。
func logLogin(username, ip string, success bool) {
	succ := 0
	if success {
		succ = 1
	}
	// 插入 + 裁剪同一事务：写事务串行（_txlock=immediate），
	// 裁剪基于本轮插入的自增 id，避免并发下取 MAX(id) 误删其它请求刚插入的行。
	tx, err := store.DB.Begin()
	if err != nil {
		return
	}
	defer tx.Rollback()
	res, err := tx.Exec(`INSERT INTO login_logs(username, ip, success, created_at) VALUES(?,?,?,?)`,
		truncateStr(username, 255), ip, succ, nowFloat())
	if err != nil {
		return
	}
	if id, _ := res.LastInsertId(); id > 500 {
		_, _ = tx.Exec(`DELETE FROM login_logs WHERE id <= ?`, id-500)
	}
	_ = tx.Commit()
}

// audit 写管理员审计日志，并裁剪只保留最近 5000 条（防无限膨胀）。
func audit(action string, detail string) {
	// 与 logLogin 同样的事务化裁剪：按本轮插入 id 裁剪，避免并发误删
	tx, err := store.DB.Begin()
	if err != nil {
		logger.Err(err, "audit begin failed", "action", action)
		return
	}
	defer tx.Rollback()
	res, err := tx.Exec(
		`INSERT INTO admin_audit_logs(action, detail, created_at) VALUES(?,?,?)`,
		action, truncateStr(detail, 4000), nowFloat())
	if err != nil {
		logger.Err(err, "audit write failed", "action", action)
		return
	}
	if id, _ := res.LastInsertId(); id > 5000 {
		_, _ = tx.Exec(`DELETE FROM admin_audit_logs WHERE id <= ?`, id-5000)
	}
	if err := tx.Commit(); err != nil {
		logger.Err(err, "audit commit failed")
	}
}

func nowFloat() float64 { return float64(time.Now().UnixNano()) / 1e9 }

func timeFromFloat(f float64) time.Time {
	return time.Unix(int64(f), int64((f-float64(int64(f)))*1e9))
}

func truncateStr(s string, n int) string {
	b := []byte(s)
	if len(b) <= n {
		return s
	}
	for n > 0 && (b[n]&0xC0) == 0x80 {
		n--
	}
	return string(b[:n])
}

var platformNames = fetchers.Platforms

func jsonMarshal(v interface{}) ([]byte, error) { return json.Marshal(v) }

// ipBanned 永久封禁检查。
func ipBanned(ip string) bool {
	var n int
	_ = store.DB.QueryRow(`SELECT count(*) FROM banned_ips WHERE ip=?`, ip).Scan(&n)
	return n > 0
}
