package web

import (
	"errors"
	"fmt"
	"net/http"
	"strings"

	"daili-go/internal/bingli"
	"daili-go/internal/logger"
	"daili-go/internal/ratelimit"
)

// 病例（bingli）独立模块：模板浏览、预览图、下单、订单详情、图片代理。
// 除路由注册外不触碰任何既有业务代码；所有上游密钥仅保存在服务端。

var (
	bingReadLimiter    = ratelimit.New(120, 60) // 模板列表/详情
	bingPreviewLimiter = ratelimit.New(60, 60)  // 预览图
	bingImageLimiter   = ratelimit.New(120, 60) // 订单图片
	bingOrderLimiter   = ratelimit.New(10, 60)  // 每 IP 下单
	bingGlobalLimiter  = ratelimit.New(40, 60)  // 全站下单保护上游
	bingRetryLimiter   = ratelimit.New(10, 60)
)

// bingError 统一错误响应（不记录字段内容）。
func bingError(w http.ResponseWriter, err error) {
	var inErr *bingli.InputError
	var nfErr *bingli.NotFoundError
	var rfErr *bingli.RefundedError
	var rfFail *bingli.RefundFailedError
	var priceErr *bingli.PriceChangedError
	switch {
	case errors.As(err, &inErr):
		errJSON(w, 400, inErr.Msg)
	case errors.As(err, &priceErr):
		errJSON(w, 409, fmt.Sprintf("模板价格已更新（当前 %.2f 积分），请刷新后重新确认", float64(priceErr.Fen)/100))
	case errors.As(err, &nfErr):
		errJSON(w, 404, nfErr.Msg)
	case errors.As(err, &rfFail):
		logger.Err(err, "bing refund failed")
		errJSON(w, 500, rfFail.Msg)
	case errors.As(err, &rfErr):
		if rfErr.Status == 400 {
			errJSON(w, 400, rfErr.Msg+"（积分已退回）")
		} else {
			errJSON(w, 502, rfErr.Msg+"（积分已退回）")
		}
	case errors.Is(err, bingli.ErrNotConfigured):
		errJSON(w, 503, "病例功能暂未开放")
	default:
		logger.Warnf("bing handler error: %v", err)
		errJSON(w, 502, "病例服务暂时不可用，请稍后再试")
	}
}

func bingUnavailable(w http.ResponseWriter) bool {
	if !bingli.Enabled() {
		errJSON(w, 503, "病例功能暂未开放")
		return true
	}
	return false
}

