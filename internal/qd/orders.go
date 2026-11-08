package qd

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"

	"daili-go/internal/ledger"
	"daili-go/internal/logger"
	"daili-go/internal/securestore"
	"daili-go/internal/store"
)

// 学习通手机号：5-15 位数字（与引擎侧校验一致）。
var phoneRe = regexp.MustCompile(`^\d{5,15}$`)

// 引擎订单状态 → 本站状态归一。
const (
	StatusProcessing = "processing"  // 与引擎对接中（本地已扣积分）
	StatusPendingOn  = "pending_on"  // 引擎单已建，等待登录上号（密码错误等）
	StatusActive     = "active"      // 监控中
	StatusDone       = "done"        // 服务期结束/订单完成
	StatusError      = "error"       // 建单失败（已退款）
)

// qdOrderRow 本地订单行。
type qdOrderRow struct {
	OrderID       string
	UserID        int64
	Phone         string
	EncPass       string
	Plan          string
	EngineOrderID string
	Token         string
	Status        string
	EngineStatus  string
	Name          string
	UID           string
	SignOK        int64
	SignFail      int64
	PushURL       string
	LastMsg       string
	EndAt         float64
	AmountFen     int64
	CreatedAt     float64
	UpdatedAt     float64
}

const orderColumns = `order_id, user_id, phone, COALESCE(enc_pass,''), plan,
	COALESCE(engine_order_id,''), COALESCE(token,''), status,
	COALESCE(engine_status,''), COALESCE(name,''), COALESCE(uid,''),
	sign_ok, sign_fail, COALESCE(push_url,''), COALESCE(last_msg,''), COALESCE(end_at,0),
	amount_fen, created_at, updated_at`

func scanOrder(sc interface{ Scan(...interface{}) error }) (*qdOrderRow, error) {
	var o qdOrderRow
	if err := sc.Scan(&o.OrderID, &o.UserID, &o.Phone, &o.EncPass, &o.Plan,
		&o.EngineOrderID, &o.Token, &o.Status,
		&o.EngineStatus, &o.Name, &o.UID,
		&o.SignOK, &o.SignFail, &o.PushURL, &o.LastMsg, &o.EndAt,
		&o.AmountFen, &o.CreatedAt, &o.UpdatedAt); err != nil {
		return nil, err
	}
	return &o, nil
}

func newQdOrderID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// OrderView 订单 JSON 视图（绝不包含密码/token）。
type OrderView struct {
	OrderID      string       `json:"order_id"`
	Phone        string       `json:"phone"`
	Plan         string       `json:"plan"`
	PlanName     string       `json:"plan_name"`
	Status       string       `json:"status"`
	EngineStatus string       `json:"engine_status,omitempty"`
	Name         string       `json:"name,omitempty"`
	UID          string       `json:"uid,omitempty"`
	SignOK       int64        `json:"sign_ok"`
	SignFail     int64        `json:"sign_fail"`
	PushURL      string       `json:"push_url,omitempty"`
	LastMsg      string       `json:"last_msg,omitempty"`
	EndAt        float64      `json:"end_at,omitempty"`
	PricePoints  float64      `json:"price_points"`
	Logs         []LogEntry   `json:"logs,omitempty"`
	Records      []SignRecord `json:"records,omitempty"`
	CreatedAt    float64      `json:"created_at"`
	UpdatedAt    float64      `json:"updated_at"`
}

// LogEntry 引擎实时日志条目。
type LogEntry struct {
	Time  string `json:"time"`
	Level string `json:"level"`
	Msg   string `json:"msg"`
}

// SignRecord 引擎签到记录条目。
type SignRecord struct {
	Time   string `json:"time"`
	Title  string `json:"title"`
	Course string `json:"course"`
}

// planName 卡型展示名。
func planName(plan string) string {
	if plan == PlanMonth {
		return "月卡（30 天）"
	}
	return "周卡（7 天）"
}

// hasLocalActiveOrder 本站是否已有该手机号的活跃订单（任意用户）。
func hasLocalActiveOrder(phone string) (string, bool) {
	if store.DB == nil {
		return "", false
	}
	var orderID string
	err := store.DB.QueryRow(
		`SELECT order_id FROM qd_orders WHERE phone=? AND status IN ('processing','pending_on','active')
		 ORDER BY created_at DESC LIMIT 1`, phone).Scan(&orderID)
	if err != nil {
		return "", false
	}
	return orderID, true
}

