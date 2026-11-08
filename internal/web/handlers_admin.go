package web

import (
	"database/sql"
	"encoding/csv"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"crypto/rand"

	"daili-go/internal/auth"
	"daili-go/internal/config"
	"daili-go/internal/fetchers"
	"daili-go/internal/frontcat"
	"daili-go/internal/ledger"
	"daili-go/internal/logger"
	"daili-go/internal/productcache"
	"daili-go/internal/ratelimit"
	"daili-go/internal/securestore"
	"daili-go/internal/store"
	appsync "daili-go/internal/sync"
	"daili-go/internal/syscfg"
)

// 独立管理后台 JSON 接口（/api/admin/*），对齐 Python routers/admin_api.py：
// 认证 / 看板 / 用户管理 / 订单汇总 / 充值码 / 流水与审计 / CSV 导出。

var adminLoginLimiter2 = ratelimit.New(10, 60)
var adminLoginGlobal2 = ratelimit.New(30, 60)

// adminAcctLock 管理员账号维度失败锁定：15 分钟错 5 次锁满窗口。
// 与 agent 登录锁同策略；两个登录入口（JSON/表单）共用同一把锁。
var adminAcctLock = ratelimit.New(5, 900)

type adminLoginBody struct {
	Username string `json:"username"`
	Password string `json:"password"`
}

// HandleAdminLogin POST /api/admin/login
func HandleAdminLogin(w http.ResponseWriter, r *http.Request) {
	var data adminLoginBody
	if !readJSON(w, r, &data) {
		return
	}
	ip := clientIP(r)
	if !adminLoginLimiter2.Allow("admin-login:"+ip) || !adminLoginGlobal2.Allow("admin-login-global") {
		errJSON(w, 429, "尝试过于频繁，请稍后再试")
		return
	}
	const lockKey = "admin-acct-lock"
	locked := adminAcctLock.Peek(lockKey) >= adminAcctLock.Limit()
	ok := auth.SafeEq(data.Username, config.AdminUsername) && auth.VerifyPassword(data.Password, config.AdminPasswordHash)
	logLogin("admin:"+data.Username, ip, ok)
	// 正确凭证直接放行并清零失败计数（同 agent 登录锁策略）
	if ok {
		adminAcctLock.Reset(lockKey)
		setAdminCookie(w)
		w.WriteHeader(204)
		return
	}
	adminAcctLock.Count(lockKey)
	if locked {
		errJSON(w, 429, "失败次数过多，已临时锁定，请 15 分钟后再试")
		return
	}
	errJSON(w, 401, "账号或密码错误")
}

// HandleAdminLogout POST /api/admin/logout
func HandleAdminLogout(w http.ResponseWriter, r *http.Request) {
	clearAdminCookie(w)
	w.WriteHeader(204)
}

// HandleAdminMe GET /api/admin/me
func HandleAdminMe(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	writeJSON(w, 200, map[string]string{"username": config.AdminUsername})
}

// HandleAdminChangePassword POST /api/admin/change-password
// {"old_password":"...","new_password":"..."} —— 校验旧密码后写入 system_settings 并热更新。
func HandleAdminChangePassword(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	var body struct {
		OldPassword string `json:"old_password"`
		NewPassword string `json:"new_password"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	if body.OldPassword == "" || body.NewPassword == "" {
		errJSON(w, 400, "请填写旧密码和新密码")
		return
	}
	if !auth.VerifyPassword(body.OldPassword, config.AdminPasswordHash) {
		errJSON(w, 400, "旧密码不正确")
		return
	}
	np := strings.TrimSpace(body.NewPassword)
	if n := len([]rune(np)); n < 6 || n > 72 {
		errJSON(w, 400, "新密码需 6~72 个字符")
		return
	}
	if auth.SafeEq(body.OldPassword, np) {
		errJSON(w, 400, "新密码不能与旧密码相同")
		return
	}
	hash := auth.HashPassword(np)
	if err := syscfg.SetAdminPasswordHash(hash); err != nil {
		logger.Err(err, "admin change password failed")
		errJSON(w, 500, "保存失败，请稍后再试")
		return
	}
	audit("admin.password.change", `{"ok":true}`)
	writeJSON(w, 200, map[string]interface{}{"ok": true})
}

// HandleMoreSync POST /api/more/sync —— 管理后台手动触发冷门网课商品同步。
func HandleMoreSync(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	res, err := appsync.RunOnce()
	if err != nil {
		logger.Err(err, "manual sync failed")
		errJSON(w, 500, "同步失败："+err.Error())
		return
	}
	logger.Infof("manual sync triggered by admin: %+v", res)
	writeJSON(w, 200, res)
}

// beijingMidnight 返回 now 所在「今日」的起始 Unix 秒，口径固定为东八区（北京时间）零点。
// 本平台的经验场景操作员均为北京时间：前端时间用浏览器本地时区（操作员本地=东八区），
// 管理后台「今日」统计沿用北京零点，做到与操作员看到的日期一致；
// 推导用纯 Unix 秒 + 固定偏移，与服务器部署在哪个时区无关，跨时区部署口径稳定不漂移。
func beijingMidnight(now time.Time) float64 {
	return float64(now.Unix() - (now.Unix()+8*3600)%86400)
}

// HandleAdminDashboard GET /api/admin/dashboard
func HandleAdminDashboard(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	now := time.Now()
	today0 := beijingMidnight(now)

	salesSince := func(since float64) map[string]interface{} {
		var cnt int64
		var amount interface{}
		_ = store.DB.QueryRow(
			`SELECT count(*), COALESCE(sum(amount_fen),0) FROM orders WHERE status='paid' AND paid_at>=?`,
			since).Scan(&cnt, &amount)
		var pointsUsed interface{}
		_ = store.DB.QueryRow(
			`SELECT COALESCE(sum(-delta),0) FROM point_logs WHERE delta<0 AND created_at>=?`, since).
			Scan(&pointsUsed)
		var newUsers int64
		_ = store.DB.QueryRow(
			`SELECT count(*) FROM agent_users WHERE created_at>=?`, since).Scan(&newUsers)
		return map[string]interface{}{
			"orders": cnt, "amount_fen": amount, "points_used": pointsUsed, "new_users": newUsers,
		}
	}

	var usersTotal int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM agent_users`).Scan(&usersTotal)
	var pendingOrders int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM orders WHERE status='pending'`).Scan(&pendingOrders)
	var unprocessed int64
	_ = store.DB.QueryRow(
		`SELECT count(*) FROM orders WHERE status='paid' AND COALESCE(process_status,'pending')!='done'`).Scan(&unprocessed)

	type platRow struct {
		Platform string `json:"platform"`
		Count    int64  `json:"count"`
	}
	platforms := []platRow{}
	rows, err := store.DB.Query(
		`SELECT platform, count(*) FROM orders WHERE status='paid' GROUP BY platform ORDER BY count(*) DESC`)
	if err == nil {
		defer rows.Close()
		for rows.Next() {
			var p platRow
			if rows.Scan(&p.Platform, &p.Count) == nil {
				platforms = append(platforms, p)
			}
		}
	}
	var codesUnused, codesUsed int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM redeem_codes WHERE status='unused'`).Scan(&codesUnused)
	_ = store.DB.QueryRow(`SELECT count(*) FROM redeem_codes WHERE status='used'`).Scan(&codesUsed)

	writeJSON(w, 200, map[string]interface{}{
		"today":            salesSince(today0),
		"week":             salesSince(float64(now.Unix()) - 7*86400),
		"month":            salesSince(float64(now.Unix()) - 30*86400),
		"users_total":      usersTotal,
		"pending_orders":   pendingOrders,
		"unprocessed_paid": unprocessed,
		"platforms":        platforms,
		"codes":            map[string]interface{}{"unused": codesUnused, "used": codesUsed},
	})
}

