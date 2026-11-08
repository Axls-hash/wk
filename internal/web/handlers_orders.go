package web

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"daili-go/internal/config"
	"daili-go/internal/epay"
	"daili-go/internal/fetchers"
	"daili-go/internal/ledger"
	"daili-go/internal/logger"
	"daili-go/internal/ratelimit"
	"daili-go/internal/store"
)

// 支付订单：登录用户积分下单（强制）+ 存量易支付回调兼容。
// 对齐 Python routers/orders.py 当前行为。

var (
	orderLimiter       = ratelimit.New(30, 60)
	orderGlobalLimiter = ratelimit.New(60, 60)
)

type scrapedCourseIn struct {
	Title          string      `json:"title"`
	Link           string      `json:"link"`
	Teacher        string      `json:"teacher"`
	School         string      `json:"school"`
	TotalHours     interface{} `json:"total_hours"`
	CompletedHours interface{} `json:"completed_hours"`
	Status         string      `json:"status"`
	Deadline       string      `json:"deadline"`
	Progress       interface{} `json:"progress"`
	Kcid           string      `json:"kcid"`
}

type orderCreateBody struct {
	Platform  string            `json:"platform"`
	Username  string            `json:"username"`
	Password  string            `json:"password"`
	School    string            `json:"school"`
	Courses   []scrapedCourseIn `json:"courses"`
	Remark    string            `json:"remark"`
	PayMethod string            `json:"pay_method"` // 兼容字段：本版本仅支持积分，非 "points" 一律拒绝
	// ExpectedPriceFen 前端展示的单价（分）。传了就必须与后端实时价一致，
	// 否则拒绝下单（409），避免价格在浏览/缓存期间变化后“看到的价格 ≠ 实际扣费”。
	// 旧版前端不传（nil）时保持原有行为。
	ExpectedPriceFen *int64 `json:"expected_price_fen"`
}

