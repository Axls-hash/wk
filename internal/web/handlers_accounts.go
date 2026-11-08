package web

import (
	"encoding/json"
	"fmt"
	"net/http"
	"sort"
	"strings"
	"time"

	"daili-go/internal/bingli"
	"daili-go/internal/config"
	"daili-go/internal/fetchers"
	"daili-go/internal/logger"
	"daili-go/internal/productcache"
	"daili-go/internal/qd"
	"daili-go/internal/ratelimit"
	"daili-go/internal/store"
	appsync "daili-go/internal/sync"
	"daili-go/internal/tasks"
)

// 查课相关：平台列表 / 提交账号 / 任务轮询 / SSE / 公开配置。
// 登录制：查课必须登录，任务只对主人可见（对齐 Python accounts.py）。

var (
	submitLimiter       = ratelimit.New(30, 60)
	globalSubmitLimiter = ratelimit.New(60, 60)
	// 自动防滥：24h 大窗口限流，超限仅返回 429、窗口滚动后自然恢复。
	// 绝不把共享出口 IP（NAT 多人共用）误打成永久封禁；
	// 永久黑名单只允许管理员在后台手动写入。
	autoBanDaily = ratelimit.New(1500, 24*3600)
)

// HandlePlatforms GET /api/platforms
func HandlePlatforms(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, 200, fetchers.Platforms)
}

// HandleMorePlatforms GET /api/more-platforms —— 综合网课平台模块。
//
// 按库里已上架商品的 fenlei 动态生成；名称优先用同步时保存的上游 fenleiname
//（如「龙龙项目」「嫩妞项目」），其次静态目录旧名，最后才回退「分类N」。
func HandleMorePlatforms(w http.ResponseWriter, r *http.Request) {
	nameByCat := appsync.LoadFenleiNames()
	hidden := appsync.LoadHiddenFenlei()
	order := make([]string, 0, len(fetchers.MorePlatforms))
	seenOrder := map[string]bool{}
	for _, e := range fetchers.MorePlatforms {
		if !seenOrder[e.Category] {
			order = append(order, e.Category)
			seenOrder[e.Category] = true
		}
		// 静态目录名作兜底；上游 fenleiname 已存在则不覆盖
		if _, ok := nameByCat[e.Category]; !ok {
			nameByCat[e.Category] = e.Name
		}
	}

	rows, err := store.DB.Query(
		`SELECT fenlei, COUNT(*) FROM more_products
		 WHERE status=1 AND price_yuan>0
		 GROUP BY fenlei HAVING COUNT(*)>0`)
	if err != nil {
		errJSON(w, 500, "加载平台失败")
		return
	}
	defer rows.Close()

	type catRow struct {
		fenlei string
		count  int64
	}
	have := map[string]int64{}
	extras := make([]catRow, 0)
	for rows.Next() {
		var fenlei string
		var n int64
		if rows.Scan(&fenlei, &n) != nil || fenlei == "" || n <= 0 {
			continue
		}
		if hidden[fenlei] {
			continue // 管理员已删除的分类不进前台
		}
		have[fenlei] = n
		if !seenOrder[fenlei] {
			extras = append(extras, catRow{fenlei: fenlei, count: n})
		}
	}

	platformName := func(cat string) string {
		if n := strings.TrimSpace(nameByCat[cat]); n != "" {
			return n
		}
		return "分类" + cat
	}

	items := make([]map[string]interface{}, 0, len(have))
	for _, cat := range order {
		if n, ok := have[cat]; ok {
			items = append(items, map[string]interface{}{
				"name": platformName(cat), "category": cat, "count": n,
			})
		}
	}
	sort.Slice(extras, func(i, j int) bool { return extras[i].count > extras[j].count })
	for _, e := range extras {
		items = append(items, map[string]interface{}{
			"name": platformName(e.fenlei), "category": e.fenlei, "count": e.count,
		})
	}
	writeJSON(w, 200, items)
}

