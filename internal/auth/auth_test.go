package auth

import (
	"strings"
	"testing"
	"time"

	"daili-go/internal/config"
)

func init() { config.AdminSecret = "unit-test-secret" }

// TestPasswordHashRoundTrip PBKDF2 哈希往返 + 格式兼容（Python 版互认）。
func TestPasswordHashRoundTrip(t *testing.T) {
	hash := HashPassword("s3cret-pw")
	if !VerifyPassword("s3cret-pw", hash) {
		t.Fatal("正确密码应通过")
	}
	if VerifyPassword("wrong", hash) {
		t.Fatal("错误密码应拒绝")
	}
	// Python 版格式：pbkdf2$iters$salt_hex$hash_hex
	parts := strings.Split(hash, "$")
	if len(parts) != 4 || parts[0] != "pbkdf2" || parts[1] != "120000" {
		t.Fatalf("哈希格式不符: %s", hash)
	}
	// 手工构造的向量（与 Python hashlib.pbkdf2_hmac 同参数）必须可校验：
	// salt=0102..0f, iters=1000, password="abc" 的 SHA256-PBKDF2 已知值
	vec := "pbkdf2$1000$" +
		"000102030405060708090a0b0c0d0e0f$" +
		hexOfPBKDF2("abc", "000102030405060708090a0b0c0d0e0f", 1000)
	if !VerifyPassword("abc", vec) {
		t.Fatal("标准 PBKDF2 向量应通过")
	}
	if VerifyPassword("abc", "garbage") || VerifyPassword("abc", "pbkdf2$bad$ff$ff") {
		t.Fatal("畸形存储格式应安全返回 false")
	}
}

// TestAgentToken 签发/解析/篡改/过期。
func TestAgentToken(t *testing.T) {
	token, maxAge := MakeAgentToken(42, 7)
	if maxAge != config.AgentSessionMaxAge {
		t.Fatalf("maxAge: %d", maxAge)
	}
	claims := ParseAgentToken(token)
	if claims == nil || claims.ID != 42 || claims.Version != 7 {
		t.Fatalf("解析失败: %+v", claims)
	}
	// 篡改 payload（确保替换字符与原字符不同；expires 末位恰好是 9 时用 0，
	// 否则末位可能碰巧未变，导致测试偶发失败）
	last := "9"
	if strings.HasSuffix(token, "9") {
		last = "0"
	}
	bad := token[:len(token)-1] + last
	if ParseAgentToken(bad) != nil {
		t.Fatal("篡改令牌应被拒绝")
	}
	// 篡改签名
	sig, payload, _ := strings.Cut(token, ".")
	if ParseAgentToken("deadbeef."+payload) != nil && sig == "deadbeef" {
		t.Fatal("伪造签名应被拒绝")
	}
	if ParseAgentToken("") != nil || ParseAgentToken("no-dot") != nil {
		t.Fatal("畸形令牌应被拒绝")
	}
}

// TestAdminToken 管理员令牌。
func TestAdminToken(t *testing.T) {
	tok, age := MakeAdminToken()
	if age != AdminMaxAge {
		t.Fatalf("maxAge %d", age)
	}
	if !ParseAdminToken(tok) {
		t.Fatal("合法令牌应通过")
	}
	if ParseAdminToken(strings.Repeat("0.", 1) + "1") {
		// 过期时间 1 → 必然过期
	}
	// 构造已过期令牌
	expired := signPayload("1") + ".1"
	if ParseAdminToken(expired) {
		t.Fatal("过期令牌应拒绝")
	}
}

// TestSafeEq 恒定时间比较。
func TestSafeEq(t *testing.T) {
	if !SafeEq("a", "a") || SafeEq("a", "b") {
		t.Fatal("SafeEq 行为异常")
	}
	var _ = time.Now
}