// HandleBingApplications GET /api/bing/applications
func HandleBingApplications(w http.ResponseWriter, r *http.Request) {
	if _, ok := requireAgent(w, r); !ok {
		return
	}
	if bingUnavailable(w) {
		return
	}
	if !bingReadLimiter.Allow("bing-list:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	items, err := bingli.ListApplications()
	if err != nil {
		bingError(w, err)
		return
	}
	writeJSON(w, 200, map[string]interface{}{"items": items})
}

// HandleBingApplication GET /api/bing/applications/{id}
func HandleBingApplication(w http.ResponseWriter, r *http.Request) {
	if _, ok := requireAgent(w, r); !ok {
		return
	}
	if bingUnavailable(w) {
		return
	}
	if !bingReadLimiter.Allow("bing-detail:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	id := strings.TrimSpace(r.PathValue("id"))
	if id == "" || len(id) > 128 {
		errJSON(w, 400, "模板参数不合法")
		return
	}
	detail, err := bingli.GetApplication(id)
	if err != nil {
		var apiErr *bingli.APIError
		if errors.As(err, &apiErr) && apiErr.Status == 404 {
			errJSON(w, 404, "模板不存在或已下架")
			return
		}
		bingError(w, err)
		return
	}
	writeJSON(w, 200, detail)
}

// HandleBingApplicationPreview GET /api/bing/applications/{id}/preview
func HandleBingApplicationPreview(w http.ResponseWriter, r *http.Request) {
	if _, ok := requireAgent(w, r); !ok {
		return
	}
	if bingUnavailable(w) {
		return
	}
	if !bingPreviewLimiter.Allow("bing-preview:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	id := strings.TrimSpace(r.PathValue("id"))
	if id == "" || len(id) > 128 {
		errJSON(w, 400, "模板参数不合法")
		return
	}
	img, ct, err := bingli.PreviewImage(id)
	if err != nil {
		bingError(w, err)
		return
	}
	w.Header().Set("Content-Type", ct)
	w.Header().Set("Content-Disposition", "inline")
	w.Header().Set("Cache-Control", "private, max-age=600")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(200)
	_, _ = w.Write(img)
}

// HandleBingCreateOrder POST /api/bing/orders {application_id, fields}
func HandleBingCreateOrder(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if bingUnavailable(w) {
		return
	}
	ip := clientIP(r)
	if !bingOrderLimiter.Allow("bing-order:"+ip) || !bingGlobalLimiter.Allow("bing-order-global") {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	var body struct {
		ApplicationID    string            `json:"application_id"`
		Fields           map[string]string `json:"fields"`
		ExpectedPriceFen *int64            `json:"expected_price_fen"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	view, err := bingli.CreateOrder(u.ID, body.ApplicationID, body.Fields, body.ExpectedPriceFen)
	if err != nil {
		bingError(w, err)
		return
	}
	writeJSON(w, 201, view)
}

// HandleBingOrders GET /api/bing/orders
func HandleBingOrders(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if !bingReadLimiter.Allow("bing-orders:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	items, err := bingli.ListOrders(u.ID)
	if err != nil {
		logger.Err(err, "bing list orders failed", "user_id", u.ID)
		errJSON(w, 500, "加载失败，请稍后再试")
		return
	}
	writeJSON(w, 200, map[string]interface{}{"items": items})
}

// HandleBingOrder GET /api/bing/orders/{order_id}
func HandleBingOrder(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if !bingReadLimiter.Allow("bing-order-detail:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	orderID := strings.TrimSpace(r.PathValue("order_id"))
	if orderID == "" || len(orderID) > 64 {
		errJSON(w, 400, "订单参数不合法")
		return
	}
	view, err := bingli.GetOrder(u.ID, orderID)
	if err != nil {
		if _, isNF := err.(*bingli.NotFoundError); isNF {
			errJSON(w, 404, "订单不存在")
			return
		}
		logger.Err(err, "bing get order failed", "order_id", orderID)
		errJSON(w, 500, "加载失败，请稍后再试")
		return
	}
	writeJSON(w, 200, view)
}

// HandleBingOrderImage GET /api/bing/orders/{order_id}/image —— 上游 PNG 代理。
func HandleBingOrderImage(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	// 已支付订单的图片不受管理端开关影响：关闭入口只是禁止新建订单。
	if !bingImageLimiter.Allow("bing-image:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	orderID := strings.TrimSpace(r.PathValue("order_id"))
	if orderID == "" || len(orderID) > 64 {
		errJSON(w, 400, "订单参数不合法")
		return
	}
	img, ct, err := bingli.GetOrderImage(u.ID, orderID)
	if err != nil {
		bingError(w, err)
		return
	}
	w.Header().Set("Content-Type", ct)
	w.Header().Set("Content-Disposition", "inline")
	w.Header().Set("Cache-Control", "private, max-age=600")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(200)
	_, _ = w.Write(img)
}

// HandleBingRetryOrder POST /api/bing/orders/{order_id}/retry —— 手动重试 processing 订单。
func HandleBingRetryOrder(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	// 重试是已扣积分订单的收尾，不受管理端开关影响。
	if !bingRetryLimiter.Allow("bing-retry:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	orderID := strings.TrimSpace(r.PathValue("order_id"))
	if orderID == "" || len(orderID) > 64 {
		errJSON(w, 400, "订单参数不合法")
		return
	}
	view, err := bingli.RetryOrder(u.ID, orderID)
	if err != nil {
		bingError(w, err)
		return
	}
	writeJSON(w, 200, view)
}
