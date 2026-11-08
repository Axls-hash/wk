package qd

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"sync"
	"testing"

	"daili-go/internal/config"
	"daili-go/internal/securestore"
	"daili-go/internal/store"
)

// openTestDB 打开独立临时库（每个测试一个，跑完删除）。
// Windows 下必须先关旧连接才能删文件；顺带清掉可能残留的设置行防跨测试泄漏。
func openTestDB(t *testing.T) {
	t.Helper()
	path := "./test_qd.db"
	if store.DB != nil {
		_ = store.DB.Close()
		store.DB = nil
	}
	_ = os.Remove(path)
	_ = os.Remove(path + "-wal")
	_ = os.Remove(path + "-shm")
	if err := store.Open(path); err != nil {
		t.Fatalf("open test db: %v", err)
	}
	_, _ = store.DB.Exec(`DELETE FROM qd_settings`)
	t.Cleanup(func() {
		if store.DB != nil {
			_ = store.DB.Close()
			store.DB = nil
		}
		os.Remove(path)
		os.Remove(path + "-wal")
		os.Remove(path + "-shm")
	})
}

// seedUser 建测试用户（带初始积分），返回 id。
func seedUser(t *testing.T, username string, points float64) int64 {
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

func userPoints(t *testing.T, id int64) float64 {
	t.Helper()
	var pts float64
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, id).Scan(&pts)
	return pts
}

// engineStub 签到引擎桩：按 act 记录请求、按脚本应答。
type engineStub struct {
	t    *testing.T
	srv  *httptest.Server
	mu   sync.Mutex
	reqs map[string][]url.Values
	resp map[string]string // act → 应答 JSON；缺省走默认成功应答
}

func newEngineStub(t *testing.T) *engineStub {
	s := &engineStub{t: t, reqs: map[string][]url.Values{}, resp: map[string]string{}}
	s.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = r.ParseForm()
		act := r.URL.Query().Get("act")
		s.mu.Lock()
		s.reqs[act] = append(s.reqs[act], r.PostForm)
		body := s.resp[act]
		s.mu.Unlock()
		if body == "" {
			body = defaultResp(act)
		}
		w.Header().Set("Content-Type", "application/json; charset=UTF-8")
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(s.srv.Close)
	return s
}

func defaultResp(act string) string {
	switch act {
	case "chadan":
		return `{"code":1,"data":[]}`
	case "add":
		return `{"code":0,"msg":"提交成功","status":0,"message":"提交成功","id":"T-1","yid":"T-1"}`
	case "login":
		return `{"code":1,"msg":"登录成功，自动监控已启动","token":"tok-1","uid":"u-1","name":"张三"}`
	case "status":
		return `{"code":1,"uid":"u-1","name":"张三","phone":"13800001234","monitor_status":"running",` +
			`"logs":[{"time":"2026-10-28 15:00:00","level":"info","msg":"开始监控"}],` +
			`"records":[{"time":"2026-10-28 15:01:00","title":"普通签到","course":"kpop"}],` +
			`"order":{"id":"T-1","status":"进行中","process":"已签0次","remarks":"-","end_at":"2026-11-04 15:00:00"}}`
	case "getmoney":
		return `{"code":1,"msg":"查询成功","money":"96.40"}`
	case "photo":
		return `{"code":1,"msg":"上传成功","photoId":"obj-1"}`
	case "config":
		return `{"code":1,"msg":"配置已保存"}`
	}
	return `{"code":-1,"msg":"未知操作"}`
}

func (s *engineStub) calls(act string) []url.Values {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]url.Values{}, s.reqs[act]...)
}

func (s *engineStub) setResp(act, body string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.resp[act] = body
}

// applyConfig 把引擎桩写进全局配置（测试结束还原）。
func (s *engineStub) applyConfig(t *testing.T) {
	t.Helper()
	oldURL, oldUW, oldKW, oldUM, oldKM := config.QdEngineURL, config.QdUIDWeek, config.QdKeyWeek, config.QdUIDMonth, config.QdKeyMonth
	oldPW, oldPM := config.QdPriceWeek, config.QdPriceMonth
	config.QdEngineURL = s.srv.URL
	config.QdUIDWeek, config.QdKeyWeek = "1", "k-week"
	config.QdUIDMonth, config.QdKeyMonth = "1", "k-month"
	config.QdPriceWeek, config.QdPriceMonth = "3", "7"
	t.Cleanup(func() {
		config.QdEngineURL, config.QdUIDWeek, config.QdKeyWeek, config.QdUIDMonth, config.QdKeyMonth = oldURL, oldUW, oldKW, oldUM, oldKM
		config.QdPriceWeek, config.QdPriceMonth = oldPW, oldPM
	})
}

