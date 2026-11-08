package web

import (
	"fmt"
	"math"
	"net/http"
	"strconv"
	"strings"
	"sync"

	"daili-go/internal/config"
	"daili-go/internal/ledger"
	"daili-go/internal/logger"
	"daili-go/internal/ratelimit"
	"daili-go/internal/store"
	"daili-go/internal/syy"
)

// 我爱运动（syy）：用户在本站用积分下单（km 数量），后端经天河 syytianheban
// 套娃 openapi 开户/加量，返回源台专属链接，用户在源台页面完成实际运动单。
// 资金动作全部携带 transaction_id（= 本站订单号），上游幂等，重放安全。

var (
	syyOrderLimiter  = ratelimit.New(10, 60)
	syyGlobalLimiter = ratelimit.New(30, 60)
)

// syyInFlight 按「用户:项目」串行化资金动作（下单/开户/提现）：
// 并发双击或双标签页会同时通过"已有客户"查询，开出两个上游客户，
// 其中一个的余额成为孤儿。LoadOrStore 保证同一时刻每个 (用户,项目) 只有一笔在途。
var syyInFlight sync.Map

func syyTryLock(userID, projectID int64) (release func(), busy bool) {
	key := fmt.Sprintf("%d:%d", userID, projectID)
	if _, loaded := syyInFlight.LoadOrStore(key, struct{}{}); loaded {
		return nil, false
	}
	return func() { syyInFlight.Delete(key) }, true
}

func syyBusy(w http.ResponseWriter) {
	errJSON(w, 409, "上一笔操作还在处理中，请稍候")
}

type syyProjectOut struct {
	ProjectID int64   `json:"project_id"`
	Name      string  `json:"name"`
	UnitFen   int64   `json:"unit_fen"`
	BasePrice float64 `json:"base_price"`
	Desc      string  `json:"description"`
	Source    string  `json:"source"` // custom=管理端自定义价 default=基准价×倍率
}

// syyPriceFen 项目售价（分/单位）：管理端自定义价优先，未设置则用 基准价×倍率。
// 注意：上游对我方的实际成本 = 基准价 × 我方等级费率 ÷ 基准费率（接口不返回），
// 自定义价需覆盖成本，否则亏钱卖。
func syyPriceFen(project *syy.Project) (int64, string) {
	var fen int64
	if err := store.DB.QueryRow(`SELECT price_fen FROM syy_prices WHERE project_id=?`, project.ProjectID).Scan(&fen); err == nil && fen > 0 {
		return fen, "custom"
	}
	return int64(project.BasePrice*config.SyyPriceMult*100 + 0.5), "default"
}

// HandleSyyProjects GET /api/syy/projects —— 可售项目列表（公开）。
func HandleSyyProjects(w http.ResponseWriter, r *http.Request) {
	list, err := syy.Projects()
	if err != nil {
		errJSON(w, 502, err.Error())
		return
	}
	items := make([]syyProjectOut, 0, len(list))
	for i := range list {
		unit, source := syyPriceFen(&list[i])
		if unit <= 0 {
			continue
		}
		items = append(items, syyProjectOut{
			ProjectID: list[i].ProjectID,
			Name:      list[i].Name,
			UnitFen:   unit,
			BasePrice: list[i].BasePrice,
			Desc:      truncateStr(list[i].Description, 200),
			Source:    source,
		})
	}
	writeJSON(w, 200, map[string]interface{}{"items": items, "upstream_name": syy.UpstreamName()})
}

