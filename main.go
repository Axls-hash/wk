// wk_dl —— 课程进度抓取平台（纯 HTML 前端 + Go 后端版）。
//
// 与 daili-go 行为/API 完全兼容，唯一区别：前端不再 embed 进二进制，
// 而是以可编辑的静态文件形式放 <root>/static/ 目录，运行时直接读取。
// 改前端 = 直接编辑 static/ 下的 html/css/js，重启服务即生效，无需重新编译。
package main

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"daili-go/internal/auth"
	"daili-go/internal/bingli"
	"daili-go/internal/config"
	"daili-go/internal/logger"
	"daili-go/internal/proxy"
	"daili-go/internal/store"
	"daili-go/internal/sync"
	"daili-go/internal/syscfg"
	"daili-go/internal/tasks"
	"daili-go/internal/web"
)

// staticDir 前端静态文件根目录（可编辑，改完重启即生效）。
var staticDir string

func main() {
	// 本地预览模式（ALLOW_SQLITE=1）下 panic 不闪退：报错后停住等回车，
	// 让双击运行也能看清失败原因。生产不设该变量，照旧立即退出交 systemd 重启。
	defer func() {
		if r := recover(); r != nil {
			fmt.Printf("[wk_dl] 启动失败: %v\n", r)
			if os.Getenv("ALLOW_SQLITE") == "1" {
				fmt.Println("\n按回车键退出…")
				_, _ = bufio.NewReader(os.Stdin).ReadString('\n')
			}
			os.Exit(1)
		}
	}()
	run()
}