// CreateOrder 下单：校验 → chadan 预检 → 扣积分建单 → 引擎下单 → 引擎上号。
func CreateOrder(userID int64, phone, pass, plan string, expectedPoints *float64) (*OrderView, error) {
	if !Enabled() {
		return nil, ErrNotConfigured
	}
	phone = strings.TrimSpace(phone)
	pass = strings.TrimSpace(pass)
	if !phoneRe.MatchString(phone) {
		return nil, &InputError{Msg: "请输入正确的学习通手机号"}
	}
	if pass == "" {
		return nil, &InputError{Msg: "请输入学习通密码"}
	}
	if len(pass) > 64 {
		return nil, &InputError{Msg: "密码过长"}
	}
	if !validPlan(plan) {
		return nil, &InputError{Msg: "请选择卡型"}
	}
	uid, key, ok := engineAccount(plan)
	if !ok {
		return nil, &InputError{Msg: "该卡型暂未开放，请选择其他卡型"}
	}

	// 同号互斥：引擎侧或本站任一侧有活跃订单都拒绝（防跨渠道重复开通/白嫖）
	if _, engineActive := EngineActiveOrder(phone); engineActive {
		return nil, &InputError{Msg: "该手机号已有进行中的自动签到服务，服务期内无需重复开通"}
	}
	if _, local := hasLocalActiveOrder(phone); local {
		return nil, &InputError{Msg: "该手机号已有进行中的自动签到服务，服务期内无需重复开通"}
	}

	priceFen := planPriceFen(plan)
	points := float64(priceFen) / 100
	if expectedPoints != nil && *expectedPoints != points {
		return nil, &PriceChangedError{Points: points}
	}

	orderID := newQdOrderID()
	encPass, err := securestore.Encrypt(pass)
	if err != nil {
		return nil, fmt.Errorf("凭据加密失败")
	}
	// 本地建单 + 扣积分（同一事务，余额不足整体回滚）
	if _, err := ledger.PayQdOrderWithPoints(ledger.QdOrder{
		OrderID:   orderID,
		UserID:    userID,
		Phone:     phone,
		EncPass:   encPass,
		Plan:      plan,
		AmountFen: priceFen,
	}); err != nil {
		if strings.Contains(err.Error(), "积分不足") {
			return nil, &InputError{Msg: err.Error()}
		}
		logger.Err(err, "qd create order failed", "user_id", userID)
		return nil, fmt.Errorf("下单失败，请稍后再试")
	}

	// 引擎下单（引擎自动扣该商户批发余额；明确拒绝 → 退积分）
	add, err := enginePost("add", map[string]string{
		"uid": uid, "key": key, "platform": "1", "school": "自动签到",
		"user": phone, "pass": pass, "kcname": "自动监控", "kcid": "",
	})
	if err != nil {
		// 连接失败：引擎状态未知，但 act=add 幂等（同号活跃单返回原单），稍后重试
		logger.Warnf("qd engine add ambiguous: order=%s err=%v", orderID, err)
		_ = setOrderFields(orderID, userID, map[string]any{
			"status": StatusProcessing, "last_msg": "引擎暂时不可用，正在自动重试…",
		})
		go retryPendingOrder(orderID, userID, phone, pass, plan, uid, key)
		return loadOrderView(userID, orderID)
	}
	if add.Code != 0 && add.Code != 1 {
		// 引擎明确拒绝（余额不足等）：退积分
		msg := add.Msg
		if strings.TrimSpace(msg) == "" {
			msg = "引擎拒绝接单"
		}
		if rerr := ledger.FailQdOrderAndRefund(orderID, userID, msg); rerr != nil {
			logger.Err(rerr, "qd refund failed", "order_id", orderID)
			return nil, &RefundFailedError{Msg: "下单失败，积分退款异常，请联系管理员"}
		}
		return nil, &RefundedError{Msg: msg}
	}
	engineOrderID := add.strExtra("id")
	if engineOrderID == "" {
		engineOrderID = add.strExtra("yid")
	}
	_ = setOrderFields(orderID, userID, map[string]any{
		"engine_order_id": engineOrderID,
		"status":          StatusPendingOn,
		"last_msg":        "下单成功，正在登录上号…",
	})

	// 引擎上号（登录失败不退积分：服务期已起算，用户可在详情页免费重试登录）
	view, err := loginAndUpdate(userID, orderID, phone, pass)
	if err != nil {
		return loadOrderView(userID, orderID)
	}
	return view, nil
}