// ---------------- 用户管理 ----------------

// HandleAdminUsers GET /api/admin/users?q=&page=&page_size=
func HandleAdminUsers(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	q := strings.TrimSpace(r.URL.Query().Get("q"))
	page := clamp(queryInt(r, "page", 1), 1, 1<<30)
	pageSize := clamp(queryInt(r, "page_size", 20), 1, 100)
	like := "%" + q + "%"

	var total int64
	_ = store.DB.QueryRow(
		`SELECT count(*) FROM agent_users WHERE username LIKE ? OR contact LIKE ?`, like, like).Scan(&total)

	offset := (page - 1) * pageSize
	rows, err := store.DB.Query(`
		SELECT u.id, u.username, u.contact, u.points, u.status, u.created_at,
		       COALESCE(s.order_count,0), COALESCE(s.paid_fen,0)
		FROM agent_users u
		LEFT JOIN (
			SELECT agent_id AS aid, count(*) AS order_count,
			       sum(CASE WHEN status='paid' THEN amount_fen ELSE 0 END) AS paid_fen
			FROM orders GROUP BY agent_id
		) s ON s.aid = u.id
		WHERE u.username LIKE ? OR u.contact LIKE ?
		ORDER BY u.id DESC LIMIT ? OFFSET ?`, like, like, pageSize, offset)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()
	items := []map[string]interface{}{}
	for rows.Next() {
		var id, orderCount, paidFen int64
		var points float64 // 积分支持小数（精确到分），int64 会截断显示
		var username, contact, status string
		var createdAt float64
		if rows.Scan(&id, &username, &contact, &points, &status, &createdAt, &orderCount, &paidFen) == nil {
			items = append(items, map[string]interface{}{
				"id": id, "username": username, "contact": contact, "points": points,
				"status": status, "created_at": createdAt,
				"order_count": orderCount, "paid_amount_fen": paidFen,
			})
		}
	}
	writeJSON(w, 200, map[string]interface{}{
		"total": total, "page": page, "page_size": pageSize, "items": items,
	})
}

// HandleAdminUserPoints POST /api/admin/users/{id}/points
func HandleAdminUserPoints(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "user_id")
	if !ok {
		return
	}
	var body struct {
		Delta  *float64 `json:"delta"`
		Reason string   `json:"reason"`
	}
	if !readJSON(w, r, &body) || body.Delta == nil || *body.Delta == 0 {
		errJSON(w, 400, "变动值不能为 0")
		return
	}
	reason := strings.TrimSpace(body.Reason)
	if reason == "" {
		reason = "管理员调整"
	}
	balance, berr := ledger.AdjustBalance(id, *body.Delta, "管理员调整："+reason)
	if berr != nil {
		errJSON(w, 400, berr.Error())
		return
	}
	audit("user.points", fmt.Sprintf(`{"agent_id":%d,"delta":%v,"reason":%q,"balance":%v}`, id, *body.Delta, reason, balance))
	writeJSON(w, 200, map[string]float64{"balance": balance})
}

// HandleAdminBan POST /api/admin/users/{id}/ban —— 封禁并吊销会话。
func HandleAdminBan(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "user_id")
	if !ok {
		return
	}
	var username string
	var status string
	err := store.DB.QueryRow(`SELECT username, status FROM agent_users WHERE id=?`, id).Scan(&username, &status)
	if err != nil {
		errJSON(w, 404, "用户不存在")
		return
	}
	if status != "banned" {
		_, _ = store.DB.Exec(
			`UPDATE agent_users SET status='banned', token_version=token_version+1 WHERE id=?`, id)
		audit("user.ban", fmt.Sprintf(`{"agent_id":%d,"username":%q}`, id, username))
	}
	writeJSON(w, 200, map[string]string{"status": "banned"})
}

// HandleAdminUnban POST /api/admin/users/{id}/unban
func HandleAdminUnban(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "user_id")
	if !ok {
		return
	}
	var username, status string
	err := store.DB.QueryRow(`SELECT username, status FROM agent_users WHERE id=?`, id).Scan(&username, &status)
	if err != nil {
		errJSON(w, 404, "用户不存在")
		return
	}
	if status != "active" {
		_, _ = store.DB.Exec(`UPDATE agent_users SET status='active' WHERE id=?`, id)
		audit("user.unban", fmt.Sprintf(`{"agent_id":%d,"username":%q}`, id, username))
	}
	writeJSON(w, 200, map[string]string{"status": "active"})
}

// HandleAdminResetPassword POST /api/admin/users/{id}/reset-password
func HandleAdminResetPassword(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "user_id")
	if !ok {
		return
	}
	var body struct {
		NewPassword string `json:"new_password"`
	}
	if !readJSON(w, r, &body) || len(body.NewPassword) < 8 || len(body.NewPassword) > 64 {
		err422(w)
		return
	}
	var username string
	err := store.DB.QueryRow(`SELECT username FROM agent_users WHERE id=?`, id).Scan(&username)
	if err != nil {
		errJSON(w, 404, "用户不存在")
		return
	}
	if _, err := store.DB.Exec(`UPDATE agent_users SET password_hash=?, token_version=token_version+1 WHERE id=?`,
		auth.HashPassword(body.NewPassword), id); err != nil {
		logger.Err(err, "admin reset password failed", "agent_id", id)
		errJSON(w, 500, "重置密码失败，请稍后再试")
		return
	}
	audit("user.reset_password", fmt.Sprintf(`{"agent_id":%d,"username":%q}`, id, username))
	writeJSON(w, 200, map[string]bool{"ok": true})
}