// HandleSyyCreateOrder POST /api/syy/orders {project_id, km}
// 同步链路：扣积分（事务）→ 上游开户/加量（幂等）→ 回写订单；
// 上游明确失败自动退积分；网络类失败保持 processing（启动重试按同 transaction_id 重放）。
func HandleSyyCreateOrder(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	ip := clientIP(r)
	if !syyOrderLimiter.Allow("syy-order:"+ip) || !syyGlobalLimiter.Allow("syy-order-global") {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	var body struct {
		ProjectID int64   `json:"project_id"`
		KM        float64 `json:"km"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	km := math.Round(body.KM*100) / 100
	if km <= 0 || km > 100000 {
		errJSON(w, 400, "数量需在 0.01 ~ 100000 之间")
		return
	}
	project, err := syy.ProjectByID(body.ProjectID)
	if err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	release, busy := syyTryLock(u.ID, project.ProjectID)
	if !busy {
		syyBusy(w)
		return
	}
	defer release()
	unitFen, _ := syyPriceFen(project)
	if unitFen <= 0 {
		errJSON(w, 400, "该项目暂不可售")
		return
	}
	amountFen := int64(math.Round(km * float64(unitFen)))

	// 同一用户同一项目复用已有客户（链接不变，直接加量）
	var existingCustomer int64
	_ = store.DB.QueryRow(
		`SELECT customer_id FROM syy_orders
		 WHERE user_id=? AND project_id=? AND status='done' AND customer_id>0
		 ORDER BY created_at DESC LIMIT 1`, u.ID, project.ProjectID).Scan(&existingCustomer)

	orderID := newOrderID()
	if _, err := ledger.PaySyyOrderWithPoints(ledger.SyyOrder{
		OrderID:     orderID,
		UserID:      u.ID,
		ProjectID:   project.ProjectID,
		ProjectName: project.Name,
		KM:          km,
		UnitFen:     unitFen,
		AmountFen:   amountFen,
	}); err != nil {
		if strings.Contains(err.Error(), "积分不足") {
			errJSON(w, 400, err.Error())
			return
		}
		logger.Err(err, "syy create order failed", "user_id", u.ID)
		errJSON(w, 500, "下单失败，请稍后再试")
		return
	}

	link, balance, dispErr := syyDispatch(orderID, project, km, existingCustomer)
	if dispErr == nil {
		writeJSON(w, 201, map[string]interface{}{
			"order_id":      orderID,
			"status":        "done",
			"km":            km,
			"amount_fen":    amountFen,
			"balance":       balance,
			"customer_link": link,
			"is_new":        existingCustomer <= 0,
		})
		return
	}
	if syy.IsAPIError(dispErr) {
		// 上游明确拒绝：钱没花出去。状态翻转与退款放在同一事务里，
		// 避免原先“先退款、再单独更新状态”之间崩溃导致重启后重复退款。
		if rerr := ledger.FailSyyOrderAndRefund(orderID, u.ID, dispErr.Error()); rerr != nil {
			logger.Err(rerr, "syy refund failed", "order_id", orderID)
		}
		errJSON(w, 502, dispErr.Error())
		return
	}
	// 网络类失败（上游状态未知）：订单保持 processing，启动重试按同 transaction_id 重放
	logger.Warnf("syy order ambiguous: order=%s err=%v", orderID, dispErr)
	writeJSON(w, 201, map[string]interface{}{
		"order_id":   orderID,
		"status":     "processing",
		"km":         km,
		"amount_fen": amountFen,
	})
}

// syyDispatch 执行上游资金动作：已有客户走加量，否则开户。
// 返回（专属链接, 客户当前公里余额, 错误）。
func syyDispatch(orderID string, project *syy.Project, km float64, existingCustomer int64) (string, float64, error) {
	if existingCustomer > 0 {
		before, after, err := syy.AdjustBalance(existingCustomer, km, orderID)
		if err != nil {
			return "", 0, err
		}
		newBalance := after
		if newBalance == 0 && before > 0 {
			newBalance = before + km
		}
		if _, err := store.DB.Exec(
			`UPDATE syy_orders SET status='done', balance=?, updated_at=? WHERE order_id=?`,
			newBalance, nowFloat(), orderID); err != nil {
			logger.Err(err, "syy update order failed", "order_id", orderID)
		}
		return syyResolveLink(existingCustomer), newBalance, nil
	}
	cust, err := syy.GenerateCustomer(project.ProjectID, km, orderID)
	if err != nil {
		return "", 0, err
	}
	_, err = store.DB.Exec(
		`UPDATE syy_orders SET status='done', customer_id=?, customer_key=?, customer_link=?, balance=?, updated_at=? WHERE order_id=?`,
		cust.ID, truncateStr(cust.APIKey, 128), truncateStr(cust.CustomerLink, 500), cust.Balance, nowFloat(), orderID)
	if err != nil {
		logger.Err(err, "syy update order failed", "order_id", orderID)
	}
	return cust.CustomerLink, cust.Balance, nil
}

// syyResolveLink 客户专属链接：先取本地最近一次成功记录；为空则实时查上游并回填本地。
// 老数据（早期上游不返回链接时期开的户）库里是空串，若不回查上游，
// 开户/下单接口会永远返回空链接，用户被卡在「未开通」。
func syyResolveLink(customerID int64) string {
	var link string
	_ = store.DB.QueryRow(
		`SELECT customer_link FROM syy_orders WHERE customer_id=? AND customer_link!='' ORDER BY created_at DESC LIMIT 1`,
		customerID).Scan(&link)
	if link != "" {
		return link
	}
	live, err := syy.GetCustomer(customerID)
	if err != nil || live.CustomerLink == "" {
		return ""
	}
	if _, err := store.DB.Exec(
		`UPDATE syy_orders SET customer_link=? WHERE customer_id=? AND (customer_link='' OR customer_link IS NULL)`,
		truncateStr(live.CustomerLink, 500), customerID); err != nil {
		logger.Err(err, "syy backfill link failed", "customer_id", customerID)
	}
	return live.CustomerLink
}

// RetryPendingSyyOrders 启动重放：processing 状态的订单按原 transaction_id 重试
// （上游幂等：处理过则重放返回原结果，未处理过则现在执行），收敛最终状态。
func RetryPendingSyyOrders() {
	go func() {
		defer func() { recover() }()
		rows, err := store.DB.Query(
			`SELECT order_id, user_id, project_id, project_name, km, amount_fen
			 FROM syy_orders WHERE status='processing'`)
		if err != nil {
			logger.Err(err, "syy retry scan failed")
			return
		}
		type item struct {
			orderID           string
			userID, projectID int64
			projectName       string
			km                float64
			amountFen         int64
		}
		var pending []item
		for rows.Next() {
			var it item
			if rows.Scan(&it.orderID, &it.userID, &it.projectID, &it.projectName, &it.km, &it.amountFen) == nil {
				pending = append(pending, it)
			}
		}
		rows.Close()
		for _, it := range pending {
			// 提现单（km<0）：重放上游负数调额（幂等）→ 成功后补退积分（事务内防双退）
			if it.km < 0 {
				syyRetryWithdraw(it)
				continue
			}
			project, err := syy.ProjectByID(it.projectID)
			if err != nil {
				continue // 上游项目列表不可用/项目下架：保持 processing，下次启动再试
			}
			var customerID int64
			_ = store.DB.QueryRow(
				`SELECT customer_id FROM syy_orders WHERE user_id=? AND project_id=? AND status='done' AND customer_id>0
				 ORDER BY created_at DESC LIMIT 1`, it.userID, it.projectID).Scan(&customerID)
			_, _, derr := syyDispatch(it.orderID, project, it.km, customerID)
			if derr == nil {
				logger.Infof("syy retry done: order=%s", it.orderID)
				continue
			}
			if syy.IsAPIError(derr) {
				// 状态翻转与退款同事务：崩溃重启后重放不会重复退款；
				// 免费开户单 amountFen=0，函数内部自动跳过退款。
				if rerr := ledger.FailSyyOrderAndRefund(it.orderID, it.userID, derr.Error()); rerr != nil {
					logger.Err(rerr, "syy retry refund failed", "order_id", it.orderID)
				}
				logger.Warnf("syy retry failed(refunded): order=%s err=%v", it.orderID, derr)
			}
			// 网络类错误：保持 processing，下次启动再试
		}
	}()
}

// syyRetryWithdraw 重放提现单：上游同 transaction_id 幂等；确认成功后补退积分。
func syyRetryWithdraw(it struct {
	orderID           string
	userID, projectID int64
	projectName       string
	km                float64
	amountFen         int64
}) {
	var customerID int64
	_ = store.DB.QueryRow(
		`SELECT customer_id FROM syy_orders WHERE order_id=? AND status='processing'`,
		it.orderID).Scan(&customerID)
	if customerID <= 0 {
		return
	}
	_, after, err := syy.AdjustBalance(customerID, it.km, it.orderID) // km 为负数
	if err != nil {
		if syy.IsAPIError(err) {
			_, _ = store.DB.Exec(`UPDATE syy_orders SET status='error', remark=?, updated_at=? WHERE order_id=? AND status='processing'`,
				truncateStr(err.Error(), 500), nowFloat(), it.orderID)
			logger.Warnf("syy retry withdraw failed: order=%s err=%v", it.orderID, err)
		}
		return // 网络类错误保持 processing
	}
	refundFen := -it.amountFen
	if err := syyCompleteWithdraw(it.orderID, it.userID, refundFen, it.projectName, -it.km, after); err != nil {
		logger.Err(err, "syy retry withdraw settle failed", "order_id", it.orderID)
		return
	}
	logger.Infof("syy retry withdraw done: order=%s refund=%d分", it.orderID, refundFen)
}

// HandleSyyOrders GET /api/syy/orders —— 我的运动购买记录（仅本人）。
func HandleSyyOrders(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	rows, err := store.DB.Query(
		`SELECT order_id, project_id, project_name, km, unit_fen, amount_fen, status,
		        COALESCE(customer_link,''), COALESCE(remark,''), balance, created_at
		 FROM syy_orders WHERE user_id=? ORDER BY created_at DESC LIMIT 200`, u.ID)
	if err != nil {
		errJSON(w, 500, "加载失败，请稍后再试")
		return
	}
	defer rows.Close()
	items := []map[string]interface{}{}
	for rows.Next() {
		var orderID, projectName, status, link, remark string
		var projectID int64
		var km, balance float64
		var unitFen, amountFen int64
		var createdAt float64
		if rows.Scan(&orderID, &projectID, &projectName, &km, &unitFen, &amountFen, &status, &link, &remark, &balance, &createdAt) != nil {
			continue
		}
		items = append(items, map[string]interface{}{
			"order_id": orderID, "project_id": projectID, "project_name": projectName,
			"km": km, "unit_fen": unitFen, "amount_fen": amountFen,
			"status": status, "customer_link": link, "remark": remark,
			"balance": balance, "created_at": createdAt,
		})
	}
	writeJSON(w, 200, map[string]interface{}{"items": items})
}

// HandleSyyWithdraw POST /api/syy/withdraw {project_id, km}
// 退还公里数：把客户在源台剩余的公里数提现回本站积分。
// 流程：查上游实时余额（不信本地缓存）→ 校验可退额度 → 建提现单（processing）
// → 上游 adjustCustomerBalance 负数调额（钱自动退回本站对接账户）→ 退积分+落账同事务。
// 积分退还发生在上游成功之后，且与「processing→done」状态翻转在同一事务内，
// 崩溃重启后重试只会补一次退款，不会双退。
var syyWithdrawLimiter = ratelimit.New(10, 60)

func HandleSyyWithdraw(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	ip := clientIP(r)
	if !syyWithdrawLimiter.Allow("syy-withdraw:"+ip) || !syyGlobalLimiter.Allow("syy-order-global") {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	var body struct {
		ProjectID int64   `json:"project_id"`
		KM        float64 `json:"km"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	km := math.Round(body.KM*100) / 100
	if km <= 0 || km > 100000 {
		errJSON(w, 400, "数量需在 0.01 ~ 100000 之间")
		return
	}
	project, err := syy.ProjectByID(body.ProjectID)
	if err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	release, busy := syyTryLock(u.ID, project.ProjectID)
	if !busy {
		syyBusy(w)
		return
	}
	defer release()
	unitFen, _ := syyPriceFen(project)
	if unitFen <= 0 {
		errJSON(w, 400, "该项目暂不可操作")
		return
	}
	var customerID int64
	var link string
	err = store.DB.QueryRow(
		`SELECT customer_id, COALESCE(customer_link,'') FROM syy_orders
		 WHERE user_id=? AND project_id=? AND status='done' AND customer_id>0
		 ORDER BY created_at DESC LIMIT 1`, u.ID, project.ProjectID,
	).Scan(&customerID, &link)
	if err != nil || customerID <= 0 {
		errJSON(w, 404, "该项目下暂无运动客户，无可退公里数")
		return
	}

	// 以上游实时余额为准（本地缓存可能过期）
	live, err := syy.GetCustomer(customerID)
	if err != nil {
		errJSON(w, 502, err.Error())
		return
	}
	if km > live.Balance+1e-6 {
		errJSON(w, 400, fmt.Sprintf("可退余额不足：当前剩余 %s 单位", syy.FmtFloat(live.Balance)))
		return
	}
	if live.CustomerLink != "" {
		link = live.CustomerLink
	}
	refundFen := int64(math.Round(km * float64(unitFen)))
	orderID := newOrderID()
	if _, err := store.DB.Exec(
		`INSERT INTO syy_orders(order_id, user_id, project_id, project_name, customer_id, customer_key, customer_link,
		   km, unit_fen, amount_fen, status, balance, transaction_id, created_at, updated_at)
		 VALUES(?,?,?,?,?,?,?,?,?,?, 'processing', ?, ?, ?, ?)`,
		orderID, u.ID, project.ProjectID, truncateStr(project.Name, 100), customerID,
		truncateStr(live.APIKey, 128), truncateStr(link, 500),
		-km, unitFen, -refundFen, live.Balance, orderID, nowFloat(), nowFloat()); err != nil {
		logger.Err(err, "syy withdraw insert failed", "order_id", orderID)
		errJSON(w, 500, "下单失败，请稍后再试")
		return
	}

	_, after, err := syy.AdjustBalance(customerID, -km, orderID)
	if err == nil {
		err = syyCompleteWithdraw(orderID, u.ID, refundFen, project.Name, km, after)
	}
	if err == nil {
		writeJSON(w, 200, map[string]interface{}{
			"order_id": orderID, "km": km, "refund_fen": refundFen, "balance": after,
		})
		return
	}
	if syy.IsAPIError(err) {
		// 上游明确拒绝（如并发下余额被消耗）：未动积分，标记失败即可
		_, _ = store.DB.Exec(`UPDATE syy_orders SET status='error', remark=?, updated_at=? WHERE order_id=? AND status='processing'`,
			truncateStr(err.Error(), 500), nowFloat(), orderID)
		errJSON(w, 502, err.Error())
		return
	}
	// 网络类失败（上游状态未知）：保持 processing，启动重试按同 transaction_id 重放后补退款
	logger.Warnf("syy withdraw ambiguous: order=%s err=%v", orderID, err)
	writeJSON(w, 200, map[string]interface{}{
		"order_id": orderID, "status": "processing", "km": km, "refund_fen": refundFen,
	})
}

// syyCompleteWithdraw 提现落账：状态翻转（processing→done，条件更新防重复）+ 退积分 + 流水，同一事务。
func syyCompleteWithdraw(orderID string, userID int64, refundFen int64, projectName string, km float64, after float64) error {
	tx, err := store.DB.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	res, err := tx.Exec(
		`UPDATE syy_orders SET status='done', balance=?, updated_at=? WHERE order_id=? AND status='processing'`,
		after, nowFloat(), orderID)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return nil // 已被其它路径完成（重试幂等）
	}
	cost := float64(refundFen) / 100.0
	if _, err := tx.Exec(
		`UPDATE agent_users SET points=ROUND((points+?)*100)/100 WHERE id=?`, cost, userID); err != nil {
		return err
	}
	var newBalance float64
	if err := tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, userID).Scan(&newBalance); err != nil {
		return err
	}
	if _, err := tx.Exec(
		`INSERT INTO point_logs(agent_id, delta, balance_after, reason, order_id, created_at)
		 VALUES(?,?,?,?,?,?)`,
		userID, cost, newBalance, "我爱运动提现 "+truncateStr(projectName, 20)+" x"+trimFloatDot(km), orderID, nowFloat()); err != nil {
		return err
	}
	return tx.Commit()
}

