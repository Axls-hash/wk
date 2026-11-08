// Package ledger 积分账务：余额调整 / 积分支付 / 充值码核销与生成。
//
// 设计要点：
//   - 所有余额变动都是「条件原子 UPDATE」（WHERE points >= 扣减额），并发无双花
//   - 多语句操作内部自管事务：调用方传 *sql.DB 即可，无需自己包事务
//     （规避 SQLite 单连接下事务内嵌套池外查询的自我死锁问题）
//   - web 层只做参数校验与响应组装，账务规则全部收拢在本包
package ledger

import (
	"crypto/rand"
	"database/sql"
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"

	"daili-go/internal/securestore"
	"daili-go/internal/store"
)

// Runner 统一 *sql.DB 与 *sql.Tx。
type Runner interface {
	QueryRow(query string, args ...interface{}) *sql.Row
	Exec(query string, args ...interface{}) (sql.Result, error)
}

func nowF() float64 { return float64(time.Now().UnixNano()) / 1e9 }

// round2 积分精确到分（0.01）：消除 float64 累加尾差。
// 所有入库的余额/流水值必须经过此函数。
func round2(v float64) float64 { return math.Round(v*100) / 100 }

// ---------------- 余额调整 ----------------

// AdjustBalance 原子调整积分并写流水。delta 正充负扣；余额不足返回错误。
// 返回新余额。
func AdjustBalance(agentID int64, delta float64, reason string) (float64, error) {
	if delta > 1e9 || delta < -1e9 || delta == 0 {
		return 0, fmt.Errorf("变动值超出范围")
	}
	tx, err := store.DB.Begin()
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()
	balance, err := adjustBalanceTx(tx, agentID, delta, reason)
	if err != nil {
		return 0, err
	}
	if err = tx.Commit(); err != nil {
		return 0, err
	}
	return balance, nil
}

// adjustBalanceTx 在给定事务内调整（供组合操作复用）。
func adjustBalanceTx(tx *sql.Tx, agentID int64, delta float64, reason string) (float64, error) {
	return adjustBalanceOn(tx, agentID, delta, reason)
}

// adjustBalanceOn 在任意 Runner 上执行条件原子调整 + 流水。
// 注意：调用方保证多语句场景传入的是同一个事务。
func adjustBalanceOn(r Runner, agentID int64, delta float64, reason string) (float64, error) {
	var balance float64
	err := r.QueryRow(`SELECT points FROM agent_users WHERE id=?`, agentID).Scan(&balance)
	if err != nil {
		return 0, fmt.Errorf("代理不存在")
	}
	if delta < 0 && balance+delta < -1e-9 {
		return 0, fmt.Errorf("积分余额不足")
	}

	// 条件原子更新：加扣统一为一条语句，WHERE 保证结果不为负
	// （并发下抢不到的请求 rowcount=0 → 余额不足；float 用 1e-9 容差）
	res, err := r.Exec(
		`UPDATE agent_users SET points=ROUND((points+?)*100)/100 WHERE id=? AND points+? >= -1e-9`,
		delta, agentID, delta)
	if err != nil {
		return 0, err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return 0, fmt.Errorf("积分余额不足")
	}
	if err = r.QueryRow(`SELECT points FROM agent_users WHERE id=?`, agentID).Scan(&balance); err != nil {
		return 0, err
	}
	balance = round2(balance)
	if _, err = r.Exec(
		`INSERT INTO point_logs(agent_id, delta, balance_after, reason, order_id, created_at)
		 VALUES(?,?,?,?, '', ?)`,
		agentID, round2(delta), balance, truncate(reason, 200), nowF()); err != nil {
		return 0, err
	}
	return balance, nil
}

// ---------------- 积分支付下单 ----------------

