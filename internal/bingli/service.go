package bingli

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"strings"
	"time"
	"unicode/utf8"

	"daili-go/internal/config"
	"daili-go/internal/ledger"
	"daili-go/internal/logger"
	"daili-go/internal/securestore"
	"daili-go/internal/store"
)

// InputError 参数/余额类错误（可直接展示给用户）。
type InputError struct{ Msg string }

func (e *InputError) Error() string { return e.Msg }

// NotFoundError 资源不存在或不属于当前用户。
type NotFoundError struct{ Msg string }

func (e *NotFoundError) Error() string { return e.Msg }

// RefundedError 上游明确拒绝且已自动退回积分。
type RefundedError struct {
	Msg    string
	Status int // 上游 HTTP 状态码；400 表示入参问题
}

func (e *RefundedError) Error() string { return e.Msg }

// RefundFailedError 上游明确拒绝但自动退款也失败，需要人工介入。
type RefundFailedError struct{ Msg string }

func (e *RefundFailedError) Error() string { return e.Msg }

// PriceChangedError 用户看到的售价与下单时的实时价不一致（管理端改价）。
// 在扣积分之前返回，让用户刷新确认，绝不按新价静默扣款。
type PriceChangedError struct{ Fen int64 }

func (e *PriceChangedError) Error() string { return "模板价格已更新，请刷新后重新确认" }

func newBingOrderID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// PriceFen 本站售价（分）：上游模板价（积分/元）× 倍率。
func PriceFen(upstreamPrice float64) int64 {
	fen := int64(math.Round(upstreamPrice * config.BingPriceMult * 100))
	if fen < 1 {
		fen = 1
	}
	return fen
}

func pricePoints(fen int64) float64 { return math.Round(float64(fen)) / 100 }

// ---------------- 模板 ----------------

// ApplicationOut 模板列表项（前端字段）。
type ApplicationOut struct {
	ID          string  `json:"id"`
	Name        string  `json:"name"`
	Description string  `json:"description"`
	PriceFen    int64   `json:"price_fen"`
	PricePoints float64 `json:"price_points"`
	PageSize    string  `json:"page_size"`
	Category    string  `json:"category"`
}

// FieldOut 模板字段。
type FieldOut struct {
	FieldKey  string `json:"field_key"`
	Label     string `json:"label"`
	Type      string `json:"type"`
	Required  bool   `json:"required"`
	MaxLength int    `json:"max_length"`
}

// StampOut 印章字段配置。
type StampOut struct {
	OrgField    string `json:"org_field"`
	CodeField   string `json:"code_field"`
	DefaultOrg  string `json:"default_org"`
	DefaultCode string `json:"default_code"`
}

// ApplicationDetailOut 模板详情。
type ApplicationDetailOut struct {
	ApplicationOut
	Fields []FieldOut `json:"fields"`
	Stamp  *StampOut  `json:"stamp,omitempty"`
}

func toApplicationOut(a Application, fen int64) ApplicationOut {
	return ApplicationOut{
		ID:          a.ID,
		Name:        a.Name,
		Description: a.Description,
		PriceFen:    fen,
		PricePoints: pricePoints(fen),
		PageSize:    a.PageSize,
		Category:    a.Category,
	}
}

// ListApplications 可售模板列表。
func ListApplications() ([]ApplicationOut, error) {
	list, err := Applications()
	if err != nil {
		return nil, err
	}
	out := make([]ApplicationOut, 0, len(list))
	overrides := loadPriceOverrides()
	for _, a := range list {
		fen, _ := effectivePriceFen(a.ID, a.Price, overrides)
		out = append(out, toApplicationOut(a, fen))
	}
	return out, nil
}

// GetApplication 模板详情。
func GetApplication(id string) (*ApplicationDetailOut, error) {
	d, err := ApplicationByID(id)
	if err != nil {
		return nil, err
	}
	fen, _ := ApplicationPrice(d.ID, d.Price)
	out := &ApplicationDetailOut{ApplicationOut: toApplicationOut(d.Application, fen)}
	out.Fields = make([]FieldOut, 0, len(d.Fields))
	for _, f := range d.Fields {
		out.Fields = append(out.Fields, FieldOut{
			FieldKey:  f.FieldKey,
			Label:     f.Label,
			Type:      f.Type,
			Required:  f.Required,
			MaxLength: f.MaxLength,
		})
	}
	if d.Stamp != nil {
		out.Stamp = &StampOut{
			OrgField:    d.Stamp.OrgField,
			CodeField:   d.Stamp.CodeField,
			DefaultOrg:  d.Stamp.DefaultOrg,
			DefaultCode: d.Stamp.DefaultCode,
		}
	}
	return out, nil
}