// HandleAdminDeleteUser DELETE /api/admin/users/{user_id} —— 删除已注册用户。
// 仅删 agent_users 行本身；订单/流水/充值码记录保留（查询时以 #id 兜底显示），
// 不影响冻结资金、统计口径与外键（本库无 FK 约束）。已登录会话随用户行消失而自然失效。
func HandleAdminDeleteUser(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "user_id")
	if !ok {
		return
	}
	var username, status string
	var points float64
	err := store.DB.QueryRow(`SELECT username, status, points FROM agent_users WHERE id=?`, id).
		Scan(&username, &status, &points)
	if err != nil {
		errJSON(w, 404, "用户不存在")
		return
	}
	res, err := store.DB.Exec(`DELETE FROM agent_users WHERE id=?`, id)
	if err != nil {
		logger.Err(err, "delete user failed", "agent_id", id)
		errJSON(w, 500, "删除失败，请稍后再试")
		return
	}
	if n, _ := res.RowsAffected(); n == 0 {
		errJSON(w, 404, "用户不存在")
		return
	}
	// 关联数据保留仅作审计，订单/流水/任务可通过 agent_id 继续追溯；
	// 如需物理清理可由管理员手动 SQL 处理，避免误删核心业务数据。
	audit("user.delete", fmt.Sprintf(`{"agent_id":%d,"username":%q,"status":%q,"points":%v}`, id, username, status, points))
	logger.Infof("user deleted: id=%d username=%s", id, username)
	writeJSON(w, 200, map[string]bool{"ok": true})
}

// ---------------- 订单汇总 ----------------

// condClause 一条 WHERE 子句与其参数（绑定式构造，杜绝占位符/参数错位）。
type condClause struct {
	sql  string
	args []interface{}
}

func orderFilterClauses(r *http.Request, includeStatus bool) []condClause {
	qp := r.URL.Query()
	var clauses []condClause
	add := func(sql string, args ...interface{}) {
		clauses = append(clauses, condClause{sql: sql, args: args})
	}
	statusS := qp.Get("status")
	payMethod := qp.Get("pay_method")
	process := qp.Get("process")
	platform := qp.Get("platform")
	q := qp.Get("q")
	start := queryFloat(r, "start", 0)
	end := queryFloat(r, "end", 0)

	if includeStatus && (statusS == "paid" || statusS == "pending") {
		add("status=?", statusS)
	}
	if payMethod == "epay" || payMethod == "points" {
		add("pay_method=?", payMethod)
	}
	switch process {
	case "pending", "processing", "done":
		add("COALESCE(process_status,'pending')=?", process)
	case "undone":
		add("COALESCE(process_status,'pending')!='done'")
	}
	if platform != "" {
		add("platform=?", platform)
	}
	if start > 0 {
		add("created_at>=?", start)
	}
	if end > 0 {
		add("created_at<=?", end)
	}
	if qq := strings.TrimSpace(q); qq != "" {
		like := "%" + qq + "%"
		add("(username LIKE ? OR order_id LIKE ? OR remark LIKE ?)", like, like, like)
	}
	return clauses
}

func clausesWhere(clauses []condClause) (string, []interface{}) {
	if len(clauses) == 0 {
		return "", nil
	}
	sqls := make([]string, 0, len(clauses))
	var args []interface{}
	for _, c := range clauses {
		sqls = append(sqls, c.sql)
		args = append(args, c.args...)
	}
	return " WHERE " + strings.Join(sqls, " AND "), args
}

func whereClause(conds []string) string {
	if len(conds) == 0 {
		return ""
	}
	return " WHERE " + strings.Join(conds, " AND ")
}

func validProcessTransition(from, to string) bool {
	if from == to {
		return true
	}
	switch from {
	case "pending":
		return to == "processing"
	case "processing":
		return to == "pending" || to == "done"
	case "done":
		return false
	default:
		return false
	}
}

// HandleAdminOrders GET /api/admin/orders
func HandleAdminOrders(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	where, args := clausesWhere(orderFilterClauses(r, true))

	var total int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM orders`+where, args...).Scan(&total)

	// 合计查询：固定 status='paid'，其余筛选复用（不含 status 子句，天然无错位）
	sumWhere, sumArgs := clausesWhere(orderFilterClauses(r, false))
	var paidSum interface{}
	if err := store.DB.QueryRow(
		`SELECT COALESCE(sum(amount_fen),0) FROM orders WHERE status='paid'`+sumWhere,
		sumArgs...,
	).Scan(&paidSum); err != nil {
		paidSum = 0
	}

	page := clamp(queryInt(r, "page", 1), 1, 1<<30)
	pageSize := clamp(queryInt(r, "page_size", 20), 1, 100)
	listArgs := append(append([]interface{}{}, args...), pageSize, (page-1)*pageSize)
	rows, err := store.DB.Query(`
		SELECT order_id, platform, username, password, course_count, courses, remark,
		       amount_fen, status, COALESCE(process_status,'pending'), pay_method, pay_type,
		       trade_no, agent_id, created_at, paid_at
		FROM orders`+where+` ORDER BY created_at DESC LIMIT ? OFFSET ?`, listArgs...)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()
	items := []map[string]interface{}{}
	for rows.Next() {
		m := scanOrderRow(rows)
		if m != nil {
			items = append(items, m)
		}
	}
	writeJSON(w, 200, map[string]interface{}{
		"total": total, "page": page, "page_size": pageSize, "paid_sum_fen": paidSum, "items": items,
	})
}

// HandleAdminOrders GET /api/admin/orders

type rowScanner interface {
	Scan(dest ...interface{}) error
}

func scanOrderRow(rows rowScanner) map[string]interface{} {
	var orderID, platform, username, password, courses, remark string
	var courseCount, amountFen int64
	var status, processStatus, payMethod, payType, tradeNo string
	var agentID int64
	var createdAt float64
	var paidAt sql.NullFloat64
	if err := rows.Scan(&orderID, &platform, &username, &password, &courseCount, &courses,
		&remark, &amountFen, &status, &processStatus, &payMethod, &payType,
		&tradeNo, &agentID, &createdAt, &paidAt); err != nil {
		return nil
	}
	_ = password
	m := map[string]interface{}{
		"order_id": orderID, "platform": platform, "username": username,
		"password": "••••••••", "course_count": courseCount, // 列表不返回明文密码，需在单笔凭据接口按需取
		"courses": parseCoursesJSON(courses),
		"remark":  remark, "amount_fen": amountFen, "status": status,
		"process_status": processStatus, "pay_method": payMethod, "pay_type": payType,
		"trade_no": tradeNo, "agent_id": agentID, "created_at": createdAt, "paid_at": nil,
	}
	if paidAt.Valid {
		m["paid_at"] = paidAt.Float64
	}
	return m
}

// HandleAdminSetProcess PATCH /api/orders/{id} 由路由指向这里（/api/admin/orders/{id}）
func HandleAdminSetProcess(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	orderID := r.PathValue("order_id")
	var body struct {
		ProcessStatus string `json:"process_status"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	if body.ProcessStatus != "pending" && body.ProcessStatus != "processing" && body.ProcessStatus != "done" {
		errJSON(w, 400, "非法的处理状态")
		return
	}
	var username, currentProcess string
	err := store.DB.QueryRow(`SELECT username, COALESCE(process_status, 'pending') FROM orders WHERE order_id=?`, orderID).Scan(&username, &currentProcess)
	if err != nil {
		errJSON(w, 404, "订单不存在")
		return
	}
	if !validProcessTransition(currentProcess, body.ProcessStatus) {
		errJSON(w, 409, "不允许的订单状态流转")
		return
	}
	if _, err := store.DB.Exec(`UPDATE orders SET process_status=? WHERE order_id=?`, body.ProcessStatus, orderID); err != nil {
		logger.Err(err, "update order process status failed", "order_id", orderID)
		errJSON(w, 500, "更新订单状态失败")
		return
	}
	audit("order.process",
		fmt.Sprintf(`{"order_id":%q,"process_status":%q,"username":%q}`, orderID, body.ProcessStatus, username))
	writeJSON(w, 200, map[string]string{"process_status": body.ProcessStatus})
}

