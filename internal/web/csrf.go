package web

import (
	"crypto/rand"
	"daili-go/internal/config"
	"encoding/hex"
	"net/http"
)

const csrfCookie = "csrf_token"

func csrfToken() string {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return ""
	}
	return hex.EncodeToString(b)
}

func CSRFMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		token := ""
		if cookie, err := r.Cookie(csrfCookie); err == nil {
			token = cookie.Value
		}
		if token == "" {
			token = csrfToken()
			if token != "" {
				http.SetCookie(w, &http.Cookie{Name: csrfCookie, Value: token, Path: "/", MaxAge: 86400, HttpOnly: false, SameSite: http.SameSiteLaxMode, Secure: config.SecureCookie})
			}
		}
		if r.Method != http.MethodGet && r.Method != http.MethodHead && r.Method != http.MethodOptions {
			header := r.Header.Get("X-CSRF-Token")
			if token == "" || header == "" || !secureTokenEqual(token, header) {
				errJSON(w, http.StatusForbidden, "CSRF token 无效")
				return
			}
		}
		next.ServeHTTP(w, r)
	})
}

func secureTokenEqual(a, b string) bool {
	if len(a) != len(b) {
		return false
	}
	var diff byte
	for i := range a {
		diff |= a[i] ^ b[i]
	}
	return diff == 0
}