func pointsOf(v float64) *float64 { return &v }

// TestPlansAndSettings 卡型价格默认值 + 后台改价 + 开关 + 余额代理。
func TestPlansAndSettings(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)

	plans := Plans()
	if len(plans) != 2 || plans[0].Price != 3 || plans[1].Price != 7 {
		t.Fatalf("默认价应为 3/7: %+v", plans)
	}
	if !Enabled() {
		t.Fatal("配置齐全且开关缺省应为可用")
	}
	if err := SetEnabled(false); err != nil {
		t.Fatal(err)
	}
	if Enabled() {
		t.Fatal("开关关闭后应不可用")
	}
	_ = SetEnabled(true)

	if err := SetPrice(PlanWeek, 5); err != nil {
		t.Fatal(err)
	}
	if got := settingPrice(priceWeekKey, config.QdPriceWeek); got != 5 {
		t.Fatalf("改价后周卡应为 5, got %v", got)
	}
	if err := SetPrice("bad", 1); err == nil {
		t.Fatal("非法卡型应报错")
	}
	v := Settings()
	if !v.Available || v.PriceWeek != 5 || v.PriceMonth != 7 {
		t.Fatalf("Settings 视图异常: %+v", v)
	}
	if v.Balance != "96.40" {
		t.Fatalf("引擎余额应代理查询 96.40, got %q", v.Balance)
	}
}

// TestCreateOrderHappy 正常下单：扣积分 → 引擎下单 → 上号 → active。
func TestCreateOrderHappy(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)
	uid := seedUser(t, "u1", 10)

	v, err := CreateOrder(uid, "13800001234", "pass123", PlanWeek, pointsOf(3))
	if err != nil {
		t.Fatalf("下单失败: %v", err)
	}
	if v.Status != StatusActive {
		t.Fatalf("订单应 active: %+v", v)
	}
	if userPoints(t, uid) != 7 { // 10 - 3
		t.Fatalf("积分应扣 3, got %v", userPoints(t, uid))
	}
	if adds := stub.calls("add"); len(adds) != 1 {
		t.Fatalf("应调用引擎 add 1 次, got %d", len(adds))
	} else if adds[0].Get("uid") != "1" || adds[0].Get("user") != "13800001234" || adds[0].Get("kcname") != "自动监控" {
		t.Fatalf("add 参数错误: %v", adds[0])
	}
	if logins := stub.calls("login"); len(logins) != 1 || logins[0].Get("pass") != "pass123" {
		t.Fatalf("login 参数错误: %v", stub.calls("login"))
	}
	// 密码加密落库，不含明文
	var enc string
	_ = store.DB.QueryRow(`SELECT enc_pass FROM qd_orders WHERE order_id=?`, v.OrderID).Scan(&enc)
	if enc == "" || strings.Contains(enc, "pass123") {
		t.Fatalf("密码应加密落库: %q", enc)
	}
	if plain, derr := securestore.Decrypt(enc); derr != nil || plain != "pass123" {
		t.Fatalf("密码应可解密回明文: %q %v", plain, derr)
	}
	// 详情：同步引擎状态 + 日志/记录 + 到期时间
	d, err := GetOrder(uid, v.OrderID)
	if err != nil {
		t.Fatal(err)
	}
	if d.EngineStatus != "running" || len(d.Logs) != 1 || len(d.Records) != 1 {
		t.Fatalf("详情应含引擎状态/日志/记录: %+v", d)
	}
	if d.EndAt == 0 {
		t.Fatal("应同步引擎服务到期时间")
	}
}

// TestCreateOrderEngineReject 引擎明确拒绝 → 自动退积分。
func TestCreateOrderEngineReject(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)
	stub.setResp("add", `{"code":-1,"msg":"商户余额不足（本单需1.20元），请联系站长充值"}`)
	uid := seedUser(t, "u1", 10)

	_, err := CreateOrder(uid, "13800001234", "pw", PlanWeek, pointsOf(3))
	var rf *RefundedError
	if !errors.As(err, &rf) {
		t.Fatalf("应返回退款错误: %v", err)
	}
	if userPoints(t, uid) != 10 {
		t.Fatalf("积分应原路退回, got %v", userPoints(t, uid))
	}
	var status string
	_ = store.DB.QueryRow(`SELECT status FROM qd_orders LIMIT 1`).Scan(&status)
	if status != StatusError {
		t.Fatalf("订单应标记 error, got %s", status)
	}
}