func newOrderID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// HandleCreateOrder POST /api/orders —— 登录 + 纯积分支付，建单+扣款同事务。
func HandleCreateOrder(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	var data orderCreateBody
	if !readJSON(w, r, &data) {
		return
	}
	if !isPlatformName(data.Platform) {
		errJSON(w, 400, fmt.Sprintf("不支持的平台：%s", data.Platform))
		return
	}
	// pay_method 是兼容字段：本版本只支持积分支付。客户端传其它方式会误导
	// （以为走 epay 实际被静默扣积分），这里显式拒绝，宁可报错也不静默处理。
	if data.PayMethod != "" && data.PayMethod != "points" {
		errJSON(w, 400, "仅支持积分（points）支付")
		return
	}
	if data.Username == "" || data.Password == "" {
		errJSON(w, 400, "账号和密码不能为空")
		return
	}
	if len(data.Courses) == 0 {
		errJSON(w, 400, "请先勾选至少一门课程")
		return
	}
	if len(data.Courses) > 500 {
		err422(w)
		return
	}

	ip := clientIP(r)
	if ipBanned(ip) {
		errJSON(w, 403, "该 IP 已被永久封禁，禁止下单")
		return
	}
	if !orderLimiter.Allow("orders:" + ip) {
		errJSON(w, 429, "提交过于频繁，请稍后再试")
		return
	}
	if !orderGlobalLimiter.Allow("orders-global") {
		errJSON(w, 429, "服务繁忙，请稍后再试")
		return
	}

	orderID := newOrderID()
	// 计价（三种情况，永不静默回退，避免显示与扣款不一致 / 把天价商品按默认价卖）：
	//   1) 冷门同步商品 → 用 more_products 里的入库价（= 天河原价 × SKYRIVER_SYNC_PRICE_MULT，同步时已算好）
	//   2) 静态平台（主流 / 冷门目录）→ 平台价 / 默认价
	//   3) 其它（同步商品已下架或缺失）→ 直接拒绝下单
	priceFen := int64(0)
	if fp, ok := moreProductPriceFen(data.Platform); ok {
		priceFen = fp
	} else if isStaticPlatform(data.Platform) {
		priceFen = store.PriceForPlatform(data.Platform, int64(config.CoursePriceFen))
	} else {
		errJSON(w, 400, "该商品已下架或不可下单，请重新选择")
		return
	}
	// 前端展示价与实时价不一致（管理员改价/同步更新/页面停留过久）：
	// 在扣积分之前拒绝，让用户刷新确认新价格，绝不静默按新价扣款。
	if data.ExpectedPriceFen != nil && *data.ExpectedPriceFen != priceFen {
		errJSON(w, 409, fmt.Sprintf("商品价格已更新（当前 %s 元/门），请刷新后重新确认", epay.YuanStr(priceFen)))
		return
	}
	amountFen := priceFen * int64(len(data.Courses))
	coursesJSON := marshalCourses(data.Courses)

	school := data.School
	if school == "" {
		school = data.Platform // 缺省用平台名兜底（与查课一致）
	}
	newBalance, err := ledger.PayOrderWithPoints(
		orderID, data.Platform,
		truncateStr(data.Username, 255), truncateStr(data.Password, 255), truncateStr(school, 255),
		coursesJSON, cutStr(strings.TrimSpace(data.Remark), 500),
		int64(len(data.Courses)), amountFen, u.ID)
	if err != nil {
		if strings.Contains(err.Error(), "积分不足") {
			errJSON(w, 400, err.Error())
			return
		}
		logger.Err(err, "create order failed", "agent_id", u.ID)
		errJSON(w, 500, "下单失败，请稍后再试")
		return
	}
	_ = newBalance

	// ---- 扣积分已成功，自动把所选课程逐门下到天河（act=add）----
	// 后台异步：积分扣完即返回，避免 N 次天和串行 HTTP 卡住请求/超时/重复点击。
	// process_status 已在 ledger 建单事务内标为 'processing'（防丢单窗口）；
	// goroutine 稍后更新 upstream + 最终 process_status（全成→processing，有失败→pending 留人工）。
	go func() {
		defer func() { recover() }() // 后台下单 panic 不能打崩进程
		_, _ = submitCoursesToSkyriver(orderID, data, school)
	}()

	writeJSON(w, 201, map[string]interface{}{
		"order_id": orderID,
		"amount":   epay.YuanStr(amountFen),
		"pay_url":  "",
	})
}

// skyriverUpstreamItem 天和下 single单门结果记录（存 orders.upstream）。
type skyriverUpstreamItem struct {
	Title string `json:"title"`
	Kcid  string `json:"kcid"`
	Yid   string `json:"yid,omitempty"`
	Error string `json:"error,omitempty"`
}

// submitSem 全局并发闸：下单后异步提交上游的 goroutine 上限 8。
// 爆单时不再无上限集火上游——超出的单在信号量处排队（后台执行，不阻塞下单响应）；
// 每次占位最久 500 门课×20s 超时也会自然释放，不会死等。
var submitSem = make(chan struct{}, 8)

