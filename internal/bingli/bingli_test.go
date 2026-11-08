package bingli

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"daili-go/internal/config"
	"daili-go/internal/ledger"
	"daili-go/internal/securestore"
	"daili-go/internal/store"
)

func TestPriceFen(t *testing.T) {
	old := config.BingPriceMult
	defer func() { config.BingPriceMult = old }()

	config.BingPriceMult = 2
	if got := PriceFen(10); got != 2000 {
		t.Fatalf("PriceFen(10)=%d want 2000", got)
	}
	if got := PriceFen(0.015); got != 3 {
		t.Fatalf("PriceFen(0.015)=%d want 3", got)
	}
	if got := PriceFen(0); got != 1 {
		t.Fatalf("PriceFen(0)=%d want 1 (最低价兜底)", got)
	}
}

func TestValidateFields(t *testing.T) {
	d := &ApplicationDetail{
		Application: Application{ID: "a1", Name: "门诊病历"},
		Fields: []Field{
			{FieldKey: "patientName", Label: "姓名", Required: true, MaxLength: 4},
			{FieldKey: "gender", Label: "性别", Type: "text"},
		},
		Stamp: &Stamp{OrgField: "stampOrg", CodeField: "stampCode"},
	}

	out, err := validateFields(d, map[string]string{
		"patientName": " 张三 ",
		"gender":      "男",
		"stampOrg":    "示例医院",
	})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if out["patientName"] != "张三" {
		t.Fatalf("trim failed: %q", out["patientName"])
	}
	if out["stampOrg"] != "示例医院" {
		t.Fatalf("stamp field missing: %v", out)
	}

	if _, err := validateFields(d, map[string]string{"gender": "男"}); err == nil {
		t.Fatal("missing required field should fail")
	} else {
		var ie *InputError
		if !errors.As(err, &ie) {
			t.Fatalf("want InputError, got %T", err)
		}
	}

	if _, err := validateFields(d, map[string]string{"patientName": "张三", "unknown": "x"}); err == nil {
		t.Fatal("unknown field should fail")
	}

	if _, err := validateFields(d, map[string]string{"patientName": "张三丰丰丰"}); err == nil {
		t.Fatal("maxLength should fail")
	}

	noStamp := &ApplicationDetail{
		Fields: []Field{{FieldKey: "patientName", Label: "姓名", Required: true}},
	}
	if _, err := validateFields(noStamp, map[string]string{"patientName": "张三", "stampOrg": "医院"}); err == nil {
		t.Fatal("stamp field without stamp config should fail")
	}
}

func TestFlexTypes(t *testing.T) {
	var v struct {
		ID    flexString `json:"id"`
		Price flexFloat  `json:"price"`
	}
	if err := json.Unmarshal([]byte(`{"id":123,"price":"10.5"}`), &v); err != nil {
		t.Fatal(err)
	}
	if string(v.ID) != "123" || float64(v.Price) != 10.5 {
		t.Fatalf("flex parse failed: id=%q price=%v", v.ID, v.Price)
	}
}

func TestResolveURL(t *testing.T) {
	c := &Client{base: "https://case.zmoee.com/api/v1"}
	got, err := c.resolveURL("/api/applications/x/preview.png?v=1")
	if err != nil || got != "https://case.zmoee.com/api/applications/x/preview.png?v=1" {
		t.Fatalf("resolve relative failed: %q %v", got, err)
	}
	if _, err := c.resolveURL("https://evil.example/x.png"); err == nil {
		t.Fatal("cross-host URL should fail")
	}
}

