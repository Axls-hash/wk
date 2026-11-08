// Package auth 认证：PBKDF2 密码哈希 + HMAC 签名会话令牌。
//
// 令牌格式与 Python 版完全一致（可互相校验，用户无需重新登录）：
//   - agent: hex(hmac(secret, "agent_id.version.expires")) + "." + payload
//   - admin: hex(hmac(secret, expires)) + "." + expires
package auth

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/hex"
	"fmt"
	"strconv"
	"strings"
	"time"

	"golang.org/x/crypto/pbkdf2"

	"daili-go/internal/config"
)

const pbkdf2Iters = 120_000

// ---------------- 密码哈希 ----------------

func HashPassword(password string) string {
	salt := make([]byte, 16)
	_, _ = rand.Read(salt)
	dk := pbkdf2.Key([]byte(password), salt, pbkdf2Iters, 32, sha256.New)
	return fmt.Sprintf("pbkdf2$%d$%s$%s", pbkdf2Iters, hex.EncodeToString(salt), hex.EncodeToString(dk))
}

func VerifyPassword(password, stored string) bool {
	parts := strings.Split(stored, "$")
	if len(parts) != 4 || parts[0] != "pbkdf2" {
		return false
	}
	iters, err := strconv.Atoi(parts[1])
	if err != nil || iters <= 0 || iters > 10_000_000 {
		return false
	}
	salt, err1 := hex.DecodeString(parts[2])
	want, err2 := hex.DecodeString(parts[3])
	if err1 != nil || err2 != nil {
		return false
	}
	dk := pbkdf2.Key([]byte(password), salt, iters, len(want), sha256.New)
	return hmac.Equal(dk, want)
}

// ---------------- agent 会话 ----------------

const AgentCookie = "agent_session"

type AgentClaims struct {
	ID      int64
	Version int64
}

func sign(payload string) string {
	mac := hmac.New(sha256.New, []byte(config.AdminSecret))
	mac.Write([]byte(payload))
	return hex.EncodeToString(mac.Sum(nil))
}
func signAgent(payload string) string { return sign("agent:" + payload) }
func signAdmin(payload string) string { return sign("admin:" + payload) }

func MakeAgentToken(id, version int64) (token string, maxAge int) {
	expires := time.Now().Unix() + int64(config.AgentSessionMaxAge)
	payload := fmt.Sprintf("%d.%d.%d", id, version, expires)
	return signAgent(payload) + "." + payload, config.AgentSessionMaxAge
}

func ParseAgentToken(token string) *AgentClaims {
	sig, payload, ok := strings.Cut(token, ".")
	if !ok {
		return nil
	}
	parts := strings.Split(payload, ".")
	if len(parts) != 3 {
		return nil
	}
	id, err1 := strconv.ParseInt(parts[0], 10, 64)
	version, err2 := strconv.ParseInt(parts[1], 10, 64)
	expires, err3 := strconv.ParseInt(parts[2], 10, 64)
	if err1 != nil || err2 != nil || err3 != nil {
		return nil
	}
	if expires < time.Now().Unix() {
		return nil
	}
	// 域隔离：新 token 用 agent 前缀，老 token 兼容过渡期仍接受
	if subtle.ConstantTimeCompare([]byte(sig), []byte(signAgent(payload))) == 1 {
		return &AgentClaims{ID: id, Version: version}
	}
	if subtle.ConstantTimeCompare([]byte(sig), []byte(sign(payload))) == 1 {
		return &AgentClaims{ID: id, Version: version}
	}
	return nil
}

// ---------------- admin 会话 ----------------

const AdminCookie = "admin_session"

const AdminMaxAge = 12 * 3600

func MakeAdminToken() (token string, maxAge int) {
	expires := time.Now().Unix() + AdminMaxAge
	payload := strconv.FormatInt(expires, 10)
	return signAdmin(payload) + "." + payload, AdminMaxAge
}

func ParseAdminToken(token string) bool {
	ok, _ := ParseAdminTokenEx(token)
	return ok
}

// ParseAdminTokenEx 校验并返回令牌的绝对过期时间（供滑动续期计算剩余期）。
func ParseAdminTokenEx(token string) (bool, int64) {
	sig, payload, ok := strings.Cut(token, ".")
	if !ok {
		return false, 0
	}
	expires, err := strconv.ParseInt(payload, 10, 64)
	if err != nil {
		return false, 0
	}
	if expires < time.Now().Unix() {
		return false, 0
	}
	if subtle.ConstantTimeCompare([]byte(sig), []byte(signAdmin(payload))) == 1 {
		return true, expires
	}
	// 兼容旧 token（过渡期）
	if subtle.ConstantTimeCompare([]byte(sig), []byte(sign(payload))) == 1 {
		return true, expires
	}
	return false, 0
}

// SafeEq 恒定时间字符串比较。
func SafeEq(a, b string) bool {
	return subtle.ConstantTimeCompare([]byte(a), []byte(b)) == 1
}