// HandleAdminOrderCredentials GET /api/admin/orders/{order_id}/credentials
// 按需返回单笔订单的账号密码（列表接口只返回遮蔽值，避免全表明文密码被一次性拉出）。
func HandleAdminOrderCredentials(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	orderID := r.PathValue("order_id")
	var username, password string
	err := store.DB.QueryRow(`SELECT username, password FROM orders WHERE order_id=?`, orderID).Scan(&username, &password)
	if err != nil {
		errJSON(w, 404, "订单不存在")
		return
	}
	password, err = securestore.Decrypt(password)
	if err != nil {
		errJSON(w, 500, "凭据解密失败")
		return
	}
	audit("order.credentials",
		fmt.Sprintf(`{"order_id":%q,"username":%q}`, orderID, username))
	writeJSON(w, 200, map[string]string{
		"order_id": orderID, "username": username, "password": password,
	})
}

// HandleAdminOrderPause POST /api/admin/orders/{order_id}/pause
// 暂停订单在上游的全部（或指定）课程任务（天河 act=zt）。
// body 可选 {"kcids":[".."]}：只暂停指定 kcid 的课程；不传 = 全部已提交课程。
// 暂停只作用于上游执行状态，不改动本站订单状态/积分；恢复需到天河后台或重新补刷。
func HandleAdminOrderPause(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	orderID := r.PathValue("order_id")
	var upstreamJSON, status string
	if err := store.DB.QueryRow(
		`SELECT COALESCE(upstream,''), status FROM orders WHERE order_id=?`, orderID,
	).Scan(&upstreamJSON, &status); err != nil {
		errJSON(w, 404, "订单不存在")
		return
	}
	if status != "paid" {
		errJSON(w, 400, "仅已支付订单可暂停上游任务")
		return
	}
	var upstream []skyriverUpstreamItem
	if err := json.Unmarshal([]byte(upstreamJSON), &upstream); err != nil || len(upstream) == 0 {
		errJSON(w, 400, "该订单没有上游提交记录，无需暂停")
		return
	}

	// 可选 kcid 过滤：body 为空视为全量暂停（管理端一键暂停）
	var wantKcids map[string]bool
	body, _ := io.ReadAll(http.MaxBytesReader(w, r.Body, 64<<10))
	if len(strings.TrimSpace(string(body))) > 0 {
		var in struct {
			Kcids []string `json:"kcids"`
		}
		if err := json.Unmarshal(body, &in); err != nil {
			errJSON(w, 400, "请求体不是合法 JSON")
			return
		}
		if len(in.Kcids) > 0 {
			wantKcids = make(map[string]bool, len(in.Kcids))
			for _, k := range in.Kcids {
				wantKcids[strings.TrimSpace(k)] = true
			}
		}
	}
	targets := make([]skyriverUpstreamItem, 0, len(upstream))
	for _, item := range upstream {
		if strings.TrimSpace(item.Yid) == "" {
			continue // 未成功提交到上游的课程无从暂停
		}
		if wantKcids != nil && !wantKcids[item.Kcid] {
			continue
		}
		targets = append(targets, item)
	}
	if len(targets) == 0 {
		errJSON(w, 400, "没有可暂停的上游任务")
		return
	}

	type pauseItem struct {
		Title string `json:"title"`
		Kcid  string `json:"kcid"`
		Yid   string `json:"yid"`
		OK    bool   `json:"ok"`
		Msg   string `json:"msg"`
	}
	items := make([]pauseItem, len(targets))
	type pauseJob struct {
		Index int
		Yid   string
	}
	jobs := make(chan pauseJob)
	var workers sync.WaitGroup
	deadline := time.Now().Add(30 * time.Second)
	for i := 0; i < 6; i++ {
		workers.Add(1)
		go func() {
			defer workers.Done()
			for job := range jobs {
				target := targets[job.Index]
				if time.Now().After(deadline) {
					items[job.Index] = pauseItem{Title: target.Title, Kcid: target.Kcid, Yid: job.Yid, Msg: "暂停请求超时"}
					continue
				}
				message, err := fetchers.SkyriverPauseOrder(job.Yid)
				if err != nil {
					items[job.Index] = pauseItem{Title: target.Title, Kcid: target.Kcid, Yid: job.Yid, Msg: err.Error()}
					continue
				}
				items[job.Index] = pauseItem{Title: target.Title, Kcid: target.Kcid, Yid: job.Yid, OK: true, Msg: message}
			}
		}()
	}
	for index, item := range targets {
		jobs <- pauseJob{Index: index, Yid: item.Yid}
	}
	close(jobs)
	workers.Wait()

	success, failed := 0, 0
	for _, item := range items {
		if item.OK {
			success++
		} else {
			failed++
		}
	}
	audit("order.pause",
		fmt.Sprintf(`{"order_id":%q,"total":%d,"ok":%d,"fail":%d}`, orderID, len(items), success, failed))
	logger.Infof("order pause: order=%s total=%d ok=%d fail=%d", orderID, len(items), success, failed)
	writeJSON(w, 200, map[string]interface{}{
		"order_id": orderID, "total": len(items), "success": success, "failed": failed, "items": items,
	})
}