var syyBalanceLimiter = ratelimit.New(30, 60) // 项目切换时会自动查账户状态，放宽到 30/min

// HandleSyyOpen POST /api/syy/open {project_id} —— 免费开户（0 公里）。
// 上游模型明确支持"初始余额可为0"：先拿专属链接进站，之后按需充值/退还。
// 已有客户则幂等返回现有链接，不重复开户。
var syyOpenLimiter = ratelimit.New(6, 60)

func HandleSyyOpen(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	ip := clientIP(r)
	if !syyOpenLimiter.Allow("syy-open:"+ip) || !syyGlobalLimiter.Allow("syy-order-global") {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	var body struct {
		ProjectID int64 `json:"project_id"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	project, err := syy.ProjectByID(body.ProjectID)
	if err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	release, busy := syyTryLock(u.ID, project.ProjectID)
	if !busy {
		syyBusy(w)
		return
	}
	defer release()
	// 已有客户：幂等返回现有链接；老数据库里可能是空串，回查上游补齐
	var customerID int64
	var link string
	_ = store.DB.QueryRow(
		`SELECT customer_id, COALESCE(customer_link,'') FROM syy_orders
		 WHERE user_id=? AND project_id=? AND status='done' AND customer_id>0
		 ORDER BY created_at DESC LIMIT 1`, u.ID, project.ProjectID).Scan(&customerID, &link)
	if customerID > 0 {
		if link == "" {
			link = syyResolveLink(customerID)
		}
		bal := 0.0
		_ = store.DB.QueryRow(
			`SELECT balance FROM syy_orders WHERE customer_id=? ORDER BY created_at DESC LIMIT 1`,
			customerID).Scan(&bal)
		writeJSON(w, 200, map[string]interface{}{"customer_link": link, "balance": bal, "is_new": false})
		return
	}
	orderID := newOrderID()
	if _, err := store.DB.Exec(
		`INSERT INTO syy_orders(order_id, user_id, project_id, project_name, km, unit_fen, amount_fen,
		   status, transaction_id, created_at, updated_at)
		 VALUES(?,?,?,?,0,0,0,'processing',?,?,?)`,
		orderID, u.ID, project.ProjectID, truncateStr(project.Name, 100), orderID, nowFloat(), nowFloat()); err != nil {
		logger.Err(err, "syy open insert failed", "order_id", orderID)
		errJSON(w, 500, "开通失败，请稍后再试")
		return
	}
	// balance=0：上游不产生任何扣费，纯开户
	cust, err := syy.GenerateCustomer(project.ProjectID, 0, orderID)
	if err == nil {
		if _, err = store.DB.Exec(
			`UPDATE syy_orders SET status='done', customer_id=?, customer_key=?, customer_link=?, balance=0, updated_at=? WHERE order_id=?`,
			cust.ID, truncateStr(cust.APIKey, 128), truncateStr(cust.CustomerLink, 500), nowFloat(), orderID); err != nil {
			logger.Err(err, "syy open update failed", "order_id", orderID)
		}
		writeJSON(w, 200, map[string]interface{}{
			"customer_link": cust.CustomerLink, "balance": 0, "is_new": true,
		})
		return
	}
	if syy.IsAPIError(err) {
		_, _ = store.DB.Exec(`UPDATE syy_orders SET status='error', remark=?, updated_at=? WHERE order_id=?`,
			truncateStr(err.Error(), 500), nowFloat(), orderID)
		errJSON(w, 502, err.Error())
		return
	}
	// 网络类失败：保持 processing，启动重试按同 transaction_id 重放
	logger.Warnf("syy open ambiguous: order=%s err=%v", orderID, err)
	writeJSON(w, 200, map[string]interface{}{"status": "processing", "order_id": orderID})
}

// HandleSyyBalance GET /api/syy/balance?project_id= —— 查我在该项目的上游实时余额。
func HandleSyyBalance(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if !syyBalanceLimiter.Allow("syy-balance:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	var pid int64
	if p, perr := strconv.ParseInt(strings.TrimSpace(r.URL.Query().Get("project_id")), 10, 64); perr == nil && p > 0 {
		pid = p
	} else {
		errJSON(w, 400, "参数缺失")
		return
	}
	var customerID int64
	err := store.DB.QueryRow(
		`SELECT customer_id FROM syy_orders
		 WHERE user_id=? AND project_id=? AND status='done' AND customer_id>0
		 ORDER BY created_at DESC LIMIT 1`, u.ID, pid).Scan(&customerID)
	if err != nil {
		errJSON(w, 404, "该项目下暂无运动客户")
		return
	}
	cust, err := syy.GetCustomer(customerID)
	if err != nil {
		errJSON(w, 502, err.Error())
		return
	}
	_, _ = store.DB.Exec(`UPDATE syy_orders SET balance=? WHERE user_id=? AND project_id=? AND status='done'`,
		cust.Balance, u.ID, pid)
	link := cust.CustomerLink
	if link == "" {
		link = syyResolveLink(customerID)
	}
	writeJSON(w, 200, map[string]interface{}{
		"project_id": pid, "balance": cust.Balance, "customer_link": link,
	})
}

// ---------------- 管理端：运动定价 ----------------

// HandleAdminSyyProjects GET /api/admin/syy-projects —— 运动项目售价一览（含自定义/默认来源）。
func HandleAdminSyyProjects(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	list, err := syy.Projects()
	if err != nil {
		errJSON(w, 502, err.Error())
		return
	}
	items := make([]map[string]interface{}, 0, len(list))
	for i := range list {
		unit, source := syyPriceFen(&list[i])
		items = append(items, map[string]interface{}{
			"project_id":  list[i].ProjectID,
			"name":        list[i].Name,
			"base_price":  list[i].BasePrice,
			"unit_fen":    unit,
			"source":      source,
			"description": truncateStr(list[i].Description, 120),
		})
	}
	writeJSON(w, 200, map[string]interface{}{"items": items})
}

// HandleAdminSetSyyPrice POST /api/admin/syy-projects/{id}/price {"price_yuan": 1.5}
// price_yuan > 0 设为自定义售价；<= 0 删除自定义价，恢复 基准价×倍率 的默认价。
func HandleAdminSetSyyPrice(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "id")
	if !ok {
		return
	}
	var body struct {
		PriceYuan *float64 `json:"price_yuan"`
	}
	if !readJSON(w, r, &body) || body.PriceYuan == nil {
		err422(w)
		return
	}
	yuan := *body.PriceYuan
	if yuan < 0 || yuan > 10000 {
		errJSON(w, 400, "价格需在 0 ~ 10000 元之间（0=恢复默认倍率价）")
		return
	}
	if yuan > 0 {
		fen := int64(yuan*100 + 0.5)
		upsert := `INSERT INTO syy_prices(project_id, price_fen, updated_at) VALUES(?,?,?)`
		if store.IsMySQL() {
			upsert += ` ON DUPLICATE KEY UPDATE price_fen=VALUES(price_fen), updated_at=VALUES(updated_at)`
		} else {
			upsert += ` ON CONFLICT(project_id) DO UPDATE SET price_fen=excluded.price_fen, updated_at=excluded.updated_at`
		}
		if _, err := store.DB.Exec(upsert, id, fen, nowFloat()); err != nil {
			logger.Err(err, "syy set price failed", "project_id", id)
			errJSON(w, 500, "保存失败，请稍后再试")
			return
		}
		audit("syy.price.set", fmt.Sprintf(`{"project_id":%d,"price_fen":%d}`, id, fen))
		logger.Infof("syy price changed: project=%d fen=%d", id, fen)
		writeJSON(w, 200, map[string]interface{}{"project_id": id, "price_fen": fen, "source": "custom"})
		return
	}
	res, err := store.DB.Exec(`DELETE FROM syy_prices WHERE project_id=?`, id)
	if err != nil {
		errJSON(w, 500, "保存失败，请稍后再试")
		return
	}
	if n, _ := res.RowsAffected(); n == 0 {
		errJSON(w, 400, "该项目未设置自定义价")
		return
	}
	audit("syy.price.reset", fmt.Sprintf(`{"project_id":%d}`, id))
	writeJSON(w, 200, map[string]interface{}{"project_id": id, "source": "default"})
}
