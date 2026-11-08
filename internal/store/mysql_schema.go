package store

import (
	"fmt"
	"net/url"
	"strings"
)

func isMySQLDSN(raw string) bool {
	value := strings.ToLower(strings.TrimSpace(raw))
	return strings.HasPrefix(value, "mysql://") || strings.Contains(value, "@tcp(")
}

func mysqlDSN(raw string) (string, error) {
	raw = strings.TrimSpace(raw)
	if !strings.HasPrefix(strings.ToLower(raw), "mysql://") {
		return raw, nil
	}
	parsed, err := url.Parse(raw)
	if err != nil || parsed.User == nil || parsed.Host == "" || parsed.Path == "" {
		return "", fmt.Errorf("invalid mysql DATABASE_URL")
	}
	password, _ := parsed.User.Password()
	host := parsed.Host
	if !strings.Contains(host, ":") {
		host += ":3306"
	}
	query := parsed.Query()
	query.Set("parseTime", "true")
	query.Set("charset", "utf8mb4")
	query.Set("collation", "utf8mb4_unicode_ci")
	query.Set("loc", "Local")
	return parsed.User.Username() + ":" + password + "@tcp(" + host + ")/" + strings.TrimPrefix(parsed.Path, "/") + "?" + query.Encode(), nil
}