// HandleAdminExportOrders GET /api/admin/orders/export.csv
func HandleAdminExportOrders(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	where, listArgs := clausesWhere(orderFilterClauses(r, true))
	var total int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM orders`+where, listArgs...).Scan(&total)
	const exportLimit = int64(5000) // 与下方 SQL LIMIT 保持一致
	var exported int64
	rows, err := store.DB.Query(`
		SELECT order_id, platform, username, password, course_count, courses, remark,
		       amount_fen, status, COALESCE(process_status,'pending'), pay_method, pay_type,
		       trade_no, agent_id, created_at, paid_at
		FROM orders`+where+` ORDER BY created_at DESC LIMIT 5000`, listArgs...)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()

	buf := &strings.Builder{}
	buf.WriteString("\ufeff") // BOM
	cw := csv.NewWriter(buf)
	cw.Write([]string{"下单时间", "订单号", "平台", "账号", "门数", "金额(元)", "支付状态",
		"处理状态", "支付方式", "渠道", "交易号", "代理ID", "备注"})
	for rows.Next() {
		m := scanOrderRow(rows)
		if m == nil {
			continue
		}
		exported++
		createdAt := m["created_at"].(float64)
		processStatus := ""
		if ps, ok := m["process_status"].(string); ok {
			processStatus = ps
		}
		cw.Write([]string{
			time.Unix(int64(createdAt), 0).Format("2006-01-02 15:04:05"),
			csvSafe(str(m["order_id"])), csvSafe(str(m["platform"])), csvSafe(str(m["username"])),
			fmt.Sprintf("%v", m["course_count"]),
			fmt.Sprintf("%.2f", float64(toI64(m["amount_fen"]))/100),
			csvSafe(str(m["status"])), csvSafe(processStatus),
			csvSafe(str(m["pay_method"])), csvSafe(str(m["pay_type"])), csvSafe(str(m["trade_no"])),
			fmt.Sprintf("%v", m["agent_id"]),
			csvSafe(strings.ReplaceAll(str(m["remark"]), "\n", " ")),
		})
	}
	if total > exported && exported == exportLimit { // 截断提示：不能让管理员误以为导出了全部
		cw.Write([]string{"提示", fmt.Sprintf("符合条件的订单共 %d 条，本次仅导出最近 %d 条；如需更多请缩小筛选范围后分批导出", total, exported)})
	}
	cw.Flush()
	w.Header().Set("Content-Type", "text/csv; charset=utf-8")
	w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=orders_%s.csv",
		time.Now().Format("20060102_150405")))
	w.Write([]byte(buf.String()))
}

// ---------------- 平台价格 ----------------

// HandleAdminPrices GET /api/admin/prices —— 全平台价格一览（含未设置的默认价）。
func HandleAdminPrices(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	set, _ := store.AllPlatformPrices()
	items := []map[string]interface{}{}
	for _, name := range platformNames {
		fen := int64(config.CoursePriceFen)
		source := "default"
		if v, ok := set[name]; ok && v > 0 {
			fen = v
			source = "custom"
		}
		items = append(items, map[string]interface{}{
			"platform": name, "price_fen": fen,
			"default_fen": int64(config.CoursePriceFen), "source": source,
		})
	}
	writeJSON(w, 200, map[string]interface{}{"items": items})
}

// HandleAdminSetPrice POST /api/admin/prices/{platform} {"price_yuan": 3.5}
// price_yuan <=0 表示恢复默认价（删除覆盖）。
func HandleAdminSetPrice(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	platform := r.PathValue("platform")
	valid := false
	for _, n := range platformNames {
		if n == platform {
			valid = true
			break
		}
	}
	if !valid {
		errJSON(w, 400, "未知平台")
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
		errJSON(w, 400, "价格需在 0 ~ 10000 元之间（0=恢复默认）")
		return
	}
	fen := int64(yuan*100 + 0.5)
	if err := store.SetPlatformPrice(platform, fen); err != nil {
		logger.Err(err, "set price failed", "platform", platform)
		errJSON(w, 500, "保存失败，请稍后再试")
		return
	}
	audit("price.set", fmt.Sprintf(`{"platform":%q,"price_fen":%d}`, platform, fen))
	logger.Infof("price changed: platform=%s fen=%d", platform, fen)
	writeJSON(w, 200, map[string]interface{}{
		"platform": platform, "price_fen": fen, "source": map[bool]string{true: "default", false: "custom"}[fen <= 0],
	})
}

// ---------------- 综合网课（more_products）售价管理 ----------------

// HandleAdminMoreProducts GET /api/admin/more-products?q=&fenlei=&page=&page_size=
// 综合网课同步商品列表：展示进货价(base_yuan)与当前售价(price_yuan)，支持名称搜索与按模块分类筛选。
// 仅供管理端改价用；用户端列表走公开的 /api/more-products。
func HandleAdminMoreProducts(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	q := strings.TrimSpace(r.URL.Query().Get("q"))
	fenlei := strings.TrimSpace(r.URL.Query().Get("fenlei"))
	page := clamp(queryInt(r, "page", 1), 1, 1<<30)
	pageSize := clamp(queryInt(r, "page_size", 20), 1, 200)

	where := " WHERE 1=1"
	var args []interface{}
	if q != "" {
		where += " AND name LIKE ?"
		args = append(args, "%"+q+"%")
	}
	if fenlei != "" {
		where += " AND fenlei=?"
		args = append(args, fenlei)
	}
	var total int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM more_products`+where, args...).Scan(&total)
	listArgs := append(append([]interface{}{}, args...), pageSize, (page-1)*pageSize)
	rows, err := store.DB.Query(
		`SELECT cid, name, fenlei, price_yuan, base_yuan, price_override, status
		 FROM more_products`+where+` ORDER BY fenlei, name LIMIT ? OFFSET ?`, listArgs...)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()
	items := []map[string]interface{}{}
	for rows.Next() {
		var cid int64
		var name, f string
		var price, base float64
		var over, status int
		if rows.Scan(&cid, &name, &f, &price, &base, &over, &status) == nil {
			items = append(items, map[string]interface{}{
				"cid": cid, "name": name, "fenlei": f,
				"price_yuan": price, "base_yuan": base,
				"price_override": over == 1, "status": status,
			})
		}
	}
	writeJSON(w, 200, map[string]interface{}{
		"total": total, "page": page, "page_size": pageSize, "items": items,
	})
}

// ---- 前台自定义分类 ----

// HandleAdminFrontCategories GET /api/admin/front-categories
func HandleAdminFrontCategories(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	items, err := frontcat.List()
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]interface{}{"items": items})
}