// PreviewImage 模板预览图。
func PreviewImage(id string) ([]byte, string, error) {
	c := NewClient()
	if c == nil {
		return nil, "", ErrNotConfigured
	}
	img, ct, err := c.PreviewImage(id)
	if err != nil {
		var apiErr *APIError
		if errors.As(err, &apiErr) && apiErr.Status == 404 {
			return nil, "", &NotFoundError{Msg: "该模板暂无预览图"}
		}
		return nil, "", err
	}
	return img, ct, nil
}

// ---------------- 下单 ----------------

// validateFields 按模板定义校验并清理字段。
// 只接受模板声明的 fieldKey 与可选的印章字段；不猜测、不静默丢弃用户输入。
func validateFields(d *ApplicationDetail, input map[string]string) (map[string]string, error) {
	if len(input) > 200 {
		return nil, &InputError{Msg: "字段数量异常"}
	}
	allowed := make(map[string]string, len(d.Fields)+2) // key -> label
	for _, f := range d.Fields {
		allowed[f.FieldKey] = f.Label
	}
	if d.Stamp != nil {
		if d.Stamp.OrgField != "" {
			allowed[d.Stamp.OrgField] = "印章名称"
		}
		if d.Stamp.CodeField != "" {
			allowed[d.Stamp.CodeField] = "印章文字"
		}
	}
	for k := range input {
		if _, ok := allowed[k]; !ok {
			return nil, &InputError{Msg: "模板字段不匹配，请刷新页面后重试"}
		}
	}

	out := make(map[string]string, len(input))
	total := 0
	for _, f := range d.Fields {
		raw, ok := input[f.FieldKey]
		v := strings.TrimSpace(raw)
		label := f.Label
		if label == "" {
			label = f.FieldKey
		}
		if !ok || v == "" {
			if f.Required {
				return nil, &InputError{Msg: "请填写：" + label}
			}
			continue
		}
		if f.MaxLength > 0 && utf8.RuneCountInString(v) > f.MaxLength {
			return nil, &InputError{Msg: fmt.Sprintf("%s最多 %d 个字", label, f.MaxLength)}
		}
		if utf8.RuneCountInString(v) > 20000 {
			return nil, &InputError{Msg: label + "内容过长"}
		}
		total += len(v)
		out[f.FieldKey] = v
	}
	if total > 200000 {
		return nil, &InputError{Msg: "填写内容过长，请精简后重试"}
	}
	// 印章字段：只在模板开启印章时接受，空值交给上游用默认文字
	if d.Stamp != nil {
		for _, key := range []string{d.Stamp.OrgField, d.Stamp.CodeField} {
			if key == "" {
				continue
			}
			if v := strings.TrimSpace(input[key]); v != "" {
				if utf8.RuneCountInString(v) > 200 {
					return nil, &InputError{Msg: "印章内容过长"}
				}
				out[key] = v
			}
		}
	}
	return out, nil
}

// OrderView 订单 JSON 视图。
type OrderView struct {
	OrderID         string            `json:"order_id"`
	ApplicationID   string            `json:"application_id"`
	ApplicationName string            `json:"application_name"`
	Status          string            `json:"status"`
	ImageReady      bool              `json:"image_ready"`
	ImageURL        string            `json:"image_url,omitempty"`
	RenderError     string            `json:"render_error,omitempty"`
	Remark          string            `json:"remark,omitempty"`
	AmountFen       int64             `json:"amount_fen"`
	PricePoints     float64           `json:"price_points"`
	UpstreamOrderID string            `json:"upstream_order_id,omitempty"`
	Fields          map[string]string `json:"fields,omitempty"`
	CreatedAt       float64           `json:"created_at"`
	UpdatedAt       float64           `json:"updated_at"`
}

func imagePath(orderID string, updatedAt float64) string {
	return fmt.Sprintf("/api/bing/orders/%s/image?v=%d", orderID, int64(updatedAt))
}