// TestBingOrderLedger 覆盖建单扣款、加密落库、失败退款与防重复退款。
func TestBingOrderLedger(t *testing.T) {
	oldKey := config.CredentialEncryptionKey
	config.CredentialEncryptionKey = "test-encryption-key"
	defer func() { config.CredentialEncryptionKey = oldKey }()

	path := "./test_bingli.db"
	for _, p := range []string{path, path + "-wal", path + "-shm"} {
		_ = os.Remove(p)
	}
	if err := store.Open(path); err != nil {
		t.Fatalf("open test db: %v", err)
	}
	t.Cleanup(func() {
		if store.DB != nil {
			_ = store.DB.Close()
		}
		for _, p := range []string{path, path + "-wal", path + "-shm"} {
			_ = os.Remove(p)
		}
	})

	res, err := store.DB.Exec(
		`INSERT INTO agent_users(username, password_hash, contact, points, token_version, status, created_at)
		 VALUES('bing-user', 'x', '', 10, 0, 'active', 1)`)
	if err != nil {
		t.Fatalf("seed user: %v", err)
	}
	userID, _ := res.LastInsertId()

	orderID := "0123456789abcdef0123456789abcdef"
	balance, err := ledger.PayBingOrderWithPoints(ledger.BingOrder{
		OrderID:             orderID,
		UserID:              userID,
		ApplicationID:       "app-1",
		ApplicationName:     "门诊病历",
		FieldsJSON:          `{"patientName":"张三","gender":"男"}`,
		AmountFen:           500,
		UpstreamPricePoints: 2.5,
	})
	if err != nil {
		t.Fatalf("pay order: %v", err)
	}
	if balance != 5 {
		t.Fatalf("balance=%v want 5", balance)
	}

	var enc, status string
	if err := store.DB.QueryRow(
		`SELECT fields_enc, status FROM bing_orders WHERE order_id=?`, orderID).Scan(&enc, &status); err != nil {
		t.Fatalf("query order: %v", err)
	}
	if status != "processing" {
		t.Fatalf("status=%q want processing", status)
	}
	if !strings.HasPrefix(enc, "enc:v1:") {
		t.Fatalf("fields not encrypted: %q", enc)
	}
	plain, err := securestore.Decrypt(enc)
	if err != nil || !strings.Contains(plain, "张三") {
		t.Fatalf("decrypt fields failed: %q %v", plain, err)
	}

	if err := ledger.FailBingOrderAndRefund(orderID, userID, "模板不存在"); err != nil {
		t.Fatalf("refund: %v", err)
	}
	var points float64
	if err := store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, userID).Scan(&points); err != nil {
		t.Fatalf("query points: %v", err)
	}
	if points != 10 {
		t.Fatalf("points after refund=%v want 10", points)
	}
	if err := store.DB.QueryRow(`SELECT status FROM bing_orders WHERE order_id=?`, orderID).Scan(&status); err != nil {
		t.Fatalf("query status: %v", err)
	}
	if status != "error" {
		t.Fatalf("status=%q want error", status)
	}

	// 重复退款必须无效果（防止并发/重试双退）
	if err := ledger.FailBingOrderAndRefund(orderID, userID, "再次退款"); err != nil {
		t.Fatalf("second refund: %v", err)
	}
	if err := store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, userID).Scan(&points); err != nil {
		t.Fatalf("query points: %v", err)
	}
	if points != 10 {
		t.Fatalf("double refund detected: points=%v", points)
	}
}

