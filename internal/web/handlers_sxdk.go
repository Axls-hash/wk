package web

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"math"
	"net/http"
	"strings"
	"time"

	"daili-go/internal/config"
	"daili-go/internal/ledger"
	"daili-go/internal/logger"
	"daili-go/internal/ratelimit"
	"daili-go/internal/store"
	"daili-go/internal/sxdk"
)

// 实习打卡（sxdk）HTTP 接口 —— 完全独立于课程/综合网课，不影响既有模块。

// sxdk 上游接口限流：search-phone 直打上游验证账号、sync 全量拉上游订单。
// 不设限会被脚本当「上游爆破代理」滥用——高频消耗上游、暴露本站对接 IP，
// 严重时导致对接账号被上游封禁。IP 维度 + 短窗口，正常用户无感。
var (
	sxdkSearchLimiter = ratelimit.New(10, 60) // 每 IP 每分钟 10 次账号验证
	sxdkSearchGlobal  = ratelimit.New(30, 60) // 全站每分钟 30 次，保护上游
	sxdkSyncLimiter   = ratelimit.New(6, 60)  // 每 IP 每分钟 6 次进度同步
)

// newSxdkID 生成 32 位订单号。
func newSxdkOrderID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// sxdkOrderJSON 订单表的 JSON 视图（用 *bool 表达可选字段，统一前端结构）。
type sxdkOrderOut struct {
	OrderID      string  `json:"order_id"`
	Platform     string  `json:"platform"`
	PlatformName string  `json:"platform_name"`
	Phone        string  `json:"phone"`
	Days         int     `json:"days"`
	AmountFen    int64   `json:"amount_fen"`
	Status       string  `json:"status"`
	Code         int     `json:"code"`
	TianheID     int64   `json:"tianhe_id"`
	Name         string  `json:"name"`
	CheckWeek    string  `json:"check_week"`
	EndTime      string  `json:"end_time"`
	Remark       string  `json:"remark"`
	CreatedAt    float64 `json:"created_at"`
}

func scanSxdkOrder(rows rowScanner) *sxdkOrderOut {
	var o sxdkOrderOut
	if err := rows.Scan(&o.OrderID, &o.Platform, &o.TianheID, &o.Phone, &o.Name, &o.CheckWeek,
		&o.EndTime, &o.Days, &o.AmountFen, &o.Status, &o.Code, &o.Remark, &o.CreatedAt); err != nil {
		return nil
	}
	o.PlatformName = sxdk.PlatformName(o.Platform)
	return &o
}

// HandleSxdkPlatforms GET /api/sxdk/platforms —— 实习打卡平台列表 + 售价。
func HandleSxdkPlatforms(w http.ResponseWriter, r *http.Request) {
	items := make([]map[string]interface{}, 0, len(sxdk.Platforms))
	for _, p := range sxdk.Platforms {
		items = append(items, map[string]interface{}{
			"code":        p.Code,
			"name":        p.Name,
			"price_yuan":  config.SxdkPriceYuan,
			"price_daily": config.SxdkPriceYuan,
		})
	}
	writeJSON(w, 200, map[string]interface{}{
		"items":      items,
		"price_yuan": config.SxdkPriceYuan, // 统一售价（积分/天）
	})
}