func viewFromRow(orderID, appID, appName, status, upstreamOrderID, renderError, remark string,
	imageReady int, amountFen int64, createdAt, updatedAt float64) OrderView {
	v := OrderView{
		OrderID:         orderID,
		ApplicationID:   appID,
		ApplicationName: appName,
		Status:          status,
		ImageReady:      imageReady == 1,
		RenderError:     renderError,
		Remark:          remark,
		AmountFen:       amountFen,
		PricePoints:     pricePoints(amountFen),
		UpstreamOrderID: upstreamOrderID,
		CreatedAt:       createdAt,
		UpdatedAt:       updatedAt,
	}
	if v.ImageReady {
		v.ImageURL = imagePath(orderID, updatedAt)
	}
	return v
}

// CreateOrder 同步下单：校验 → 扣积分建单（事务）→ 上游生成图片。
//   - 上游明确拒绝：自动退款并返回 RefundedError
//   - 网络/500 等未知结果：保持 processing，返回订单，后台/启动重试按同订单号幂等重放
func CreateOrder(userID int64, appID string, input map[string]string, expectedPriceFen *int64) (*OrderView, error) {
	if !Enabled() {
		return nil, ErrNotConfigured
	}
	appID = strings.TrimSpace(appID)
	if appID == "" {
		return nil, &InputError{Msg: "请选择病例模板"}
	}
	detail, err := ApplicationByID(appID)
	if err != nil {
		if IsDefinitive(err) {
			return nil, &InputError{Msg: "模板不存在或已下架"}
		}
		return nil, err
	}
	fields, err := validateFields(detail, input)
	if err != nil {
		return nil, err
	}
	fieldsJSON, err := json.Marshal(fields)
	if err != nil {
		return nil, &InputError{Msg: "填写内容不合法"}
	}
	orderID := newBingOrderID()
	amountFen, _ := ApplicationPrice(detail.ID, detail.Price)
	// 用户端展示价与实时价不一致（管理员刚改价/页面停留过久）：扣积分前拒绝。
	if expectedPriceFen != nil && *expectedPriceFen != amountFen {
		return nil, &PriceChangedError{Fen: amountFen}
	}
	if _, err := ledger.PayBingOrderWithPoints(ledger.BingOrder{
		OrderID:             orderID,
		UserID:              userID,
		ApplicationID:       detail.ID,
		ApplicationName:     detail.Name,
		FieldsJSON:          string(fieldsJSON),
		AmountFen:           amountFen,
		UpstreamPricePoints: detail.Price,
	}); err != nil {
		if strings.Contains(err.Error(), "积分不足") {
			return nil, &InputError{Msg: err.Error()}
		}
		logger.Err(err, "bing create order failed", "user_id", userID)
		return nil, fmt.Errorf("下单失败，请稍后再试")
	}
	view, err := submitOrder(orderID, userID, detail.ID, detail.Name, amountFen, fields)
	if err != nil {
		return nil, err
	}
	return view, nil
}

