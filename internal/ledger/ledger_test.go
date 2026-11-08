package ledger

import (
	"fmt"
	"sync"
	"testing"

	"daili-go/internal/store"
)

// TestAdjustBalanceBasic 正负调整与余额下限。
func TestAdjustBalanceBasic(t *testing.T) {
	OpenTestDB(t)
	id := SeedUser(t, "u1", 100)

	bal, err := AdjustBalance(id, 30, "充")
	if err != nil || bal != 130 {
		t.Fatalf("充值: bal=%v err=%v", bal, err)
	}
	bal, err = AdjustBalance(id, -50, "扣")
	if err != nil || bal != 80 {
		t.Fatalf("扣减: bal=%v err=%v", bal, err)
	}
	if _, err = AdjustBalance(id, -81, "超扣"); err == nil {
		t.Fatal("超额扣减应报错")
	}
	var points int64
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, id).Scan(&points)
	if points != 80 {
		t.Fatalf("失败后余额应不变: %d", points)
	}
	var logs int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM point_logs WHERE agent_id=?`, id).Scan(&logs)
	if logs != 2 { // 失败的那次不应写流水
		t.Fatalf("流水条数应=2: %d", logs)
	}
}

// TestConcurrentDeductNoDoubleSpend 并发扣减总额守恒（双花回归）。
func TestConcurrentDeductNoDoubleSpend(t *testing.T) {
	OpenTestDB(t)
	const balance, n, each = 100, 10, 10
	id := SeedUser(t, "u2", balance)

	var wg sync.WaitGroup
	okCount := make(chan struct{}, n)
	for i := 0; i < n; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if _, err := AdjustBalance(id, -each, "并发扣"); err == nil {
				okCount <- struct{}{}
			}
		}()
	}
	wg.Wait()
	close(okCount)

	success := 0
	for range okCount {
		success++
	}
	var points int64
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, id).Scan(&points)

	if int64(success)*each+points != balance {
		t.Fatalf("账目不平：成功%d笔×%d + 余额%d ≠ %d", success, each, points, balance)
	}
	if success != balance/each {
		t.Fatalf("成功笔数应为 %d，实际 %d", balance/each, success)
	}
}

// TestRedeemCodeOnce 一码一用 + 并发兑换只有一个成功。
func TestRedeemCodeOnce(t *testing.T) {
	OpenTestDB(t)
	uid := SeedUser(t, "u3", 0)

	codes, err := GenerateCodes(1, 50, "t")
	if err != nil || len(codes) != 1 {
		t.Fatalf("生成: %v", err)
	}
	code := codes[0]

	// 小写兑换应自动转大写
	bal, pts, err := RedeemCode(uid, lower(code))
	if err != nil || pts != 50 || bal != 50 {
		t.Fatalf("首次兑换: bal=%v pts=%v err=%v", bal, pts, err)
	}
	if _, _, err = RedeemCode(uid, code); err == nil {
		t.Fatal("重复兑换应报错")
	}

	// 并发兑换同一新码
	codes2, _ := GenerateCodes(1, 20, "t2")
	var wg sync.WaitGroup
	winners := make(chan struct{}, 5)
	for i := 0; i < 5; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if _, _, err := RedeemCode(uid, codes2[0]); err == nil {
				winners <- struct{}{}
			}
		}()
	}
	wg.Wait()
	close(winners)
	wins := len(winners)
	if wins != 1 {
		t.Fatalf("并发兑换应恰好 1 个成功，实际 %d", wins)
	}
	var points int64
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, uid).Scan(&points)
	if points != 70 { // 50 + 20
		t.Fatalf("最终余额应 70，实际 %d", points)
	}
}

// TestLegacyHyphenatedCode 存量带连字符码仍可兑换（回退查询路径）。
func TestLegacyHyphenatedCode(t *testing.T) {
	OpenTestDB(t)
	uid := SeedUser(t, "u6", 0)
	_, err := store.DB.Exec(
		`INSERT INTO redeem_codes(code, points, status, used_by, created_at, note)
		 VALUES('ABCD-EFGH-JKMN-PQRS', 30, 'unused', 0, 1, 'legacy')`)
	if err != nil {
		t.Fatalf("seed: %v", err)
	}
	bal, pts, err := RedeemCode(uid, "abcd-efgh-jkmn-pqrs")
	if err != nil || pts != 30 || bal != 30 {
		t.Fatalf("存量连字符码兑换: bal=%v pts=%v err=%v", bal, pts, err)
	}
	// 新生成的无连字符码：手输带连字符也应能兑换（规范化匹配）
	codes, _ := GenerateCodes(1, 20, "t")
	if _, _, err = RedeemCode(uid, fmtCode(codes[0])); err != nil {
		t.Fatalf("分组输入兑换: %v", err)
	}
	var points int64
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, uid).Scan(&points)
	if points != 50 {
		t.Fatalf("余额应 50: %d", points)
	}
}

func fmtCode(c string) string {
	if len(c) != 16 {
		return c
	}
	return c[0:4] + "-" + c[4:8] + "-" + c[8:12] + "-" + c[12:16]
}

// TestDisableCode 作废后不可兑换。
func TestDisableCode(t *testing.T) {
	OpenTestDB(t)
	uid := SeedUser(t, "u4", 0)
	codes, _ := GenerateCodes(1, 10, "t")
	if err := DisableCode(codes[0]); err != nil {
		t.Fatalf("作废: %v", err)
	}
	if _, _, err := RedeemCode(uid, codes[0]); err == nil {
		t.Fatal("作废码兑换应报错")
	}
}

// TestFailSyyOrderAndRefund 失败状态翻转与退款同事务，重复调用只退一次。
func TestFailSyyOrderAndRefund(t *testing.T) {
	OpenTestDB(t)
	uid := SeedUser(t, "syy1", 94) // 模拟已扣 6 积分
	_, err := store.DB.Exec(
		`INSERT INTO syy_orders(order_id, user_id, project_id, project_name, km, unit_fen, amount_fen,
		   status, transaction_id, created_at, updated_at)
		 VALUES('syy001', ?, 1, 'p', 1, 600, 600, 'processing', 'syy001', 1, 1)`, uid)
	if err != nil {
		t.Fatalf("seed order: %v", err)
	}
	if err := FailSyyOrderAndRefund("syy001", uid, "upstream rejected"); err != nil {
		t.Fatalf("refund: %v", err)
	}
	var points int64
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, uid).Scan(&points)
	if points != 100 {
		t.Fatalf("退款后余额应 100: %d", points)
	}
	// 重复调用（模拟重放）：订单已 error，不得二次退款
	if err := FailSyyOrderAndRefund("syy001", uid, "again"); err != nil {
		t.Fatalf("second call: %v", err)
	}
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, uid).Scan(&points)
	if points != 100 {
		t.Fatalf("重复调用不得二次退款: %d", points)
	}
	var status, remark string
	_ = store.DB.QueryRow(`SELECT status, remark FROM syy_orders WHERE order_id='syy001'`).Scan(&status, &remark)
	if status != "error" || remark != "upstream rejected" {
		t.Fatalf("订单状态异常: status=%s remark=%q", status, remark)
	}
}

// TestPayOrderWithPoints 下单扣款原子性与幂等标记。
func TestPayOrderWithPoints(t *testing.T) {
	OpenTestDB(t)
	uid := SeedUser(t, "u5", 7) // 7 积分

	bal, err := PayOrderWithPoints("ord001", "学习通", "stu", "pw",
		"", `[{"title":"a"},{"title":"b"}]`, "", 2, 600 /*6元*/, uid)
	if err != nil || bal != 1 { // 6积分 扣完剩 1
		t.Fatalf("下单: bal=%v err=%v", bal, err)
	}
	var status string
	_ = store.DB.QueryRow(`SELECT status FROM orders WHERE order_id='ord001'`).Scan(&status)
	if status != "paid" {
		t.Fatalf("订单应 paid: %s", status)
	}
	// 余额不足：整体回滚不落单
	_, err = PayOrderWithPoints("ord002", "学习通", "stu", "pw",
		"", `[{"title":"a"}]`, "", 1, 900 /*9元>1积分*/, uid)
	if err == nil {
		t.Fatal("余额不足应报错")
	}
	var cnt int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM orders WHERE order_id='ord002'`).Scan(&cnt)
	if cnt != 0 {
		t.Fatal("失败订单不应落库（事务回滚）")
	}
	var points int64
	_ = store.DB.QueryRow(`SELECT points FROM agent_users WHERE id=?`, uid).Scan(&points)
	if points != 1 {
		t.Fatalf("回滚后余额应 1: %d", points)
	}
	_ = fmt.Sprint()
}