// PayOrderWithPoints 积分支付订单：建单 + 条件原子扣款 + 标记 paid + 流水，
// 全程同一事务；余额不足整体回滚不留孤儿单。返回新余额。
// 积分支持小数：cost = amountFen/100（1 积分 = 1 元，精确到分）。
func PayOrderWithPoints(orderID, platform, username, password, school, coursesJSON, remark string,
	courseCount, amountFen int64, agentID int64) (float64, error) {
	encryptedPassword, err := securestore.Encrypt(password)
	if err != nil {
		return 0, fmt.Errorf("encrypt order credential: %w", err)
	}

	// 金额兜底：0 元/0 门订单一律拒绝（防止任何调用路径出现 0 积分建单）
	if amountFen <= 0 || courseCount <= 0 {
		return 0, fmt.Errorf("订单金额异常")
	}

	cost := round2(float64(amountFen) / 100.0)

	tx, err := store.DB.Begin()
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()

	res, err := tx.Exec(
		// process_status 直接在此事务内置 'processing'：与扣款同一事务，
		// 消除「扣款成功、标记处理中分开执行」中间崩溃丢单的窗口。
		`INSERT INTO orders(order_id, platform, username, password, school, courses, remark,
		   course_count, amount_fen, status, process_status, trade_no, pay_type, created_at, agent_id, pay_method)
		 VALUES(?,?,?,?,?,?,?,?,?,'pending','processing','','',?,?, 'points')`,
		orderID, platform, username, encryptedPassword, school, coursesJSON, remark,
		courseCount, amountFen, nowF(), agentID)
	if err != nil {
		return 0, err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return 0, fmt.Errorf("建单失败")
	}

	// 条件原子扣款
	res, err = tx.Exec(
		`UPDATE agent_users SET points=ROUND((points-?)*100)/100 WHERE id=? AND points-? >= -1e-9`,
		cost, agentID, cost)
	if err != nil {
		return 0, err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		var bal float64
		_ = tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, agentID).Scan(&bal)
		return 0, fmt.Errorf("积分不足（需要 %s，余额 %s）", fmtPts(cost), fmtPts(bal))
	}
	var newBalance float64
	_ = tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, agentID).Scan(&newBalance)
	newBalance = round2(newBalance)

	now := nowF()
	if _, err = tx.Exec(
		`UPDATE orders SET status='paid', pay_type='points', paid_at=? WHERE order_id=?`,
		now, orderID); err != nil {
		return 0, err
	}
	if _, err = tx.Exec(
		`INSERT INTO point_logs(agent_id, delta, balance_after, reason, order_id, created_at)
		 VALUES(?,?,?,?,?,?)`,
		agentID, -cost, newBalance, "下单支付 "+truncate(orderID, 8), orderID, now); err != nil {
		return 0, err
	}
	if err = tx.Commit(); err != nil {
		return 0, err
	}
	return newBalance, nil
}

// ---------------- 实习打卡下单 ----------------

// SxdkOrder 实习打卡订单的建单字段（与 sxdk_orders 表一一对应）。
type SxdkOrder struct {
	OrderID       string
	UserID        int64
	Platform      string
	Phone         string
	Password      string
	Name          string
	Address       string
	ProjectName   string
	SchoolText    string
	CheckWeek     string
	EndTime       string
	UpCheckTime   string
	DownCheckTime string
	DayPaper      int
	WeekPaper     int
	MonthPaper    int
	RunType       int
	Days          int
	SellYuan      float64
	AmountFen     int64
}