// TestCreateOrderRenderErrorRefunds 上游受理但渲染失败时必须标记 error 并退回积分。
func TestCreateOrderRenderErrorRefunds(t *testing.T) {
	oldKey := config.CredentialEncryptionKey
	oldURL, oldEmail, oldAPIKey := config.BingAPIURL, config.BingUserEmail, config.BingAPIKey
	oldMult, oldTimeout := config.BingPriceMult, config.BingTimeoutSeconds
	config.CredentialEncryptionKey = "test-encryption-key"
	config.BingUserEmail = "tester@example.com"
	config.BingAPIKey = "test-key"
	config.BingPriceMult = 2
	config.BingTimeoutSeconds = 10
	defer func() {
		config.CredentialEncryptionKey = oldKey
		config.BingAPIURL, config.BingUserEmail, config.BingAPIKey = oldURL, oldEmail, oldAPIKey
		config.BingPriceMult, config.BingTimeoutSeconds = oldMult, oldTimeout
	}()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/api/v1/applications/app-1":
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"id": "app-1", "name": "门诊病历", "price": 3,
				"fields": []map[string]interface{}{
					{"fieldKey": "patientName", "label": "姓名", "type": "text", "required": true, "maxLength": 20},
				},
			})
		case r.Method == http.MethodPost && r.URL.Path == "/api/v1/orders":
			// 上游明确受理（返回订单号）但渲染失败
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"orderId": "up-render-fail", "applicationId": "app-1", "status": "SUBMITTED",
				"chargedPoints": 3, "renderError": "字体缺失",
			})
		default:
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"statusCode":404,"message":"not found"}`))
		}
	}))
	defer server.Close()
	config.BingAPIURL = server.URL + "/api/v1"

	path := "./test_bingli_render.db"
	for _, p := range []string{path, path + "-wal", path + "-shm"} {
		_ = os.Remove(p)
	}
	if err := store.Open(path); err != nil {
		t.Fatalf("open test db: %v", err)
	}
	t.Cleanup(func() {
		if store.DB != nil {
			_ = store.DB.Close()
		}
		for _, p := range []string{path, path + "-wal", path + "-shm"} {
			_ = os.Remove(p)
		}
	})
	res, err := store.DB.Exec(
		`INSERT INTO agent_users(username, password_hash, contact, points, token_version, status, created_at)
		 VALUES('bing-render', 'x', '', 10, 0, 'active', 1)`)
	if err != nil {
		t.Fatalf("seed user: %v", err)
	}
	userID, _ := res.LastInsertId()

	_, err = CreateOrder(userID, "app-1", map[string]string{"patientName": "张三"}, nil)
	var rfErr *RefundedError
	if !errors.As(err, &rfErr) {
		t.Fatalf("want RefundedError, got %T: %v", err, err)
	}

	var points float64
	if err := store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, userID).Scan(&points); err != nil {
		t.Fatalf("query points: %v", err)
	}
	if points != 10 {
		t.Fatalf("points=%v want 10（渲染失败应全额退款）", points)
	}
	var status, renderErr, upstreamID string
	var imageReady int
	if err := store.DB.QueryRow(
		`SELECT status, COALESCE(render_error,''), COALESCE(upstream_order_id,''), image_ready
		 FROM bing_orders WHERE user_id=? ORDER BY created_at DESC LIMIT 1`, userID).
		Scan(&status, &renderErr, &upstreamID, &imageReady); err != nil {
		t.Fatalf("query order: %v", err)
	}
	if status != "error" || imageReady != 0 || !strings.Contains(renderErr, "字体缺失") || upstreamID != "up-render-fail" {
		t.Fatalf("order state: status=%q image_ready=%d render_error=%q upstream=%q",
			status, imageReady, renderErr, upstreamID)
	}
}

// TestCreateOrderWithFakeUpstream 用 httptest 模拟上游，验证完整下单链路。
func TestCreateOrderWithFakeUpstream(t *testing.T) {
	oldKey := config.CredentialEncryptionKey
	oldURL, oldEmail, oldAPIKey := config.BingAPIURL, config.BingUserEmail, config.BingAPIKey
	oldMult, oldTimeout := config.BingPriceMult, config.BingTimeoutSeconds
	config.CredentialEncryptionKey = "test-encryption-key"
	config.BingUserEmail = "tester@example.com"
	config.BingAPIKey = "test-key"
	config.BingPriceMult = 2
	config.BingTimeoutSeconds = 10
	defer func() {
		config.CredentialEncryptionKey = oldKey
		config.BingAPIURL, config.BingUserEmail, config.BingAPIKey = oldURL, oldEmail, oldAPIKey
		config.BingPriceMult, config.BingTimeoutSeconds = oldMult, oldTimeout
	}()

	var gotExternalID string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/api/v1/applications/app-1":
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"id": "app-1", "name": "门诊病历", "price": 3,
				"fields": []map[string]interface{}{
					{"fieldKey": "patientName", "label": "姓名", "type": "text", "required": true, "maxLength": 20},
				},
			})
		case r.Method == http.MethodPost && r.URL.Path == "/api/v1/orders":
			var body struct {
				ApplicationID     string            `json:"applicationId"`
				Fields            map[string]string `json:"fields"`
				ExternalRequestID string            `json:"externalRequestId"`
			}
			_ = json.NewDecoder(r.Body).Decode(&body)
			gotExternalID = body.ExternalRequestID
			if body.ApplicationID != "app-1" || body.Fields["patientName"] != "张三" {
				t.Errorf("unexpected upstream body: %+v", body)
			}
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"orderId": "up-1", "applicationId": "app-1", "status": "SUBMITTED",
				"chargedPoints": 3, "imageData": "data:image/png;base64,AAAA",
			})
		default:
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"statusCode":404,"message":"not found"}`))
		}
	}))
	defer server.Close()
	config.BingAPIURL = server.URL + "/api/v1"

	path := "./test_bingli_create.db"
	for _, p := range []string{path, path + "-wal", path + "-shm"} {
		_ = os.Remove(p)
	}
	if err := store.Open(path); err != nil {
		t.Fatalf("open test db: %v", err)
	}
	t.Cleanup(func() {
		if store.DB != nil {
			_ = store.DB.Close()
		}
		for _, p := range []string{path, path + "-wal", path + "-shm"} {
			_ = os.Remove(p)
		}
	})
	res, err := store.DB.Exec(
		`INSERT INTO agent_users(username, password_hash, contact, points, token_version, status, created_at)
		 VALUES('bing-create', 'x', '', 100, 0, 'active', 1)`)
	if err != nil {
		t.Fatalf("seed user: %v", err)
	}
	userID, _ := res.LastInsertId()

	view, err := CreateOrder(userID, "app-1", map[string]string{"patientName": " 张三 "}, nil)
	if err != nil {
		t.Fatalf("create order: %v", err)
	}
	if gotExternalID != view.OrderID {
		t.Fatalf("externalRequestId=%q want order id %q", gotExternalID, view.OrderID)
	}
	if view.Status != "done" || !view.ImageReady {
		t.Fatalf("unexpected view: %+v", view)
	}
	if view.AmountFen != 600 || view.PricePoints != 6 {
		t.Fatalf("price mismatch: %+v", view)
	}

	var points float64
	if err := store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, userID).Scan(&points); err != nil {
		t.Fatalf("query points: %v", err)
	}
	if points != 94 {
		t.Fatalf("points=%v want 94", points)
	}
	var upstreamID, fieldsEnc string
	if err := store.DB.QueryRow(
		`SELECT upstream_order_id, fields_enc FROM bing_orders WHERE order_id=?`, view.OrderID).
		Scan(&upstreamID, &fieldsEnc); err != nil {
		t.Fatalf("query order: %v", err)
	}
	if upstreamID != "up-1" {
		t.Fatalf("upstream id=%q", upstreamID)
	}
	if !strings.HasPrefix(fieldsEnc, "enc:v1:") {
		t.Fatalf("fields not encrypted: %q", fieldsEnc)
	}
}