// HandleAdminFrontCategoryCreate POST /api/admin/front-categories {"name":"学习通","sort_order":0,"price_mult":1.5}
func HandleAdminFrontCategoryCreate(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	var body struct {
		Name      string   `json:"name"`
		SortOrder int      `json:"sort_order"`
		PriceMult *float64 `json:"price_mult"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	mult := 1.0
	if body.PriceMult != nil {
		mult = *body.PriceMult
	}
	cat, err := frontcat.Create(body.Name, body.SortOrder, mult)
	if err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	audit("frontcat.create", fmt.Sprintf(`{"id":%d,"name":%q,"price_mult":%v}`, cat.ID, cat.Name, cat.PriceMult))
	writeJSON(w, 200, cat)
}

// HandleAdminFrontCategoryUpdate PUT /api/admin/front-categories/{id}
func HandleAdminFrontCategoryUpdate(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "id")
	if !ok {
		return
	}
	var body struct {
		Name      string   `json:"name"`
		SortOrder *int     `json:"sort_order"`
		PriceMult *float64 `json:"price_mult"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	if err := frontcat.Update(id, body.Name, body.SortOrder, body.PriceMult); err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	audit("frontcat.update", fmt.Sprintf(`{"id":%d,"name":%q}`, id, body.Name))
	writeJSON(w, 200, map[string]interface{}{"ok": true})
}

// HandleAdminFrontCategoryDelete DELETE /api/admin/front-categories/{id}
func HandleAdminFrontCategoryDelete(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "id")
	if !ok {
		return
	}
	if err := frontcat.Delete(id); err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	audit("frontcat.delete", fmt.Sprintf(`{"id":%d}`, id))
	writeJSON(w, 200, map[string]interface{}{"ok": true})
}

// HandleAdminFrontCategoryItems GET /api/admin/front-categories/{id}/items
// 返回 cids（勾选回填）+ items（详情：售价/状态/介绍，供分类内上下架与改介绍）。
func HandleAdminFrontCategoryItems(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "id")
	if !ok {
		return
	}
	cids, err := frontcat.ItemCIDs(id)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	items, err := frontcat.ItemsDetail(id)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]interface{}{"cids": cids, "items": items})
}

// HandleAdminFrontCategoryAddItems POST /api/admin/front-categories/{id}/items {"cids":[1,2]}
func HandleAdminFrontCategoryAddItems(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "id")
	if !ok {
		return
	}
	var body struct {
		CIDs []int64 `json:"cids"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	if err := frontcat.AddItems(id, body.CIDs); err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	audit("frontcat.items.add", fmt.Sprintf(`{"id":%d,"n":%d}`, id, len(body.CIDs)))
	writeJSON(w, 200, map[string]interface{}{"ok": true})
}

// HandleAdminFrontCategoryRemoveItems POST /api/admin/front-categories/{id}/items/remove {"cids":[1,2]}
func HandleAdminFrontCategoryRemoveItems(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "id")
	if !ok {
		return
	}
	var body struct {
		CIDs []int64 `json:"cids"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	if err := frontcat.RemoveItems(id, body.CIDs); err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	audit("frontcat.items.remove", fmt.Sprintf(`{"id":%d,"n":%d}`, id, len(body.CIDs)))
	writeJSON(w, 200, map[string]interface{}{"ok": true})
}

// HandleAdminMoreFenlei GET /api/admin/more-fenlei —— 货源分类列表（名称/上架数/价区间/是否已删）。
func HandleAdminMoreFenlei(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	items, err := appsync.ListFenleiStats()
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	writeJSON(w, 200, map[string]interface{}{"items": items})
}

// HandleAdminHideMoreFenlei POST /api/admin/more-fenlei/{fenlei}/hide —— 删除分类（前台不显示，商品下架）。
func HandleAdminHideMoreFenlei(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	fenlei := strings.TrimSpace(r.PathValue("fenlei"))
	if fenlei == "" {
		err422(w)
		return
	}
	if err := appsync.HideFenlei(fenlei); err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	audit("more.fenlei.hide", fmt.Sprintf(`{"fenlei":%q}`, fenlei))
	writeJSON(w, 200, map[string]interface{}{"ok": true, "fenlei": fenlei, "hidden": true})
}

// HandleAdminShowMoreFenlei POST /api/admin/more-fenlei/{fenlei}/show —— 恢复分类并上架有价商品。
func HandleAdminShowMoreFenlei(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	fenlei := strings.TrimSpace(r.PathValue("fenlei"))
	if fenlei == "" {
		err422(w)
		return
	}
	if err := appsync.ShowFenlei(fenlei); err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	audit("more.fenlei.show", fmt.Sprintf(`{"fenlei":%q}`, fenlei))
	writeJSON(w, 200, map[string]interface{}{"ok": true, "fenlei": fenlei, "hidden": false})
}

// HandleAdminSetMoreProductStatus POST /api/admin/more-products/{cid}/status {"status":0|1}
func HandleAdminSetMoreProductStatus(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	cid, ok := pathInt64(w, r, "cid")
	if !ok {
		return
	}
	var body struct {
		Status *int `json:"status"`
	}
	if !readJSON(w, r, &body) || body.Status == nil {
		err422(w)
		return
	}
	st := *body.Status
	if err := appsync.SetProductStatus(cid, st); err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	action := "more.status.off"
	if st == 1 {
		action = "more.status.on"
	}
	audit(action, fmt.Sprintf(`{"cid":%d,"status":%d}`, cid, st))
	writeJSON(w, 200, map[string]interface{}{"cid": cid, "status": st})
}

// HandleAdminSetMoreProductContent POST /api/admin/more-products/{cid}/content {"content":"..."}
// 空 content 表示取消自定义介绍（下次同步恢复上游文案）。
func HandleAdminSetMoreProductContent(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	cid, ok := pathInt64(w, r, "cid")
	if !ok {
		return
	}
	var body struct {
		Content *string `json:"content"`
	}
	if !readJSON(w, r, &body) || body.Content == nil {
		err422(w)
		return
	}
	if err := appsync.SetProductContent(cid, *body.Content); err != nil {
		errJSON(w, 400, err.Error())
		return
	}
	audit("more.content.set", fmt.Sprintf(`{"cid":%d,"n":%d}`, cid, len([]rune(strings.TrimSpace(*body.Content)))))
	var content string
	var over int
	_ = store.DB.QueryRow(`SELECT content, COALESCE(content_override,0) FROM more_products WHERE cid=?`, cid).Scan(&content, &over)
	writeJSON(w, 200, map[string]interface{}{
		"cid": cid, "content": content, "content_override": over == 1,
	})
}