// PaySxdkOrderWithPoints 实习打卡下单：建 sxdk_orders + 条件原子扣款 同一事务，
// 与 PayOrderWithPoints 同一套一致性保障——余额不足或建单失败整体回滚，
// 不留孤儿单、不锁积分（消除先前「扣款/落库/退款三事务」的崩溃丢单窗口）。
// 返回新余额。
func PaySxdkOrderWithPoints(o SxdkOrder) (float64, error) {
	encryptedPassword, err := securestore.Encrypt(o.Password)
	if err != nil {
		return 0, fmt.Errorf("encrypt sxdk credential: %w", err)
	}
	cost := round2(float64(o.AmountFen) / 100.0)

	tx, err := store.DB.Begin()
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()

	if _, err := tx.Exec(
		`INSERT INTO sxdk_orders(order_id,user_id,platform,phone,password,name,address,project_name,school_text,
		   check_week,end_time,up_check_time,down_check_time,day_paper,week_paper,month_paper,run_type,
		   days,sell_yuan,amount_fen,status,code,remark,created_at)
		 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',-1,'',?)`,
		o.OrderID, o.UserID, o.Platform, o.Phone, encryptedPassword, o.Name, o.Address, o.ProjectName, o.SchoolText,
		o.CheckWeek, o.EndTime, o.UpCheckTime, o.DownCheckTime,
		o.DayPaper, o.WeekPaper, o.MonthPaper, o.RunType,
		o.Days, o.SellYuan, o.AmountFen, nowF()); err != nil {
		return 0, err
	}

	// 条件原子扣款
	res, err := tx.Exec(
		`UPDATE agent_users SET points=ROUND((points-?)*100)/100 WHERE id=? AND points-? >= -1e-9`,
		cost, o.UserID, cost)
	if err != nil {
		return 0, err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		var bal float64
		_ = tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, o.UserID).Scan(&bal)
		return 0, fmt.Errorf("积分不足（需要 %s，余额 %s）", fmtPts(cost), fmtPts(bal))
	}
	var newBalance float64
	_ = tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, o.UserID).Scan(&newBalance)
	newBalance = round2(newBalance)

	now := nowF()
	if _, err = tx.Exec(
		`INSERT INTO point_logs(agent_id, delta, balance_after, reason, order_id, created_at)
		 VALUES(?,?,?,?,?,?)`,
		o.UserID, -cost, newBalance, "实习打卡下单 "+o.Platform+" 天x"+strconv.Itoa(o.Days), o.OrderID, now); err != nil {
		return 0, err
	}
	if err = tx.Commit(); err != nil {
		return 0, err
	}
	return newBalance, nil
}

// ---------------- 我爱运动（syy）下单 ----------------

// SyyOrder 运动订单的建单字段（与 syy_orders 表一一对应）。
type SyyOrder struct {
	OrderID     string
	UserID      int64
	ProjectID   int64
	ProjectName string
	KM          float64
	UnitFen     int64
	AmountFen   int64
}

// PaySyyOrderWithPoints 运动下单：建 syy_orders（processing）+ 条件原子扣款 + 流水，
// 同一事务；余额不足或建单失败整体回滚。返回新余额。
func PaySyyOrderWithPoints(o SyyOrder) (float64, error) {
	if o.AmountFen <= 0 || o.KM <= 0 {
		return 0, fmt.Errorf("订单金额异常")
	}
	tx, err := store.DB.Begin()
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()

	if _, err := tx.Exec(
		`INSERT INTO syy_orders(order_id, user_id, project_id, project_name, km, unit_fen, amount_fen,
		   status, transaction_id, created_at, updated_at)
		 VALUES(?,?,?,?,?,?,?, 'processing', ?, ?, ?)`,
		o.OrderID, o.UserID, o.ProjectID, truncate(o.ProjectName, 100), o.KM, o.UnitFen, o.AmountFen,
		o.OrderID, nowF(), nowF()); err != nil {
		return 0, err
	}

	cost := round2(float64(o.AmountFen) / 100.0)
	res, err := tx.Exec(
		`UPDATE agent_users SET points=ROUND((points-?)*100)/100 WHERE id=? AND points-? >= -1e-9`,
		cost, o.UserID, cost)
	if err != nil {
		return 0, err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		var bal float64
		_ = tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, o.UserID).Scan(&bal)
		return 0, fmt.Errorf("积分不足（需要 %s，余额 %s）", fmtPts(cost), fmtPts(bal))
	}
	var newBalance float64
	_ = tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, o.UserID).Scan(&newBalance)
	newBalance = round2(newBalance)

	now := nowF()
	if _, err = tx.Exec(
		`INSERT INTO point_logs(agent_id, delta, balance_after, reason, order_id, created_at)
		 VALUES(?,?,?,?,?,?)`,
		o.UserID, -cost, newBalance, "我爱运动下单 "+truncate(o.ProjectName, 20)+" x"+fmtPts(o.KM), o.OrderID, now); err != nil {
		return 0, err
	}
	if err = tx.Commit(); err != nil {
		return 0, err
	}
	return newBalance, nil
}

