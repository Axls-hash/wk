package securestore

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/sha256"
	"daili-go/internal/config"
	"encoding/base64"
	"fmt"
	"io"
)

func key() []byte { sum := sha256.Sum256([]byte(config.CredentialEncryptionKey)); return sum[:] }

func Encrypt(value string) (string, error) {
	block, err := aes.NewCipher(key())
	if err != nil {
		return "", err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return "", err
	}
	nonce := make([]byte, gcm.NonceSize())
	if _, err = io.ReadFull(rand.Reader, nonce); err != nil {
		return "", err
	}
	sealed := gcm.Seal(nonce, nonce, []byte(value), nil)
	return "enc:v1:" + base64.RawStdEncoding.EncodeToString(sealed), nil
}

func Decrypt(value string) (string, error) {
	if len(value) < 7 || value[:7] != "enc:v1:" {
		return value, nil
	}
	raw, err := base64.RawStdEncoding.DecodeString(value[7:])
	if err != nil {
		return "", err
	}
	block, err := aes.NewCipher(key())
	if err != nil {
		return "", err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return "", err
	}
	ns := gcm.NonceSize()
	if len(raw) < ns {
		return "", fmt.Errorf("invalid encrypted credential")
	}
	plain, err := gcm.Open(nil, raw[:ns], raw[ns:], nil)
	if err != nil {
		return "", err
	}
	return string(plain), nil
}
