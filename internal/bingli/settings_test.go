package bingli

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"daili-go/internal/config"
	"daili-go/internal/store"
)

func openBingSettingsTestDB(t *testing.T, path string) {
	t.Helper()
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
}

func seedBingUser(t *testing.T, username string, points float64) int64 {
	t.Helper()
	res, err := store.DB.Exec(
		`INSERT INTO agent_users(username, password_hash, contact, points, token_version, status, created_at)
		 VALUES(?, 'x', '', ?, 0, 'active', 1)`, username, points)
	if err != nil {
		t.Fatalf("seed user: %v", err)
	}
	id, _ := res.LastInsertId()
	return id
}

// TestFeatureSwitchSettings 开关默认开启、可持久化关闭，且与上游配置分层判断。
func TestFeatureSwitchSettings(t *testing.T) {
	oldURL, oldEmail, oldKey := config.BingAPIURL, config.BingUserEmail, config.BingAPIKey
	oldMult := config.BingPriceMult
	config.BingAPIURL = "https://case.example.com/api/v1"
	config.BingUserEmail = "tester@example.com"
	config.BingAPIKey = "test-key"
	config.BingPriceMult = 2
	defer func() {
		config.BingAPIURL, config.BingUserEmail, config.BingAPIKey = oldURL, oldEmail, oldKey
		config.BingPriceMult = oldMult
	}()

	openBingSettingsTestDB(t, "./test_bing_settings.db")

	if !Configured() || !Enabled() {
		t.Fatal("已配置且缺省开关时应可用")
	}
	if err := SetEnabled(false); err != nil {
		t.Fatalf("disable: %v", err)
	}
	if FeatureSwitchOn() || Enabled() {
		t.Fatal("关闭后用户端应不可用")
	}
	s := Settings()
	if !s.Configured || s.SwitchOn || s.Available {
		t.Fatalf("设置面板状态错误: %+v", s)
	}
	if err := SetEnabled(true); err != nil {
		t.Fatalf("enable: %v", err)
	}
	if !FeatureSwitchOn() || !Enabled() {
		t.Fatal("重新开启后应可用")
	}

	// 老库没有设置行：默认开启，不能被误判为关闭。
	if _, err := store.DB.Exec(`DELETE FROM bing_settings`); err != nil {
		t.Fatalf("delete setting: %v", err)
	}
	if !FeatureSwitchOn() {
		t.Fatal("缺省设置行应按开启处理")
	}
}

// TestApplicationPriceOverride 自定义价优先，删除覆盖后回到上游价×倍率。
func TestApplicationPriceOverride(t *testing.T) {
	oldMult := config.BingPriceMult
	config.BingPriceMult = 2
	defer func() { config.BingPriceMult = oldMult }()

	openBingSettingsTestDB(t, "./test_bing_price.db")

	if fen, source := ApplicationPrice("app-1", 3); fen != 600 || source != "default" {
		t.Fatalf("默认价: fen=%d source=%s want 600/default", fen, source)
	}
	if err := SetApplicationPrice("app-1", 950); err != nil {
		t.Fatalf("set price: %v", err)
	}
	if fen, source := ApplicationPrice("app-1", 3); fen != 950 || source != "custom" {
		t.Fatalf("自定义价: fen=%d source=%s want 950/custom", fen, source)
	}
	if err := SetApplicationPrice("app-1", 0); err != nil {
		t.Fatalf("reset price: %v", err)
	}
	if fen, source := ApplicationPrice("app-1", 3); fen != 600 || source != "default" {
		t.Fatalf("恢复默认: fen=%d source=%s want 600/default", fen, source)
	}
}

// TestCreateOrderCustomPriceAndSwitch 下单按自定义价扣费；展示价不一致时拒绝；
// 管理端关闭开关后不允许新建订单（已支付订单的看图/重试不走这里的入口）。
func TestCreateOrderCustomPriceAndSwitch(t *testing.T) {
	oldURL, oldEmail, oldKey := config.BingAPIURL, config.BingUserEmail, config.BingAPIKey
	oldMult, oldTimeout := config.BingPriceMult, config.BingTimeoutSeconds
	oldEnc := config.CredentialEncryptionKey
	config.BingUserEmail = "tester@example.com"
	config.BingAPIKey = "test-key"
	config.BingPriceMult = 2
	config.BingTimeoutSeconds = 10
	config.CredentialEncryptionKey = "test-encryption-key"
	defer func() {
		config.BingAPIURL, config.BingUserEmail, config.BingAPIKey = oldURL, oldEmail, oldKey
		config.BingPriceMult, config.BingTimeoutSeconds = oldMult, oldTimeout
		config.CredentialEncryptionKey = oldEnc
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
			_ = json.NewEncoder(w).Encode(map[string]interface{}{
				"orderId": "up-price-1", "applicationId": "app-1", "status": "SUBMITTED",
				"chargedPoints": 3, "imageData": "data:image/png;base64,AAAA",
			})
		default:
			w.WriteHeader(404)
			_, _ = w.Write([]byte(`{"statusCode":404,"message":"not found"}`))
		}
	}))
	defer server.Close()
	config.BingAPIURL = server.URL + "/api/v1"

	openBingSettingsTestDB(t, "./test_bing_custom_price.db")
	userID := seedBingUser(t, "bing-price-user", 10)
	if err := SetApplicationPrice("app-1", 500); err != nil {
		t.Fatalf("set custom price: %v", err)
	}

	expectedOld := int64(600)
	_, err := CreateOrder(userID, "app-1", map[string]string{"patientName": "张三"}, &expectedOld)
	var priceErr *PriceChangedError
	if !errors.As(err, &priceErr) || priceErr.Fen != 500 {
		t.Fatalf("want PriceChangedError(500), got %T: %v", err, err)
	}
	var points float64
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, userID).Scan(&points)
	if points != 10 {
		t.Fatalf("价格不一致不得扣款: points=%v", points)
	}
	var count int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM bing_orders WHERE user_id=?`, userID).Scan(&count)
	if count != 0 {
		t.Fatalf("价格不一致不得建单: count=%d", count)
	}

	expected := int64(500)
	view, err := CreateOrder(userID, "app-1", map[string]string{"patientName": "张三"}, &expected)
	if err != nil {
		t.Fatalf("create order: %v", err)
	}
	if view.AmountFen != 500 {
		t.Fatalf("amount_fen=%d want 500", view.AmountFen)
	}
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, userID).Scan(&points)
	if points != 5 {
		t.Fatalf("应按自定义价扣 5 积分: points=%v", points)
	}

	// 管理端关闭入口：新订单被拒绝，余额不变。
	if err := SetEnabled(false); err != nil {
		t.Fatalf("disable: %v", err)
	}
	_, err = CreateOrder(userID, "app-1", map[string]string{"patientName": "张三"}, &expected)
	if !errors.Is(err, ErrNotConfigured) {
		t.Fatalf("关闭后应拒绝下单, got %T: %v", err, err)
	}
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, userID).Scan(&points)
	if points != 5 {
		t.Fatalf("关闭后不得扣款: points=%v", points)
	}
}