// FailSyyOrderAndRefund 把仍在 processing 的我爱运动订单标记为 error 并原路退回积分。
// 状态翻转（条件更新）与退款在同一事务内：并发/重放只会退一次；
// 退款失败则整体回滚，订单保持 processing，后续启动重试仍有机会补退。
func FailSyyOrderAndRefund(orderID string, userID int64, reason string) error {
	tx, err := store.DB.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	var amountFen int64
	err = tx.QueryRow(
		`SELECT amount_fen FROM syy_orders WHERE order_id=? AND user_id=? AND status='processing'`,
		orderID, userID).Scan(&amountFen)
	if err == sql.ErrNoRows {
		return nil // 已处理过或订单不存在（重试幂等）
	}
	if err != nil {
		return err
	}
	res, err := tx.Exec(
		`UPDATE syy_orders SET status='error', remark=?, updated_at=?
		 WHERE order_id=? AND user_id=? AND status='processing'`,
		truncate(reason, 500), nowF(), orderID, userID)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return nil
	}
	cost := round2(float64(amountFen) / 100.0)
	if cost > 0 {
		if _, err = adjustBalanceOn(tx, userID, cost, "我爱运动下单失败退款 "+truncate(orderID, 8)); err != nil {
			return err
		}
	}
	return tx.Commit()
}

// ---------------- 病例（bingli）下单 ----------------

// BingOrder 病例订单的建单字段（与 bing_orders 表对应）。
// FieldsJSON 为明文 JSON，由本函数加密后落库（字段属于敏感医疗信息）。
type BingOrder struct {
	OrderID             string
	UserID              int64
	ApplicationID       string
	ApplicationName     string
	FieldsJSON          string
	AmountFen           int64
	UpstreamPricePoints float64
}

// PayBingOrderWithPoints 病例下单：建 bing_orders（processing）+ 条件原子扣款 + 流水，
// 同一事务；余额不足或建单失败整体回滚。返回新余额。
// external_request_id 固定为本站订单号，供上游幂等重试。
func PayBingOrderWithPoints(o BingOrder) (float64, error) {
	if o.AmountFen <= 0 {
		return 0, fmt.Errorf("订单金额异常")
	}
	encrypted, err := securestore.Encrypt(o.FieldsJSON)
	if err != nil {
		return 0, fmt.Errorf("encrypt bing fields: %w", err)
	}
	cost := round2(float64(o.AmountFen) / 100.0)

	tx, err := store.DB.Begin()
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()

	now := nowF()
	if _, err := tx.Exec(
		`INSERT INTO bing_orders(order_id, user_id, upstream_order_id, application_id, application_name,
		   fields_enc, amount_fen, upstream_price_points, status, external_request_id, created_at, updated_at)
		 VALUES(?,?, '', ?, ?, ?, ?, ?, 'processing', ?, ?, ?)`,
		o.OrderID, o.UserID, truncate(o.ApplicationID, 128), truncate(o.ApplicationName, 200),
		encrypted, o.AmountFen, round2(o.UpstreamPricePoints), o.OrderID, now, now); err != nil {
		return 0, err
	}

	res, err := tx.Exec(
		`UPDATE agent_users SET points=ROUND((points-?)*100)/100 WHERE id=? AND points-? >= -1e-9`,
		cost, o.UserID, cost)
	if err != nil {
		return 0, err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		var bal float64
		_ = tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, o.UserID).Scan(&bal)
		return 0, fmt.Errorf("积分不足（需要 %s，余额 %s）", fmtPts(cost), fmtPts(bal))
	}
	var newBalance float64
	_ = tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, o.UserID).Scan(&newBalance)
	newBalance = round2(newBalance)

	if _, err = tx.Exec(
		`INSERT INTO point_logs(agent_id, delta, balance_after, reason, order_id, created_at)
		 VALUES(?,?,?,?,?,?)`,
		o.UserID, -cost, newBalance, "病例下单 "+truncate(o.ApplicationName, 20), o.OrderID, now); err != nil {
		return 0, err
	}
	if err = tx.Commit(); err != nil {
		return 0, err
	}
	return newBalance, nil
}

