// Package epay 易支付（彩虹协议）签名与金额工具。逻辑对齐 Python 版。
package epay

import (
	"crypto/hmac"
	"crypto/md5"
	"encoding/hex"
	"fmt"
	"math"
	"net/url"
	"sort"
	"strconv"
	"strings"
)

// SignParams 参数按 ASCII 升序拼接 a=b&c=d...（排除 sign/sign_type 与空值），
// 末尾拼商户密钥取 MD5 小写。
func SignParams(params map[string]string, key string) string {
	keys := make([]string, 0, len(params))
	for k, v := range params {
		if k == "sign" || k == "sign_type" || v == "" {
			continue
		}
		keys = append(keys, k)
	}
	sort.Strings(keys)
	pairs := make([]string, 0, len(keys))
	for _, k := range keys {
		pairs = append(pairs, k+"="+params[k])
	}
	sum := md5.Sum([]byte(strings.Join(pairs, "&") + key))
	return hex.EncodeToString(sum[:])
}

// VerifySign 校验 notify/return 参数签名（恒定时间比较）。
func VerifySign(params map[string]string, key string) bool {
	sign := params["sign"]
	if sign == "" {
		return false
	}
	expect := SignParams(params, key)
	return hmac.Equal([]byte(strings.ToLower(sign)), []byte(expect))
}

// YuanStr 分 → "9.00" 形式字符串。
func YuanStr(fen int64) string {
	return fmt.Sprintf("%.2f", float64(fen)/100)
}

// MoneyToFen 渠道回调金额字符串 → 分；解析失败返回 false。
// 用整数化处理规避浮点误差："9.00" -> 900。
func MoneyToFen(money string) (int64, bool) {
	s := strings.TrimSpace(money)
	if s == "" {
		return 0, false
	}
	neg := false
	if strings.HasPrefix(s, "-") {
		neg = true
		s = s[1:]
	}
	intPart, fracPart := s, ""
	if i := strings.IndexByte(s, '.'); i >= 0 {
		intPart, fracPart = s[:i], s[i+1:]
	}
	if len(fracPart) > 2 {
		fracPart = fracPart[:2]
	}
	if intPart == "" && fracPart == "" {
		return 0, false
	}
	ip := int64(0)
	if intPart != "" {
		v, err := strconv.ParseInt(intPart, 10, 64)
		if err != nil {
			return 0, false
		}
		ip = v
	}
	fp := int64(0)
	if fracPart != "" {
		for i := 0; i < len(fracPart); i++ {
			c := fracPart[i]
			if c < '0' || c > '9' {
				return 0, false
			}
			fp = fp*10 + int64(c-'0')
		}
		for i := len(fracPart); i < 2; i++ {
			fp *= 10
		}
	}
	fen := ip*100 + fp
	if fen > math.MaxInt32 {
		return 0, false
	}
	if neg {
		fen = -fen
	}
	return fen, true
}

// BuildPayUrl 构造收银台跳转 URL。
func BuildPayUrl(apiURL, pid, key, payType, orderID, name string, amountFen int64, notifyURL, returnURL string) string {
	params := map[string]string{
		"pid":          pid,
		"type":         payType,
		"out_trade_no": orderID,
		"notify_url":   notifyURL,
		"return_url":   returnURL,
		"name":         truncate(name, 100),
		"money":        YuanStr(amountFen),
	}
	params["sign"] = SignParams(params, key)
	params["sign_type"] = "MD5"

	vals := url.Values{}
	for k, v := range params {
		vals.Set(k, v)
	}
	return apiURL + "/submit.php?" + vals.Encode()
}

func truncate(s string, n int) string {
	b := []byte(s)
	if len(b) <= n {
		return s
	}
	// 不截断多字节字符中间
	for n > 0 && (b[n]&0xC0) == 0x80 {
		n--
	}
	return string(b[:n])
}