// HandleSxdkSearchPhone POST /api/sxdk/search-phone —— 验证打卡账号并带出姓名/学校等信息。
func HandleSxdkSearchPhone(w http.ResponseWriter, r *http.Request) {
	if _, ok := requireAgent(w, r); !ok {
		return
	}
	ip := clientIP(r)
	if !sxdkSearchLimiter.Allow("sxdk-search:"+ip) || !sxdkSearchGlobal.Allow("sxdk-search-global") {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	var body struct {
		Platform string `json:"platform"`
		Phone    string `json:"phone"`
		Password string `json:"password"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	if !sxdk.ValidPlatform(body.Platform) || body.Phone == "" || body.Password == "" {
		errJSON(w, 400, "参数不完整")
		return
	}
	client := sxdk.NewClient()
	if client == nil {
		errJSON(w, 500, "实习打卡上游未配置")
		return
	}
	data, err := client.SearchPhoneInfo(body.Platform, body.Phone, body.Password)
	if err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	writeJSON(w, 200, data)
}

// sxdkOrderCreateBody 下单入参（与 cop addOrder 字段一致）。
type sxdkOrderCreateBody struct {
	Platform      string `json:"platform"`
	Phone         string `json:"phone"`
	Password      string `json:"password"`
	Name          string `json:"name"`
	Address       string `json:"address"`
	ProjectName   string `json:"project_name"`
	SchoolText    string `json:"school_text"`
	GwName        string `json:"gw_name"`
	Country       string `json:"country"`
	City          string `json:"city"`
	Adcode        string `json:"adcode"`
	CheckWeek     string `json:"check_week"`
	CheckTime     string `json:"check_time"`
	EndTime       string `json:"end_time"`
	UpCheckTime   string `json:"up_check_time"`
	DownCheckTime string `json:"down_check_time"`
	DayPaper      int    `json:"day_paper"`
	WeekPaper     int    `json:"week_paper"`
	MonthPaper    int    `json:"month_paper"`
	RunType       int    `json:"run_type"`
	Holiday       bool   `json:"holiday"`
}

// HandleSxdkCreateOrder POST /api/sxdk/orders —— 建单 + 扣积分，异步提交上游。
func HandleSxdkCreateOrder(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	client := sxdk.NewClient()
	if client == nil {
		errJSON(w, 500, "实习打卡上游未配置")
		return
	}
	var body sxdkOrderCreateBody
	if !readJSON(w, r, &body) {
		return
	}
	body.Platform = strings.TrimSpace(body.Platform)
	body.Phone = strings.TrimSpace(body.Phone)
	body.Password = strings.TrimSpace(body.Password)
	body.CheckWeek = strings.TrimSpace(body.CheckWeek)
	body.EndTime = strings.TrimSpace(body.EndTime)
	if !sxdk.ValidPlatform(body.Platform) {
		errJSON(w, 400, "不支持的平台")
		return
	}
	if body.Phone == "" || body.Password == "" || body.EndTime == "" || body.CheckWeek == "" {
		errJSON(w, 400, "请填写完整：打卡账号、密码、打卡周期、结束日期")
		return
	}
	if _, err := time.Parse("2006-01-02", body.EndTime); err != nil {
		errJSON(w, 400, "结束日期格式应为 YYYY-MM-DD")
		return
	}
	days := sxdk.CalcDays(time.Now(), body.EndTime, body.CheckWeek)
	if days <= 0 {
		errJSON(w, 400, "打卡周期或结束日期不符合规范")
		return
	}
	if body.RunType < 1 || body.RunType > 3 {
		body.RunType = 2 // 对齐 cop 前端 form 默认（账密1号）
	}
	if body.DayPaper == 0 {
		body.DayPaper = 2
	}
	if body.WeekPaper == 0 {
		body.WeekPaper = 2
	}
	if body.MonthPaper == 0 {
		body.MonthPaper = 2
	}
	// 价格倍率：与上游一致，仅 xyb(校友帮)+runType=3(绑微1号) 单价×5
	bei := sxdk.Bei(body.Platform, body.RunType)
	amountFen := int64(math.Round(config.SxdkPriceYuan*100.0)) * int64(days) * int64(bei)

	orderID := newSxdkOrderID()
	// 建单 + 积分扣款同一事务（原子，余额不足/建单失败整体回滚）：
	// 消除「扣款成功、建单失败/进程中断」之间丢单锁积分的窗口。
	if _, err := ledger.PaySxdkOrderWithPoints(ledger.SxdkOrder{
		OrderID:       orderID,
		UserID:        u.ID,
		Platform:      body.Platform,
		Phone:         cutStr(body.Phone, 64),
		Password:      cutStr(body.Password, 128),
		Name:          cutStr(body.Name, 64),
		Address:       cutStr(body.Address, 255),
		ProjectName:   cutStr(body.ProjectName, 64),
		SchoolText:    cutStr(body.SchoolText, 128),
		CheckWeek:     cutStr(body.CheckWeek, 64),
		EndTime:       cutStr(body.EndTime, 32),
		UpCheckTime:   cutStr(body.UpCheckTime, 64),
		DownCheckTime: cutStr(body.DownCheckTime, 64),
		DayPaper:      body.DayPaper,
		WeekPaper:     body.WeekPaper,
		MonthPaper:    body.MonthPaper,
		RunType:       body.RunType,
		Days:          days,
		SellYuan:      config.SxdkPriceYuan,
		AmountFen:     amountFen,
	}); err != nil {
		if strings.Contains(err.Error(), "积分不足") {
			errJSON(w, 400, err.Error())
			return
		}
		logger.Err(err, "sxdk create order failed", "agent_id", u.ID)
		errJSON(w, 500, "下单失败，请稍后再试")
		return
	}

	// 3) 后台异步提交上游（防串行卡请求；失败标记 error，积分不退、留人工）
	go func() {
		defer func() { recover() }()
		req := sxdk.AddOrderReq{
			Platform:    body.Platform,
			Phone:       body.Phone,
			Password:    body.Password,
			Name:        body.Name,
			Address:     body.Address,
			ProjectName: body.ProjectName,
			SchoolID:    body.SchoolText,
			GwName:      body.GwName,
			Country:     body.Country,
			City:        body.City,
			Adcode:      body.Adcode,
			CheckWeek:   body.CheckWeek,
			CheckTime:   body.CheckTime,
			EndTime:     body.EndTime,
			UpTime:      body.UpCheckTime,
			DownTime:    body.DownCheckTime,
			DayPaper:    body.DayPaper,
			WeekPaper:   body.WeekPaper,
			MonthPaper:  body.MonthPaper,
			RunType:     body.RunType,
			Holiday:     body.Holiday,
		}
		resp, err := client.AddOrder(req)
		if err != nil {
			logger.Warnf("sxdk add fail order=%s err=%v", orderID, err)
			_, _ = store.DB.Exec(`UPDATE sxdk_orders SET status='error', remark=? WHERE order_id=?`,
				truncateStr(fmt.Sprintf("上游下单失败：%s", err), 300), orderID)
			return
		}
		if _, err := store.DB.Exec(
			`UPDATE sxdk_orders SET status='done', tianhe_id=?, code=1, remark=? WHERE order_id=?`,
			resp.OrderID, truncateStr(resp.Msg, 300), orderID); err != nil {
			logger.Err(err, "sxdk mark done fail", "order_id", orderID)
		}
	}()

	writeJSON(w, 201, map[string]interface{}{
		"order_id":   orderID,
		"platform":   body.Platform,
		"days":       days,
		"amount_fen": amountFen,
		"status":     "pending",
	})
}

// HandleSxdkOrders GET /api/sxdk/orders —— 我（当前用户）的实习打卡订单。
func HandleSxdkOrders(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	rows, err := store.DB.Query(
		`SELECT order_id, platform, tianhe_id, phone, name, check_week, end_time, days, amount_fen, status, code, remark, created_at
		 FROM sxdk_orders WHERE user_id=? ORDER BY created_at DESC LIMIT 200`, u.ID)
	if err != nil {
		errJSON(w, 500, "加载失败，请稍后再试")
		return
	}
	defer rows.Close()
	items := []*sxdkOrderOut{}
	for rows.Next() {
		if o := scanSxdkOrder(rows); o != nil {
			items = append(items, o)
		}
	}
	writeJSON(w, 200, map[string]interface{}{"items": items})
}

// HandleSxdkSync POST /api/sxdk/sync —— 同步我全部订单进度：调上游 yunOrder 一次，按手机号+平台回写状态。
func HandleSxdkSync(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if !sxdkSyncLimiter.Allow("sxdk-sync:" + clientIP(r)) {
		errJSON(w, 429, "同步过于频繁，请稍后再试")
		return
	}
	client := sxdk.NewClient()
	if client == nil {
		errJSON(w, 500, "实习打卡上游未配置")
		return
	}
	items, err := client.YunOrder()
	if err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	// 上游返回全部订单 → 匹配本用户订单回写状态。
	// 匹配策略（防串单）：先按 tianhe_id 精确匹配单笔回写；未绑定上游单号的
	// 才按 platform+phone 回退，且只回写/绑定最新一笔——同一手机号续单会有
	// 多笔订单，旧的 OR 条件会把一条上游状态同时刷到多笔订单上。
	remark := "同步于 " + time.Now().Format("01-02 15:04")
	updated := 0
	for _, it := range items {
		upID := it.ID
		// 1) 已绑定上游单号的订单：按 tianhe_id 精确回写
		res, err := store.DB.Exec(
			`UPDATE sxdk_orders SET code=?, remark=? WHERE user_id=? AND tianhe_id=?`,
			it.Code, remark, u.ID, upID)
		if err != nil {
			continue
		}
		if aff, _ := res.RowsAffected(); aff > 0 {
			updated++
			continue
		}
		// 2) 未绑定(tianhe_id=0)的订单：按 platform+phone 找最新一笔绑定并回写；
		//    其余（更早的续单）保持原状，不被这条上游状态污染
		var orderID string
		if err := store.DB.QueryRow(
			`SELECT order_id FROM sxdk_orders
			 WHERE user_id=? AND platform=? AND phone=? AND tianhe_id=0
			 ORDER BY created_at DESC LIMIT 1`,
			u.ID, it.Platform, it.Phone).Scan(&orderID); err != nil {
			continue
		}
		res, err = store.DB.Exec(
			`UPDATE sxdk_orders SET code=?, tianhe_id=?, remark=? WHERE order_id=?`,
			it.Code, upID, remark, orderID)
		if err == nil {
			if aff, _ := res.RowsAffected(); aff > 0 {
				updated++
			}
		}
	}
	writeJSON(w, 200, map[string]interface{}{"updated": updated})
}

var _ = logger.Err