// FailBingOrderAndRefund 把仍在 processing 的病例订单标记为 error 并原路退回积分。
// 条件更新保证并发/重复调用只退一次；订单已终态时直接返回 nil。
func FailBingOrderAndRefund(orderID string, userID int64, reason string) error {
	tx, err := store.DB.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	var amountFen int64
	err = tx.QueryRow(
		`SELECT amount_fen FROM bing_orders WHERE order_id=? AND user_id=? AND status='processing'`,
		orderID, userID).Scan(&amountFen)
	if err == sql.ErrNoRows {
		return nil // 已处理过或订单不存在
	}
	if err != nil {
		return err
	}
	res, err := tx.Exec(
		`UPDATE bing_orders SET status='error', render_error=?, updated_at=?
		 WHERE order_id=? AND user_id=? AND status='processing'`,
		truncate(reason, 500), nowF(), orderID, userID)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return nil
	}
	cost := round2(float64(amountFen) / 100.0)
	if cost > 0 {
		if _, err = adjustBalanceOn(tx, userID, cost, "病例下单失败退款 "+truncate(reason, 60)); err != nil {
			return err
		}
	}
	return tx.Commit()
}

// ---------------- 学习通自动签到（qd） ----------------

// QdOrder 自动签到订单的建单字段（与 qd_orders 表对应）。
// EncPass 为学习通密码的 AES-GCM 密文（qd 包加密后传入，本函数原样落库）。
type QdOrder struct {
	OrderID   string
	UserID    int64
	Phone     string
	EncPass   string
	Plan      string
	AmountFen int64
}

// PayQdOrderWithPoints 自动签到下单：建 qd_orders（processing）+ 条件原子扣款 + 流水，
// 同一事务；余额不足或建单失败整体回滚。返回新余额。
func PayQdOrderWithPoints(o QdOrder) (float64, error) {
	if o.AmountFen <= 0 {
		return 0, fmt.Errorf("订单金额异常")
	}
	if o.Phone == "" {
		return 0, fmt.Errorf("手机号不能为空")
	}
	cost := round2(float64(o.AmountFen) / 100.0)
	planName := "周卡"
	if o.Plan == "month" {
		planName = "月卡"
	}

	tx, err := store.DB.Begin()
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()

	now := nowF()
	if _, err := tx.Exec(
		`INSERT INTO qd_orders(order_id, user_id, phone, enc_pass, plan, engine_order_id,
		   token, status, engine_status, last_msg, end_at, amount_fen, created_at, updated_at)
		 VALUES(?,?,?,?,?, '', '', 'processing', '', '正在开通…', 0, ?, ?, ?)`,
		o.OrderID, o.UserID, truncate(o.Phone, 20), o.EncPass, truncate(o.Plan, 8),
		o.AmountFen, now, now); err != nil {
		return 0, err
	}

	res, err := tx.Exec(
		`UPDATE agent_users SET points=ROUND((points-?)*100)/100 WHERE id=? AND points-? >= -1e-9`,
		cost, o.UserID, cost)
	if err != nil {
		return 0, err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		var bal float64
		_ = tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, o.UserID).Scan(&bal)
		return 0, fmt.Errorf("积分不足（需要 %s，余额 %s）", fmtPts(cost), fmtPts(bal))
	}
	var newBalance float64
	_ = tx.QueryRow(`SELECT points FROM agent_users WHERE id=?`, o.UserID).Scan(&newBalance)
	newBalance = round2(newBalance)

	if _, err = tx.Exec(
		`INSERT INTO point_logs(agent_id, delta, balance_after, reason, order_id, created_at)
		 VALUES(?,?,?,?,?,?)`,
		o.UserID, -cost, newBalance, "自动签到开通 "+planName, o.OrderID, now); err != nil {
		return 0, err
	}
	if err = tx.Commit(); err != nil {
		return 0, err
	}
	return newBalance, nil
}