// HandleMoreProducts GET /api/more-products?fenlei=8 —— 冷门商品列表（从天河同步，status=1）。
// note 截到 500 字符：既保证商品说明基本完整（用户端卡片/平台说明直接展示），
// 又给全量/单分类列表的响应体设一个上界，避免 content 超长拖垮请求。
func HandleMoreProducts(w http.ResponseWriter, r *http.Request) {
	fenlei := strings.TrimSpace(r.URL.Query().Get("fenlei"))
	items, err := productcache.Items(fenlei)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, items)
}

// HandleConfig GET /api/config —— 默认价 + 各平台独立价（未设置的由前端回退默认价）。
func HandleConfig(w http.ResponseWriter, r *http.Request) {
	prices, _ := store.AllPlatformPrices()
	if prices == nil {
		prices = map[string]int64{}
	}
	writeJSON(w, 200, map[string]interface{}{
		"course_price_fen": config.CoursePriceFen,
		"platform_prices":  prices,
		// 病例入口是否展示给用户（上游已配置 且 管理端开关开启）
		"bing_enabled": bingli.Enabled(),
		// 学习通自动签到入口是否展示给用户（引擎已配置 且 管理端开关开启）
		"qd_enabled": qd.Enabled(),
	})
}

type accountSubmit struct {
	Platform string `json:"platform"`
	Username string `json:"username"`
	Password string `json:"password"`
	School   string `json:"school"`
}

// HandleSubmitAccount POST /api/accounts
func HandleSubmitAccount(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	var data accountSubmit
	if !readJSON(w, r, &data) {
		return
	}
	if !fetchers.IsPlatform(data.Platform) {
		errJSON(w, 400, fmt.Sprintf("不支持的平台：%s", data.Platform))
		return
	}
	if data.Username == "" || data.Password == "" {
		errJSON(w, 400, "账号和密码不能为空")
		return
	}

	ip := clientIP(r)
	if ipBanned(ip) {
		errJSON(w, 403, "该 IP 已被永久封禁，禁止查课")
		return
	}
	if !submitLimiter.Allow("accounts:" + ip) {
		errJSON(w, 429, "提交过于频繁，请稍后再试")
		return
	}
	if !globalSubmitLimiter.Allow("global") {
		errJSON(w, 429, "服务繁忙，请稍后再试")
		return
	}
	// 24h 大窗口兜底：NAT 多人共享出口 IP 只会被 429 顶一天，不会误进永久黑名单
	if !autoBanDaily.Allow("daily:" + ip) {
		errJSON(w, 429, "操作过于频繁，该 IP 今日查询已超限，请明天再试")
		return
	}

	// 账号互斥 key = 「平台:账号」。刻意跨用户共享互斥（tasks 单测 TestAccountMutex 固化此语义）：
	// 同一平台账号（含共享/演示账号）同时只放行一个抓取任务，避免同一套凭证同时多路打上游被限流；
	// 普通用户各自查自己的学号账号互不影响。此处不要改成带 user_id 的 key——那会破坏该防线。
	key := data.Platform + ":" + data.Username
	if len(key) > 255 {
		key = truncateStr(key, 200) + shortHash(key)
	}

	taskID, err := tasks.M.Submit(data.Platform, data.Username, data.Password, data.School, key, u.ID)
	if err != nil {
		if err == tasks.ErrAlreadyRunning {
			errJSON(w, 409, "该账号正在抓取中，请稍后再试")
			return
		}
		logger.Err(err, "task submit failed", "user_id", u.ID)
		errJSON(w, 500, "提交失败，请稍后再试")
		return
	}
	writeJSON(w, 201, map[string]string{"task_id": taskID})
}

// HandleGetTask GET /api/tasks/{id} —— 仅任务主人可见。
func HandleGetTask(w http.ResponseWriter, r *http.Request) {
	taskID := r.PathValue("task_id")
	task := tasks.M.Get(taskID)
	if task == nil {
		errJSON(w, 404, "任务不存在或已过期")
		return
	}
	if !taskOwnerOK(task, r) {
		errJSON(w, 404, "任务不存在或已过期")
		return
	}
	writeJSON(w, 200, taskResultPayload(task))
}

