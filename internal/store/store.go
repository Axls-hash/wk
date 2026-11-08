// Package store provides the database connection and schema helpers.
package store

import (
	"database/sql"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	_ "github.com/go-sql-driver/mysql"
	_ "modernc.org/sqlite"
)

var DB *sql.DB
var Driver string

func IsMySQL() bool { return Driver == "mysql" }

// OpenMySQL is the production database entry point. It never falls back to SQLite.
func OpenMySQL(raw string) error {
	if !isMySQLDSN(raw) {
		return fmt.Errorf("DATABASE_URL must be a MySQL DSN")
	}
	dsn, err := mysqlDSN(raw)
	if err != nil {
		return err
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return err
	}
	db.SetMaxOpenConns(20)
	db.SetMaxIdleConns(8)
	db.SetConnMaxLifetime(time.Hour)
	if err := db.Ping(); err != nil {
		_ = db.Close()
		return err
	}
	DB = db
	Driver = "mysql"
	return migrateMySQL()
}

// Open is retained for package-level tests that use temporary SQLite databases.
// The application entry point must use OpenMySQL.
func Open(path string) error {
	if isMySQLDSN(path) {
		return OpenMySQL(path)
	}
	if strings.TrimSpace(path) == "" {
		return fmt.Errorf("database path must be configured")
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	dsn := fmt.Sprintf("file:%s?_pragma=journal_mode(WAL)&_pragma=busy_timeout(5000)&_pragma=synchronous(NORMAL)&_txlock=immediate", path)
	db, err := sql.Open("sqlite", dsn)
	if err != nil {
		return err
	}
	db.SetMaxOpenConns(20)
	db.SetMaxIdleConns(8)
	db.SetConnMaxLifetime(time.Hour)
	if err := db.Ping(); err != nil {
		return err
	}
	DB = db
	Driver = "sqlite"
	return migrate()
}
func mustExec(q string) {
	if _, err := DB.Exec(q); err != nil {
		panic(fmt.Sprintf("store migrate: %v\nsql: %s", err, q))
	}
}

// tableExists 判断表是否存在。
func tableExists(name string) bool {
	var n int
	_ = DB.QueryRow(`SELECT count(*) FROM sqlite_master WHERE type='table' AND name=?`, name).Scan(&n)
	return n > 0
}

// columnExists 判断列是否存在。
func columnExists(table, column string) bool {
	rows, err := DB.Query(fmt.Sprintf("PRAGMA table_info(%s)", table))
	if err != nil {
		return false
	}
	defer rows.Close()
	for rows.Next() {
		var cid int
		var name, ctype string
		var notNull, pk int
		var dflt sql.NullString
		if err := rows.Scan(&cid, &name, &ctype, &notNull, &dflt, &pk); err == nil && name == column {
			return true
		}
	}
	return false
}

func addColumn(table, column, ddl string) {
	if !tableExists(table) || columnExists(table, column) {
		return
	}
	mustExec(fmt.Sprintf("ALTER TABLE %s ADD COLUMN %s %s", table, column, ddl))
}

func migrate() error {
	mustExec(`CREATE TABLE IF NOT EXISTS scrape_tasks (
		task_id VARCHAR(32) PRIMARY KEY,
		account_key VARCHAR(255) NOT NULL,
		user_id INTEGER NOT NULL DEFAULT 0,
		status VARCHAR(16) NOT NULL DEFAULT 'pending',
		courses TEXT NOT NULL DEFAULT '[]',
		error TEXT NOT NULL DEFAULT '',
		created_at REAL NOT NULL
	)`)
	// 账号互斥：同一「平台:账号」同时只允许一个 pending/running 任务（部分唯一索引）
	mustExec(`CREATE UNIQUE INDEX IF NOT EXISTS uq_scrape_task_active_account
		ON scrape_tasks (account_key)
		WHERE status IN ('pending', 'running')`)

	mustExec(`CREATE TABLE IF NOT EXISTS rate_limits (
		rate_key VARCHAR(255) NOT NULL,
		window_id INTEGER NOT NULL,
		hit_count INTEGER NOT NULL DEFAULT 0,
		expires_at REAL NOT NULL DEFAULT 0,
		PRIMARY KEY (rate_key, window_id)
	)`)

	mustExec(`CREATE TABLE IF NOT EXISTS course_submissions (
		submit_id VARCHAR(32) PRIMARY KEY,
		platform VARCHAR(32) NOT NULL,
		username VARCHAR(255) NOT NULL,
		password VARCHAR(255) NOT NULL,
		courses TEXT NOT NULL DEFAULT '[]',
		remark VARCHAR(500) NOT NULL DEFAULT '',
		created_at REAL NOT NULL
	)`)

	mustExec(`CREATE TABLE IF NOT EXISTS orders (
		order_id VARCHAR(32) PRIMARY KEY,
		platform VARCHAR(32) NOT NULL,
		username VARCHAR(255) NOT NULL,
		password VARCHAR(255) NOT NULL,
		courses TEXT NOT NULL DEFAULT '[]',
		remark VARCHAR(500) NOT NULL DEFAULT '',
		course_count INTEGER NOT NULL DEFAULT 0,
		amount_fen INTEGER NOT NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'pending',
		trade_no VARCHAR(64) NOT NULL DEFAULT '',
		pay_type VARCHAR(16) NOT NULL DEFAULT '',
		created_at REAL NOT NULL,
		paid_at REAL,
		agent_id INTEGER NOT NULL DEFAULT 0,
		pay_method VARCHAR(16) NOT NULL DEFAULT 'epay',
		process_status VARCHAR(16) NOT NULL DEFAULT 'pending',
		school VARCHAR(255) NOT NULL DEFAULT '',
		upstream TEXT NOT NULL DEFAULT ''
	)`)

	mustExec(`CREATE TABLE IF NOT EXISTS banned_ips (
		ip VARCHAR(64) PRIMARY KEY,
		banned_at REAL NOT NULL,
		reason VARCHAR(64) NOT NULL DEFAULT ''
	)`)

	mustExec(`CREATE TABLE IF NOT EXISTS login_logs (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		username VARCHAR(255) NOT NULL,
		ip VARCHAR(64) NOT NULL DEFAULT '',
		success INTEGER NOT NULL DEFAULT 0,
		created_at REAL NOT NULL
	)`)

	mustExec(`CREATE TABLE IF NOT EXISTS agent_users (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		username VARCHAR(32) NOT NULL UNIQUE,
		password_hash VARCHAR(255) NOT NULL,
		contact VARCHAR(100) NOT NULL DEFAULT '',
		points REAL NOT NULL DEFAULT 0,
		token_version INTEGER NOT NULL DEFAULT 0,
		status VARCHAR(16) NOT NULL DEFAULT 'active',
		created_at REAL NOT NULL
	)`)

	mustExec(`CREATE TABLE IF NOT EXISTS point_logs (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		agent_id INTEGER NOT NULL,
		delta REAL NOT NULL,
		balance_after REAL NOT NULL,
		reason VARCHAR(200) NOT NULL DEFAULT '',
		order_id VARCHAR(32) NOT NULL DEFAULT '',
		created_at REAL NOT NULL
	)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_point_logs_agent ON point_logs(agent_id)`)

	mustExec(`CREATE TABLE IF NOT EXISTS redeem_codes (
		code VARCHAR(32) PRIMARY KEY,
		points INTEGER NOT NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'unused',
		used_by INTEGER NOT NULL DEFAULT 0,
		used_at REAL,
		created_at REAL NOT NULL,
		note VARCHAR(200) NOT NULL DEFAULT ''
	)`)

	mustExec(`CREATE TABLE IF NOT EXISTS platform_prices (
		platform VARCHAR(32) PRIMARY KEY,
		price_fen INTEGER NOT NULL,
		updated_at REAL NOT NULL
	)`)

	mustExec(`CREATE TABLE IF NOT EXISTS admin_audit_logs (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		action VARCHAR(64) NOT NULL,
		detail TEXT NOT NULL DEFAULT '',
		created_at REAL NOT NULL
	)`)

	mustExec(`CREATE TABLE IF NOT EXISTS announcements (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		title VARCHAR(100) NOT NULL,
		content TEXT NOT NULL,
		level VARCHAR(16) NOT NULL DEFAULT 'info',
		is_pinned INTEGER NOT NULL DEFAULT 0,
		is_active INTEGER NOT NULL DEFAULT 1,
		created_at REAL NOT NULL,
		updated_at REAL NOT NULL,
		created_by VARCHAR(64) NOT NULL DEFAULT ''
	)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_announcements_active_pinned ON announcements(is_active, is_pinned, created_at DESC)`)

	// 冷门网课商品表：从天河 getclass 差量同步的项目（cid 即天河对接ID/noun）
	mustExec(`CREATE TABLE IF NOT EXISTS more_products (
		cid INTEGER PRIMARY KEY,
		fenlei INTEGER NOT NULL DEFAULT 0,
		name TEXT NOT NULL,
		price_yuan REAL NOT NULL DEFAULT 0,
		base_yuan REAL NOT NULL DEFAULT 0,
		price_override INTEGER NOT NULL DEFAULT 0,
		content TEXT NOT NULL DEFAULT '',
		status INTEGER NOT NULL DEFAULT 1,
		updated_at REAL NOT NULL
	)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_more_products_fenlei ON more_products(fenlei)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_more_products_name ON more_products(name)`)

	// 实习打卡订单（sxdk）：独立业务表，与课程订单(orders)完全分开，互不影响
	mustExec(`CREATE TABLE IF NOT EXISTS sxdk_orders (
		order_id VARCHAR(32) PRIMARY KEY,
		user_id INTEGER NOT NULL,
		platform VARCHAR(16) NOT NULL,
		tianhe_id INTEGER NOT NULL DEFAULT 0,
		phone VARCHAR(64) NOT NULL,
		password VARCHAR(128) NOT NULL,
		name VARCHAR(64) NOT NULL DEFAULT '',
		address VARCHAR(255) NOT NULL DEFAULT '',
		project_name VARCHAR(64) NOT NULL DEFAULT '',
		school_text VARCHAR(128) NOT NULL DEFAULT '',
		check_week VARCHAR(64) NOT NULL,
		end_time VARCHAR(32) NOT NULL,
		up_check_time VARCHAR(64) NOT NULL DEFAULT '',
		down_check_time VARCHAR(64) NOT NULL DEFAULT '',
		day_paper INTEGER NOT NULL DEFAULT 2,
		week_paper INTEGER NOT NULL DEFAULT 2,
		month_paper INTEGER NOT NULL DEFAULT 2,
		run_type INTEGER NOT NULL DEFAULT 1,
		days INTEGER NOT NULL DEFAULT 0,
		sell_yuan REAL NOT NULL DEFAULT 1,
		amount_fen INTEGER NOT NULL DEFAULT 0,
		status VARCHAR(16) NOT NULL DEFAULT 'pending',
		code INTEGER NOT NULL DEFAULT -1,
		remark TEXT NOT NULL DEFAULT '',
		created_at REAL NOT NULL
	)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_sxdk_orders_user ON sxdk_orders(user_id, created_at DESC)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_sxdk_orders_tianhe ON sxdk_orders(tianhe_id)`)

	// 我爱运动（syy）订单：一行=一次购买（km 数量），同一用户同一项目复用同一上游客户
	mustExec(`CREATE TABLE IF NOT EXISTS syy_orders (
		order_id VARCHAR(32) PRIMARY KEY,
		user_id INTEGER NOT NULL,
		project_id INTEGER NOT NULL,
		project_name VARCHAR(100) NOT NULL DEFAULT '',
		customer_id INTEGER NOT NULL DEFAULT 0,
		customer_key VARCHAR(128) NOT NULL DEFAULT '',
		customer_link VARCHAR(500) NOT NULL DEFAULT '',
		km REAL NOT NULL DEFAULT 0,
		unit_fen INTEGER NOT NULL DEFAULT 0,
		amount_fen INTEGER NOT NULL DEFAULT 0,
		status VARCHAR(16) NOT NULL DEFAULT 'processing',
		balance REAL NOT NULL DEFAULT 0,
		transaction_id VARCHAR(64) NOT NULL DEFAULT '',
		remark VARCHAR(500) NOT NULL DEFAULT '',
		created_at REAL NOT NULL,
		updated_at REAL NOT NULL
	)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_syy_orders_user ON syy_orders(user_id, created_at DESC)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_syy_orders_project ON syy_orders(user_id, project_id)`)

	// 我爱运动：管理端按项目自定义售价（分/单位）；未设置的项目走 基准价×倍率
	mustExec(`CREATE TABLE IF NOT EXISTS syy_prices (
		project_id INTEGER PRIMARY KEY,
		price_fen INTEGER NOT NULL,
		updated_at REAL NOT NULL
	)`)

	// 病例（bingli）订单：字段内容加密存储，敏感数据不落明文
	mustExec(`CREATE TABLE IF NOT EXISTS bing_orders (
		order_id VARCHAR(32) PRIMARY KEY,
		user_id INTEGER NOT NULL,
		upstream_order_id VARCHAR(64) NOT NULL DEFAULT '',
		application_id VARCHAR(128) NOT NULL,
		application_name VARCHAR(200) NOT NULL DEFAULT '',
		fields_enc TEXT NOT NULL,
		amount_fen INTEGER NOT NULL DEFAULT 0,
		upstream_price_points REAL NOT NULL DEFAULT 0,
		upstream_charged_points REAL NOT NULL DEFAULT 0,
		status VARCHAR(16) NOT NULL DEFAULT 'processing',
		image_ready INTEGER NOT NULL DEFAULT 0,
		render_error VARCHAR(500) NOT NULL DEFAULT '',
		remark VARCHAR(500) NOT NULL DEFAULT '',
		external_request_id VARCHAR(64) NOT NULL UNIQUE,
		created_at REAL NOT NULL,
		updated_at REAL NOT NULL
	)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_bing_orders_user ON bing_orders(user_id, created_at DESC)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_bing_orders_status ON bing_orders(status)`)

	// 病例：管理端运行开关（键值表；缺行时按“开启”处理，兼容老库）
	mustExec(`CREATE TABLE IF NOT EXISTS bing_settings (
		name VARCHAR(32) PRIMARY KEY,
		value VARCHAR(200) NOT NULL DEFAULT '',
		updated_at REAL NOT NULL
	)`)

	// 病例：管理端按模板自定义售价（分）；未设置时走 上游模板价×倍率
	mustExec(`CREATE TABLE IF NOT EXISTS bing_prices (
		application_id VARCHAR(128) PRIMARY KEY,
		price_fen INTEGER NOT NULL,
		updated_at REAL NOT NULL
	)`)

	// 学习通自动签到（qd）订单：学习通密码 AES-GCM 加密落库，不存明文
	mustExec(`CREATE TABLE IF NOT EXISTS qd_orders (
		order_id VARCHAR(32) PRIMARY KEY,
		user_id INTEGER NOT NULL,
		phone VARCHAR(20) NOT NULL,
		enc_pass TEXT NOT NULL DEFAULT '',
		plan VARCHAR(8) NOT NULL DEFAULT 'week',
		engine_order_id VARCHAR(64) NOT NULL DEFAULT '',
		token VARCHAR(64) NOT NULL DEFAULT '',
		status VARCHAR(16) NOT NULL DEFAULT 'processing',
		engine_status VARCHAR(16) NOT NULL DEFAULT '',
		name VARCHAR(64) NOT NULL DEFAULT '',
		uid VARCHAR(32) NOT NULL DEFAULT '',
		sign_ok INTEGER NOT NULL DEFAULT 0,
		sign_fail INTEGER NOT NULL DEFAULT 0,
		push_url VARCHAR(500) NOT NULL DEFAULT '',
		last_msg VARCHAR(500) NOT NULL DEFAULT '',
		end_at REAL NOT NULL DEFAULT 0,
		amount_fen INTEGER NOT NULL DEFAULT 0,
		created_at REAL NOT NULL,
		updated_at REAL NOT NULL
	)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_qd_orders_user ON qd_orders(user_id, created_at DESC)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_qd_orders_phone ON qd_orders(phone)`)
	mustExec(`CREATE INDEX IF NOT EXISTS idx_qd_orders_status ON qd_orders(status)`)

	// 学习通自动签到：管理端开关 + 卡型零售价（键值表；缺行时按“开启/默认价”处理）
	mustExec(`CREATE TABLE IF NOT EXISTS qd_settings (
		name VARCHAR(32) PRIMARY KEY,
		value VARCHAR(200) NOT NULL DEFAULT '',
		updated_at REAL NOT NULL
	)`)

	// ---- 旧库补列（与 Python 版 _migrate_sqlite 一致；新库 create 已含全部列）----
	addColumn("more_products", "base_yuan", "REAL NOT NULL DEFAULT 0")         // 进货价（=天河价×倍率，同步始终刷新）
	addColumn("more_products", "price_override", "INTEGER NOT NULL DEFAULT 0") // 1=管理员自定义售价（同步不再覆盖 price_yuan）
	// 存量行回填：仅限「进价为空且未被自定义」的行（新功能引入前的数据），
	// base=当前售价、标记保持默认 0；已被管理员改价的（price_override=1）绝不动。
	_, _ = DB.Exec(`UPDATE more_products SET base_yuan=price_yuan WHERE base_yuan=0 AND price_override=0`)
	addColumn("course_submissions", "remark", "VARCHAR(500) NOT NULL DEFAULT ''")
	addColumn("orders", "agent_id", "INTEGER NOT NULL DEFAULT 0")
	addColumn("orders", "pay_method", "VARCHAR(16) NOT NULL DEFAULT 'epay'")
	addColumn("orders", "process_status", "VARCHAR(16) NOT NULL DEFAULT 'pending'")
	addColumn("orders", "school", "VARCHAR(255) NOT NULL DEFAULT ''")
	addColumn("orders", "upstream", "TEXT NOT NULL DEFAULT ''")
	addColumn("agent_users", "token_version", "INTEGER NOT NULL DEFAULT 0")
	addColumn("agent_users", "status", "VARCHAR(16) NOT NULL DEFAULT 'active'")
	addColumn("scrape_tasks", "user_id", "INTEGER NOT NULL DEFAULT 0")
	addColumn("rate_limits", "expires_at", "REAL NOT NULL DEFAULT 0")

	// 旧库可能没有 partial unique index（账号互斥防线），确保存在
	mustExec(`CREATE UNIQUE INDEX IF NOT EXISTS uq_scrape_task_active_account
		ON scrape_tasks (account_key)
		WHERE status IN ('pending', 'running')`)

	// 用户名大小写不敏感唯一性：历史数据干净才建 NOCASE 唯一索引兜底
	// （应用层 lower()=? 查重是第一道防线，索引兜住并发竞态）；
	// 已存在 Alice/alice 这类重复的老库不阻塞启动，仅告警。
	var usernameDupes int
	_ = DB.QueryRow(
		`SELECT count(*) FROM (SELECT lower(username) AS lu FROM agent_users GROUP BY lu HAVING count(*) > 1)`,
	).Scan(&usernameDupes)
	if usernameDupes > 0 {
		fmt.Printf("[daili-go][warn] agent_users 存在 %d 组仅大小写不同的用户名，跳过 NOCASE 唯一索引；请人工合并后重启\n", usernameDupes)
	} else {
		mustExec(`CREATE UNIQUE INDEX IF NOT EXISTS uq_agent_users_username_nocase ON agent_users(username COLLATE NOCASE)`)
	}

	return nil
}

// PriceForPlatform 取某平台的单价（分）；未单独设置时返回传入的默认价。
func PriceForPlatform(platform string, defaultFen int64) int64 {
	var fen int64
	err := DB.QueryRow(`SELECT price_fen FROM platform_prices WHERE platform=?`, platform).Scan(&fen)
	if err != nil || fen <= 0 {
		return defaultFen
	}
	return fen
}

// AllPlatformPrices 返回全部已设置的平台价（供管理端与公开配置）。
func AllPlatformPrices() (map[string]int64, error) {
	rows, err := DB.Query(`SELECT platform, price_fen FROM platform_prices`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[string]int64{}
	for rows.Next() {
		var p string
		var f int64
		if rows.Scan(&p, &f) == nil {
			out[p] = f
		}
	}
	return out, rows.Err()
}

// SetPlatformPrice 设置/更新某平台单价（分）。fen<=0 表示删除该条（回退默认价）。
func SetPlatformPrice(platform string, fen int64) error {
	if fen <= 0 {
		_, err := DB.Exec(`DELETE FROM platform_prices WHERE platform=?`, platform)
		return err
	}
	_, err := DB.Exec(
		`INSERT INTO platform_prices(platform, price_fen, updated_at) VALUES(?,?,?)
		 ON DUPLICATE KEY UPDATE price_fen=VALUES(price_fen), updated_at=VALUES(updated_at)`,
		platform, fen, time.Now().UnixNano()/1e9)
	return err
}