// FailQdOrderAndRefund 把仍在 processing 的自动签到订单标记为 error 并原路退回积分。
// 条件更新保证并发/重复调用只退一次；订单已终态时直接返回 nil。
func FailQdOrderAndRefund(orderID string, userID int64, reason string) error {
	tx, err := store.DB.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	var amountFen int64
	err = tx.QueryRow(
		`SELECT amount_fen FROM qd_orders WHERE order_id=? AND user_id=? AND status='processing'`,
		orderID, userID).Scan(&amountFen)
	if err == sql.ErrNoRows {
		return nil // 已处理过或订单不存在
	}
	if err != nil {
		return err
	}
	res, err := tx.Exec(
		`UPDATE qd_orders SET status='error', last_msg=?, updated_at=?
		 WHERE order_id=? AND user_id=? AND status='processing'`,
		truncate(reason, 500), nowF(), orderID, userID)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return nil
	}
	cost := round2(float64(amountFen) / 100.0)
	if cost > 0 {
		if _, err = adjustBalanceOn(tx, userID, cost, "自动签到开通失败退款 "+truncate(reason, 60)); err != nil {
			return err
		}
	}
	return tx.Commit()
}

// ---------------- 充值码 ----------------

// RedeemCode 兑换充值码：核销 + 到账同一事务，一码一用。
type RedeemError struct {
	Code int
	Msg  string
}

func (e *RedeemError) Error() string { return e.Msg }

func RedeemCode(agentID int64, rawCode string) (newBalance, points float64, err error) {
	tx, err := store.DB.Begin()
	if err != nil {
		return 0, 0, err
	}
	defer tx.Rollback()

	// 规范化：大写 + 去连字符/空格（用户手输常漏连字符）。
	// 库中可能存有旧格式的带连字符码，先查规范化形式，未命中再回退原样。
	code := normalizeCode(rawCode)
	var status string
	e := tx.QueryRow(`SELECT points, status FROM redeem_codes WHERE code=?`, code).
		Scan(&points, &status)
	if e == sql.ErrNoRows {
		legacy := strings.ToUpper(strings.TrimSpace(rawCode))
		if legacy != code {
			e = tx.QueryRow(`SELECT points, status FROM redeem_codes WHERE code=?`, legacy).
				Scan(&points, &status)
			if e == nil {
				code = legacy // 原子核销仍按库中的实际主键
			}
		}
	}
	switch {
	case e == sql.ErrNoRows:
		return 0, 0, &RedeemError{Code: 404, Msg: "充值码无效"}
	case e != nil:
		return 0, 0, e
	case status == "disabled":
		return 0, 0, &RedeemError{Code: 400, Msg: "该充值码已作废"}
	case status == "used":
		return 0, 0, &RedeemError{Code: 400, Msg: "该充值码已被使用"}
	}
	res, e := tx.Exec(
		`UPDATE redeem_codes SET status='used', used_by=?, used_at=? WHERE code=? AND status='unused'`,
		agentID, nowF(), code)
	if e != nil {
		return 0, 0, e
	}
	if n, _ := res.RowsAffected(); n == 0 { // 并发兑换只有一个成功
		return 0, 0, &RedeemError{Code: 400, Msg: "该充值码已被使用"}
	}
	newBalance, e = adjustBalanceOn(tx, agentID, points, "充值码兑换 "+code)
	if e != nil {
		return 0, 0, &RedeemError{Code: 400, Msg: e.Error()}
	}
	if e = tx.Commit(); e != nil {
		return 0, 0, e
	}
	return newBalance, points, nil
}