func taskOwnerOK(task *tasks.Snapshot, r *http.Request) bool {
	u := currentAgent(r)
	return u != nil && u.ID == task.UserID
}

func taskResultPayload(task *tasks.Snapshot) map[string]interface{} {
	return map[string]interface{}{
		"task_id":  task.ID,
		"status":   task.Status,
		"platform": task.Platform,
		"courses":  coursesOut(task.Courses),
		"error":    task.Error,
	}
}

// coursesOut 宽松转换抓取结果为前端安全结构（对齐 Python _scraped_out）。
func coursesOut(raw []map[string]interface{}) []map[string]interface{} {
	out := make([]map[string]interface{}, 0, len(raw))
	for _, c := range raw {
		if c == nil {
			continue
		}
		out = append(out, map[string]interface{}{
			"title":           cutStr(fmtAny(c["title"]), 200),
			"link":            cutStr(fmtAny(c["link"]), 500),
			"teacher":         cutStr(fmtAny(c["teacher"]), 100),
			"school":          cutStr(fmtAny(c["school"]), 100),
			"total_hours":     toF64(c["total_hours"]),
			"completed_hours": toF64(c["completed_hours"]),
			"status":          orDefault(cutStr(fmtAny(c["status"]), 20), "in_progress"),
			"deadline":        cutStr(fmtAny(c["deadline"]), 20),
			"progress":        progressOf(c),
			"kcid":            cutStr(fmtAny(c["kcid"]), 64),
		})
	}
	return out
}

func progressOf(c map[string]interface{}) float64 {
	total := toF64(c["total_hours"])
	done := toF64(c["completed_hours"])
	if total > 0 {
		p := done / total * 100
		if p > 100 {
			p = 100
		}
		return round1(p)
	}
	p := parsePercentLoose(c["progress"])
	return round1(p)
}

func parsePercentLoose(v interface{}) float64 {
	switch n := v.(type) {
	case nil:
		return 0
	case float64:
		return n
	case json.Number:
		f, _ := n.Float64()
		return f
	default:
		s := strings.TrimRight(strings.TrimSpace(fmt.Sprintf("%v", v)), "%")
		var f float64
		fmt.Sscanf(s, "%g", &f)
		return f
	}
}

func round1(f float64) float64 { return float64(int(f*10+0.5)) / 10 }

// HandleTaskEvents GET /api/tasks/{id}/events —— SSE 推送终态。
func HandleTaskEvents(w http.ResponseWriter, r *http.Request) {
	taskID := r.PathValue("task_id")

	// EventSource 自动携带 cookie；先做归属校验
	ownerOK := false
	first := tasks.M.Get(taskID)
	if first != nil && taskOwnerOK(first, r) {
		ownerOK = true
	}

	flusher, canFlush := w.(http.Flusher)
	if !canFlush {
		errJSON(w, 500, "streaming unsupported")
		return
	}
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache, no-store, must-revalidate")
	w.Header().Set("X-Accel-Buffering", "no")
	w.Header().Set("Connection", "keep-alive")

	sse := func(event string, data interface{}) {
		raw, _ := json.Marshal(data)
		fmt.Fprintf(w, "event: %s\ndata: %s\n\n", event, string(raw))
		flusher.Flush()
	}

	gen := func() {
		defer func() { recover() }()
		task := tasks.M.Get(taskID)
		if !ownerOK || task == nil {
			sse("result", map[string]interface{}{"status": "error", "error": "任务不存在或已过期"})
			return
		}
		if task.Status == "done" || task.Status == "error" {
			sse("result", taskResultPayload(task))
			return
		}
		deadline := time.Now().Add(60 * time.Second)
		for {
			select {
			case <-r.Context().Done():
				return
			case <-time.After(time.Second):
			}
			task = tasks.M.Get(taskID)
			if task == nil {
				sse("result", map[string]interface{}{"status": "error", "error": "任务不存在或已过期"})
				return
			}
			if task.Status == "done" || task.Status == "error" {
				sse("result", taskResultPayload(task))
				return
			}
			if time.Now().After(deadline) {
				sse("timeout", map[string]interface{}{})
				return
			}
		}
	}
	gen()
}

