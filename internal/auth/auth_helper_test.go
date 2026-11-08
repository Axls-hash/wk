package auth

import (
	"crypto/sha256"
	"encoding/hex"
	"golang.org/x/crypto/pbkdf2"
)

// hexOfPBKDF2 计算标准 PBKDF2-SHA256 向量。
func hexOfPBKDF2(password, saltHex string, iters int) string {
	salt, _ := hex.DecodeString(saltHex)
	dk := pbkdf2.Key([]byte(password), salt, iters, 32, sha256.New)
	return hex.EncodeToString(dk)
}

func signPayload(payload string) string {
	return sign(payload)
}
