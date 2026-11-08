package config

import (
	"crypto/rand"
	"encoding/hex"
)

// randomToken 生成 n 字节随机数的 url-safe 字符串（对齐 Python secrets.token_urlsafe）。
func randomToken(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	s := hex.EncodeToString(b) // hex 足够安全且无特殊字符
	return s
}