// TestCreateOrderChadanActive 引擎侧已有活跃订单 → 拒绝且不扣积分。
func TestCreateOrderChadanActive(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)
	stub.setResp("chadan", `{"code":1,"data":[{"id":"T-9","status":"进行中"}]}`)
	uid := seedUser(t, "u1", 10)

	_, err := CreateOrder(uid, "13800001234", "pw", PlanWeek, pointsOf(3))
	var in *InputError
	if !errors.As(err, &in) || !strings.Contains(in.Msg, "已有进行中") {
		t.Fatalf("应拒绝重复开通: %v", err)
	}
	if userPoints(t, uid) != 10 {
		t.Fatalf("不应扣积分, got %v", userPoints(t, uid))
	}
	if len(stub.calls("add")) != 0 {
		t.Fatal("不应发起引擎下单")
	}
}

// TestCreateOrderInsufficient 积分不足直接拒绝。
func TestCreateOrderInsufficient(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)
	uid := seedUser(t, "u1", 1)

	_, err := CreateOrder(uid, "13800001234", "pw", PlanWeek, pointsOf(3))
	var in *InputError
	if !errors.As(err, &in) || !strings.Contains(in.Msg, "积分不足") {
		t.Fatalf("应提示积分不足: %v", err)
	}
	if userPoints(t, uid) != 1 {
		t.Fatalf("余额不应变化, got %v", userPoints(t, uid))
	}
}

// TestCreateOrderPriceChanged 页面展示价与实时价不一致 → 不扣款。
func TestCreateOrderPriceChanged(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)
	uid := seedUser(t, "u1", 10)

	_, err := CreateOrder(uid, "13800001234", "pw", PlanWeek, pointsOf(2))
	var pc *PriceChangedError
	if !errors.As(err, &pc) {
		t.Fatalf("应返回价格变更错误: %v", err)
	}
	if userPoints(t, uid) != 10 {
		t.Fatalf("不应扣积分, got %v", userPoints(t, uid))
	}
}

// TestCreateOrderLoginFail 密码错误：不退积分（服务期已起算），订单标记待上号。
func TestCreateOrderLoginFail(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)
	stub.setResp("login", `{"code":-1,"msg":"账号或密码错误"}`)
	uid := seedUser(t, "u1", 10)

	v, err := CreateOrder(uid, "13800001234", "bad-pass", PlanWeek, pointsOf(3))
	if err != nil {
		t.Fatalf("下单接口本身应成功受理: %v", err)
	}
	if v.Status != StatusPendingOn {
		t.Fatalf("订单应标记待上号, got %s", v.Status)
	}
	if userPoints(t, uid) != 7 {
		t.Fatalf("登录失败不退积分（服务期已起算）, got %v", userPoints(t, uid))
	}
	if !strings.Contains(v.LastMsg, "账号或密码错误") {
		t.Fatalf("应展示引擎错误消息: %q", v.LastMsg)
	}
}

// TestGetOrderAutoRelogin token 失效 → 自动重新上号 → 状态/日志照常拉取。
func TestGetOrderAutoRelogin(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)
	uid := seedUser(t, "u1", 10)

	v, err := CreateOrder(uid, "13800001234", "pw", PlanWeek, pointsOf(3))
	if err != nil {
		t.Fatal(err)
	}
	// 引擎侧把 status 打成会话失效（模拟重启未恢复），login 换新 token
	stub.setResp("status", `{"code":-1,"msg":"会话已失效，请重新登录"}`)
	stub.setResp("login", `{"code":1,"msg":"登录成功","token":"tok-2","uid":"u-1","name":"张三"}`)

	d, err := GetOrder(uid, v.OrderID)
	if err != nil {
		t.Fatal(err)
	}
	if d.Status != StatusActive {
		t.Fatalf("自动重登后应恢复 active: %+v", d)
	}
	logins := stub.calls("login")
	if len(logins) != 2 || logins[1].Get("pass") != "pw" {
		t.Fatalf("应自动用落盘密码重新上号: %v", logins)
	}
	var token string
	_ = store.DB.QueryRow(`SELECT token FROM qd_orders WHERE order_id=?`, v.OrderID).Scan(&token)
	if token != "tok-2" {
		t.Fatalf("token 应更新为 tok-2, got %q", token)
	}
}