// GenerateCodes 批量生成未使用的充值码。返回生成的码列表。
func GenerateCodes(count int64, points int64, note string) ([]string, error) {
	existing := map[string]bool{}
	rows, err := store.DB.Query(`SELECT code FROM redeem_codes LIMIT 200000`)
	if err != nil {
		return nil, err
	}
	for rows.Next() {
		var c string
		if rows.Scan(&c) == nil {
			existing[c] = true
		}
	}
	rows.Close()

	codes := make([]string, 0, count)
	tx, err := store.DB.Begin()
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	now := nowF()
	attempts := 0
	for int64(len(codes)) < count {
		attempts++
		if attempts > int(count)*20+50 {
			return nil, fmt.Errorf("生成失败，请重试")
		}
		code := GenCode()
		if existing[code] {
			continue
		}
		existing[code] = true
		if _, err := tx.Exec(
			`INSERT INTO redeem_codes(code, points, status, used_by, created_at, note)
			 VALUES(?,?,'unused',0,?,?)`,
			code, points, now, truncate(note, 200)); err != nil {
			continue
		}
		codes = append(codes, code)
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return codes, nil
}

// DisableCode 作废未使用充值码。
func DisableCode(code string) error {
	code = strings.ToUpper(strings.TrimSpace(code))
	res, err := store.DB.Exec(
		`UPDATE redeem_codes SET status='disabled' WHERE code=? AND status='unused'`, code)
	if err != nil {
		return err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return fmt.Errorf("码不存在或已被使用/作废")
	}
	return nil
}

// normalizeCode 码规范化：去空格/连字符并转大写。
func normalizeCode(s string) string {
	s = strings.ToUpper(strings.TrimSpace(s))
	return strings.NewReplacer("-", "", " ", "").Replace(s)
}

// fmtPts 积分格式化：最多两位小数，去尾零。
func fmtPts(v float64) string {
	s := strconv.FormatFloat(v, 'f', 2, 64)
	s = strings.TrimRight(s, "0")
	return strings.TrimRight(s, ".")
}

// GenCode 16 位无易混字符随机串（拒绝采样均匀分布），统一无连字符存储；
// 展示层可用 FormatCode 分组。
func GenCode() string {
	const alphabet = "23456789ABCDEFGHJKMNPQRSTUVWXYZ"
	const maxValid = 248 // 31*8，丢弃 >=248 的随机字节避免偏差
	raw := make([]byte, 0, 16)
	buf := make([]byte, 32)
	for len(raw) < 16 {
		if _, err := rand.Read(buf); err != nil {
			continue
		}
		for _, b := range buf {
			if b >= maxValid {
				continue
			}
			raw = append(raw, alphabet[int(b)%len(alphabet)])
			if len(raw) == 16 {
				break
			}
		}
	}
	return string(raw)
}

// FormatCode 无连字符码 → XXXX-XXXX-XXXX-XXXX 展示格式（已分组的原样返回）。
func FormatCode(code string) string {
	if len(code) != 16 || strings.Contains(code, "-") {
		return code
	}
	return code[0:4] + "-" + code[4:8] + "-" + code[8:12] + "-" + code[12:16]
}

func truncate(s string, n int) string {
	b := []byte(s)
	if len(b) <= n {
		return s
	}
	for n > 0 && (b[n]&0xC0) == 0x80 {
		n--
	}
	return string(b[:n])
}
