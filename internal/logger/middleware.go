package logger

import (
	"io"
	"log/slog"
	"net"
	"net/http"
	"strings"
	"time"
)

func io_MultiWriter(writers ...io.Writer) io.Writer { return io.MultiWriter(writers...) }

type statusWriter struct {
	http.ResponseWriter
	status int
	bytes  int
}

func (w *statusWriter) WriteHeader(code int) {
	// 记录首次状态码，重复调用时更新为最新码但不重复写头（net/http 已忽略二次 WriteHeader）
	if w.status == 0 {
		w.status = code
		w.ResponseWriter.WriteHeader(code)
		return
	}
	// 已写头后再次调用仅更新记录值，供日志准确
	w.status = code
}

func (w *statusWriter) Write(b []byte) (int, error) {
	if w.status == 0 {
		w.status = http.StatusOK
	}
	n, err := w.ResponseWriter.Write(b)
	w.bytes += n
	return n, err
}

// Flush 透传（SSE 依赖）。
func (w *statusWriter) Flush() {
	if f, ok := w.ResponseWriter.(http.Flusher); ok {
		f.Flush()
	}
}

// RequestLog HTTP 访问日志中间件：method path status 耗时 ip。
// /api/tasks/*/events 的 SSE 长连接（最常驻 60s）无法在开始时打日志，
// 只能随 handler 返回后记录；避免每连接刷一条分钟级噪音日志，将其降为 debug 级。
func RequestLog(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		sw := &statusWriter{ResponseWriter: w}
		next.ServeHTTP(sw, r)

		if sw.status == 0 {
			sw.status = http.StatusOK
		}
		dur := time.Since(start).Milliseconds()
		if strings.HasPrefix(r.URL.Path, "/api/tasks/") &&
			strings.HasSuffix(r.URL.Path, "/events") {
			L.Debug("http-sse-done",
				"path", r.URL.Path,
				"status", sw.status,
				"dur_ms", dur,
				"ip", clientIPString(r),
			)
			return
		}
		L.Info("http",
			"method", r.Method,
			"path", r.URL.Path,
			"status", sw.status,
			"dur_ms", dur,
			"ip", clientIPString(r),
		)
	})
}

func clientIPString(r *http.Request) string {
	if host, _, err := net.SplitHostPort(r.RemoteAddr); err == nil {
		return host // 兼容 IPv6（"[::1]:80" -> "::1"）
	}
	return r.RemoteAddr
}

var _ = slog.Default