// TestReloginManual 手动重新上号：不带密码用落盘凭据，带密码用新密码。
func TestReloginManual(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)
	uid := seedUser(t, "u1", 10)
	stub.setResp("login", `{"code":-1,"msg":"账号或密码错误"}`)
	v, err := CreateOrder(uid, "13800001234", "bad-pass", PlanWeek, pointsOf(3))
	if err != nil {
		t.Fatal(err)
	}

	// 不带密码 → 用落盘的旧密码重试（依旧失败，但 login 收到 bad-pass）
	if _, err := Relogin(uid, v.OrderID, ""); err == nil {
		t.Fatal("旧密码应仍然失败")
	}
	if logins := stub.calls("login"); len(logins) != 2 || logins[1].Get("pass") != "bad-pass" {
		t.Fatalf("应使用落盘密码: %v", logins)
	}

	// 带新密码 → 成功
	stub.setResp("login", `{"code":1,"msg":"登录成功","token":"tok-3","uid":"u-1","name":"张三"}`)
	v2, err := Relogin(uid, v.OrderID, "new-pass")
	if err != nil {
		t.Fatal(err)
	}
	if v2.Status != StatusActive {
		t.Fatalf("重新上号后应 active: %+v", v2)
	}
}

// TestPhotoUpload 人脸上传转发引擎。
func TestPhotoUpload(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)
	uid := seedUser(t, "u1", 10)
	v, err := CreateOrder(uid, "13800001234", "pw", PlanWeek, pointsOf(3))
	if err != nil {
		t.Fatal(err)
	}
	if err := Photo(uid, v.OrderID, "face.jpg", "image/jpeg", []byte("fake-jpeg")); err != nil {
		t.Fatalf("上传应成功: %v", err)
	}
	if len(stub.calls("photo")) != 1 {
		t.Fatal("引擎应收到 photo 请求")
	}
}

// TestPushPersist 推送链接持久化：保存落库 + 视图回显 + 重新上号自动重放。
func TestPushPersist(t *testing.T) {
	openTestDB(t)
	stub := newEngineStub(t)
	stub.applyConfig(t)
	uid := seedUser(t, "u1", 10)
	v, err := CreateOrder(uid, "13800001234", "pw", PlanWeek, pointsOf(3))
	if err != nil {
		t.Fatal(err)
	}

	// 保存推送：落库 + 转发引擎
	const link = "https://push.showdoc.com.cn/abc123"
	if err := Push(uid, v.OrderID, link); err != nil {
		t.Fatalf("保存推送失败: %v", err)
	}
	if len(stub.calls("config")) != 1 {
		t.Fatal("保存推送应转发引擎 config")
	}
	var saved string
	_ = store.DB.QueryRow(`SELECT push_url FROM qd_orders WHERE order_id=?`, v.OrderID).Scan(&saved)
	if saved != link {
		t.Fatalf("推送链接应落库, got %q", saved)
	}

	// 列表/详情视图回显（前端预填用）
	lv, err := GetOrder(uid, v.OrderID)
	if err != nil || lv.PushURL != link {
		t.Fatalf("详情应回显 push_url: %+v err=%v", lv, err)
	}

	// 重新上号（重建会话）→ 自动重放推送链接
	stub.setResp("login", `{"code":1,"msg":"登录成功","token":"tok-9","uid":"u-1","name":"张三"}`)
	if _, err := Relogin(uid, v.OrderID, ""); err != nil {
		t.Fatal(err)
	}
	configs := stub.calls("config")
	if len(configs) != 2 {
		t.Fatalf("重新上号后应自动重放推送, config 调用 %d 次", len(configs))
	}
	if configs[1].Get("push_url") != link {
		t.Fatalf("重放应带落库链接: %v", configs[1])
	}

	// 清空推送：落空串 + 引擎同步清
	if err := Push(uid, v.OrderID, ""); err != nil {
		t.Fatal(err)
	}
	_ = store.DB.QueryRow(`SELECT push_url FROM qd_orders WHERE order_id=?`, v.OrderID).Scan(&saved)
	if saved != "" {
		t.Fatalf("清空后应存空串, got %q", saved)
	}
}
