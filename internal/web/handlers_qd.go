package web

import (
	"errors"
	"io"
	"net/http"
	"strings"

	"daili-go/internal/logger"
	"daili-go/internal/qd"
	"daili-go/internal/ratelimit"
)

// 学习通自动签到（qd）独立模块：下单/列表/详情(状态+日志)/重新上号/人脸上传。
// 除路由注册外不触碰任何既有业务代码；学习通密码 AES-GCM 加密落库，
// token/密码绝不出现在任何响应里。

var (
	qdReadLimiter   = ratelimit.New(120, 60) // 列表/详情
	qdOrderLimiter  = ratelimit.New(6, 60)   // 每 IP 下单
	qdGlobalLimiter = ratelimit.New(30, 60)  // 全站下单保护引擎
	qdPhotoLimiter  = ratelimit.New(10, 60)  // 人脸上传
)

// qdError 统一错误响应。
func qdError(w http.ResponseWriter, err error) {
	var inErr *qd.InputError
	var nfErr *qd.NotFoundError
	var rfErr *qd.RefundedError
	var rfFail *qd.RefundFailedError
	var priceErr *qd.PriceChangedError
	switch {
	case errors.As(err, &inErr):
		errJSON(w, 400, inErr.Msg)
	case errors.As(err, &priceErr):
		errJSON(w, 409, err.Error())
	case errors.As(err, &nfErr):
		errJSON(w, 404, nfErr.Msg)
	case errors.As(err, &rfFail):
		logger.Err(err, "qd refund failed")
		errJSON(w, 500, rfFail.Msg)
	case errors.As(err, &rfErr):
		errJSON(w, 502, rfErr.Msg+"（积分已退回）")
	case errors.Is(err, qd.ErrNotConfigured):
		errJSON(w, 503, "自动签到功能暂未开放")
	default:
		logger.Warnf("qd handler error: %v", err)
		errJSON(w, 502, "自动签到服务暂时不可用，请稍后再试")
	}
}

func qdUnavailable(w http.ResponseWriter) bool {
	if !qd.Enabled() {
		errJSON(w, 503, "自动签到功能暂未开放")
		return true
	}
	return false
}

// HandleQdPlans GET /api/qd/plans 卡型 + 当前零售价。
func HandleQdPlans(w http.ResponseWriter, r *http.Request) {
	if _, ok := requireAgent(w, r); !ok {
		return
	}
	if qdUnavailable(w) {
		return
	}
	if !qdReadLimiter.Allow("qd-plans:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	writeJSON(w, 200, map[string]any{"plans": qd.Plans()})
}

// HandleQdCreateOrder POST /api/qd/orders {phone, pass, plan, expected_points}
func HandleQdCreateOrder(w http.ResponseWriter, r *http.Request) {
	agent, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if qdUnavailable(w) {
		return
	}
	if !qdOrderLimiter.Allow("qd-order:" + clientIP(r)) || !qdGlobalLimiter.Allow("qd-global:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	var body struct {
		Phone          string   `json:"phone"`
		Pass           string   `json:"pass"`
		Plan           string   `json:"plan"`
		ExpectedPoints *float64 `json:"expected_points"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	v, err := qd.CreateOrder(agent.ID, body.Phone, body.Pass, body.Plan, body.ExpectedPoints)
	if err != nil {
		qdError(w, err)
		return
	}
	writeJSON(w, 200, v)
}

// HandleQdOrders GET /api/qd/orders 我的订单（同步引擎状态）。
func HandleQdOrders(w http.ResponseWriter, r *http.Request) {
	agent, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if !qdReadLimiter.Allow("qd-list:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	items, err := qd.ListOrders(agent.ID)
	if err != nil {
		qdError(w, err)
		return
	}
	writeJSON(w, 200, map[string]any{"items": items})
}

// HandleQdOrder GET /api/qd/orders/{id} 详情（含引擎日志与签到记录）。
func HandleQdOrder(w http.ResponseWriter, r *http.Request) {
	agent, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if !qdReadLimiter.Allow("qd-detail:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	id := strings.TrimSpace(r.PathValue("order_id"))
	if id == "" || len(id) > 64 {
		errJSON(w, 400, "订单参数不合法")
		return
	}
	v, err := qd.GetOrder(agent.ID, id)
	if err != nil {
		qdError(w, err)
		return
	}
	writeJSON(w, 200, v)
}

// HandleQdRelogin POST /api/qd/orders/{id}/relogin 重新上号（服务期内免费）。
// 密码可省略：用落盘的加密密码重试；换密码可传新密码。
func HandleQdRelogin(w http.ResponseWriter, r *http.Request) {
	agent, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if !qdOrderLimiter.Allow("qd-relogin:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	id := strings.TrimSpace(r.PathValue("order_id"))
	if id == "" || len(id) > 64 {
		errJSON(w, 400, "订单参数不合法")
		return
	}
	var body struct {
		Pass string `json:"pass"`
	}
	_ = readJSON(w, r, &body)
	v, err := qd.Relogin(agent.ID, id, strings.TrimSpace(body.Pass))
	if err != nil {
		qdError(w, err)
		return
	}
	writeJSON(w, 200, v)
}

// HandleQdPush POST /api/qd/orders/{id}/push {"push_url": "..."} 微信推送绑定。
func HandleQdPush(w http.ResponseWriter, r *http.Request) {
	agent, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if !qdPhotoLimiter.Allow("qd-push:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	id := strings.TrimSpace(r.PathValue("order_id"))
	if id == "" || len(id) > 64 {
		errJSON(w, 400, "订单参数不合法")
		return
	}
	var body struct {
		PushURL string `json:"push_url"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	pushURL := strings.TrimSpace(body.PushURL)
	if pushURL != "" && len(pushURL) > 500 {
		errJSON(w, 400, "推送链接过长")
		return
	}
	if err := qd.Push(agent.ID, id, pushURL); err != nil {
		qdError(w, err)
		return
	}
	writeJSON(w, 200, map[string]any{"ok": true, "message": "推送链接已保存"})
}

// HandleQdPhoto POST /api/qd/orders/{id}/photo multipart 人脸照片上传。
func HandleQdPhoto(w http.ResponseWriter, r *http.Request) {
	agent, ok := requireAgent(w, r)
	if !ok {
		return
	}
	if !qdPhotoLimiter.Allow("qd-photo:" + clientIP(r)) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	id := strings.TrimSpace(r.PathValue("order_id"))
	if id == "" || len(id) > 64 {
		errJSON(w, 400, "订单参数不合法")
		return
	}
	r.Body = http.MaxBytesReader(w, r.Body, 10<<20)
	if err := r.ParseMultipartForm(10 << 20); err != nil {
		errJSON(w, 400, "文件过大（上限 10MB）")
		return
	}
	file, header, err := r.FormFile("file")
	if err != nil {
		errJSON(w, 400, "缺少 file 字段")
		return
	}
	defer file.Close()
	data, _ := io.ReadAll(file)
	ct := header.Header.Get("Content-Type")
	if err := qd.Photo(agent.ID, id, header.Filename, ct, data); err != nil {
		qdError(w, err)
		return
	}
	writeJSON(w, 200, map[string]any{"ok": true, "message": "人脸照片已保存，该账号签到自动使用"})
}
