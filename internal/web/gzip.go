package web

import (
	"compress/gzip"
	"io"
	"net/http"
	"strings"
	"sync"
)

// gzip 中间件：对可压缩文本响应启用 gzip（前端 JS/CSS/HTML/JSON 体积 -60%+）。
//
// 排除：
//   - SSE（text/event-stream）：流式推送不能缓冲压缩
//   - 已是压缩格式（图片/字体/CSV-BOM 无所谓但也不必）
//   - 客户端未声明 Accept-Encoding: gzip

var gzipWriterPool = sync.Pool{
	New: func() interface{} { return gzip.NewWriter(io.Discard) },
}

type gzipResponseWriter struct {
	http.ResponseWriter
	gz          *gzip.Writer
	enabled     bool
	wroteHeader bool
}

func (g *gzipResponseWriter) WriteHeader(code int) {
	if g.wroteHeader {
		return
	}
	g.wroteHeader = true
	// Content-Type 到这里才最终确定：流式类型不压、非文本类型不压
	if g.enabled && !isStreamType(g.Header().Get("Content-Type")) &&
		compressibleCT(g.Header().Get("Content-Type")) {
		g.Header().Set("Content-Encoding", "gzip")
		g.Header().Del("Content-Length") // 压缩后长度未知，交给 chunked
		if v := gzipWriterPool.Get(); v != nil {
			if gz, ok := v.(*gzip.Writer); ok {
				gz.Reset(g.ResponseWriter)
				g.gz = gz
			} else {
				g.gz = gzip.NewWriter(g.ResponseWriter)
			}
		} else {
			g.gz = gzip.NewWriter(g.ResponseWriter)
		}
	} else {
		g.enabled = false
	}
	g.ResponseWriter.WriteHeader(code)
}

func (g *gzipResponseWriter) Write(b []byte) (int, error) {
	if !g.wroteHeader {
		g.WriteHeader(http.StatusOK)
	}
	if g.enabled {
		return g.gz.Write(b)
	}
	return g.ResponseWriter.Write(b)
}

// Flush 支持（SSE 与 http.Flusher 依赖）：先冲刷 gzip 缓冲再透传。
func (g *gzipResponseWriter) Flush() {
	if g.enabled && g.gz != nil {
		_ = g.gz.Flush()
	}
	if f, ok := g.ResponseWriter.(http.Flusher); ok {
		f.Flush()
	}
}

func isStreamType(contentType string) bool {
	return strings.HasPrefix(contentType, "text/event-stream")
}

func compressibleCT(ct string) bool {
	lower := strings.ToLower(strings.SplitN(ct, ";", 2)[0])
	switch lower {
	case "", "text/html", "text/css", "text/plain", "text/csv",
		"text/javascript", "application/javascript", "application/json",
		"image/svg+xml", "application/xml", "text/xml":
		return true
	}
	return false
}

// AcceptsGzip 客户端是否接受 gzip。
func acceptsGzip(r *http.Request) bool {
	for _, v := range r.Header.Values("Accept-Encoding") {
		for _, part := range strings.Split(v, ",") {
			if strings.TrimSpace(strings.SplitN(part, ";", 2)[0]) == "gzip" {
				return true
			}
		}
	}
	return false
}

// GzipMiddleware gzip 压缩中间件。
func GzipMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !acceptsGzip(r) || r.Method == http.MethodHead {
			next.ServeHTTP(w, r)
			return
		}
		gw := &gzipResponseWriter{ResponseWriter: w, enabled: true}
		// Content-Type 在 handler 里才设置；这里无法预判，靠 WriteHeader 时检查。
		// 但 SSE 等流式响应需要提前豁免——按路径兜底判断。
		if strings.HasPrefix(r.URL.Path, "/api/tasks/") &&
			strings.HasSuffix(r.URL.Path, "/events") {
			gw.enabled = false
		}
		defer func() {
			if gw.enabled && gw.wroteHeader {
				if gz := gw.gz; gz != nil {
					_ = gz.Close()
					gz.Reset(io.Discard)
					gzipWriterPool.Put(gz)
				}
			}
		}()
		next.ServeHTTP(gw, r)
	})
}