func run() {
	// 项目根 = 可执行文件所在目录；开发时回退到工作目录
	root := rootDir()
	staticDir = filepath.Join(root, "static")
	logger.Init(filepath.Join(root, "logs"), os.Getenv("LOG_LEVEL") == "debug")
	config.Load(root)
	proxy.Init()

	if err := store.OpenMySQL(config.DBPath); err != nil {
		// 本地预览模式（ALLOW_SQLITE=1）：MySQL 连不上时退回 SQLite app.db，
		// 方便双击 exe 直接看效果。生产绝不设置该变量——MySQL 不可用就该
		// 启动失败等 systemd 重试，静默落到 SQLite 会造成数据分叉。
		if os.Getenv("ALLOW_SQLITE") == "1" {
			logger.Warnf("MySQL 不可用(%v)，进入本地预览模式：使用 SQLite app.db（数据与生产不通）", err)
			if err2 := store.Open(filepath.Join(root, "app.db")); err2 != nil {
				panic(fmt.Sprintf("数据库初始化失败: mysql=%v sqlite=%v", err, err2))
			}
		} else {
			panic(err)
		}
	}
	syscfg.LoadFromDB() // 后台保存的支付/天河对接覆盖 .env（库优先）
	tasks.Init()
	store.StartBackupLoop(filepath.Join(root, "backups"), 24*time.Hour)
	web.ResubmitProcessingOrders() // 启动时重放中断的下单，防重启丢单
	// 先用库里的在售商品恢复内存目录，再启动异步同步：
	// 否则从服务启动到首次同步完成之间，综合网课商品能显示但下单/查课会被判为“不支持的平台”。
	if err := sync.LoadCatalogFromDB(); err != nil {
		logger.Warnf("load product catalog from db failed: %v", err)
	}
	sync.Start()                // 冷门网课商品自动同步（天河 getclass 差量更新）
	web.RetryPendingSyyOrders() // 我爱运动：processing 订单按原 transaction_id 重放（上游幂等）
	bingli.RetryPending()       // 病例：processing 订单按原 externalRequestId 重放（上游幂等）

	mux := http.NewServeMux()

	// ---- 公开 ----
	mux.HandleFunc("GET /api/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"status":"ok"}`))
	})
	mux.HandleFunc("GET /api/platforms", web.HandlePlatforms)
	mux.HandleFunc("GET /api/more-platforms", web.HandleMorePlatforms)
	mux.HandleFunc("GET /api/more-products", web.HandleMoreProducts)
	mux.HandleFunc("GET /api/config", web.HandleConfig)
	mux.HandleFunc("GET /api/announcements", web.HandleListAnnouncements)
	// 支付回调：易支付渠道既发 GET 异步通知，也可能用 POST；两个方法都挂上，
	// 避免渠道 POST 通知被 Go 1.22+ ServeMux 直接 405 导致订单永远停在 pending。
	mux.HandleFunc("GET /pay/epay/notify", web.HandleEpayNotify)
	mux.HandleFunc("POST /pay/epay/notify", web.HandleEpayNotify)
	mux.HandleFunc("GET /pay/epay/return", web.HandleEpayReturn)
	mux.HandleFunc("POST /pay/epay/return", web.HandleEpayReturn)

	// ---- 查课（登录制）----
	mux.HandleFunc("POST /api/accounts", web.HandleSubmitAccount)
	mux.HandleFunc("GET /api/tasks/{task_id}", web.HandleGetTask)
	mux.HandleFunc("GET /api/tasks/{task_id}/events", web.HandleTaskEvents)
	mux.HandleFunc("POST /api/submit-courses", web.HandleSubmitCourses)

	// ---- 订单 ----
	mux.HandleFunc("POST /api/orders", web.HandleCreateOrder)
	mux.HandleFunc("GET /api/orders/{order_id}", web.HandleGetOrder)

	// ---- 实习打卡（sxdk，独立模块）----
	mux.HandleFunc("GET /api/sxdk/platforms", web.HandleSxdkPlatforms)
	mux.HandleFunc("POST /api/sxdk/search-phone", web.HandleSxdkSearchPhone)
	mux.HandleFunc("POST /api/sxdk/orders", web.HandleSxdkCreateOrder)
	mux.HandleFunc("GET /api/sxdk/orders", web.HandleSxdkOrders)
	mux.HandleFunc("POST /api/sxdk/sync", web.HandleSxdkSync)

	// ---- 我爱运动（syy，套娃下游对接天河 syytianheban）----
	mux.HandleFunc("GET /api/syy/projects", web.HandleSyyProjects)
	mux.HandleFunc("POST /api/syy/open", web.HandleSyyOpen)
	mux.HandleFunc("POST /api/syy/orders", web.HandleSyyCreateOrder)
	mux.HandleFunc("GET /api/syy/orders", web.HandleSyyOrders)
	mux.HandleFunc("GET /api/syy/balance", web.HandleSyyBalance)
	mux.HandleFunc("POST /api/syy/withdraw", web.HandleSyyWithdraw)

	// ---- 病例（bingli，独立模块；模板浏览/预览图/下单/图片代理）----
	mux.HandleFunc("GET /api/bing/applications", web.HandleBingApplications)
	mux.HandleFunc("GET /api/bing/applications/{id}", web.HandleBingApplication)
	mux.HandleFunc("GET /api/bing/applications/{id}/preview", web.HandleBingApplicationPreview)
	mux.HandleFunc("POST /api/bing/orders", web.HandleBingCreateOrder)
	mux.HandleFunc("GET /api/bing/orders", web.HandleBingOrders)
	mux.HandleFunc("GET /api/bing/orders/{order_id}", web.HandleBingOrder)
	mux.HandleFunc("GET /api/bing/orders/{order_id}/image", web.HandleBingOrderImage)
	mux.HandleFunc("POST /api/bing/orders/{order_id}/retry", web.HandleBingRetryOrder)

	// ---- 学习通自动签到（qd，独立模块；对接本机签到引擎 29 协议）----
	mux.HandleFunc("GET /api/qd/plans", web.HandleQdPlans)
	mux.HandleFunc("POST /api/qd/orders", web.HandleQdCreateOrder)
	mux.HandleFunc("GET /api/qd/orders", web.HandleQdOrders)
	mux.HandleFunc("GET /api/qd/orders/{order_id}", web.HandleQdOrder)
	mux.HandleFunc("POST /api/qd/orders/{order_id}/relogin", web.HandleQdRelogin)
	mux.HandleFunc("POST /api/qd/orders/{order_id}/photo", web.HandleQdPhoto)
	mux.HandleFunc("POST /api/qd/orders/{order_id}/push", web.HandleQdPush)
	mux.HandleFunc("GET /api/admin/qd/settings", web.HandleAdminQdSettings)
	mux.HandleFunc("POST /api/admin/qd/settings", web.HandleAdminQdSetEnabled)
	mux.HandleFunc("POST /api/admin/qd/price", web.HandleAdminQdSetPrice)
	mux.HandleFunc("GET /api/admin/qd/orders", web.HandleAdminQdOrders)

	// ---- 代理用户 ----
	mux.HandleFunc("POST /api/agent/register", web.HandleAgentRegister)
	mux.HandleFunc("POST /api/agent/login", web.HandleAgentLogin)
	mux.HandleFunc("POST /api/agent/logout", web.HandleAgentLogout)
	mux.HandleFunc("GET /api/agent/me", web.HandleAgentMe)
	mux.HandleFunc("POST /api/agent/password", web.HandleAgentPassword)
	mux.HandleFunc("POST /api/agent/redeem", web.HandleAgentRedeem)
	mux.HandleFunc("GET /api/agent/orders", web.HandleAgentOrders)
	mux.HandleFunc("GET /api/agent/orders/{order_id}/progress", web.HandleAgentOrderProgress)
	mux.HandleFunc("POST /api/agent/orders/{order_id}/rebrush", web.HandleAgentOrderRebrush)
	mux.HandleFunc("GET /api/agent/points", web.HandleAgentPoints)

	// ---- 管理后台 JSON ----
	mux.HandleFunc("POST /api/admin/login", web.HandleAdminLogin)
	mux.HandleFunc("POST /api/admin/change-password", web.HandleAdminChangePassword)
	mux.HandleFunc("POST /api/admin/logout", web.HandleAdminLogout)
	mux.HandleFunc("GET /api/admin/me", web.HandleAdminMe)
	mux.HandleFunc("GET /api/admin/dashboard", web.HandleAdminDashboard)
	mux.HandleFunc("GET /api/admin/users", web.HandleAdminUsers)
	mux.HandleFunc("POST /api/admin/users/{user_id}/points", web.HandleAdminUserPoints)
	mux.HandleFunc("POST /api/admin/users/{user_id}/ban", web.HandleAdminBan)
	mux.HandleFunc("POST /api/admin/users/{user_id}/unban", web.HandleAdminUnban)
	mux.HandleFunc("POST /api/admin/users/{user_id}/reset-password", web.HandleAdminResetPassword)
	mux.HandleFunc("DELETE /api/admin/users/{user_id}", web.HandleAdminDeleteUser)
	mux.HandleFunc("GET /api/admin/orders", web.HandleAdminOrders)
	mux.HandleFunc("GET /api/admin/orders/export.csv", web.HandleAdminExportOrders)
	mux.HandleFunc("PATCH /api/admin/orders/{order_id}", web.HandleAdminSetProcess)
	mux.HandleFunc("GET /api/admin/orders/{order_id}/credentials", web.HandleAdminOrderCredentials)
	mux.HandleFunc("POST /api/admin/orders/{order_id}/pause", web.HandleAdminOrderPause)
	mux.HandleFunc("GET /api/admin/prices", web.HandleAdminPrices)
	mux.HandleFunc("POST /api/admin/prices/{platform}", web.HandleAdminSetPrice)
	mux.HandleFunc("GET /api/admin/syy-projects", web.HandleAdminSyyProjects)
	mux.HandleFunc("POST /api/admin/syy-projects/{id}/price", web.HandleAdminSetSyyPrice)
	mux.HandleFunc("GET /api/admin/more-products", web.HandleAdminMoreProducts)
	mux.HandleFunc("POST /api/admin/more-products/{cid}/price", web.HandleAdminSetMoreProductPrice)
	mux.HandleFunc("POST /api/admin/more-products/{cid}/status", web.HandleAdminSetMoreProductStatus)
	mux.HandleFunc("POST /api/admin/more-products/{cid}/content", web.HandleAdminSetMoreProductContent)
	mux.HandleFunc("GET /api/admin/more-fenlei", web.HandleAdminMoreFenlei)
	mux.HandleFunc("POST /api/admin/more-fenlei/{fenlei}/hide", web.HandleAdminHideMoreFenlei)
	mux.HandleFunc("POST /api/admin/more-fenlei/{fenlei}/show", web.HandleAdminShowMoreFenlei)
	mux.HandleFunc("GET /api/admin/front-categories", web.HandleAdminFrontCategories)
	mux.HandleFunc("POST /api/admin/front-categories", web.HandleAdminFrontCategoryCreate)
	mux.HandleFunc("PUT /api/admin/front-categories/{id}", web.HandleAdminFrontCategoryUpdate)
	mux.HandleFunc("DELETE /api/admin/front-categories/{id}", web.HandleAdminFrontCategoryDelete)
	mux.HandleFunc("GET /api/admin/front-categories/{id}/items", web.HandleAdminFrontCategoryItems)
	mux.HandleFunc("POST /api/admin/front-categories/{id}/items", web.HandleAdminFrontCategoryAddItems)
	mux.HandleFunc("POST /api/admin/front-categories/{id}/items/remove", web.HandleAdminFrontCategoryRemoveItems)
	// 病例：管理端运行开关 + 按模板定价（不影响其他业务模块）
	mux.HandleFunc("GET /api/admin/bing/settings", web.HandleAdminBingSettings)
	mux.HandleFunc("POST /api/admin/bing/settings", web.HandleAdminBingSetEnabled)
	mux.HandleFunc("GET /api/admin/bing/applications", web.HandleAdminBingApplications)
	mux.HandleFunc("POST /api/admin/bing/applications/{id}/price", web.HandleAdminBingSetPrice)
	mux.HandleFunc("GET /api/admin/redeem-codes", web.HandleAdminListCodes)
	mux.HandleFunc("POST /api/admin/redeem-codes", web.HandleAdminGenerateCodes)
	mux.HandleFunc("GET /api/admin/redeem-codes/export.csv", web.HandleAdminExportCodes)
	mux.HandleFunc("POST /api/admin/redeem-codes/{code}/disable", web.HandleAdminDisableCode)
	mux.HandleFunc("GET /api/admin/announcements", web.HandleAdminListAnnouncements)
	mux.HandleFunc("POST /api/admin/announcements", web.HandleAdminCreateAnnouncement)
	mux.HandleFunc("PUT /api/admin/announcements/{id}", web.HandleAdminUpdateAnnouncement)
	mux.HandleFunc("DELETE /api/admin/announcements/{id}", web.HandleAdminDeleteAnnouncement)
	mux.HandleFunc("GET /api/admin/point-logs", web.HandleAdminPointLogs)
	mux.HandleFunc("GET /api/admin/banned-ips", web.HandleAdminBannedIPs)
	mux.HandleFunc("POST /api/admin/banned-ips/{ip}/unban", web.HandleAdminUnbanIP)
	mux.HandleFunc("GET /api/admin/audit-logs", web.HandleAdminAuditLogs)
	mux.HandleFunc("GET /api/admin/login-logs", web.HandleAdminLoginLogs)
	mux.HandleFunc("POST /api/more/sync", web.HandleMoreSync)
	mux.HandleFunc("GET /api/admin/syscfg", web.HandleAdminSyscfg)
	mux.HandleFunc("POST /api/admin/syscfg", web.HandleAdminSyscfgSave)
	mux.HandleFunc("GET /api/admin/syscfg/skyriver-balance", web.HandleAdminSkyriverBalance)

	// ---- 前端静态页 + 页面分发（static/ 为可编辑源文件）----
	// 与 daili-go 不同：不再 embed，直接读磁盘；页面按路径分发，
	// 未知路径回退 index.html（SPA 兜底）。文件带 hash 的构建产物
	// 不存在了——所有静态资源统一 no-cache，改完刷新即见。
	// no-cache（不带 no-store）：浏览器可缓存静态资源，每次用 If-Modified-Since 协商，
	// 未变更返回 304（几十字节），变更则 200 拿新文件——改完刷新即生效的体验不变，
	// 但常规导航不再整包重下 200KB+ 资源。
	const noCache = "no-cache"
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		p := strings.TrimPrefix(r.URL.Path, "/")

		// 未注册的 /api 路径返回 JSON 404
		if p == "api" || strings.HasPrefix(p, "api/") {
			w.Header().Set("Content-Type", "application/json; charset=utf-8")
			w.WriteHeader(404)
			w.Write([]byte(`{"detail":"Not Found"}`))
			return
		}

		switch {
		case p == "admin" || p == "admin/":
			servePage(w, r, "admin.html", noCache)
		case p == "agent" || p == "agent/":
			servePage(w, r, "agent.html", noCache)
		case p == "qd" || p == "qd/":
			servePage(w, r, "qd.html", noCache)
		case strings.HasPrefix(p, "pay/"): // /pay/订单号 结果页
			servePage(w, r, "pay.html", noCache)
		case p == "" || p == "index.html":
			servePage(w, r, "index.html", noCache)
		default:
			// 存在的静态文件（logo.png/assets/*/static/* 等）直接发
			if serveFileIfExists(w, r, p, noCache) {
				return
			}
			// SPA 兜底
			servePage(w, r, "index.html", noCache)
		}
	})

	handler := logger.RequestLog(web.CSRFMiddleware(web.GzipMiddleware(securityHeaders(mux))))

	addr := ":8000"
	if a := os.Getenv("ADDR"); a != "" {
		addr = a
	}
	printStartupInfo(addr)

	// 生产加固：读头超时防 Slowloris；SSE 是长连接所以 WriteTimeout 不设
	server := &http.Server{
		Addr:              addr,
		Handler:           handler,
		ReadHeaderTimeout: 15 * time.Second,
		IdleTimeout:       120 * time.Second,
	}

	go func() {
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Errorf("server exited: %v", err)
			os.Exit(1)
		}
	}()

	// 优雅关停：Ctrl+C / SIGTERM → 停止收新请求（最多等 8s）→ 中断任务即时标记
	quit := make(chan os.Signal, 1)
	signal.Notify(quit, os.Interrupt, syscall.SIGTERM)
	<-quit
	logger.Infof("shutting down...")
	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()
	if err := server.Shutdown(ctx); err != nil {
		logger.Warnf("shutdown timeout, forcing exit")
	}
	tasks.M.InterruptAll()
	store.Backup(filepath.Join(root, "backups")) // 退出前兜底备份一份
	store.PruneBackups(filepath.Join(root, "backups"), 14)
	logger.Infof("bye")
}