// loginAndUpdate 引擎登录上号并刷新本地订单状态。
func loginAndUpdate(userID int64, orderID, phone, pass string) (*OrderView, error) {
	_, uid, key, ok := planOfOrder(orderID, userID)
	if !ok {
		return nil, &NotFoundError{Msg: "订单不存在"}
	}
	rs, err := enginePost("login", map[string]string{
		"uid": uid, "key": key, "user": phone, "pass": pass,
	})
	if err != nil {
		_ = setOrderFields(orderID, userID, map[string]any{"last_msg": "引擎暂时不可用，请稍后重新上号"})
		return nil, err
	}
	if rs.Code != 1 {
		msg := strings.TrimSpace(rs.Msg)
		if strings.Contains(msg, "无生效订单") {
			// 引擎侧订单已不存在（到期/取消）：订单按完成收尾
			_ = setOrderFields(orderID, userID, map[string]any{
				"status": StatusDone, "last_msg": "引擎侧订单已结束",
			})
			return loadOrderView(userID, orderID)
		}
		if msg == "" {
			msg = "登录失败，请检查学习通账号密码"
		}
		_ = setOrderFields(orderID, userID, map[string]any{"last_msg": "登录失败：" + msg})
		return nil, &InputError{Msg: msg}
	}
	token := rs.strExtra("token")
	updates := map[string]any{
		"token":         token,
		"status":        StatusActive,
		"engine_status": "logged_in",
		"name":          rs.strExtra("name"),
		"uid":           rs.strExtra("uid"),
		"last_msg":      "上号成功，自动监控已启动",
	}
	if name := rs.strExtra("name"); name != "" {
		updates["last_msg"] = "上号成功（" + name + "），自动监控已启动"
	}
	_ = setOrderFields(orderID, userID, updates)
	applyPushAfterLogin(userID, orderID) // 重新上号后自动重放推送链接
	return loadOrderView(userID, orderID)
}

// planOfOrder 读订单卡型并取对应引擎账号。
func planOfOrder(orderID string, userID int64) (plan, uid, key string, ok bool) {
	o, err := fetchOrder(userID, orderID)
	if err != nil {
		return "", "", "", false
	}
	uid, key, ok = engineAccount(o.Plan)
	return o.Plan, uid, key, ok
}

// retryPendingOrder 引擎连接失败时的后台重试（act=add 幂等，重复调用安全）。
func retryPendingOrder(orderID string, userID int64, phone, pass, plan, uid, key string) {
	for attempt := 0; attempt < 5; attempt++ {
		time.Sleep(10 * time.Second)
		// 订单可能已被退款/处理，先确认还在 processing
		o, err := fetchOrder(userID, orderID)
		if err != nil || o.Status != StatusProcessing {
			return
		}
		add, err := enginePost("add", map[string]string{
			"uid": uid, "key": key, "platform": "1", "school": "自动签到",
			"user": phone, "pass": pass, "kcname": "自动监控", "kcid": "",
		})
		if err != nil {
			continue
		}
		if add.Code != 0 && add.Code != 1 {
			msg := add.Msg
			if strings.TrimSpace(msg) == "" {
				msg = "引擎拒绝接单"
			}
			if rerr := ledger.FailQdOrderAndRefund(orderID, userID, msg); rerr != nil {
				logger.Err(rerr, "qd retry refund failed", "order_id", orderID)
			}
			return
		}
		engineOrderID := add.strExtra("id")
		if engineOrderID == "" {
			engineOrderID = add.strExtra("yid")
		}
		_ = setOrderFields(orderID, userID, map[string]any{
			"engine_order_id": engineOrderID,
			"status":          StatusPendingOn,
			"last_msg":        "下单成功，正在登录上号…",
		})
		_, _ = loginAndUpdate(userID, orderID, phone, pass)
		return
	}
	_ = setOrderFields(orderID, userID, map[string]any{"last_msg": "引擎持续不可用，请联系管理员处理"})
}

// EngineActiveOrder 查引擎侧该手机号是否有生效订单（chadan）。
func EngineActiveOrder(phone string) (string, bool) {
	rs, err := enginePost("chadan", map[string]string{"username": phone})
	if err != nil || rs.Code != 1 {
		return "", false
	}
	items, _ := rs.Extra["data"].([]any)
	for _, it := range items {
		m, _ := it.(map[string]any)
		st, _ := m["status"].(string)
		if st == "待处理" || st == "进行中" || st == "补刷中" {
			id, _ := m["id"].(string)
			return id, true
		}
	}
	return "", false
}