// submitCoursesToSkyriver 把订单所选课程逐门下到天河（act=add），写回 order.upstream，
// 并根据是否有失败设置 process_status：全成='processing'，有失败='pending'（留人工，积分不退）。
func submitCoursesToSkyriver(orderID string, data orderCreateBody, school string) (string, string) {
	submitSem <- struct{}{}
	defer func() { <-submitSem }()
	items := make([]skyriverUpstreamItem, 0, len(data.Courses))
	failCount := 0
	// 用户备注透传上游（时长/分数要求）；trim 口径与 orders.remark 存库一致
	remark := strings.TrimSpace(data.Remark)
	for _, c := range data.Courses {
		yid, err := fetchers.SkyriverAddOrder(data.Platform, school, data.Username, data.Password, c.Title, c.Kcid, remark)
		if err != nil {
			failCount++
			items = append(items, skyriverUpstreamItem{Title: c.Title, Kcid: c.Kcid, Error: err.Error()})
		} else {
			items = append(items, skyriverUpstreamItem{Title: c.Title, Kcid: c.Kcid, Yid: yid})
		}
	}
	raw, _ := jsonMarshal(items)
	failMsg := ""
	ps := "processing"
	if failCount > 0 {
		ps = "pending"
		failMsg = fmt.Sprintf("部分课程提交失败（%d/%d 门），需人工处理", failCount, len(items))
		logger.Warnf("skyriver add partial fail: order=%s failed=%d total=%d", orderID, failCount, len(items))
	}
	if _, err := store.DB.Exec(`UPDATE orders SET upstream=?, process_status=? WHERE order_id=?`, string(raw), ps, orderID); err != nil {
		return string(raw), "记录上游结果失败"
	}
	return string(raw), failMsg
}

func marshalCourses(courses []scrapedCourseIn) string {
	type outCourse struct {
		Title          string  `json:"title"`
		Link           string  `json:"link"`
		Teacher        string  `json:"teacher"`
		School         string  `json:"school"`
		TotalHours     float64 `json:"total_hours"`
		CompletedHours float64 `json:"completed_hours"`
		Status         string  `json:"status"`
		Deadline       string  `json:"deadline"`
		Progress       float64 `json:"progress"`
		Kcid           string  `json:"kcid"`
	}
	out := make([]outCourse, 0, len(courses))
	for _, c := range courses {
		status := c.Status
		if status == "" || len(status) > 20 {
			status = "in_progress"
		}
		kcid := cutStr(c.Kcid, 64) // rune 截断，避免切断多字节字符
		out = append(out, outCourse{
			Title:          cutStr(c.Title, 200),
			Link:           cutStr(c.Link, 500),
			Teacher:        cutStr(c.Teacher, 100),
			School:         cutStr(c.School, 100),
			TotalHours:     toF64(c.TotalHours),
			CompletedHours: toF64(c.CompletedHours),
			Status:         status,
			Deadline:       cutStr(c.Deadline, 20),
			Progress:       parsePercentLoose(c.Progress),
			Kcid:           kcid,
		})
	}
	raw, _ := jsonMarshal(out)
	return string(raw)
}

// HandleGetOrder GET /api/orders/{id} —— 登录且仅限订单主人（纵深防御：
// 订单号虽不可枚举，也不应作为唯一凭证；历史游客单 agent_id=0 一律 404）。
func HandleGetOrder(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	orderID := r.PathValue("order_id")
	var status string
	var amountFen, ownerID int64
	err := store.DB.QueryRow(
		`SELECT status, amount_fen, agent_id FROM orders WHERE order_id=?`, orderID).
		Scan(&status, &amountFen, &ownerID)
	if err != nil || ownerID != u.ID { // 不区分「不存在」与「非本人」，统一 404
		errJSON(w, 404, "订单不存在")
		return
	}
	writeJSON(w, 200, map[string]interface{}{
		"order_id": orderID,
		"status":   status,
		"amount":   epay.YuanStr(amountFen),
	})
}