// servePage 直接发送 static/ 下的 html 页面。
func servePage(w http.ResponseWriter, r *http.Request, name, cacheControl string) {
	w.Header().Set("Cache-Control", cacheControl)
	http.ServeFile(w, r, filepath.Join(staticDir, name))
}

// serveFileIfExists 若文件存在于 static/ 下则带 no-cache 发送；否则 false。
// 路径先 Join 归一化再校验前缀，杜绝目录穿越。
func serveFileIfExists(w http.ResponseWriter, r *http.Request, p, cacheControl string) bool {
	if p == "" || strings.Contains(p, `\`) || strings.Contains(p, "..") {
		return false
	}
	clean := filepath.Clean(filepath.FromSlash(p))
	full := filepath.Join(staticDir, clean)
	rel, err := filepath.Rel(staticDir, full)
	if err != nil || strings.HasPrefix(rel, ".."+string(filepath.Separator)) || rel == ".." {
		return false
	}
	fi, err := os.Stat(full)
	if err != nil || fi.IsDir() {
		return false
	}
	w.Header().Set("Cache-Control", cacheControl)
	http.ServeFile(w, r, full)
	return true
}

// securityHeaders 全局安全响应头（对齐 daili-go / Python main.py）。
func securityHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h := w.Header()
		h.Set("X-Content-Type-Options", "nosniff")
		h.Set("X-Frame-Options", "DENY")
		h.Set("Referrer-Policy", "no-referrer")
		h.Set("Strict-Transport-Security", "max-age=31536000; includeSubDomains")
		h.Set("Content-Security-Policy",
			"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "+
				"img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'")
		h.Set("X-XSS-Protection", "0")
		if strings.HasPrefix(r.URL.Path, "/api/tasks") || strings.HasPrefix(r.URL.Path, "/api/bing/") {
			h.Set("Cache-Control", "no-store") // 图片 handler 会覆盖为 private, max-age
		}
		next.ServeHTTP(w, r)
	})
}

func rootDir() string {
	exe, err := os.Executable()
	if err == nil {
		dir := filepath.Dir(exe)
		if _, err := os.Stat(filepath.Join(dir, ".env")); err == nil {
			return dir
		}
	}
	wd, _ := os.Getwd()
	return wd
}

var _ = auth.AgentCookie

func printStartupInfo(addr string) {
	fmt.Printf("[wk_dl] listening %s | db=%s | workers %d | static %s\n", addr, store.Driver, config.FetchWorkers, staticDir)
	fmt.Printf("[wk_dl] sync categories=%v price_mult=%.3f every %dh\n",
		config.SyncCategories, config.SyncPriceMult, config.SyncIntervalHours)
	if bingli.Enabled() {
		fmt.Printf("[wk_dl] bingli enabled | upstream=%s | price_mult=%.2f\n",
			config.BingAPIURL, config.BingPriceMult)
	} else {
		fmt.Printf("[wk_dl] bingli disabled (BING_USER_EMAIL/BING_API_KEY 未配置)\n")
	}
}