// submitOrder 调用上游并回写本地订单；调用方保证本地订单已是 processing。
func submitOrder(orderID string, userID int64, appID, appName string, amountFen int64, fields map[string]string) (*OrderView, error) {
	c := NewClient()
	if c == nil {
		// 配置在扣款后被关闭：没有发起上游请求，直接退款
		if rerr := ledger.FailBingOrderAndRefund(orderID, userID, "病例功能未配置"); rerr != nil {
			logger.Err(rerr, "bing refund failed", "order_id", orderID)
			return nil, &RefundFailedError{Msg: "病例功能暂不可用，积分退款异常，请联系管理员"}
		}
		return nil, &RefundedError{Msg: "病例功能暂不可用，积分已退回"}
	}
	resp, err := c.SubmitOrder(orderID, appID, fields)
	if err != nil {
		if IsDefinitive(err) {
			if rerr := ledger.FailBingOrderAndRefund(orderID, userID, err.Error()); rerr != nil {
				logger.Err(rerr, "bing refund failed", "order_id", orderID)
				return nil, &RefundFailedError{Msg: "下单失败，积分退款异常，请联系管理员"}
			}
			status := 0
			var apiErr *APIError
			if errors.As(err, &apiErr) {
				status = apiErr.Status
			}
			return nil, &RefundedError{Msg: err.Error(), Status: status}
		}
		// 结果未知：保持 processing，稍后重试（同 externalRequestId 幂等，不会重复扣上游余额）
		logger.Warnf("bing order ambiguous: order=%s err=%v", orderID, err)
		return loadOrderView(userID, orderID)
	}
	// 上游受理但渲染失败（renderError 非空）：这单不会再产出图片。
	// 先保留上游单号供对账，然后把本地订单标记为 error 并退回积分。
	// 不要用同一个 externalRequestId 重试——上游幂等会原样返回这次失败结果；
	// 用户重新下单会生成新的本站订单号，从而拿到新的上游请求。
	if renderErr := strings.TrimSpace(resp.RenderError); renderErr != "" {
		if _, uerr := store.DB.Exec(
			`UPDATE bing_orders SET upstream_order_id=?, upstream_charged_points=?, updated_at=?
			 WHERE order_id=? AND user_id=? AND status='processing'`,
			string(resp.OrderID), float64(resp.ChargedPoints), nowBing(), orderID, userID); uerr != nil {
			logger.Err(uerr, "bing record render error failed", "order_id", orderID)
		}
		if rerr := ledger.FailBingOrderAndRefund(orderID, userID, "上游生成失败："+renderErr); rerr != nil {
			logger.Err(rerr, "bing render error refund failed", "order_id", orderID)
			return nil, &RefundFailedError{Msg: "病例生成失败，积分退款异常，请联系管理员"}
		}
		return nil, &RefundedError{Msg: "病例生成失败，请调整填写内容后重新下单"}
	}
	if strings.TrimSpace(string(resp.OrderID)) == "" {
		// 上游已受理但没有返回订单号：保持 processing，重试时会拿到原订单
		logger.Warnf("bing order missing upstream id: order=%s", orderID)
		return loadOrderView(userID, orderID)
	}
	imageReady := 0
	if strings.TrimSpace(resp.ImageData) != "" || strings.TrimSpace(resp.ImageURL) != "" ||
		resp.IdempotentReplay {
		imageReady = 1
	}
	if _, uerr := store.DB.Exec(
		`UPDATE bing_orders SET upstream_order_id=?, status='done', upstream_charged_points=?,
		   image_ready=?, render_error=?, updated_at=?
		 WHERE order_id=? AND user_id=? AND status='processing'`,
		string(resp.OrderID), float64(resp.ChargedPoints), imageReady,
		truncateBing(strings.TrimSpace(resp.RenderError), 500), nowBing(), orderID, userID); uerr != nil {
		logger.Err(uerr, "bing update order failed", "order_id", orderID)
	}
	return loadOrderView(userID, orderID)
}

func nowBing() float64 { return float64(time.Now().UnixNano()) / 1e9 }

func truncateBing(s string, n int) string {
	if len(s) <= n {
		return s
	}
	for n > 0 && (s[n]&0xC0) == 0x80 {
		n--
	}
	return s[:n]
}

type orderRow struct {
	OrderID         string
	UserID          int64
	UpstreamOrderID string
	ApplicationID   string
	ApplicationName string
	FieldsEnc       string
	AmountFen       int64
	Status          string
	ImageReady      int
	RenderError     string
	Remark          string
	CreatedAt       float64
	UpdatedAt       float64
}

const orderColumns = `order_id, user_id, COALESCE(upstream_order_id,''), application_id,
	COALESCE(application_name,''), fields_enc, amount_fen, status, image_ready,
	COALESCE(render_error,''), COALESCE(remark,''), created_at, updated_at`

func scanOrder(sc interface{ Scan(...interface{}) error }) (*orderRow, error) {
	var o orderRow
	if err := sc.Scan(&o.OrderID, &o.UserID, &o.UpstreamOrderID, &o.ApplicationID,
		&o.ApplicationName, &o.FieldsEnc, &o.AmountFen, &o.Status, &o.ImageReady,
		&o.RenderError, &o.Remark, &o.CreatedAt, &o.UpdatedAt); err != nil {
		return nil, err
	}
	return &o, nil
}

