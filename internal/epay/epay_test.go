package epay

import (
	"crypto/md5"
	"encoding/hex"
	"testing"
)

// TestSignParams 签名规则：ASCII 升序拼接 + 密钥 MD5 小写（对齐 Python epay.py）。
func TestSignParams(t *testing.T) {
	params := map[string]string{
		"pid": "1001", "type": "wxpay", "out_trade_no": "abc123",
		"money": "9.00", "name": "课程",
	}
	got := SignParams(params, "secret")
	// 手工按规则计算期望值：a=b&c=d 拼接后 + key
	expect := md5hex("money=9.00&name=课程&out_trade_no=abc123&pid=1001&type=wxpay" + "secret")
	if got != expect {
		t.Fatalf("sign mismatch:\n got %s\nwant %s", got, expect)
	}
	// sign/sign_type/空值 必须被排除
	withNoise := map[string]string{
		"money": "9.00", "sign": "xx", "sign_type": "MD5", "empty": "",
	}
	if SignParams(withNoise, "k") != md5hex("money=9.00"+"k") {
		t.Fatal("排除规则失效")
	}
}

// TestVerifySign 验签与防篡改。
func TestVerifySign(t *testing.T) {
	p := map[string]string{"out_trade_no": "o1", "money": "3.00", "trade_status": "TRADE_SUCCESS"}
	p["sign"] = SignParams(p, "key")
	if !VerifySign(p, "key") {
		t.Fatal("正确签名应通过")
	}
	p["money"] = "0.01" // 篡改金额
	if VerifySign(p, "key") {
		t.Fatal("篡改后验签应失败")
	}
	if VerifySign(map[string]string{}, "key") {
		t.Fatal("缺 sign 应失败")
	}
}

// TestMoneyToFen 金额转分（含边界）。
func TestMoneyToFen(t *testing.T) {
	cases := []struct {
		in   string
		want int64
		ok   bool
	}{
		{"9.00", 900, true}, {"0.01", 1, true}, {"3", 300, true},
		{".5", 50, true}, {"12.345", 1234, true}, // 截断到分（不四舍五入，与 Decimal 行为一致取前两位）
		{"-1.50", -150, true}, {"", 0, false}, {"abc", 0, false},
	}
	for _, c := range cases {
		got, ok := MoneyToFen(c.in)
		if ok != c.ok || (ok && got != c.want) {
			t.Errorf("MoneyToFen(%q) = (%d,%v), want (%d,%v)", c.in, got, ok, c.want, c.ok)
		}
	}
}

// TestYuanStr 分转元字符串。
func TestYuanStr(t *testing.T) {
	if YuanStr(900) != "9.00" || YuanStr(5) != "0.05" {
		t.Fatal("YuanStr 输出不符")
	}
}

func md5hex(s string) string {
	sum := md5.Sum([]byte(s))
	return hex.EncodeToString(sum[:])
}