// ---------------- 查询 / 状态同步 ----------------

func fetchOrder(userID int64, orderID string) (*qdOrderRow, error) {
	row := store.DB.QueryRow(
		`SELECT `+orderColumns+` FROM qd_orders WHERE order_id=? AND user_id=?`,
		orderID, userID)
	o, err := scanOrder(row)
	if err == sql.ErrNoRows {
		return nil, &NotFoundError{Msg: "订单不存在"}
	}
	if err != nil {
		return nil, err
	}
	return o, nil
}

// setOrderFields 条件更新订单字段（仅本人订单；值按列类型断言）。
func setOrderFields(orderID string, userID int64, fields map[string]any) error {
	if len(fields) == 0 {
		return nil
	}
	sets := make([]string, 0, len(fields)+1)
	args := make([]any, 0, len(fields)+2)
	for k, v := range fields {
		sets = append(sets, k+"=?")
		args = append(args, v)
	}
	sets = append(sets, "updated_at=?")
	args = append(args, nowQd())
	args = append(args, orderID, userID)
	_, err := store.DB.Exec(
		`UPDATE qd_orders SET `+strings.Join(sets, ", ")+` WHERE order_id=? AND user_id=?`,
		args...)
	return err
}

// loadOrderView 读取并组装视图（含引擎状态尽力同步）。
func loadOrderView(userID int64, orderID string) (*OrderView, error) {
	o, err := fetchOrder(userID, orderID)
	if err != nil {
		return nil, err
	}
	return viewOf(o), nil
}

func viewOf(o *qdOrderRow) *OrderView {
	return &OrderView{
		OrderID:      o.OrderID,
		Phone:        o.Phone,
		Plan:         o.Plan,
		PlanName:     planName(o.Plan),
		Status:       o.Status,
		EngineStatus: o.EngineStatus,
		Name:         o.Name,
		UID:          o.UID,
		SignOK:       o.SignOK,
		SignFail:     o.SignFail,
		PushURL:      o.PushURL,
		LastMsg:      o.LastMsg,
		EndAt:        o.EndAt,
		PricePoints:  float64(o.AmountFen) / 100,
		CreatedAt:    o.CreatedAt,
		UpdatedAt:    o.UpdatedAt,
	}
}

// GetOrder 订单详情：尽力同步引擎状态/日志/签到记录。
func GetOrder(userID int64, orderID string) (*OrderView, error) {
	o, err := fetchOrder(userID, orderID)
	if err != nil {
		return nil, err
	}
	syncOrderStatus(o)
	o, err = fetchOrder(userID, orderID) // 同步后重读最新状态
	if err != nil {
		return nil, err
	}
	v := viewOf(o)
	if o.Status == StatusActive && o.Token != "" {
		if logs, records, ok := fetchEngineMonitor(o.Token); ok {
			v.Logs = logs
			v.Records = records
		}
	}
	return v, nil
}

// fetchEngineMonitor 拉引擎 status 的日志与签到记录。
func fetchEngineMonitor(token string) ([]LogEntry, []SignRecord, bool) {
	rs, err := enginePost("status", map[string]string{"token": token})
	if err != nil || rs.Code != 1 {
		return nil, nil, false
	}
	logs := parseLogs(rs.Extra["logs"])
	records := parseRecords(rs.Extra["records"])
	return logs, records, true
}

func parseLogs(v any) []LogEntry {
	raw, _ := v.([]any)
	out := make([]LogEntry, 0, len(raw))
	for _, it := range raw {
		m, ok := it.(map[string]any)
		if !ok {
			continue
		}
		out = append(out, LogEntry{
			Time:  str(m["time"]),
			Level: str(m["level"]),
			Msg:   str(m["msg"]),
		})
	}
	return out
}

func parseRecords(v any) []SignRecord {
	raw, _ := v.([]any)
	out := make([]SignRecord, 0, len(raw))
	for _, it := range raw {
		m, ok := it.(map[string]any)
		if !ok {
			continue
		}
		out = append(out, SignRecord{
			Time:   str(m["time"]),
			Title:  str(m["title"]),
			Course: str(m["course"]),
		})
	}
	return out
}

func str(v any) string {
	s, _ := v.(string)
	return s
}