// ---------------- 工具 ----------------

func banIP(ip, reason string) {
	_, _ = store.DB.Exec(`INSERT INTO banned_ips(ip, banned_at, reason) VALUES(?,?,?)`,
		ip, nowFloat(), reason)
	audit("ip.ban", fmt.Sprintf(`{"ip":%q,"reason":%q}`, ip, reason))
}

// HandleAdminBannedIPs GET /api/admin/banned-ips —— 自动/手动封禁列表（可解封）。
func HandleAdminBannedIPs(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	page := clamp(queryInt(r, "page", 1), 1, 1<<30)
	pageSize := clamp(queryInt(r, "page_size", 50), 1, 200)
	var total int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM banned_ips`).Scan(&total)
	rows, err := store.DB.Query(
		`SELECT ip, banned_at, reason FROM banned_ips ORDER BY banned_at DESC LIMIT ? OFFSET ?`,
		pageSize, (page-1)*pageSize)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()
	items := []map[string]interface{}{}
	for rows.Next() {
		var ip, reason string
		var at float64
		if rows.Scan(&ip, &at, &reason) == nil {
			items = append(items, map[string]interface{}{"ip": ip, "banned_at": at, "reason": reason})
		}
	}
	writeJSON(w, 200, map[string]interface{}{
		"total": total, "page": page, "page_size": pageSize, "items": items,
	})
}

// HandleAdminUnbanIP POST /api/admin/banned-ips/{ip}/unban
func HandleAdminUnbanIP(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	ip := strings.TrimSpace(r.PathValue("ip"))
	if ip == "" {
		errJSON(w, 400, "参数缺失")
		return
	}
	res, err := store.DB.Exec(`DELETE FROM banned_ips WHERE ip=?`, ip)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	if n, _ := res.RowsAffected(); n == 0 {
		errJSON(w, 404, "该 IP 不在封禁列表中")
		return
	}
	audit("ip.unban", fmt.Sprintf(`{"ip":%q}`, ip))
	writeJSON(w, 200, map[string]bool{"ok": true})
}

func shortHash(s string) string {
	// fnv64 hex 只有 16 字符；旧实现 [:32] 在超长 account_key 时 panic。
	// 补齐到 32 字符，保持列宽 200+32=232 < 255 的压缩语义不变。
	h := fmt.Sprintf("%x", fnv1a(s))
	for len(h) < 32 {
		h += "0"
	}
	return h[:32]
}

func fnv1a(s string) uint64 {
	var h uint64 = 14695981039346656037
	for i := 0; i < len(s); i++ {
		h ^= uint64(s[i])
		h *= 1099511628211
	}
	return h
}

func fmtAny(v interface{}) string {
	if v == nil {
		return ""
	}
	switch t := v.(type) {
	case string:
		return t
	case float64:
		return trimFloatDot(t)
	default:
		return fmt.Sprintf("%v", v)
	}
}

func trimFloatDot(f float64) string {
	s := fmt.Sprintf("%g", f)
	return s
}

func cutStr(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n])
}

func toF64(v interface{}) float64 {
	switch n := v.(type) {
	case nil:
		return 0
	case float64:
		return n
	case json.Number:
		f, _ := n.Float64()
		return f
	default:
		var f float64
		fmt.Sscanf(strings.TrimSpace(fmt.Sprintf("%v", v)), "%g", &f)
		return f
	}
}

func orDefault(s, def string) string {
	if s == "" {
		return def
	}
	return s
}