// HandleAdminSetMoreProductPrice POST /api/admin/more-products/{cid}/price {"price_yuan": 12.5}
// price_yuan：>0 自定义售价；<=0 恢复天河默认价（price_yuan 回到 base_yuan 并清覆盖标记）。
// 只改本系统的结算/展示价，下单仍按原 cid/kcname/kcid 提交天河，不影响上游扣款。
func HandleAdminSetMoreProductPrice(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	cid, ok := pathInt64(w, r, "cid")
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
		errJSON(w, 400, "价格需在 0 ~ 10000 元之间（0=恢复上游默认价）")
		return
	}
	var name string
	_ = store.DB.QueryRow(`SELECT name FROM more_products WHERE cid=?`, cid).Scan(&name)
	if yuan > 0 {
		// 有售价即可上架展示；挂载关系仍由前台分类控制
		if _, err := store.DB.Exec(`UPDATE more_products SET price_yuan=?, price_override=1, status=1 WHERE cid=?`, yuan, cid); err != nil {
			errJSON(w, 500, "保存失败，请稍后再试")
			return
		}
		audit("more.price.set", fmt.Sprintf(`{"cid":%d,"name":%q,"price_yuan":%v}`, cid, name, yuan))
	} else {
		res, err := store.DB.Exec(
			`UPDATE more_products SET price_yuan=base_yuan, price_override=0 WHERE cid=? AND base_yuan>0`, cid)
		if err != nil {
			errJSON(w, 500, "保存失败，请稍后再试")
			return
		}
		if n, _ := res.RowsAffected(); n == 0 {
			errJSON(w, 400, "该商品暂无上游默认价可恢复")
			return
		}
		audit("more.price.reset", fmt.Sprintf(`{"cid":%d,"name":%q}`, cid, name))
	}
	// 售价已变：立即让用户端商品列表缓存失效，避免展示旧价、下单按新价扣款。
	productcache.Invalidate()
	// 回读最新值返回（含覆盖标记）
	var newPrice, base float64
	var over int
	_ = store.DB.QueryRow(`SELECT price_yuan, base_yuan, price_override FROM more_products WHERE cid=?`, cid).Scan(&newPrice, &base, &over)
	writeJSON(w, 200, map[string]interface{}{
		"cid": cid, "price_yuan": newPrice, "base_yuan": base, "price_override": over == 1,
	})
}

// ---------------- 充值码 ----------------

// HandleAdminListCodes GET /api/admin/redeem-codes
func HandleAdminListCodes(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	statusS := r.URL.Query().Get("status")
	page := clamp(queryInt(r, "page", 1), 1, 1<<30)
	pageSize := clamp(queryInt(r, "page_size", 20), 1, 100)

	where := ""
	var args []interface{}
	if statusS == "unused" || statusS == "used" || statusS == "disabled" {
		where = " WHERE c.status=?"
		args = append(args, statusS)
	}
	var total int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM redeem_codes c`+where, args...).Scan(&total)

	listArgs := append(append([]interface{}{}, args...), pageSize, (page-1)*pageSize)
	rows, err := store.DB.Query(`
		SELECT c.code, c.points, c.status, c.used_by, COALESCE(u.username,''), c.used_at, c.created_at, c.note
		FROM redeem_codes c LEFT JOIN agent_users u ON u.id=c.used_by`+
		where+` ORDER BY c.created_at DESC, c.code LIMIT ? OFFSET ?`, listArgs...)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()
	items := []map[string]interface{}{}
	for rows.Next() {
		var code, status, usedByName, note string
		var points, usedBy int64
		var usedAt sql.NullFloat64
		var createdAt float64
		if err := rows.Scan(&code, &points, &status, &usedBy, &usedByName, &usedAt, &createdAt, &note); err != nil {
			continue
		}
		item := map[string]interface{}{
			"code": code, "points": points, "status": status, "used_by": usedBy,
			"used_by_name": usedByName, "created_at": createdAt, "note": note, "used_at": nil,
		}
		if usedAt.Valid {
			item["used_at"] = usedAt.Float64
		}
		items = append(items, item)
	}
	writeJSON(w, 200, map[string]interface{}{
		"total": total, "page": page, "page_size": pageSize, "items": items,
	})
}

// HandleAdminGenerateCodes POST /api/admin/redeem-codes
func HandleAdminGenerateCodes(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	var body struct {
		Count  *int64 `json:"count"`
		Points *int64 `json:"points"`
		Note   string `json:"note"`
	}
	if !readJSON(w, r, &body) || body.Count == nil || body.Points == nil {
		err422(w)
		return
	}
	count := clamp(int(*body.Count), 1, 200)
	points := *body.Points
	if points < 1 || points > 100000 {
		err422(w)
		return
	}
	note := cutStr(strings.TrimSpace(body.Note), 200)

	codes, gerr := ledger.GenerateCodes(int64(count), points, note)
	if gerr != nil {
		errJSON(w, 500, gerr.Error())
		return
	}
	audit("redeem.generate", fmt.Sprintf(`{"count":%d,"points":%d,"note":%q}`, count, points, note))
	writeJSON(w, 201, map[string]interface{}{"codes": codes, "points": points})
}

// HandleAdminDisableCode POST /api/admin/redeem-codes/{code}/disable
func HandleAdminDisableCode(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	code := strings.ToUpper(r.PathValue("code"))
	if derr := ledger.DisableCode(code); derr != nil {
		errJSON(w, 400, derr.Error())
		return
	}
	audit("redeem.disable", fmt.Sprintf(`{"code":%q}`, code))
	writeJSON(w, 200, map[string]bool{"ok": true})
}

// HandleAdminExportCodes GET /api/admin/redeem-codes/export.csv
func HandleAdminExportCodes(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	statusS := r.URL.Query().Get("status")
	where := ""
	var args []interface{}
	if statusS == "unused" || statusS == "used" || statusS == "disabled" {
		where = " WHERE status=?"
		args = append(args, statusS)
	}
	var total int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM redeem_codes`+where, args...).Scan(&total)
	rows, err := store.DB.Query(
		`SELECT code, points, status, used_by, used_at, note FROM redeem_codes`+
			where+` ORDER BY created_at DESC LIMIT 5000`, args...)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()
	buf := &strings.Builder{}
	buf.WriteString("\ufeff")
	cw := csv.NewWriter(buf)
	cw.Write([]string{"充值码", "面值", "状态", "使用者ID", "兑换时间", "备注"})
	for rows.Next() {
		var code, status, note string
		var points, usedBy int64
		var usedAt sql.NullFloat64
		if rows.Scan(&code, &points, &status, &usedBy, &usedAt, &note) != nil {
			continue
		}
		usedAtStr := ""
		if usedAt.Valid {
			usedAtStr = time.Unix(int64(usedAt.Float64), 0).Format("2006-01-02 15:04:05")
		}
		cw.Write([]string{code, fmt.Sprintf("%d", points), status, fmt.Sprintf("%d", usedBy), usedAtStr, csvSafe(note)})
	}
	if total > 5000 { // 截断提示
		cw.Write([]string{"提示", fmt.Sprintf("共 %d 个充值码，本次仅导出最近 5000 个；如需更多请按状态筛选后分批导出", total)})
	}
	cw.Flush()
	w.Header().Set("Content-Type", "text/csv; charset=utf-8")
	w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=redeem_codes_%s.csv",
		time.Now().Format("20060102_150405")))
	w.Write([]byte(buf.String()))
}