func migrateMySQL() error {
	statements := []string{
		`CREATE TABLE IF NOT EXISTS scrape_tasks (task_id VARCHAR(32) PRIMARY KEY, account_key VARCHAR(255) NOT NULL, user_id BIGINT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'pending', courses LONGTEXT NOT NULL, error TEXT NOT NULL, created_at DOUBLE NOT NULL, KEY idx_scrape_account(account_key), KEY idx_scrape_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		"CREATE TABLE IF NOT EXISTS rate_limits (rate_key VARCHAR(255) NOT NULL, window_id BIGINT NOT NULL, hit_count INT NOT NULL DEFAULT 0, expires_at DOUBLE NOT NULL DEFAULT 0, PRIMARY KEY(rate_key,window_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
		`CREATE TABLE IF NOT EXISTS course_submissions (submit_id VARCHAR(32) PRIMARY KEY, platform VARCHAR(32) NOT NULL, username VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, courses LONGTEXT NOT NULL, remark VARCHAR(500) NOT NULL DEFAULT '', created_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS orders (order_id VARCHAR(32) PRIMARY KEY, platform VARCHAR(32) NOT NULL, username VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, courses LONGTEXT NOT NULL, remark VARCHAR(500) NOT NULL DEFAULT '', course_count INT NOT NULL DEFAULT 0, amount_fen BIGINT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'pending', trade_no VARCHAR(64) NOT NULL DEFAULT '', pay_type VARCHAR(16) NOT NULL DEFAULT '', created_at DOUBLE NOT NULL, paid_at DOUBLE NULL, agent_id BIGINT NOT NULL DEFAULT 0, pay_method VARCHAR(16) NOT NULL DEFAULT 'epay', process_status VARCHAR(16) NOT NULL DEFAULT 'pending', school VARCHAR(255) NOT NULL DEFAULT '', upstream LONGTEXT NULL, KEY idx_orders_agent(agent_id), KEY idx_orders_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS banned_ips (ip VARCHAR(64) PRIMARY KEY, banned_at DOUBLE NOT NULL, reason VARCHAR(64) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS login_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(255) NOT NULL, ip VARCHAR(64) NOT NULL DEFAULT '', success TINYINT NOT NULL DEFAULT 0, created_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS agent_users (id BIGINT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(32) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, contact VARCHAR(100) NOT NULL DEFAULT '', points DECIMAL(18,2) NOT NULL DEFAULT 0, token_version BIGINT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'active', created_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
		`CREATE TABLE IF NOT EXISTS point_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, agent_id BIGINT NOT NULL, delta DECIMAL(18,2) NOT NULL, balance_after DECIMAL(18,2) NOT NULL, reason VARCHAR(200) NOT NULL DEFAULT '', order_id VARCHAR(32) NOT NULL DEFAULT '', created_at DOUBLE NOT NULL, KEY idx_point_logs_agent(agent_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS redeem_codes (code VARCHAR(32) PRIMARY KEY, points BIGINT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'unused', used_by BIGINT NOT NULL DEFAULT 0, used_at DOUBLE NULL, created_at DOUBLE NOT NULL, note VARCHAR(200) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS platform_prices (platform VARCHAR(32) PRIMARY KEY, price_fen BIGINT NOT NULL, updated_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS admin_audit_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(64) NOT NULL, detail LONGTEXT NOT NULL, created_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS announcements (id BIGINT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(100) NOT NULL, content LONGTEXT NOT NULL, level VARCHAR(16) NOT NULL DEFAULT 'info', is_pinned TINYINT NOT NULL DEFAULT 0, is_active TINYINT NOT NULL DEFAULT 1, created_at DOUBLE NOT NULL, updated_at DOUBLE NOT NULL, created_by VARCHAR(64) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS more_products (cid BIGINT PRIMARY KEY, fenlei INT NOT NULL DEFAULT 0, name VARCHAR(255) NOT NULL, price_yuan DECIMAL(18,2) NOT NULL DEFAULT 0, base_yuan DECIMAL(18,2) NOT NULL DEFAULT 0, price_override TINYINT NOT NULL DEFAULT 0, content LONGTEXT NOT NULL, status TINYINT NOT NULL DEFAULT 0, updated_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS front_categories (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, sort_order INT NOT NULL DEFAULT 0, price_mult DECIMAL(10,4) NOT NULL DEFAULT 1, created_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS front_category_items (category_id BIGINT NOT NULL, cid BIGINT NOT NULL, PRIMARY KEY (category_id, cid), KEY idx_front_cat_items_cid(cid)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS sxdk_orders (order_id VARCHAR(32) PRIMARY KEY, user_id BIGINT NOT NULL, platform VARCHAR(16) NOT NULL, tianhe_id BIGINT NOT NULL DEFAULT 0, phone VARCHAR(64) NOT NULL, password VARCHAR(128) NOT NULL, name VARCHAR(64) NOT NULL DEFAULT '', address VARCHAR(255) NOT NULL DEFAULT '', project_name VARCHAR(64) NOT NULL DEFAULT '', school_text VARCHAR(128) NOT NULL DEFAULT '', check_week VARCHAR(64) NOT NULL, end_time VARCHAR(32) NOT NULL, up_check_time VARCHAR(64) NOT NULL DEFAULT '', down_check_time VARCHAR(64) NOT NULL DEFAULT '', day_paper INT NOT NULL DEFAULT 2, week_paper INT NOT NULL DEFAULT 2, month_paper INT NOT NULL DEFAULT 2, run_type INT NOT NULL DEFAULT 1, days INT NOT NULL DEFAULT 0, sell_yuan DECIMAL(18,2) NOT NULL DEFAULT 1, amount_fen BIGINT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'pending', code INT NOT NULL DEFAULT -1, remark VARCHAR(500) NOT NULL DEFAULT '', created_at DOUBLE NOT NULL, KEY idx_sxdk_orders_user(user_id, created_at), KEY idx_sxdk_orders_tianhe(tianhe_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS syy_orders (order_id VARCHAR(32) PRIMARY KEY, user_id BIGINT NOT NULL, project_id BIGINT NOT NULL, project_name VARCHAR(100) NOT NULL DEFAULT '', customer_id BIGINT NOT NULL DEFAULT 0, customer_key VARCHAR(128) NOT NULL DEFAULT '', customer_link VARCHAR(500) NOT NULL DEFAULT '', km DOUBLE NOT NULL DEFAULT 0, unit_fen BIGINT NOT NULL DEFAULT 0, amount_fen BIGINT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'processing', balance DOUBLE NOT NULL DEFAULT 0, transaction_id VARCHAR(64) NOT NULL DEFAULT '', remark VARCHAR(500) NOT NULL DEFAULT '', created_at DOUBLE NOT NULL, updated_at DOUBLE NOT NULL, KEY idx_syy_orders_user(user_id, created_at), KEY idx_syy_orders_project(user_id, project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		`CREATE TABLE IF NOT EXISTS syy_prices (project_id BIGINT PRIMARY KEY, price_fen BIGINT NOT NULL, updated_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		// 病例（bingli）独立订单表：不与其他业务表关联
		`CREATE TABLE IF NOT EXISTS bing_orders (order_id VARCHAR(32) PRIMARY KEY, user_id BIGINT NOT NULL, upstream_order_id VARCHAR(64) NOT NULL DEFAULT '', application_id VARCHAR(128) NOT NULL, application_name VARCHAR(200) NOT NULL DEFAULT '', fields_enc LONGTEXT NOT NULL, amount_fen BIGINT NOT NULL DEFAULT 0, upstream_price_points DECIMAL(18,2) NOT NULL DEFAULT 0, upstream_charged_points DECIMAL(18,2) NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'processing', image_ready TINYINT NOT NULL DEFAULT 0, render_error VARCHAR(500) NOT NULL DEFAULT '', remark VARCHAR(500) NOT NULL DEFAULT '', external_request_id VARCHAR(64) NOT NULL, created_at DOUBLE NOT NULL, updated_at DOUBLE NOT NULL, UNIQUE KEY uq_bing_external(external_request_id), KEY idx_bing_user(user_id, created_at), KEY idx_bing_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		// 病例：管理端运行开关（键值表；缺行按开启处理）
		`CREATE TABLE IF NOT EXISTS bing_settings (name VARCHAR(32) PRIMARY KEY, value VARCHAR(200) NOT NULL DEFAULT '', updated_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		// 病例：管理端按模板自定义售价（分）
		`CREATE TABLE IF NOT EXISTS bing_prices (application_id VARCHAR(128) PRIMARY KEY, price_fen BIGINT NOT NULL, updated_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		// 学习通自动签到（qd）：订单表（密码 AES-GCM 密文落库，不存明文）
		`CREATE TABLE IF NOT EXISTS qd_orders (order_id VARCHAR(32) PRIMARY KEY, user_id BIGINT NOT NULL, phone VARCHAR(20) NOT NULL, enc_pass LONGTEXT NOT NULL DEFAULT '', plan VARCHAR(8) NOT NULL DEFAULT 'week', engine_order_id VARCHAR(64) NOT NULL DEFAULT '', token VARCHAR(64) NOT NULL DEFAULT '', status VARCHAR(16) NOT NULL DEFAULT 'processing', engine_status VARCHAR(16) NOT NULL DEFAULT '', name VARCHAR(64) NOT NULL DEFAULT '', uid VARCHAR(32) NOT NULL DEFAULT '', sign_ok INT NOT NULL DEFAULT 0, sign_fail INT NOT NULL DEFAULT 0, push_url VARCHAR(500) NOT NULL DEFAULT '', last_msg VARCHAR(500) NOT NULL DEFAULT '', end_at DOUBLE NOT NULL DEFAULT 0, amount_fen BIGINT NOT NULL DEFAULT 0, created_at DOUBLE NOT NULL, updated_at DOUBLE NOT NULL, KEY idx_qd_user(user_id, created_at), KEY idx_qd_phone(phone), KEY idx_qd_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		// 学习通自动签到：管理端开关 + 卡型零售价（键值表；缺行按开启/默认价处理）
		`CREATE TABLE IF NOT EXISTS qd_settings (name VARCHAR(32) PRIMARY KEY, value VARCHAR(200) NOT NULL DEFAULT '', updated_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
		// 系统对接：易支付 + 天河课程 API（后台可改；覆盖 .env）
		`CREATE TABLE IF NOT EXISTS system_settings (name VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL, updated_at DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`,
	}
	statements = append(statements, `ALTER TABLE orders MODIFY password TEXT NOT NULL`, `ALTER TABLE course_submissions MODIFY password TEXT NOT NULL`, `ALTER TABLE sxdk_orders MODIFY password TEXT NOT NULL`)
	for _, statement := range statements {
		if _, err := DB.Exec(statement); err != nil {
			return fmt.Errorf("mysql schema migration failed: %w", err)
		}
	}
	if err := migrateMySQLTaskMutex(); err != nil {
		return err
	}
	return nil
}

// migrateMySQLTaskMutex 账号互斥防线。
// SQLite 版依赖 partial unique index（WHERE status IN ('pending','running')），
// MySQL 不支持 partial index，这里用「生成列 + 唯一索引」模拟：
// active_key = 活跃任务(pending/running)的 account_key，否则 NULL——
// MySQL 唯一索引对 NULL 不去重，所以终态行(done/error)不占唯一位。
// tasks.Submit 的 INSERT 撞唯一键(1062) → isUniqueViolation → ErrAlreadyRunning，
// 与 SQLite 行为完全一致。需要 MySQL 5.7+（生成列特性）。
func migrateMySQLTaskMutex() error {
	// 生成列不存在则补建（MySQL 无 ADD COLUMN IF NOT EXISTS，查 information_schema 判断）
	var n int
	err := DB.QueryRow(
		`SELECT count(*) FROM information_schema.columns
		 WHERE table_schema=DATABASE() AND table_name='scrape_tasks' AND column_name='active_key'`,
	).Scan(&n)
	if err != nil {
		return fmt.Errorf("mysql task-mutex migration failed: %w", err)
	}
	if n == 0 {
		if _, err := DB.Exec(
			`ALTER TABLE scrape_tasks ADD COLUMN active_key VARCHAR(255)
			 GENERATED ALWAYS AS (CASE WHEN status IN ('pending','running') THEN account_key ELSE NULL END) STORED`,
		); err != nil {
			return fmt.Errorf("mysql add active_key failed: %w", err)
		}
	}
	// 建唯一索引前先清残留活跃任务：单进程模型下启动时的 pending/running 必然是
	// 上次进程中断的遗留（tasks.Init 随后会做同样清理，语义一致）；
	// 若不清理，历史上因缺索引产生的同账号重复活跃行会让索引创建直接失败。
	if _, err := DB.Exec(
		`UPDATE scrape_tasks SET status='error', error='服务已重启，请重新提交查询'
		 WHERE status IN ('pending','running')`,
	); err != nil {
		return fmt.Errorf("mysql task-mutex dedup failed: %w", err)
	}
	// 唯一索引不存在则补建
	var idx int
	if err := DB.QueryRow(
		`SELECT count(*) FROM information_schema.statistics
		 WHERE table_schema=DATABASE() AND table_name='scrape_tasks' AND index_name='uq_scrape_task_active'`,
	).Scan(&idx); err != nil {
		return fmt.Errorf("mysql task-mutex migration failed: %w", err)
	}
	if idx == 0 {
		if _, err := DB.Exec(
			`ALTER TABLE scrape_tasks ADD UNIQUE KEY uq_scrape_task_active (active_key)`,
		); err != nil {
			return fmt.Errorf("mysql add uq_scrape_task_active failed: %w", err)
		}
	}
	return nil
}