// syncOrderStatus 尽力同步引擎侧状态到本地（网络失败静默跳过，不影响读取）。
// token 失效时自动用落盘密码重新上号（服务期内免费）后重试一次。
func syncOrderStatus(o *qdOrderRow) {
	if store.DB == nil {
		return
	}
	switch o.Status {
	case StatusProcessing, StatusError, StatusDone:
		// 无需同步：processing 由后台重试推进；error/done 为终态
		return
	}
	if o.Token == "" {
		// 有引擎单但没 token（登录一直没成功）：不改状态，等用户重试登录
		return
	}
	rs, err := enginePost("status", map[string]string{"token": o.Token})
	if err != nil {
		return
	}
	if rs.Code != 1 {
		// 会话失效（引擎重启未恢复/被顶号）：自动重新上号一次
		if !strings.Contains(rs.Msg, "会话") && !strings.Contains(rs.Msg, "登录") {
			return
		}
		reloginOrder(o)
		return
	}
	// 订单进度：引擎 order 字段（status/process/remarks/end_at）
	updates := map[string]any{"engine_status": str(rs.Extra["monitor_status"])}
	if orderMap, ok := rs.Extra["order"].(map[string]any); ok {
		if st := str(orderMap["status"]); st != "" {
			if st == "已完成" {
				updates["status"] = StatusDone
				updates["last_msg"] = "服务期已结束"
			} else {
				updates["status"] = StatusActive
			}
		}
		if remarks := str(orderMap["remarks"]); remarks != "" {
			updates["last_msg"] = remarks
		}
		if endAt := endAtSeconds(orderMap["end_at"]); endAt > 0 {
			updates["end_at"] = endAt
		}
		// process 形如 "已签3次/失败1次"：解析签到/失败次数（chips 与 meta 展示用）
		if proc := str(orderMap["process"]); proc != "" {
			if m := signCountRe.FindStringSubmatch(proc); m != nil {
				updates["sign_ok"] = atoi64(m[1])
				updates["sign_fail"] = atoi64(m[2])
			}
		}
	}
	_ = setOrderFields(o.OrderID, o.UserID, updates)
}

var signCountRe = regexp.MustCompile(`已签(\d+)次/失败(\d+)次`)

func atoi64(s string) int64 {
	v, _ := strconv.ParseInt(strings.TrimSpace(s), 10, 64)
	return v
}

// endAtSeconds 引擎 end_at（"2006-01-02 15:04:05" 本地时区）→ Unix 秒。
func endAtSeconds(v any) float64 {
	s := str(v)
	if s == "" {
		return 0
	}
	t, err := time.ParseInLocation("2006-01-02 15:04:05", s, time.Local)
	if err != nil {
		return 0
	}
	return float64(t.Unix())
}

// reloginOrder 自动重新上号（用加密落盘的密码），恢复 token 绑定。
func reloginOrder(o *qdOrderRow) {
	if o.EncPass == "" {
		return
	}
	pass, err := securestore.Decrypt(o.EncPass)
	if err != nil || pass == "" {
		logger.Warnf("qd auto relogin decrypt failed: order=%s", o.OrderID)
		return
	}
	_, uid, key, ok := planOfOrder(o.OrderID, o.UserID)
	if !ok {
		return
	}
	rs, err := enginePost("login", map[string]string{"uid": uid, "key": key, "user": o.Phone, "pass": pass})
	if err != nil {
		return
	}
	if rs.Code == 1 {
		_ = setOrderFields(o.OrderID, o.UserID, map[string]any{
			"token": rs.strExtra("token"), "status": StatusActive,
			"engine_status": "logged_in", "last_msg": "会话自动恢复，监控继续",
		})
		applyPushAfterLogin(o.UserID, o.OrderID) // 自动重登后重放推送链接
		logger.Infof("qd auto relogin ok: order=%s phone=%s", o.OrderID, o.Phone)
	} else if strings.Contains(rs.Msg, "无生效订单") {
		_ = setOrderFields(o.OrderID, o.UserID, map[string]any{
			"status": StatusDone, "last_msg": "引擎侧订单已结束",
		})
	}
}

// ListOrders 我的自动签到订单列表（同步每单状态，尽力而为）。
func ListOrders(userID int64) ([]*OrderView, error) {
	rows, err := store.DB.Query(
		`SELECT `+orderColumns+` FROM qd_orders WHERE user_id=? ORDER BY created_at DESC LIMIT 200`,
		userID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]*OrderView, 0)
	for rows.Next() {
		o, err := scanOrder(rows)
		if err != nil {
			continue
		}
		syncOrderStatus(o)
		o2, err := fetchOrder(userID, o.OrderID)
		if err != nil {
			continue
		}
		items = append(items, viewOf(o2))
	}
	return items, nil
}