func fetchOrder(userID int64, orderID string) (*orderRow, error) {
	row := store.DB.QueryRow(
		`SELECT `+orderColumns+` FROM bing_orders WHERE order_id=? AND user_id=?`,
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

func decodeFields(enc string) (map[string]string, error) {
	plain, err := securestore.Decrypt(enc)
	if err != nil {
		return nil, err
	}
	var fields map[string]string
	if err := json.Unmarshal([]byte(plain), &fields); err != nil {
		return nil, err
	}
	return fields, nil
}

func viewOf(o *orderRow) OrderView {
	return viewFromRow(o.OrderID, o.ApplicationID, o.ApplicationName, o.Status, o.UpstreamOrderID,
		o.RenderError, o.Remark, o.ImageReady, o.AmountFen, o.CreatedAt, o.UpdatedAt)
}

func loadOrderView(userID int64, orderID string) (*OrderView, error) {
	o, err := fetchOrder(userID, orderID)
	if err != nil {
		return nil, err
	}
	v := viewOf(o)
	return &v, nil
}

// ListOrders 我的病例订单（不含字段内容）。
func ListOrders(userID int64) ([]OrderView, error) {
	rows, err := store.DB.Query(
		`SELECT `+orderColumns+` FROM bing_orders WHERE user_id=? ORDER BY created_at DESC LIMIT 200`,
		userID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]OrderView, 0)
	for rows.Next() {
		o, err := scanOrder(rows)
		if err != nil {
			continue
		}
		items = append(items, viewOf(o))
	}
	return items, nil
}

// GetOrder 订单详情（含本人填写的字段内容）。
func GetOrder(userID int64, orderID string) (*OrderView, error) {
	o, err := fetchOrder(userID, orderID)
	if err != nil {
		return nil, err
	}
	v := viewOf(o)
	if fields, derr := decodeFields(o.FieldsEnc); derr == nil {
		v.Fields = fields
	}
	return &v, nil
}

// GetOrderImage 代理上游订单图片（CSP 只允许本站，且不暴露上游密钥）。
func GetOrderImage(userID int64, orderID string) ([]byte, string, error) {
	o, err := fetchOrder(userID, orderID)
	if err != nil {
		return nil, "", err
	}
	if o.UpstreamOrderID == "" {
		return nil, "", &NotFoundError{Msg: "订单尚未生成图片，请稍后重试"}
	}
	c := NewClient()
	if c == nil {
		return nil, "", ErrNotConfigured
	}
	img, ct, err := c.OrderImage(o.UpstreamOrderID)
	if err != nil {
		var apiErr *APIError
		if errors.As(err, &apiErr) {
			switch apiErr.Status {
			case 404:
				return nil, "", &NotFoundError{Msg: "图片还在生成中，请稍后重试"}
			case 410:
				return nil, "", &NotFoundError{Msg: "图片已超过 3 天保留期，无法恢复，请重新下单"}
			}
		}
		return nil, "", err
	}
	_, _ = store.DB.Exec(
		`UPDATE bing_orders SET image_ready=1, updated_at=? WHERE order_id=? AND user_id=?`,
		nowBing(), orderID, userID)
	return img, ct, nil
}

// RetryOrder 手动重试一笔 processing 订单（同订单号幂等）。
func RetryOrder(userID int64, orderID string) (*OrderView, error) {
	o, err := fetchOrder(userID, orderID)
	if err != nil {
		return nil, err
	}
	if o.Status != "processing" {
		v := viewOf(o)
		return &v, nil
	}
	fields, err := decodeFields(o.FieldsEnc)
	if err != nil {
		logger.Err(err, "bing decrypt fields failed", "order_id", orderID)
		return nil, fmt.Errorf("订单数据读取失败，请联系管理员")
	}
	return submitOrder(o.OrderID, userID, o.ApplicationID, o.ApplicationName, o.AmountFen, fields)
}

// RetryPending 启动重放：processing 订单按原 externalRequestId 重试，收敛最终状态。
func RetryPending() {
	// 只看上游凭据：管理员关闭用户入口后，已扣积分的 processing 订单仍需收尾。
	if !Configured() {
		return
	}
	go func() {
		defer func() { recover() }()
		rows, err := store.DB.Query(
			`SELECT order_id, user_id, COALESCE(upstream_order_id,''), application_id,
			        COALESCE(application_name,''), fields_enc, amount_fen, status, image_ready,
			        COALESCE(render_error,''), COALESCE(remark,''), created_at, updated_at
			 FROM bing_orders WHERE status='processing' ORDER BY created_at ASC LIMIT 100`)
		if err != nil {
			logger.Err(err, "bing retry scan failed")
			return
		}
		var pending []*orderRow
		for rows.Next() {
			if o, serr := scanOrder(rows); serr == nil {
				pending = append(pending, o)
			}
		}
		rows.Close()
		for _, o := range pending {
			fields, derr := decodeFields(o.FieldsEnc)
			if derr != nil {
				logger.Err(derr, "bing retry decrypt failed", "order_id", o.OrderID)
				continue
			}
			_, rerr := submitOrder(o.OrderID, o.UserID, o.ApplicationID, o.ApplicationName, o.AmountFen, fields)
			if rerr == nil {
				logger.Infof("bing retry done: order=%s", o.OrderID)
			}
			// RefundedError / 网络错误均已由 submitOrder 落定状态
		}
	}()
}