// ---------------- 流水与审计 ----------------

// HandleAdminPointLogs GET /api/admin/point-logs?agent_id=
func HandleAdminPointLogs(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	agentID := queryInt(r, "agent_id", 0)
	page := clamp(queryInt(r, "page", 1), 1, 1<<30)
	pageSize := clamp(queryInt(r, "page_size", 50), 1, 200)

	where := ""
	var countArgs []interface{}
	if agentID > 0 {
		where = " WHERE p.agent_id=?"
		countArgs = append(countArgs, agentID)
	}
	var total int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM point_logs p`+where, countArgs...).Scan(&total)

	listArgs := append(append([]interface{}{}, countArgs...), pageSize, (page-1)*pageSize)
	// 用户名缺失（用户已删除）在 Go 层补 "#id"：不写 '#'||agent_id——
	// MySQL 的 || 是逻辑或（结果 0/1），SQLite 才是字符串拼接，两边语义不同。
	rows, err := store.DB.Query(`
		SELECT p.id, p.agent_id, u.username, p.delta, p.balance_after,
		       p.reason, p.order_id, p.created_at
		FROM point_logs p LEFT JOIN agent_users u ON u.id=p.agent_id`+where+
		` ORDER BY p.id DESC LIMIT ? OFFSET ?`, listArgs...)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()
	items := []map[string]interface{}{}
	for rows.Next() {
		var id, agentIDCol int64
		var delta, balanceAfter float64 // 小数价格会产生 -0.6 等小数流水（INTEGER 列存 REAL）
		var username sql.NullString
		var reason, orderID string
		var createdAt float64
		if rows.Scan(&id, &agentIDCol, &username, &delta, &balanceAfter, &reason, &orderID, &createdAt) != nil {
			continue
		}
		name := username.String
		if name == "" {
			name = "#" + strconv.FormatInt(agentIDCol, 10)
		}
		items = append(items, map[string]interface{}{
			"id": id, "agent_id": agentIDCol, "username": name, "delta": delta,
			"balance_after": balanceAfter, "reason": reason, "order_id": orderID, "created_at": createdAt,
		})
	}
	writeJSON(w, 200, map[string]interface{}{
		"total": total, "page": page, "page_size": pageSize, "items": items,
	})
}

// HandleAdminLoginLogs GET /api/admin/login-logs —— 登录日志（最近500条内倒序）。
func HandleAdminLoginLogs(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	page := clamp(queryInt(r, "page", 1), 1, 1<<30)
	pageSize := clamp(queryInt(r, "page_size", 50), 1, 200)
	var total int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM login_logs`).Scan(&total)
	rows, err := store.DB.Query(
		`SELECT id, username, ip, success, created_at FROM login_logs ORDER BY id DESC LIMIT ? OFFSET ?`,
		pageSize, (page-1)*pageSize)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()
	items := []map[string]interface{}{}
	for rows.Next() {
		var id, success int64
		var username, ip string
		var createdAt float64
		if rows.Scan(&id, &username, &ip, &success, &createdAt) == nil {
			items = append(items, map[string]interface{}{
				"id": id, "username": username, "ip": ip,
				"success": success == 1, "created_at": createdAt,
			})
		}
	}
	writeJSON(w, 200, map[string]interface{}{
		"total": total, "page": page, "page_size": pageSize, "items": items,
	})
}

// HandleAdminAuditLogs GET /api/admin/audit-logs
func HandleAdminAuditLogs(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	page := clamp(queryInt(r, "page", 1), 1, 1<<30)
	pageSize := clamp(queryInt(r, "page_size", 50), 1, 200)
	var total int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM admin_audit_logs`).Scan(&total)
	rows, err := store.DB.Query(
		`SELECT id, action, detail, created_at FROM admin_audit_logs ORDER BY id DESC LIMIT ? OFFSET ?`,
		pageSize, (page-1)*pageSize)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()
	items := []map[string]interface{}{}
	for rows.Next() {
		var id int64
		var action, detail string
		var createdAt float64
		if rows.Scan(&id, &action, &detail, &createdAt) == nil {
			items = append(items, map[string]interface{}{
				"id": id, "action": action, "detail": detail, "created_at": createdAt,
			})
		}
	}
	writeJSON(w, 200, map[string]interface{}{
		"total": total, "page": page, "page_size": pageSize, "items": items,
	})
}

// ---------------- 小工具 ----------------

func queryInt(r *http.Request, key string, def int) int {
	v := r.URL.Query().Get(key)
	if v == "" {
		return def
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		return def
	}
	return n
}

func queryFloat(r *http.Request, key string, def float64) float64 {
	v := r.URL.Query().Get(key)
	if v == "" {
		return def
	}
	f, err := strconv.ParseFloat(v, 64)
	if err != nil {
		return def
	}
	return f
}

func clamp(n, lo, hi int) int {
	if n < lo {
		return lo
	}
	if n > hi {
		return hi
	}
	return n
}

func pathInt64(w http.ResponseWriter, r *http.Request, name string) (int64, bool) {
	n, err := strconv.ParseInt(r.PathValue(name), 10, 64)
	if err != nil || n <= 0 {
		errJSON(w, 404, "不存在")
		return 0, false
	}
	return n, true
}

// csvSafe 防 Excel/Sheets 公式注入：以 = + - @ 开头的文本前置单引号。
// （账号/备注等来自用户输入，导出后可能被表格软件当公式执行）
func csvSafe(s string) string {
	if s == "" {
		return s
	}
	switch s[0] {
	case '=', '+', '-', '@', '\t', '\r':
		return "'" + s
	}
	return s
}

func str(v interface{}) string {
	if s, ok := v.(string); ok {
		return s
	}
	return fmt.Sprintf("%v", v)
}

func toI64(v interface{}) int64 {
	switch n := v.(type) {
	case int64:
		return n
	case int:
		return int64(n)
	case float64:
		return int64(n)
	default:
		i, _ := strconv.ParseInt(fmt.Sprintf("%v", v), 10, 64)
		return i
	}
}

func parseCoursesJSON(s string) []map[string]interface{} {
	out := []map[string]interface{}{}
	if json.Unmarshal([]byte(s), &out) != nil {
		return []map[string]interface{}{}
	}
	return out
}

var _ = rand.Read