// Relogin 手动重新上号（用户点击；服务期内免费）。
func Relogin(userID int64, orderID, pass string) (*OrderView, error) {
	o, err := fetchOrder(userID, orderID)
	if err != nil {
		return nil, err
	}
	if o.Status == StatusProcessing {
		return nil, &InputError{Msg: "订单还在开通中，请稍后再试"}
	}
	if o.Status == StatusError {
		return nil, &InputError{Msg: "订单已失败，请重新下单"}
	}
	phone := o.Phone
	if pass == "" {
		if o.EncPass == "" {
			return nil, &InputError{Msg: "请输入学习通密码"}
		}
		pass, err = securestore.Decrypt(o.EncPass)
		if err != nil || pass == "" {
			return nil, &InputError{Msg: "密码读取失败，请重新输入密码"}
		}
	}
	return loginAndUpdate(userID, orderID, phone, pass)
}

// Photo 人脸照片上传（转发引擎，一次上传永久复用）。
func Photo(userID int64, orderID string, fileName string, contentType string, data []byte) error {
	o, err := fetchOrder(userID, orderID)
	if err != nil {
		return err
	}
	if o.Token == "" {
		return &InputError{Msg: "请先上号成功后再上传人脸"}
	}
	if len(data) == 0 {
		return &InputError{Msg: "文件为空"}
	}
	if len(data) > 10<<20 {
		return &InputError{Msg: "文件过大（上限 10MB）"}
	}
	rs, err := enginePostFile("photo", map[string]string{"token": o.Token},
		"file", fileName, data, contentType)
	if err != nil {
		return fmt.Errorf("引擎连接失败")
	}
	if rs.Code != 1 {
		msg := rs.Msg
		if strings.TrimSpace(msg) == "" {
			msg = "上传失败"
		}
		return &InputError{Msg: msg}
	}
	return nil
}

// Push 保存微信推送链接：先落库（重启/重新上号后自动重发），再转发引擎
// 立即生效。传空串 = 清除推送。
func Push(userID int64, orderID, pushURL string) error {
	o, err := fetchOrder(userID, orderID)
	if err != nil {
		return err
	}
	pushURL = strings.TrimSpace(pushURL)
	if len(pushURL) > 500 {
		return &InputError{Msg: "推送链接过长"}
	}
	// 落库：重新上号/自动重登/引擎重启恢复后由 applyPushAfterLogin 自动重放
	if err := setOrderFields(o.OrderID, o.UserID, map[string]any{"push_url": pushURL}); err != nil {
		return fmt.Errorf("保存失败")
	}
	if o.Token == "" {
		// 尚未上号成功：先存着，上号成功后自动应用
		return nil
	}
	rs, err := enginePost("config", map[string]string{"token": o.Token, "push_url": pushURL})
	if err != nil {
		return fmt.Errorf("引擎连接失败")
	}
	if rs.Code != 1 {
		msg := rs.Msg
		if strings.TrimSpace(msg) == "" {
			msg = "保存失败"
		}
		return &InputError{Msg: msg}
	}
	return nil
}

// applyPushAfterLogin 上号成功后把落库的推送链接自动应用到新会话
// （重新上号/自动重登都会重建引擎会话，不重放就得每次重填）。
func applyPushAfterLogin(userID int64, orderID string) {
	o, err := fetchOrder(userID, orderID)
	if err != nil || o.PushURL == "" || o.Token == "" {
		return
	}
	rs, err := enginePost("config", map[string]string{"token": o.Token, "push_url": o.PushURL})
	if err != nil || rs.Code != 1 {
		logger.Warnf("qd push re-apply failed: order=%s err=%v msg=%s", orderID, err, rs.Msg)
		return
	}
	logger.Infof("qd push re-applied: order=%s phone=%s", orderID, o.Phone)
}

// AdminListOrders 管理端订单列表（跨用户，不含密码）。
func AdminListOrders(limit int) ([]*OrderView, error) {
	if limit <= 0 || limit > 500 {
		limit = 200
	}
	rows, err := store.DB.Query(
		`SELECT `+orderColumns+` FROM qd_orders ORDER BY created_at DESC LIMIT ?`, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]*OrderView, 0)
	for rows.Next() {
		o, err := scanOrder(rows)
		if err != nil {
			continue
		}
		items = append(items, viewOf(o))
	}
	return items, nil
}