// HandleEpayNotify GET /pay/epay/notify —— 存量在线支付回调兼容。
func HandleEpayNotify(w http.ResponseWriter, r *http.Request) {
	if !config.PayEnabled || config.EpayKey == "" {
		w.Write([]byte("fail"))
		return
	}
	q := map[string]string{}
	for k, v := range r.URL.Query() {
		if len(v) > 0 {
			q[k] = v[0]
		}
	}
	if !epay.VerifySign(q, config.EpayKey) {
		w.Write([]byte("sign error"))
		return
	}
	if q["trade_status"] != "TRADE_SUCCESS" {
		w.Write([]byte("success"))
		return
	}
	orderID := q["out_trade_no"]
	moneyFen, okMoney := epay.MoneyToFen(q["money"])
	tradeNo := cutStr(q["trade_no"], 64)
	payType := cutStr(q["type"], 16)

	var rowAmount int64
	var status string
	err := store.DB.QueryRow(`SELECT amount_fen, status FROM orders WHERE order_id=?`, orderID).
		Scan(&rowAmount, &status)
	if err == sql.ErrNoRows {
		w.Write([]byte("order not found"))
		return
	} else if err != nil {
		w.Write([]byte("error"))
		return
	}
	if !okMoney || moneyFen != rowAmount {
		w.Write([]byte("money mismatch"))
		return
	}
	if status == "paid" {
		w.Write([]byte("success")) // 幂等
		return
	}
	_, _ = store.DB.Exec(
		`UPDATE orders SET status='paid', trade_no=?, pay_type=?, paid_at=? WHERE order_id=? AND status='pending'`,
		tradeNo, payType, nowFloat(), orderID)
	w.Write([]byte("success"))
}

// HandleEpayReturn GET /pay/epay/return → 302 前端结果页。
func HandleEpayReturn(w http.ResponseWriter, r *http.Request) {
	orderID := r.URL.Query().Get("out_trade_no")
	if orderID != "" {
		var n int
		_ = store.DB.QueryRow(`SELECT count(*) FROM orders WHERE order_id=?`, orderID).Scan(&n)
		if n > 0 {
			http.Redirect(w, r, "/pay/"+orderID, 302)
			return
		}
	}
	http.Redirect(w, r, "/", 302)
}

// isPlatformName 平台名校验（主流 + 冷门）。
func isPlatformName(p string) bool {
	return fetchers.IsPlatform(p)
}

// isStaticPlatform 判断是否静态目录平台（主流 8 平台或冷门目录里的平台模块，
// 但排除从天河同步出的商品项目——那些必须走 more_products 价格）。
func isStaticPlatform(p string) bool {
	for _, name := range platformNames {
		if name == p {
			return true
		}
	}
	for _, e := range fetchers.MorePlatforms {
		if e.Name == p {
			return true
		}
	}
	return false
}

// moreProductPriceFen 冷门同步商品的单价（分）；未找到或价格异常（<=0 分）返回 false。
// 取价口径：优先按商品目录里该名字对应的天河 cid 精确取——不同分类下可能存在
// 同名商品（cid 不同、价格不同），按 name 查会取到任意一行的错价；
// 目录未覆盖（如重启后同步失败、目录为空）才回退按名取并固定 ORDER BY cid 保证确定性。
// 价格兜底校验：上游价格缺失/为 0 同步进来的商品必须拒绝下单——否则 amountFen=0
// 会变成 0 积分建单，而课程仍会真实提交到天河，造成无进价销售的成本损失。
func moreProductPriceFen(name string) (int64, bool) {
	if entry, ok := fetchers.ProductEntry(name); ok {
		if cid, perr := strconv.ParseInt(entry.Noun, 10, 64); perr == nil {
			var yuan float64
			if err := store.DB.QueryRow(
				`SELECT price_yuan FROM more_products WHERE cid=? AND status=1`, cid).Scan(&yuan); err == nil {
				if fen := int64(yuan*100 + 0.5); fen > 0 {
					return fen, true
				}
				return 0, false // 目录有但已下架/价格异常：拒绝下单，不回退按名取（会错卖别的 cid）
			}
			return 0, false
		}
	}
	var yuan float64
	err := store.DB.QueryRow(
		`SELECT price_yuan FROM more_products WHERE name=? AND status=1 ORDER BY cid LIMIT 1`, name).Scan(&yuan)
	if err != nil {
		return 0, false
	}
	fen := int64(yuan*100 + 0.5)
	if fen <= 0 {
		return 0, false
	}
	return fen, true
}
