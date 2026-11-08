<?php
/**
 * syytianheban（爽歪歪天河版）- 项目秘钥绑定与余额兑换核心
 *
 * 主要功能：
 * 1. 项目秘钥绑定管理
 *    - bindProjectApiKey: 为代理在项目上绑定/获取固定API秘钥
 *    - getAgentProjectKeys: 获取代理的所有项目秘钥
 *    - getProjectKey: 获取代理在指定项目的秘钥
 *
 * 2. 余额兑换管理
 *    - chargeToProject: 从二开平台余额充值到项目秘钥
 *    - withdrawFromProject: 从项目秘钥提现到二开平台余额
 *    - getExchangeRecords: 获取兑换记录
 *    - getExchangeStatistics: 获取兑换统计数据
 *
 * 3. 项目管理（带兑换比例）
 *    - getProjects: 获取启用的项目列表
 *    - getProjectById: 根据ID获取项目信息
 *    - addProject: 添加新项目
 *    - updateProject: 更新项目信息（包括兑换比例）
 *    - deleteProject: 删除项目
 *
 * 4. 系统配置管理
 *    - getConfig: 获取配置项
 *    - setConfig: 设置配置项
 *    - getAllConfig: 获取所有配置
 *
 * 数据库表结构：
 * - config: 系统配置表
 * - projects: 项目信息表（含兑换比例）
 * - project_api_keys: 项目秘钥绑定表
 * - exchange_records: 余额兑换记录表
 *
 * 核心改进（对比V2）：
 * - 从临时开卡模式改为固定秘钥绑定模式
 * - 支持余额双向兑换（充值/提现）
 * - 可配置每个项目的兑换比例
 * - 完整的消费追踪和统计功能
 *
 * @author 天河skyriver
 * @version 3.3.3
 * @since 2026-09-13
 */
class UniversalAPI {
    private $db;
    private $db_file = __DIR__ . '/爽歪歪寄生系统数据库-勿删勿清理.log';

    /**
     * 构造函数：初始化SQLite数据库
     */
    public function __construct() {
        try {
            $old_db_files = [
                __DIR__ . '/universal_api_v3.db'
            ];
            foreach ($old_db_files as $old_db_file) {
                if (file_exists($old_db_file) && !file_exists($this->db_file)) {
                    rename($old_db_file, $this->db_file);
                    break;
                }
            }

            // 连接SQLite数据库（如果不存在会自动创建）
            $this->db = new PDO('sqlite:' . $this->db_file);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 初始化数据表
            $this->initDatabase();
        } catch (PDOException $e) {
            throw new Exception('数据库连接失败: ' . $e->getMessage());
        }
    }

    /**
     * 初始化数据库表结构
     */
    private function initDatabase() {
        // 迁移登记表本身必须先存在，版本号以唯一键保证只执行一次。
        $this->db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
        )");

        // 配置表
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                config_key TEXT UNIQUE NOT NULL,
                config_value TEXT,
                description TEXT,
                updated_at TEXT DEFAULT (datetime('now', 'localtime'))
            )
        ");

        // 项目表（带兑换比例）
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                source_api_url TEXT NOT NULL,
                source_project_id INTEGER,
                exchange_rate REAL DEFAULT 1.0,
                price REAL DEFAULT 1,
                redirect_url TEXT,
                docs_url TEXT,
                description TEXT,
                status INTEGER DEFAULT 1,
                created_at TEXT DEFAULT (datetime('now', 'localtime'))
            )
        ");

        // 项目秘钥绑定表（核心新增）
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS project_api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                agent_uid INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                source_project_id INTEGER,
                source_api_key TEXT NOT NULL,
                customer_id INTEGER,
                real_access_key TEXT,
                customer_link TEXT,
                status INTEGER DEFAULT 1,
                created_at TEXT DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT DEFAULT (datetime('now', 'localtime')),
                UNIQUE(agent_uid, project_id)
            )
        ");

        // 余额兑换记录表（核心新增）
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS exchange_records (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                agent_uid INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                exchange_type TEXT NOT NULL,
                platform_amount REAL NOT NULL,
                project_amount REAL NOT NULL,
                exchange_rate REAL NOT NULL,
                before_balance REAL DEFAULT 0,
                after_balance REAL DEFAULT 0,
                remark TEXT,
                transaction_id TEXT UNIQUE NOT NULL,
                status TEXT DEFAULT 'pending',
                source_response TEXT,
                created_at TEXT DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT DEFAULT (datetime('now', 'localtime'))
            )
        ");

        // 套娃模式：当前平台作为源台时的用户对接密钥
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS openapi_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uid INTEGER UNIQUE NOT NULL,
                api_key TEXT UNIQUE NOT NULL,
                status INTEGER DEFAULT 1,
                created_at TEXT DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT DEFAULT (datetime('now', 'localtime'))
            )
        ");

        // 套娃模式：下游平台在当前平台创建的客户/项目余额
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS openapi_customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_uid INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                source_project_id INTEGER,
                api_key TEXT UNIQUE NOT NULL,
                balance REAL DEFAULT 0,
                status INTEGER DEFAULT 1,
                upstream_customer_id INTEGER DEFAULT 0,
                upstream_project_id INTEGER DEFAULT 0,
                upstream_customer_api_key TEXT DEFAULT '',
                real_access_key TEXT DEFAULT '',
                customer_link TEXT DEFAULT '',
                created_at TEXT DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT DEFAULT (datetime('now', 'localtime'))
            )
        ");

        // 套娃模式：客户工单
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS openapi_tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_uid INTEGER NOT NULL,
                customer_id INTEGER NOT NULL,
                project_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                image_data TEXT,
                compensation_amount REAL DEFAULT 0,
                review_result TEXT,
                review_note TEXT,
                status TEXT DEFAULT 'pending',
                created_at TEXT DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT DEFAULT (datetime('now', 'localtime'))
            )
        ");

        // 套娃模式：客户工单回复
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS openapi_ticket_replies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_id INTEGER NOT NULL,
                owner_uid INTEGER NOT NULL,
                customer_id INTEGER NOT NULL,
                sender_type TEXT DEFAULT 'customer',
                content TEXT,
                image_data TEXT,
                created_at TEXT DEFAULT (datetime('now', 'localtime'))
            )
        ");

        // 套娃模式：对接调用日志
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS openapi_call_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_uid INTEGER NOT NULL,
                action TEXT NOT NULL,
                auth_type TEXT DEFAULT 'owner',
                customer_id INTEGER DEFAULT 0,
                project_id INTEGER DEFAULT 0,
                status TEXT DEFAULT 'success',
                message TEXT,
                ip TEXT,
                created_at TEXT DEFAULT (datetime('now', 'localtime'))
            )
        ");

        // 套娃模式：对接账本，记录每次余额消耗/返还和计算过程
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS openapi_ledger (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_uid INTEGER NOT NULL,
                action TEXT NOT NULL,
                customer_id INTEGER DEFAULT 0,
                project_id INTEGER DEFAULT 0,
                project_name TEXT,
                direction TEXT NOT NULL,
                project_amount REAL DEFAULT 0,
                project_balance_before REAL DEFAULT 0,
                project_balance_after REAL DEFAULT 0,
                platform_amount REAL DEFAULT 0,
                platform_balance_before REAL DEFAULT 0,
                platform_balance_after REAL DEFAULT 0,
                project_price REAL DEFAULT 0,
                user_rate REAL DEFAULT 0,
                base_rate REAL DEFAULT 0,
                actual_price REAL DEFAULT 0,
                formula TEXT,
                remark TEXT,
                ip TEXT,
                created_at TEXT DEFAULT (datetime('now', 'localtime'))
            )
        ");

        // 创建索引
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_agent_project ON project_api_keys(agent_uid, project_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_agent_uid ON project_api_keys(agent_uid)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_exchange_agent ON exchange_records(agent_uid)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_exchange_project ON exchange_records(project_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_key ON openapi_keys(api_key)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_customer_owner ON openapi_customers(owner_uid)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_customer_key ON openapi_customers(api_key)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_ticket_owner ON openapi_tickets(owner_uid, project_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_ticket_customer ON openapi_tickets(customer_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_ticket_reply ON openapi_ticket_replies(ticket_id)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_call_owner ON openapi_call_logs(owner_uid, created_at)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_call_action ON openapi_call_logs(action)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_ledger_owner ON openapi_ledger(owner_uid, created_at)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_ledger_project ON openapi_ledger(project_id)");

        // 源台/套娃客户审计表：缓存客户审计结果（不实时计算，后台更新）
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS customer_audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                audit_type TEXT DEFAULT 'upstream',
                subject_key TEXT,
                customer_id INTEGER NOT NULL,
                agent_uid INTEGER NOT NULL,
                owner_uid INTEGER DEFAULT 0,
                project_id INTEGER NOT NULL,
                project_name TEXT,
                source_api_key TEXT,
                customer_api_key TEXT,
                source_balance_add REAL DEFAULT 0,
                source_balance_subtract REAL DEFAULT 0,
                source_net REAL DEFAULT 0,
                local_charge_total REAL DEFAULT 0,
                local_withdraw_total REAL DEFAULT 0,
                local_net REAL DEFAULT 0,
                difference REAL DEFAULT 0,
                has_discrepancy INTEGER DEFAULT 0,
                audit_status TEXT DEFAULT 'pending',
                audit_error TEXT,
                discrepancy_details TEXT,
                last_audit_at TEXT,
                created_at TEXT DEFAULT (datetime('now', 'localtime')),
                updated_at TEXT DEFAULT (datetime('now', 'localtime')),
                UNIQUE(subject_key)
            )
        ");

        // 迁移旧审计表：原表只有 UNIQUE(customer_id)，会与 downstream 客户ID冲突
        try {
            $columns = $this->db->query("PRAGMA table_info(customer_audit)")->fetchAll(PDO::FETCH_ASSOC);
            $column_names = array_column($columns, 'name');
            $table_sql_row = $this->db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='customer_audit'")->fetch(PDO::FETCH_ASSOC);
            $table_sql = $table_sql_row['sql'] ?? '';
            $needs_rebuild = !in_array('audit_type', $column_names)
                || !in_array('subject_key', $column_names)
                || !in_array('owner_uid', $column_names)
                || !in_array('customer_api_key', $column_names)
                || (strpos($table_sql, 'UNIQUE(customer_id)') !== false);

            if ($needs_rebuild) {
                $this->db->beginTransaction();
                $this->db->exec("ALTER TABLE customer_audit RENAME TO customer_audit_old");
                $this->db->exec("
                    CREATE TABLE customer_audit (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        audit_type TEXT DEFAULT 'upstream',
                        subject_key TEXT,
                        customer_id INTEGER NOT NULL,
                        agent_uid INTEGER NOT NULL,
                        owner_uid INTEGER DEFAULT 0,
                        project_id INTEGER NOT NULL,
                        project_name TEXT,
                        source_api_key TEXT,
                        customer_api_key TEXT,
                        source_balance_add REAL DEFAULT 0,
                        source_balance_subtract REAL DEFAULT 0,
                        source_net REAL DEFAULT 0,
                        local_charge_total REAL DEFAULT 0,
                        local_withdraw_total REAL DEFAULT 0,
                        local_net REAL DEFAULT 0,
                        difference REAL DEFAULT 0,
                        has_discrepancy INTEGER DEFAULT 0,
                        audit_status TEXT DEFAULT 'pending',
                        audit_error TEXT,
                        discrepancy_details TEXT,
                        last_audit_at TEXT,
                        created_at TEXT DEFAULT (datetime('now', 'localtime')),
                        updated_at TEXT DEFAULT (datetime('now', 'localtime')),
                        UNIQUE(subject_key)
                    )
                ");
                $old_columns = array_column($this->db->query("PRAGMA table_info(customer_audit_old)")->fetchAll(PDO::FETCH_ASSOC), 'name');
                $old_has_details = in_array('discrepancy_details', $old_columns) ? 'discrepancy_details' : 'NULL';
                $this->db->exec("
                    INSERT OR IGNORE INTO customer_audit (
                        id, audit_type, subject_key, customer_id, agent_uid, owner_uid, project_id, project_name,
                        source_api_key, customer_api_key, source_balance_add, source_balance_subtract, source_net,
                        local_charge_total, local_withdraw_total, local_net, difference, has_discrepancy,
                        audit_status, audit_error, discrepancy_details, last_audit_at, created_at, updated_at
                    )
                    SELECT
                        id, 'upstream', 'upstream:' || customer_id, customer_id, agent_uid, 0, project_id, project_name,
                        source_api_key, source_api_key, source_balance_add, source_balance_subtract, source_net,
                        local_charge_total, local_withdraw_total, local_net, difference, has_discrepancy,
                        audit_status, audit_error, {$old_has_details}, last_audit_at, created_at, updated_at
                    FROM customer_audit_old
                ");
                $this->db->exec("DROP TABLE customer_audit_old");
                $this->db->commit();
            }
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[审计表迁移] 失败: ' . $e->getMessage());
        }

        // 创建索引
        $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_customer_audit_subject ON customer_audit(subject_key)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_customer_audit_agent ON customer_audit(agent_uid)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_customer_audit_owner ON customer_audit(owner_uid)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_customer_audit_type ON customer_audit(audit_type)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_customer_audit_type_status ON customer_audit(audit_type, audit_status)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_customer_audit_status ON customer_audit(audit_status)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_customer_audit_discrepancy ON customer_audit(has_discrepancy)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_openapi_ledger_customer ON openapi_ledger(owner_uid, customer_id, project_id, direction, created_at)");

        $this->runVersionedMigrations();

        // 爽歪歪逐层套娃时透传最终业务入口密钥；旧数据库自动补字段。
        try {
            $this->db->exec("ALTER TABLE openapi_customers ADD COLUMN real_access_key TEXT DEFAULT ''");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }
        try {
            $this->db->exec("ALTER TABLE openapi_customers ADD COLUMN customer_link TEXT DEFAULT ''");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }

        // 字段迁移：为已有的 project_api_keys 表添加 customer_id 字段
        try {
            $this->db->exec("ALTER TABLE project_api_keys ADD COLUMN customer_id INTEGER");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }

        // 字段迁移：为已有的 project_api_keys 表添加 real_access_key 字段
        // 套娃模式专用：存储“真实业务系统可识别的访问密钥”，用于访问项目时拼接 redirect_url?key=
        // source_api_key 仍用于与上游 openapi 通信（工单/余额），二者职责分离
        try {
            $this->db->exec("ALTER TABLE project_api_keys ADD COLUMN real_access_key TEXT");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }
        try {
            $this->db->exec("ALTER TABLE project_api_keys ADD COLUMN customer_link TEXT");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }

        // 字段迁移：为已有的 projects 表添加 redirect_url 字段
        try {
            $this->db->exec("ALTER TABLE projects ADD COLUMN redirect_url TEXT");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }
        try {
            $this->db->exec("ALTER TABLE projects ADD COLUMN docs_url TEXT");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }

        // 字段迁移：为已有的 projects 表添加 allow_charge 字段
        try {
            $this->db->exec("ALTER TABLE projects ADD COLUMN allow_charge INTEGER DEFAULT 1");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }

        // 字段迁移：为已有的 exchange_records 表添加幂等性字段
        try {
            $this->db->exec("ALTER TABLE exchange_records ADD COLUMN transaction_id TEXT DEFAULT ''");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }
        try {
            $this->db->exec("ALTER TABLE exchange_records ADD COLUMN status TEXT DEFAULT 'success'");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }
        try {
            $this->db->exec("ALTER TABLE exchange_records ADD COLUMN source_response TEXT");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }
        try {
            $this->db->exec("ALTER TABLE exchange_records ADD COLUMN updated_at TEXT DEFAULT (datetime('now', 'localtime'))");
        } catch (PDOException $e) {
            // 字段已存在，忽略错误
        }

        // 默认源平台API地址
        $this->db->exec("INSERT OR IGNORE INTO config (config_key, config_value, description) VALUES ('source_api_url', 'https://syyrun.com/api.php', '源平台API地址')");
        $this->db->exec("UPDATE config SET config_value='https://syyrun.com/api.php' WHERE config_key='source_api_url' AND config_value='https://syy.babiq.cc/api.php'");
        $this->db->exec("INSERT OR IGNORE INTO config (config_key, config_value, description) VALUES ('nested_mode_enabled', '0', '套娃模式开关')");
    }

    /**
     * 执行版本化数据库迁移。
     * 迁移成功后才写入版本号，避免半完成迁移被误认为已完成。
     */
    private function runVersionedMigrations() {
        $version = '2026_09_04_01_nested_customer_mapping';
        $stmt = $this->db->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
        $stmt->execute([':version' => $version]);
        if (!$stmt->fetchColumn()) {
        try {
            $this->db->beginTransaction();

            $columns = array_column(
                $this->db->query("PRAGMA table_info(openapi_customers)")->fetchAll(PDO::FETCH_ASSOC),
                'name'
            );
            $addColumns = [
                'upstream_customer_id' => 'INTEGER DEFAULT 0',
                'upstream_project_id' => 'INTEGER DEFAULT 0',
                'upstream_customer_api_key' => "TEXT DEFAULT ''",
                'real_access_key' => "TEXT DEFAULT ''",
                'customer_link' => "TEXT DEFAULT ''"
            ];
            foreach ($addColumns as $name => $definition) {
                if (!in_array($name, $columns, true)) {
                    $this->db->exec("ALTER TABLE openapi_customers ADD COLUMN {$name} {$definition}");
                }
            }

            // source_project_id 已由开户时保存，属于确定映射；客户 ID不能从本地自增 ID推断。
            $this->db->exec("UPDATE openapi_customers
                SET upstream_project_id = source_project_id
                WHERE COALESCE(upstream_project_id, 0) = 0
                  AND COALESCE(source_project_id, 0) > 0");

            $insert = $this->db->prepare(
                'INSERT INTO schema_migrations (version, applied_at)
                 VALUES (:version, datetime(\'now\', \'localtime\'))'
            );
            $insert->execute([':version' => $version]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new Exception('数据库迁移失败 [' . $version . ']: ' . $e->getMessage(), 0, $e);
        }
        }

        $version = '2026_09_04_02_openapi_transaction_id';
        $stmt = $this->db->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
        $stmt->execute([':version' => $version]);
        if ($stmt->fetchColumn()) {
            return;
        }

        try {
            $this->db->beginTransaction();
            $columns = array_column(
                $this->db->query("PRAGMA table_info(openapi_ledger)")->fetchAll(PDO::FETCH_ASSOC),
                'name'
            );
            if (!in_array('transaction_id', $columns, true)) {
                $this->db->exec("ALTER TABLE openapi_ledger ADD COLUMN transaction_id TEXT DEFAULT ''");
            }
            $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_openapi_ledger_transaction
                ON openapi_ledger(owner_uid, transaction_id)
                WHERE transaction_id <> ''");
            $insert = $this->db->prepare(
                'INSERT INTO schema_migrations (version, applied_at)
                 VALUES (:version, datetime(\'now\', \'localtime\'))'
            );
            $insert->execute([':version' => $version]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw new Exception('数据库迁移失败 [' . $version . ']: ' . $e->getMessage(), 0, $e);
        }
    }

    private function generateApiKey($prefix = 'syy') {
        return $prefix . '_' . bin2hex(random_bytes(24));
    }

    /**
     * 生成唯一交易ID（用于幂等性控制）
     * @return string 交易ID
     */
    private function generateTransactionId() {
        return 'txn_' . date('YmdHis') . '_' . bin2hex(random_bytes(8));
    }

    // ==================== 套娃模式对接密钥管理 ====================

    public function getOrCreateOpenApiKey($uid) {
        $stmt = $this->db->prepare("SELECT * FROM openapi_keys WHERE uid = :uid LIMIT 1");
        $stmt->execute([':uid' => $uid]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($key) {
            return $key;
        }

        $api_key = $this->generateApiKey('syytianheban');
        $stmt = $this->db->prepare("
            INSERT INTO openapi_keys (uid, api_key, status, created_at, updated_at)
            VALUES (:uid, :api_key, 1, datetime('now', 'localtime'), datetime('now', 'localtime'))
        ");
        $stmt->execute([':uid' => $uid, ':api_key' => $api_key]);

        return [
            'id' => $this->db->lastInsertId(),
            'uid' => $uid,
            'api_key' => $api_key,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    public function resetOpenApiKey($uid) {
        $api_key = $this->generateApiKey('syytianheban');
        $stmt = $this->db->prepare("
            INSERT INTO openapi_keys (uid, api_key, status, created_at, updated_at)
            VALUES (:uid, :api_key, 1, datetime('now', 'localtime'), datetime('now', 'localtime'))
            ON CONFLICT(uid) DO UPDATE SET api_key = excluded.api_key, status = 1, updated_at = datetime('now', 'localtime')
        ");
        $stmt->execute([':uid' => $uid, ':api_key' => $api_key]);
        return $this->getOrCreateOpenApiKey($uid);
    }

    public function getUserByOpenApiKey($api_key) {
        $stmt = $this->db->prepare("SELECT * FROM openapi_keys WHERE api_key = :api_key AND status = 1 LIMIT 1");
        $stmt->execute([':api_key' => $api_key]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createOpenApiCustomer($owner_uid, $project_id, $initial_balance = 0, $upstream = []) {
        $project = $this->getProjectById($project_id);
        if (!$project || intval($project['status']) !== 1) {
            throw new Exception('项目不存在或已禁用');
        }

        $initial_balance = floatval($initial_balance);
        if ($initial_balance < 0) {
            throw new Exception('初始余额不能为负数');
        }

        $api_key = $this->generateApiKey('cust');
        $stmt = $this->db->prepare("
            INSERT INTO openapi_customers
                (owner_uid, project_id, source_project_id, api_key, balance, status,
                 upstream_customer_id, upstream_project_id, upstream_customer_api_key, real_access_key, customer_link,
                 created_at, updated_at)
            VALUES
                (:owner_uid, :project_id, :source_project_id, :api_key, :balance, 1,
                 :upstream_customer_id, :upstream_project_id, :upstream_customer_api_key, :real_access_key, :customer_link,
                 datetime('now', 'localtime'), datetime('now', 'localtime'))
        ");
        $stmt->execute([
            ':owner_uid' => $owner_uid,
            ':project_id' => $project_id,
            ':source_project_id' => $project['source_project_id'] ?? $project_id,
            ':api_key' => $api_key,
            ':balance' => $initial_balance,
            ':upstream_customer_id' => intval($upstream['customer_id'] ?? 0),
            ':upstream_project_id' => intval($upstream['project_id'] ?? 0),
            ':upstream_customer_api_key' => strval($upstream['api_key'] ?? ''),
            ':real_access_key' => strval($upstream['real_access_key'] ?? ($upstream['api_key'] ?? '')),
            ':customer_link' => strval($upstream['customer_link'] ?? '')
        ]);

        return [
            'id' => intval($this->db->lastInsertId()),
            'owner_uid' => $owner_uid,
            'project_id' => $project_id,
            'source_project_id' => $project['source_project_id'] ?? $project_id,
            'api_key' => $api_key,
            'balance' => $initial_balance,
            'upstream_customer_id' => intval($upstream['customer_id'] ?? 0),
            'upstream_project_id' => intval($upstream['project_id'] ?? 0),
            'upstream_customer_api_key' => strval($upstream['api_key'] ?? ''),
            'real_access_key' => strval($upstream['real_access_key'] ?? ($upstream['api_key'] ?? '')),
            'customer_link' => strval($upstream['customer_link'] ?? ''),
            'project_name' => $project['name']
        ];
    }

    public function getOpenApiCustomerByApiKey($api_key) {
        $stmt = $this->db->prepare("SELECT * FROM openapi_customers WHERE api_key = :api_key AND status = 1 LIMIT 1");
        $stmt->execute([':api_key' => $api_key]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getOpenApiCustomer($customer_id, $project_id = null, $owner_uid = null) {
        $sql = "SELECT * FROM openapi_customers WHERE id = :id AND status = 1";
        $params = [':id' => $customer_id];
        if ($project_id !== null) {
            $sql .= " AND project_id = :project_id";
            $params[':project_id'] = $project_id;
        }
        if ($owner_uid !== null) {
            $sql .= " AND owner_uid = :owner_uid";
            $params[':owner_uid'] = $owner_uid;
        }
        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getOpenApiCustomerAnyStatus($customer_id, $project_id = null, $owner_uid = null) {
        $sql = "SELECT * FROM openapi_customers WHERE id = :id";
        $params = [':id' => intval($customer_id)];
        if ($project_id !== null) {
            $sql .= " AND project_id = :project_id";
            $params[':project_id'] = intval($project_id);
        }
        if ($owner_uid !== null) {
            $sql .= " AND owner_uid = :owner_uid";
            $params[':owner_uid'] = intval($owner_uid);
        }
        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function setOpenApiCustomerState($owner_uid, $customer_id, $status, $balance = null) {
        $sql = "UPDATE openapi_customers SET status = :status, updated_at = datetime('now', 'localtime')";
        $params = [
            ':status' => intval($status),
            ':id' => intval($customer_id),
            ':owner_uid' => intval($owner_uid)
        ];
        if ($balance !== null) {
            $sql .= ", balance = :balance";
            $params[':balance'] = floatval($balance);
        }
        $sql .= " WHERE id = :id AND owner_uid = :owner_uid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->getOpenApiCustomerAnyStatus($customer_id, null, $owner_uid);
    }

    public function adjustOpenApiCustomerBalance($owner_uid, $customer_id, $project_id, $amount) {
        $customer = $this->getOpenApiCustomer($customer_id, $project_id, $owner_uid);
        if (!$customer) {
            throw new Exception('客户不存在或无权限');
        }

        $before = floatval($customer['balance']);
        $after = $before + floatval($amount);
        if ($after < -0.000001) {
            throw new Exception('客户余额不足');
        }

        $stmt = $this->db->prepare("
            UPDATE openapi_customers
            SET balance = :balance, updated_at = datetime('now', 'localtime')
            WHERE id = :id AND owner_uid = :owner_uid
        ");
        $stmt->execute([':balance' => $after, ':id' => $customer_id, ':owner_uid' => $owner_uid]);

        return [
            'before_balance' => $before,
            'after_balance' => $after,
            'amount' => floatval($amount)
        ];
    }

    public function updateOpenApiCustomerBalance($owner_uid, $customer_id, $project_id, $balance) {
        $customer = $this->getOpenApiCustomer($customer_id, $project_id, $owner_uid);
        if (!$customer) {
            throw new Exception('客户不存在或无权限');
        }
        $stmt = $this->db->prepare("UPDATE openapi_customers
            SET balance = :balance, updated_at = datetime('now', 'localtime')
            WHERE id = :id AND owner_uid = :owner_uid AND project_id = :project_id AND status = 1");
        $stmt->execute([
            ':balance' => floatval($balance),
            ':id' => intval($customer_id),
            ':owner_uid' => intval($owner_uid),
            ':project_id' => intval($project_id)
        ]);
        return $this->getOpenApiCustomer($customer_id, $project_id, $owner_uid);
    }

    public function updateOpenApiProjectBalanceCache($owner_uid, $project_id, $balance) {
        $stmt = $this->db->prepare("UPDATE openapi_customers
            SET balance = :balance, updated_at = datetime('now', 'localtime')
            WHERE owner_uid = :owner_uid AND project_id = :project_id AND status = 1");
        $stmt->execute([
            ':balance' => floatval($balance),
            ':owner_uid' => intval($owner_uid),
            ':project_id' => intval($project_id)
        ]);
    }

    public function getOpenApiCustomersByIds($owner_uid, array $customer_ids) {
        $customer_ids = array_values(array_unique(array_filter(array_map('intval', $customer_ids))));
        if (empty($customer_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM openapi_customers WHERE owner_uid = ? AND status = 1 AND id IN ($placeholders)");
        $stmt->execute(array_merge([$owner_uid], $customer_ids));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ==================== 套娃模式调用统计 ====================

    public function logOpenApiCall($owner_uid, $action, $auth_type = 'owner', $customer_id = 0, $project_id = 0, $status = 'success', $message = '', $ip = '') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO openapi_call_logs (owner_uid, action, auth_type, customer_id, project_id, status, message, ip, created_at)
                VALUES (:owner_uid, :action, :auth_type, :customer_id, :project_id, :status, :message, :ip, datetime('now', 'localtime'))
            ");
            $stmt->execute([
                ':owner_uid' => $owner_uid,
                ':action' => $action,
                ':auth_type' => $auth_type,
                ':customer_id' => intval($customer_id),
                ':project_id' => intval($project_id),
                ':status' => $status,
                ':message' => substr($message, 0, 255),
                ':ip' => $ip
            ]);
        } catch (Exception $e) {
            // 统计日志不能影响主流程
        }
    }

    public function addOpenApiLedger($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO openapi_ledger
                (owner_uid, action, customer_id, project_id, project_name, direction, project_amount, project_balance_before, project_balance_after, platform_amount, platform_balance_before, platform_balance_after, project_price, user_rate, base_rate, actual_price, formula, remark, ip, transaction_id, created_at)
                VALUES
                (:owner_uid, :action, :customer_id, :project_id, :project_name, :direction, :project_amount, :project_balance_before, :project_balance_after, :platform_amount, :platform_balance_before, :platform_balance_after, :project_price, :user_rate, :base_rate, :actual_price, :formula, :remark, :ip, :transaction_id, datetime('now', 'localtime'))
            ");
            $stmt->execute([
                ':owner_uid' => intval($data['owner_uid'] ?? 0),
                ':action' => $data['action'] ?? '',
                ':customer_id' => intval($data['customer_id'] ?? 0),
                ':project_id' => intval($data['project_id'] ?? 0),
                ':project_name' => $data['project_name'] ?? '',
                ':direction' => $data['direction'] ?? '',
                ':project_amount' => floatval($data['project_amount'] ?? 0),
                ':project_balance_before' => floatval($data['project_balance_before'] ?? 0),
                ':project_balance_after' => floatval($data['project_balance_after'] ?? 0),
                ':platform_amount' => floatval($data['platform_amount'] ?? 0),
                ':platform_balance_before' => floatval($data['platform_balance_before'] ?? 0),
                ':platform_balance_after' => floatval($data['platform_balance_after'] ?? 0),
                ':project_price' => floatval($data['project_price'] ?? 0),
                ':user_rate' => floatval($data['user_rate'] ?? 0),
                ':base_rate' => floatval($data['base_rate'] ?? 0),
                ':actual_price' => floatval($data['actual_price'] ?? 0),
                ':formula' => $data['formula'] ?? '',
                ':remark' => $data['remark'] ?? '',
                ':ip' => $data['ip'] ?? '',
                ':transaction_id' => trim(strval($data['transaction_id'] ?? ''))
            ]);
            return intval($this->db->lastInsertId());
        } catch (Exception $e) {
            return 0;
        }
    }

    public function getOpenApiLedger($owner_uid, $limit = 50) {
        $stmt = $this->db->prepare("SELECT * FROM openapi_ledger WHERE owner_uid = :uid ORDER BY id DESC LIMIT :limit");
        $stmt->bindValue(':uid', intval($owner_uid), PDO::PARAM_INT);
        $stmt->bindValue(':limit', intval($limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOpenApiLedgerByTransactionId($owner_uid, $transaction_id) {
        if (trim(strval($transaction_id)) === '') return null;
        $stmt = $this->db->prepare("SELECT * FROM openapi_ledger
            WHERE owner_uid = :owner_uid AND transaction_id = :transaction_id LIMIT 1");
        $stmt->execute([
            ':owner_uid' => intval($owner_uid),
            ':transaction_id' => trim(strval($transaction_id))
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getOpenApiUsageStats($owner_uid, $limit = 20) {
        $stats = [];
        $rows = $this->db->prepare("SELECT action, COUNT(*) as total FROM openapi_call_logs WHERE owner_uid = :uid GROUP BY action ORDER BY total DESC");
        $rows->execute([':uid' => $owner_uid]);
        $stats['by_action'] = $rows->fetchAll(PDO::FETCH_ASSOC);

        $row = $this->db->prepare("SELECT COUNT(*) as total FROM openapi_call_logs WHERE owner_uid = :uid");
        $row->execute([':uid' => $owner_uid]);
        $stats['total_calls'] = intval($row->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $row = $this->db->prepare("SELECT COUNT(*) as total FROM openapi_call_logs WHERE owner_uid = :uid AND created_at >= datetime('now', '-1 day', 'localtime')");
        $row->execute([':uid' => $owner_uid]);
        $stats['today_calls'] = intval($row->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $row = $this->db->prepare("SELECT COUNT(*) as total FROM openapi_customers WHERE owner_uid = :uid AND status = 1");
        $row->execute([':uid' => $owner_uid]);
        $stats['customer_count'] = intval($row->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $row = $this->db->prepare("SELECT COALESCE(SUM(balance), 0) as total FROM openapi_customers WHERE owner_uid = :uid AND status = 1");
        $row->execute([':uid' => $owner_uid]);
        $stats['customer_balance'] = floatval($row->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $row = $this->db->prepare("SELECT COUNT(*) as total FROM openapi_tickets WHERE owner_uid = :uid");
        $row->execute([':uid' => $owner_uid]);
        $stats['ticket_count'] = intval($row->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $stmt = $this->db->prepare("SELECT * FROM openapi_call_logs WHERE owner_uid = :uid ORDER BY id DESC LIMIT :limit");
        $stmt->bindValue(':uid', $owner_uid, PDO::PARAM_INT);
        $stmt->bindValue(':limit', intval($limit), PDO::PARAM_INT);
        $stmt->execute();
        $stats['recent_logs'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stats['recent_ledger'] = $this->getOpenApiLedger($owner_uid, $limit);

        return $stats;
    }

    // ==================== 系统管理聚合查询 ====================

    public function getSystemOverview() {
        $overview = [];
        $overview['agent_count'] = intval($this->db->query("SELECT COUNT(DISTINCT agent_uid) AS c FROM project_api_keys WHERE status = 1")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
        $overview['project_binding_count'] = intval($this->db->query("SELECT COUNT(*) AS c FROM project_api_keys WHERE status = 1")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
        $overview['active_project_count'] = intval($this->db->query("SELECT COUNT(*) AS c FROM projects WHERE status = 1")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        $exchange = $this->db->query("
            SELECT
                COALESCE(SUM(CASE WHEN exchange_type = 'charge' THEN ABS(platform_amount) ELSE 0 END), 0) AS total_charge,
                COALESCE(SUM(CASE WHEN exchange_type = 'withdraw' THEN platform_amount ELSE 0 END), 0) AS total_withdraw,
                COUNT(*) AS total_records
            FROM exchange_records
        ")->fetch(PDO::FETCH_ASSOC);
        $overview['exchange'] = [
            'total_charge' => floatval($exchange['total_charge'] ?? 0),
            'total_withdraw' => floatval($exchange['total_withdraw'] ?? 0),
            'net_amount' => floatval($exchange['total_withdraw'] ?? 0) - floatval($exchange['total_charge'] ?? 0),
            'total_records' => intval($exchange['total_records'] ?? 0)
        ];

        $openapi = $this->db->query("
            SELECT
                COALESCE(SUM(CASE WHEN direction = 'charge' THEN ABS(platform_amount) ELSE 0 END), 0) AS total_charge,
                COALESCE(SUM(CASE WHEN direction = 'withdraw' THEN platform_amount ELSE 0 END), 0) AS total_withdraw,
                COUNT(*) AS total_records
            FROM openapi_ledger
        ")->fetch(PDO::FETCH_ASSOC);
        $overview['openapi'] = [
            'total_charge' => floatval($openapi['total_charge'] ?? 0),
            'total_withdraw' => floatval($openapi['total_withdraw'] ?? 0),
            'net_amount' => floatval($openapi['total_withdraw'] ?? 0) - floatval($openapi['total_charge'] ?? 0),
            'total_records' => intval($openapi['total_records'] ?? 0),
            'today_calls' => intval($this->db->query("SELECT COUNT(*) AS c FROM openapi_call_logs WHERE created_at >= datetime('now', '-1 day', 'localtime')")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0),
            'customer_count' => intval($this->db->query("SELECT COUNT(*) AS c FROM openapi_customers WHERE status = 1")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0)
        ];
        $overview['tickets'] = [
            'total' => intval($this->db->query("SELECT COUNT(*) AS c FROM openapi_tickets")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0),
            'pending' => intval($this->db->query("SELECT COUNT(*) AS c FROM openapi_tickets WHERE status = 'pending'")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0)
        ];
        return $overview;
    }

    public function getSystemAgentStats($page = 1, $page_size = 20, $keyword = '', $matched_user_ids = []) {
        $page = max(1, intval($page));
        $page_size = min(100, max(1, intval($page_size)));
        $offset = ($page - 1) * $page_size;

        $uids = [];
        foreach (['SELECT DISTINCT agent_uid AS uid FROM project_api_keys WHERE status = 1', 'SELECT DISTINCT agent_uid AS uid FROM exchange_records', 'SELECT DISTINCT owner_uid AS uid FROM openapi_ledger'] as $sql) {
            foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $uids[intval($row['uid'])] = intval($row['uid']);
            }
        }
        if ($keyword !== '') {
            if (ctype_digit($keyword)) {
                $uids = array_intersect_key($uids, [intval($keyword) => intval($keyword)]);
            } elseif (!empty($matched_user_ids)) {
                $uids = array_intersect_key($uids, array_fill_keys(array_map('intval', $matched_user_ids), true));
            }
        }
        ksort($uids);
        $total = count($uids);
        $page_uids = array_slice(array_values($uids), $offset, $page_size);
        $list = [];
        foreach ($page_uids as $uid) {
            $project = $this->db->prepare("SELECT COUNT(*) AS c FROM project_api_keys WHERE status = 1 AND agent_uid = :uid");
            $project->execute([':uid' => $uid]);
            $exchange = $this->db->prepare("
                SELECT COUNT(*) AS exchange_count,
                    COALESCE(SUM(CASE WHEN exchange_type = 'charge' THEN ABS(platform_amount) ELSE 0 END), 0) AS total_charge,
                    COALESCE(SUM(CASE WHEN exchange_type = 'withdraw' THEN platform_amount ELSE 0 END), 0) AS total_withdraw,
                    MAX(created_at) AS last_exchange_at
                FROM exchange_records WHERE agent_uid = :uid
            ");
            $exchange->execute([':uid' => $uid]);
            $ex = $exchange->fetch(PDO::FETCH_ASSOC) ?: [];
            $open = $this->db->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN direction = 'charge' THEN ABS(platform_amount) ELSE 0 END), 0) AS openapi_charge,
                    COALESCE(SUM(CASE WHEN direction = 'withdraw' THEN platform_amount ELSE 0 END), 0) AS openapi_withdraw,
                    MAX(created_at) AS last_openapi_at
                FROM openapi_ledger WHERE owner_uid = :uid
            ");
            $open->execute([':uid' => $uid]);
            $op = $open->fetch(PDO::FETCH_ASSOC) ?: [];
            $calls = $this->db->prepare("SELECT COUNT(*) AS c FROM openapi_call_logs WHERE owner_uid = :uid AND created_at >= datetime('now', '-1 day', 'localtime')");
            $calls->execute([':uid' => $uid]);
            $list[] = [
                'uid' => $uid,
                'project_count' => intval($project->fetch(PDO::FETCH_ASSOC)['c'] ?? 0),
                'exchange_count' => intval($ex['exchange_count'] ?? 0),
                'total_charge' => floatval($ex['total_charge'] ?? 0),
                'total_withdraw' => floatval($ex['total_withdraw'] ?? 0),
                'net_charge' => floatval($ex['total_charge'] ?? 0) - floatval($ex['total_withdraw'] ?? 0),
                'openapi_charge' => floatval($op['openapi_charge'] ?? 0),
                'openapi_withdraw' => floatval($op['openapi_withdraw'] ?? 0),
                'today_calls' => intval($calls->fetch(PDO::FETCH_ASSOC)['c'] ?? 0),
                'last_activity' => max($ex['last_exchange_at'] ?? '', $op['last_openapi_at'] ?? '')
            ];
        }
        return ['list' => $list, 'pagination' => ['page' => $page, 'page_size' => $page_size, 'total' => $total, 'total_pages' => $page_size > 0 ? ceil($total / $page_size) : 1]];
    }

    public function getSystemAgentDetail($agent_uid) {
        $agent_uid = intval($agent_uid);
        $keys = $this->getAgentProjectKeys($agent_uid);
        foreach ($keys as &$key) {
            $project_id = intval($key['project_id']);
            $stmt = $this->db->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN exchange_type = 'charge' THEN ABS(platform_amount) ELSE 0 END), 0) AS total_charge,
                    COALESCE(SUM(CASE WHEN exchange_type = 'withdraw' THEN platform_amount ELSE 0 END), 0) AS total_withdraw,
                    MAX(created_at) AS last_exchange_at
                FROM exchange_records WHERE agent_uid = :uid AND project_id = :project_id
            ");
            $stmt->execute([':uid' => $agent_uid, ':project_id' => $project_id]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $key['total_charge'] = floatval($stats['total_charge'] ?? 0);
            $key['total_withdraw'] = floatval($stats['total_withdraw'] ?? 0);
            $key['net_charge'] = $key['total_charge'] - $key['total_withdraw'];
            $key['last_exchange_at'] = $stats['last_exchange_at'] ?? '';
        }
        unset($key);
        return [
            'projects' => $keys,
            'openapi_customers' => $this->getSystemOpenApiCustomers($agent_uid),
            'summary' => $this->getExchangeStatistics($agent_uid),
            'openapi' => $this->getOpenApiUsageStats($agent_uid, 10)
        ];
    }

    public function getSystemOpenApiCustomers($owner_uid) {
        $stmt = $this->db->prepare("
            SELECT c.*, p.name AS project_name, p.price AS project_price,
                COALESCE(SUM(CASE WHEN l.direction = 'charge' THEN ABS(l.platform_amount) ELSE 0 END), 0) AS total_charge,
                COALESCE(SUM(CASE WHEN l.direction = 'withdraw' THEN l.platform_amount ELSE 0 END), 0) AS total_withdraw,
                COUNT(l.id) AS ledger_count,
                MAX(l.created_at) AS last_ledger_at
            FROM openapi_customers c
            LEFT JOIN projects p ON c.project_id = p.id
            LEFT JOIN openapi_ledger l ON l.owner_uid = c.owner_uid AND l.customer_id = c.id
            WHERE c.owner_uid = :uid
            GROUP BY c.id
            ORDER BY c.id DESC
        ");
        $stmt->execute([':uid' => intval($owner_uid)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSystemLedger($filters = []) {
        $page = max(1, intval($filters['page'] ?? 1));
        $page_size = min(100, max(1, intval($filters['page_size'] ?? 20)));
        $offset = ($page - 1) * $page_size;
        $agent_uid = intval($filters['agent_uid'] ?? 0);
        $project_id = intval($filters['project_id'] ?? 0);
        $source = $filters['source'] ?? '';
        $direction = $filters['direction'] ?? '';
        $keyword = trim($filters['keyword'] ?? '');
        $matched_user_ids = array_values(array_unique(array_filter(array_map('intval', $filters['matched_user_ids'] ?? []))));
        $date_start = trim($filters['date_start'] ?? '');
        $date_end = trim($filters['date_end'] ?? '');

        $params = [];
        $parts = [];

        if ($source === '' || $source === 'exchange') {
            $where = ['1=1'];
            if ($agent_uid > 0) { $where[] = 'er.agent_uid = :agent_uid'; $params[':agent_uid'] = $agent_uid; }
            if ($project_id > 0) { $where[] = 'er.project_id = :project_id'; $params[':project_id'] = $project_id; }
            if ($direction !== '') { $where[] = 'er.exchange_type = :direction'; $params[':direction'] = $direction; }
            if ($date_start !== '') { $where[] = 'er.created_at >= :date_start'; $params[':date_start'] = $date_start . ' 00:00:00'; }
            if ($date_end !== '') { $where[] = 'er.created_at <= :date_end'; $params[':date_end'] = $date_end . ' 23:59:59'; }
            $parts[] = "
                SELECT 'exchange' AS ledger_source, er.id AS ledger_id, er.agent_uid AS owner_uid,
                    er.project_id, COALESCE(p.name, '') AS project_name, 0 AS customer_id,
                    er.exchange_type AS direction, 'manual_exchange' AS business_type,
                    er.platform_amount AS platform_amount,
                    CASE WHEN er.exchange_type = 'withdraw' THEN -ABS(er.project_amount) ELSE er.project_amount END AS project_amount,
                    NULL AS platform_balance_before, NULL AS platform_balance_after,
                    er.before_balance AS project_balance_before, er.after_balance AS project_balance_after,
                    er.exchange_rate AS actual_price,
                    CASE WHEN er.exchange_type = 'charge'
                        THEN ABS(er.platform_amount) || ' 元 ÷ ' || er.exchange_rate || ' 元/km = ' || ABS(er.project_amount) || ' km'
                        ELSE ABS(er.project_amount) || ' km × ' || er.exchange_rate || ' 元/km = ' || er.platform_amount || ' 元'
                    END AS formula,
                    COALESCE(er.remark, '') AS remark, '' AS ip, er.created_at AS created_at
                FROM exchange_records er
                LEFT JOIN projects p ON er.project_id = p.id
                WHERE " . implode(' AND ', $where);
        }

        if ($source === '' || $source === 'openapi') {
            $where = ['1=1'];
            if ($agent_uid > 0) { $where[] = 'owner_uid = :agent_uid'; $params[':agent_uid'] = $agent_uid; }
            if ($project_id > 0) { $where[] = 'project_id = :project_id'; $params[':project_id'] = $project_id; }
            if ($direction !== '') { $where[] = 'direction = :direction'; $params[':direction'] = $direction; }
            if ($date_start !== '') { $where[] = 'created_at >= :date_start'; $params[':date_start'] = $date_start . ' 00:00:00'; }
            if ($date_end !== '') { $where[] = 'created_at <= :date_end'; $params[':date_end'] = $date_end . ' 23:59:59'; }
            $parts[] = "
                SELECT 'openapi' AS ledger_source, id AS ledger_id, owner_uid,
                    project_id, COALESCE(project_name, '') AS project_name, customer_id,
                    direction, action AS business_type,
                    platform_amount, project_amount,
                    platform_balance_before, platform_balance_after,
                    project_balance_before, project_balance_after,
                    actual_price, COALESCE(formula, '') AS formula,
                    COALESCE(remark, '') AS remark, COALESCE(ip, '') AS ip, created_at
                FROM openapi_ledger
                WHERE " . implode(' AND ', $where);
        }

        if (empty($parts)) {
            return ['list' => [], 'summary' => ['platform_charge_total' => 0, 'platform_withdraw_total' => 0, 'project_charge_total' => 0, 'project_withdraw_total' => 0, 'net_platform_amount' => 0, 'record_count' => 0], 'pagination' => ['page' => $page, 'page_size' => $page_size, 'total' => 0, 'total_pages' => 1]];
        }

        $baseSql = implode("\nUNION ALL\n", $parts);
        $outerWhere = [];
        if ($keyword !== '') {
            $outerWhere[] = "(project_name LIKE :kw OR remark LIKE :kw OR formula LIKE :kw OR CAST(owner_uid AS TEXT) = :kw_exact OR CAST(customer_id AS TEXT) = :kw_exact" . (!empty($matched_user_ids) ? " OR owner_uid IN (" . implode(',', $matched_user_ids) . ")" : "") . ")";
            $params[':kw'] = '%' . $keyword . '%';
            $params[':kw_exact'] = $keyword;
        }
        $filteredSql = "SELECT * FROM (" . $baseSql . ") u" . (!empty($outerWhere) ? " WHERE " . implode(' AND ', $outerWhere) : '');

        $countStmt = $this->db->prepare("SELECT COUNT(*) AS total FROM (" . $filteredSql . ") c");
        foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
        $countStmt->execute();
        $total = intval($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $summaryStmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN platform_amount < 0 THEN ABS(platform_amount) ELSE 0 END), 0) AS platform_charge_total,
                COALESCE(SUM(CASE WHEN platform_amount > 0 THEN platform_amount ELSE 0 END), 0) AS platform_withdraw_total,
                COALESCE(SUM(CASE WHEN project_amount > 0 THEN project_amount ELSE 0 END), 0) AS project_charge_total,
                COALESCE(SUM(CASE WHEN project_amount < 0 THEN ABS(project_amount) ELSE 0 END), 0) AS project_withdraw_total,
                COALESCE(SUM(CASE WHEN platform_amount < 0 THEN ABS(platform_amount) ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN platform_amount > 0 THEN platform_amount ELSE 0 END), 0) AS net_platform_amount
            FROM (" . $filteredSql . ") s
        ");
        foreach ($params as $k => $v) $summaryStmt->bindValue($k, $v);
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $summary = [
            'platform_charge_total' => floatval($summary['platform_charge_total'] ?? 0),
            'platform_withdraw_total' => floatval($summary['platform_withdraw_total'] ?? 0),
            'project_charge_total' => floatval($summary['project_charge_total'] ?? 0),
            'project_withdraw_total' => floatval($summary['project_withdraw_total'] ?? 0),
            'net_platform_amount' => floatval($summary['net_platform_amount'] ?? 0),
            'record_count' => $total
        ];

        $listStmt = $this->db->prepare($filteredSql . " ORDER BY created_at DESC, ledger_id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) $listStmt->bindValue($k, $v);
        $listStmt->bindValue(':limit', $page_size, PDO::PARAM_INT);
        $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $listStmt->execute();
        $list = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        return ['list' => $list, 'summary' => $summary, 'pagination' => ['page' => $page, 'page_size' => $page_size, 'total' => $total, 'total_pages' => $page_size > 0 ? ceil($total / $page_size) : 1]];
    }

    private function buildUnifiedLedgerRows($filters) {
        $rows = [];
        $agent_uid = intval($filters['agent_uid'] ?? 0);
        $project_id = intval($filters['project_id'] ?? 0);
        $source = $filters['source'] ?? '';
        $direction = $filters['direction'] ?? '';
        $keyword = trim($filters['keyword'] ?? '');
        $matched_user_ids = array_map('intval', $filters['matched_user_ids'] ?? []);
        $date_start = trim($filters['date_start'] ?? '');
        $date_end = trim($filters['date_end'] ?? '');

        if ($source === '' || $source === 'exchange') {
            $sql = "SELECT er.*, p.name AS project_name FROM exchange_records er LEFT JOIN projects p ON er.project_id = p.id WHERE 1=1";
            $params = [];
            if ($agent_uid > 0) { $sql .= " AND er.agent_uid = :agent_uid"; $params[':agent_uid'] = $agent_uid; }
            if ($project_id > 0) { $sql .= " AND er.project_id = :project_id"; $params[':project_id'] = $project_id; }
            if ($direction !== '') { $sql .= " AND er.exchange_type = :direction"; $params[':direction'] = $direction; }
            if ($date_start !== '') { $sql .= " AND er.created_at >= :date_start"; $params[':date_start'] = $date_start . ' 00:00:00'; }
            if ($date_end !== '') { $sql .= " AND er.created_at <= :date_end"; $params[':date_end'] = $date_end . ' 23:59:59'; }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $projectAmount = $r['exchange_type'] === 'withdraw' ? -abs(floatval($r['project_amount'])) : floatval($r['project_amount']);
                $formula = $r['exchange_type'] === 'charge' ? abs(floatval($r['platform_amount'])) . ' 元 ÷ ' . floatval($r['exchange_rate']) . ' 元/km = ' . abs($projectAmount) . ' km' : abs($projectAmount) . ' km × ' . floatval($r['exchange_rate']) . ' 元/km = ' . floatval($r['platform_amount']) . ' 元';
                $rows[] = ['ledger_source' => 'exchange', 'ledger_id' => intval($r['id']), 'owner_uid' => intval($r['agent_uid']), 'project_id' => intval($r['project_id']), 'project_name' => $r['project_name'] ?? '', 'customer_id' => 0, 'direction' => $r['exchange_type'], 'business_type' => 'manual_exchange', 'platform_amount' => floatval($r['platform_amount']), 'project_amount' => $projectAmount, 'platform_balance_before' => null, 'platform_balance_after' => null, 'project_balance_before' => floatval($r['before_balance']), 'project_balance_after' => floatval($r['after_balance']), 'actual_price' => floatval($r['exchange_rate']), 'formula' => $formula, 'remark' => $r['remark'] ?? '', 'ip' => '', 'created_at' => $r['created_at']];
            }
        }

        if ($source === '' || $source === 'openapi') {
            $sql = "SELECT * FROM openapi_ledger WHERE 1=1";
            $params = [];
            if ($agent_uid > 0) { $sql .= " AND owner_uid = :agent_uid"; $params[':agent_uid'] = $agent_uid; }
            if ($project_id > 0) { $sql .= " AND project_id = :project_id"; $params[':project_id'] = $project_id; }
            if ($direction !== '') { $sql .= " AND direction = :direction"; $params[':direction'] = $direction; }
            if ($date_start !== '') { $sql .= " AND created_at >= :date_start"; $params[':date_start'] = $date_start . ' 00:00:00'; }
            if ($date_end !== '') { $sql .= " AND created_at <= :date_end"; $params[':date_end'] = $date_end . ' 23:59:59'; }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['ledger_source' => 'openapi', 'ledger_id' => intval($r['id']), 'owner_uid' => intval($r['owner_uid']), 'project_id' => intval($r['project_id']), 'project_name' => $r['project_name'] ?? '', 'customer_id' => intval($r['customer_id']), 'direction' => $r['direction'], 'business_type' => $r['action'], 'platform_amount' => floatval($r['platform_amount']), 'project_amount' => floatval($r['project_amount']), 'platform_balance_before' => floatval($r['platform_balance_before']), 'platform_balance_after' => floatval($r['platform_balance_after']), 'project_balance_before' => floatval($r['project_balance_before']), 'project_balance_after' => floatval($r['project_balance_after']), 'actual_price' => floatval($r['actual_price']), 'formula' => $r['formula'] ?? '', 'remark' => $r['remark'] ?? '', 'ip' => $r['ip'] ?? '', 'created_at' => $r['created_at']];
            }
        }
        if ($keyword !== '') {
            $rows = array_values(array_filter($rows, function($row) use ($keyword, $matched_user_ids) {
                return stripos($row['project_name'], $keyword) !== false
                    || stripos($row['remark'], $keyword) !== false
                    || stripos($row['formula'], $keyword) !== false
                    || strval($row['owner_uid']) === $keyword
                    || strval($row['customer_id']) === $keyword
                    || in_array(intval($row['owner_uid']), $matched_user_ids);
            }));
        }
        return $rows;
    }

    // ==================== 套娃模式工单管理 ====================

    public function submitOpenApiTicket($owner_uid, $customer_id, $project_id, $type, $title, $description, $image_data = '', $compensation_amount = '') {
        $customer = $this->getOpenApiCustomer($customer_id, $project_id, $owner_uid);
        if (!$customer) {
            throw new Exception('客户不存在或无权限');
        }
        if (!in_array($type, ['suggestion', 'bug', 'compensation'])) {
            throw new Exception('工单类型无效');
        }
        if (trim($title) === '' || trim($description) === '') {
            throw new Exception('工单标题和描述不能为空');
        }

        $stmt = $this->db->prepare("
            INSERT INTO openapi_tickets
            (owner_uid, customer_id, project_id, type, title, description, image_data, compensation_amount, status, created_at, updated_at)
            VALUES
            (:owner_uid, :customer_id, :project_id, :type, :title, :description, :image_data, :compensation_amount, 'pending', datetime('now', 'localtime'), datetime('now', 'localtime'))
        ");
        $stmt->execute([
            ':owner_uid' => $owner_uid,
            ':customer_id' => $customer_id,
            ':project_id' => $project_id,
            ':type' => $type,
            ':title' => $title,
            ':description' => $description,
            ':image_data' => $image_data,
            ':compensation_amount' => $compensation_amount === '' ? 0 : floatval($compensation_amount)
        ]);

        return $this->getOpenApiTicket($owner_uid, intval($this->db->lastInsertId()), $project_id);
    }

    public function getOpenApiTickets($owner_uid, $project_id = 0, $status = '', $type = '', $page = 1, $limit = 20) {
        $sql = "
            SELECT t.*, p.name as project_name
            FROM openapi_tickets t
            LEFT JOIN projects p ON t.project_id = p.id
            WHERE t.owner_uid = :owner_uid
        ";
        $params = [':owner_uid' => $owner_uid];
        if ($project_id > 0) {
            $sql .= " AND t.project_id = :project_id";
            $params[':project_id'] = $project_id;
        }
        if ($status !== '') {
            $sql .= " AND t.status = :status";
            $params[':status'] = $status;
        }
        if ($type !== '') {
            $sql .= " AND t.type = :type";
            $params[':type'] = $type;
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM (" . $sql . ") x");
        $countStmt->execute($params);
        $total = intval($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $offset = (max(1, intval($page)) - 1) * intval($limit);
        $sql .= " ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', intval($limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', intval($offset), PDO::PARAM_INT);
        $stmt->execute();

        return ['list' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => intval($page), 'limit' => intval($limit)];
    }

    public function getOpenApiTicket($owner_uid, $ticket_id, $project_id = 0) {
        $sql = "
            SELECT t.*, p.name as project_name
            FROM openapi_tickets t
            LEFT JOIN projects p ON t.project_id = p.id
            WHERE t.owner_uid = :owner_uid AND t.id = :id
        ";
        $params = [':owner_uid' => $owner_uid, ':id' => $ticket_id];
        if ($project_id > 0) {
            $sql .= " AND t.project_id = :project_id";
            $params[':project_id'] = $project_id;
        }
        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM openapi_ticket_replies WHERE ticket_id = :ticket_id ORDER BY created_at ASC, id ASC");
        $stmt->execute([':ticket_id' => $ticket_id]);
        $ticket['replies'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $ticket;
    }

    public function replyOpenApiTicket($owner_uid, $ticket_id, $project_id, $customer_id, $content = '', $image_data = '', $sender_type = 'customer') {
        $ticket = $this->getOpenApiTicket($owner_uid, $ticket_id, $project_id);
        if (!$ticket) {
            throw new Exception('工单不存在或无权限');
        }
        if (trim($content) === '' && trim($image_data) === '') {
            throw new Exception('回复内容或图片不能为空');
        }

        $stmt = $this->db->prepare("
            INSERT INTO openapi_ticket_replies (ticket_id, owner_uid, customer_id, sender_type, content, image_data, created_at)
            VALUES (:ticket_id, :owner_uid, :customer_id, :sender_type, :content, :image_data, datetime('now', 'localtime'))
        ");
        $stmt->execute([
            ':ticket_id' => $ticket_id,
            ':owner_uid' => $owner_uid,
            ':customer_id' => $customer_id,
            ':sender_type' => $sender_type,
            ':content' => $content,
            ':image_data' => $image_data
        ]);

        $this->db->prepare("UPDATE openapi_tickets SET status = 'processing', updated_at = datetime('now', 'localtime') WHERE id = :id")
            ->execute([':id' => $ticket_id]);

        return $this->getOpenApiTicket($owner_uid, $ticket_id, $project_id);
    }

    public function reviewOpenApiCompensationTicket($owner_uid, $ticket_id, $review_result, $review_note = '') {
        if (!in_array($review_result, ['approved', 'rejected'])) {
            throw new Exception('审核结果无效');
        }
        $ticket = $this->getOpenApiTicket($owner_uid, $ticket_id);
        if (!$ticket) {
            throw new Exception('工单不存在或无权限');
        }
        if ($ticket['type'] !== 'compensation') {
            throw new Exception('该工单不是补偿类型');
        }

        $status = $review_result === 'approved' ? 'resolved' : 'closed';
        $stmt = $this->db->prepare("
            UPDATE openapi_tickets
            SET review_result = :review_result, review_note = :review_note, status = :status, updated_at = datetime('now', 'localtime')
            WHERE id = :id AND owner_uid = :owner_uid
        ");
        $stmt->execute([
            ':review_result' => $review_result,
            ':review_note' => $review_note,
            ':status' => $status,
            ':id' => $ticket_id,
            ':owner_uid' => $owner_uid
        ]);

        return $this->getOpenApiTicket($owner_uid, $ticket_id);
    }

    // ==================== 项目秘钥绑定管理 ====================

    /**
     * 为代理在项目上绑定/获取固定API秘钥
     * @param int $agent_uid 代理用户ID
     * @param int $project_id 项目ID
     * @param string $source_api_key 源平台主API密钥
     * @param float $initial_balance 初始余额（默认为0，允许设置为0）
     * @return array 秘钥绑定信息
     */
    public function bindProjectApiKey($agent_uid, $project_id, $source_api_key, $initial_balance = 0) {
        try {
            // 检查是否已经绑定
            $existing = $this->getProjectKey($agent_uid, $project_id);
            if ($existing) {
                return $existing;
            }

            // 获取项目信息
            $project = $this->getProjectById($project_id);
            if (!$project) {
                throw new Exception('项目不存在');
            }

            // 允许初始余额为0（默认值），后续通过兑换充值
            $initial_balance = floatval($initial_balance);
            if ($initial_balance < 0) {
                throw new Exception('初始余额不能为负数');
            }

            // 调用源平台API为该代理在该项目上生成一个固定的API密钥
            // 使用爽歪歪正式开户接口。
            $source_response = $this->callSourceAPI('generateCustomerApi', [
                'api_key' => $source_api_key,
                'project_id' => $project['source_project_id'] ?? $project_id,
                'balance' => $initial_balance // 初始余额，默认为0，后续通过兑换充值
            ]);

            if (!$source_response || $source_response['status'] !== 'success') {
                throw new Exception('调用源平台API失败: ' . ($source_response['message'] ?? '未知错误'));
            }

            $customer = $source_response['customer'];
            $project_api_key = $customer['api_key'];
            $customer_id = $customer['id'];

            // 真实访问密钥：用于访问真实业务系统（拼接 redirect_url?key=）
            // - 若上游透传了 real_access_key（上游本身是套娃中间层），用透传值
            // - 否则上游即真实源台，api_key 本身就是真实可用的访问密钥
            $real_access_key = !empty($customer['real_access_key'])
                ? $customer['real_access_key']
                : $project_api_key;
            // 源台返回的专属链接才是最终业务入口，不再依赖本地猜测路径。
            $customer_link = strval($customer['customer_link'] ?? '');

            // 保存绑定关系
            $stmt = $this->db->prepare("
                INSERT INTO project_api_keys (agent_uid, project_id, source_project_id, source_api_key, customer_id, real_access_key, customer_link)
                VALUES (:agent_uid, :project_id, :source_project_id, :source_api_key, :customer_id, :real_access_key, :customer_link)
            ");

            $stmt->execute([
                ':agent_uid' => $agent_uid,
                ':project_id' => $project_id,
                ':source_project_id' => $project['source_project_id'] ?? $project_id,
                ':source_api_key' => $project_api_key,
                ':customer_id' => $customer_id,
                ':real_access_key' => $real_access_key,
                ':customer_link' => $customer_link
            ]);

            return [
                'id' => $this->db->lastInsertId(),
                'agent_uid' => $agent_uid,
                'project_id' => $project_id,
                'source_project_id' => $project['source_project_id'] ?? $project_id,
                'source_api_key' => $project_api_key,
                'real_access_key' => $real_access_key,
                'customer_link' => $customer_link,
                'project_name' => $project['name'],
                'balance' => $initial_balance,
                'created_at' => date('Y-m-d H:i:s')
            ];
        } catch (PDOException $e) {
            throw new Exception('绑定项目秘钥失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取代理的所有项目秘钥
     * @param int $agent_uid 代理用户ID
     * @return array 秘钥列表
     */
    public function getAgentProjectKeys($agent_uid) {
        try {
            $stmt = $this->db->prepare("
                SELECT pak.*, p.name as project_name, p.exchange_rate, p.price, p.allow_charge, p.source_api_url, p.redirect_url
                FROM project_api_keys pak
                LEFT JOIN projects p ON pak.project_id = p.id
                WHERE pak.agent_uid = :agent_uid AND pak.status = 1 AND p.status = 1
                ORDER BY pak.created_at DESC
            ");

            $stmt->execute([':agent_uid' => $agent_uid]);
            $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 余额不在此处查询，由前端按需单独请求
            foreach ($keys as &$key) {
                $key['balance'] = 0;
            }
            unset($key);

            return $keys;
        } catch (PDOException $e) {
            throw new Exception('查询项目秘钥失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取代理在指定项目的秘钥
     * @param int $agent_uid 代理用户ID
     * @param int $project_id 项目ID
     * @return array|null 秘钥信息
     */
    public function getProjectKey($agent_uid, $project_id) {
        try {
            $stmt = $this->db->prepare("
                SELECT pak.*, p.name as project_name, p.exchange_rate, p.allow_charge, p.price, p.source_api_url
                FROM project_api_keys pak
                LEFT JOIN projects p ON pak.project_id = p.id
                WHERE pak.agent_uid = :agent_uid AND pak.project_id = :project_id AND pak.status = 1
            ");

            $stmt->execute([
                ':agent_uid' => $agent_uid,
                ':project_id' => $project_id
            ]);

            $key = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($key) {
                // 不在此处实时查询余额（避免每次操作都触发 HTTP 请求拖慢速度）
                // 余额由调用方按需单独查询
                $key['balance'] = 0;
            }

            return $key;
        } catch (PDOException $e) {
            throw new Exception('查询项目秘钥失败: ' . $e->getMessage());
        }
    }

    /**
     * 查询当前用户指定项目绑定密钥在上游的实时余额。
     * 上游若也是本系统，其 getCustomerInfo 会继续逐层查询，直到最终源台。
     */
    public function getProjectKeySourceBalance($agent_uid, $project_id) {
        $projectKey = $this->getProjectKey($agent_uid, $project_id);
        if (!$projectKey || intval($projectKey['customer_id'] ?? 0) <= 0) {
            throw new Exception('未找到项目源台密钥绑定');
        }

        $source_api_key = $this->getConfig('source_api_key');
        if (!$source_api_key) {
            throw new Exception('未配置源平台API密钥');
        }

        $response = $this->callSourceAPI('getCustomerInfo', [
            'api_key' => $source_api_key,
            'customer_id' => intval($projectKey['customer_id'])
        ]);
        if (!$response || ($response['status'] ?? '') !== 'success') {
            throw new Exception('查询源台密钥余额失败: ' . ($response['message'] ?? '未知错误'));
        }

        $customer = $response['customer'] ?? null;
        if (!$customer && !empty($response['customers'][0])) {
            $customer = $response['customers'][0];
        }
        if (!$customer || !array_key_exists('balance', $customer)) {
            throw new Exception('源台未返回该密钥余额');
        }

        return [
            'balance' => floatval($customer['balance']),
            'customer_id' => intval($projectKey['customer_id']),
            'project_id' => intval($project_id),
            'source_project_id' => intval($projectKey['source_project_id'] ?? 0)
        ];
    }

    /**
     * 获取“本平台在指定项目上的真实访问密钥”，用于套娃透传。
     *
     * 当本平台作为套娃上游被下游对接时，需要把“真实业务系统可识别的访问密钥”
     * 透传给下游，而不是把本平台 openapi 自造的 cust_ 密钥给下游。
     *
     * 取值优先级（逐层透传）：
     *   1. 本平台在该项目的绑定记录里的 real_access_key（本平台若也是套娃下游，这里已是上游透传来的真实密钥）
     *   2. 兜底用 source_api_key（本平台直连真实源台时，绑定密钥本身即真实密钥）
     *   3. 都没有则返回 null（本平台未绑定该项目，无法透传）
     *
     * @param int $project_id 本平台的项目ID
     * @return string|null 真实访问密钥
     */
    public function getRealAccessKey($project_id) {
        try {
            // 本平台绑定上游通常以管理员(uid=1)身份进行；
            // 取该项目下任意一条有效绑定（优先 uid=1），作为本平台自身的绑定。
            $stmt = $this->db->prepare("
                SELECT real_access_key, source_api_key
                FROM project_api_keys
                WHERE project_id = :project_id AND status = 1
                ORDER BY (agent_uid = 1) DESC, id ASC
                LIMIT 1
            ");
            $stmt->execute([':project_id' => $project_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            if (!empty($row['real_access_key'])) {
                return $row['real_access_key'];
            }
            return !empty($row['source_api_key']) ? $row['source_api_key'] : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * 根据客户ID查询代理信息
     * @param int $customer_id 源平台客户ID
     * @return array|null 包含 agent_uid 的信息
     */
    public function getAgentByCustomerId($customer_id) {
        try {
            // 先查询 project_api_keys 表获取 agent_uid
            $stmt = $this->db->prepare("
                SELECT agent_uid FROM project_api_keys
                WHERE customer_id = :customer_id
                LIMIT 1
            ");
            $stmt->execute([':customer_id' => $customer_id]);
            $key = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$key) {
                return null;
            }

            return [
                'agent_uid' => $key['agent_uid']
            ];
        } catch (PDOException $e) {
            error_log('[getAgentByCustomerId] 查询失败: ' . $e->getMessage());
            return null;
        }
    }

    // ==================== 余额兑换管理 ====================

    /**
     * 从二开平台余额充值到项目秘钥
     * @param int $agent_uid 代理用户ID
     * @param int $project_id 项目ID
     * @param float $platform_amount 二开平台扣除金额
     * @param float $actual_price 实际单价
     * @param string|null $transaction_id 外部传入的交易ID（用于幂等性重试），为空则自动生成
     * @param float|null $requested_project_amount 前端指定要充值的精确公里数
     * @return array 兑换结果
     */
    public function chargeToProject($agent_uid, $project_id, $platform_amount, $actual_price, $transaction_id = null, $requested_project_amount = null) {
        try {
            // 计算项目余额增加金额：平台金额 / 单价 = 项目金额
            $project_amount = $requested_project_amount !== null
                ? round(floatval($requested_project_amount), 4)
                : round($platform_amount / $actual_price, 4);

            // 获取项目秘钥（包含 customer_id）
            $projectKey = $this->getProjectKey($agent_uid, $project_id);
            if (!$projectKey) {
                throw new Exception('未找到项目秘钥绑定');
            }

            if (!isset($projectKey['customer_id'])) {
                throw new Exception('项目秘钥缺少客户ID信息');
            }

            $customer_id = $projectKey['customer_id'];
            $source_project_id = $projectKey['source_project_id'];

            // 获取源平台 API 密钥
            $source_api_key = $this->getConfig('source_api_key');
            if (!$source_api_key) {
                throw new Exception('未配置源平台API密钥');
            }

            // 生成或使用传入的交易ID
            if (empty($transaction_id)) {
                $transaction_id = $this->generateTransactionId();
            }

            // 检查是否已存在相同交易ID的记录（幂等性检查）
            $existing = $this->getTransactionByTransactionId($transaction_id);
            if ($existing) {
                // 如果已成功，直接返回之前的结果
                if ($existing['status'] === 'success') {
                    return [
                        'id' => $existing['id'],
                        'platform_amount' => abs(floatval($existing['platform_amount'])),
                        'project_amount' => floatval($existing['project_amount']),
                        'before_balance' => floatval($existing['before_balance']),
                        'after_balance' => floatval($existing['after_balance']),
                        'transaction_id' => $transaction_id,
                        'is_replay' => true // 标识为重放请求
                    ];
                }
                // 如果处理中或失败，继续处理
            }

            // 先创建 pending 状态的记录（记录交易意图）
            $before_balance = null;
            $record_id = $this->createExchangeRecord([
                'agent_uid' => $agent_uid,
                'project_id' => $project_id,
                'exchange_type' => 'charge',
                'platform_amount' => -$platform_amount,
                'project_amount' => $project_amount,
                'exchange_rate' => $actual_price,
                'before_balance' => $before_balance,
                'after_balance' => 0, // 暂时为0，等源台返回后更新
                'remark' => "充值到项目，单价: {$actual_price}",
                'transaction_id' => $transaction_id,
                'status' => 'pending'
            ]);

            // 调用源平台 adjustCustomerBalance API 增加客户余额
            // 传递 transaction_id 用于源台幂等性控制（如果源台支持）
            $adjust_response = $this->callSourceAPI('adjustCustomerBalance', [
                'api_key' => $source_api_key,
                'customer_id' => $customer_id,
                'project_id' => $source_project_id,
                'amount' => $project_amount, // 正数表示增加
                'remark' => "项目充值：平台金额{$platform_amount}元，到账{$project_amount}km，单价{$actual_price}元/km",
                'transaction_id' => $transaction_id // 传递交易ID
            ]);

            // 解析源台响应
            $source_success = ($adjust_response && $adjust_response['status'] === 'success');
            $before_balance = $source_success && isset($adjust_response['balance_before'])
                ? floatval($adjust_response['balance_before']) : $before_balance;
            $after_balance = $source_success ? ($adjust_response['balance_after'] ?? 0) : 0;
            $source_msg = $adjust_response['message'] ?? ($source_success ? 'success' : '未知错误');

            // 更新本地记录状态
            $this->updateTransactionStatus($record_id, [
                'status' => $source_success ? 'success' : 'failed',
                'before_balance' => $before_balance,
                'after_balance' => $after_balance,
                'source_response' => json_encode($adjust_response)
            ]);

            if (!$source_success) {
                throw new Exception('调用源平台API失败: ' . $source_msg);
            }

            return [
                'id' => $record_id,
                'platform_amount' => $platform_amount,
                'project_amount' => $project_amount,
                'before_balance' => $before_balance,
                'after_balance' => $after_balance,
                'transaction_id' => $transaction_id
            ];
        } catch (PDOException $e) {
            throw new Exception('充值到项目失败: ' . $e->getMessage());
        }
    }

    /**
     * 从项目秘钥提现到二开平台余额
     * @param int $agent_uid 代理用户ID
     * @param int $project_id 项目ID
     * @param float $project_amount 项目余额扣除金额
     * @param float $actual_price 实际单价
     * @param string|null $transaction_id 外部传入的交易ID（用于幂等性重试），为空则自动生成
     * @return array 兑换结果
     */
    public function withdrawFromProject($agent_uid, $project_id, $project_amount, $actual_price, $transaction_id = null) {
        try {
            // 计算二开平台余额增加金额：项目金额 × 单价 = 平台金额
            $platform_amount = $project_amount * $actual_price;

            // 获取项目秘钥（包含 customer_id）
            $projectKey = $this->getProjectKey($agent_uid, $project_id);
            if (!$projectKey) {
                throw new Exception('未找到项目秘钥绑定');
            }

            if (!isset($projectKey['customer_id'])) {
                throw new Exception('项目秘钥缺少客户ID信息');
            }

            $customer_id = $projectKey['customer_id'];
            $source_project_id = $projectKey['source_project_id'];

            // 获取源平台 API 密钥
            $source_api_key = $this->getConfig('source_api_key');
            if (!$source_api_key) {
                throw new Exception('未配置源平台API密钥');
            }

            // 生成或使用传入的交易ID
            if (empty($transaction_id)) {
                $transaction_id = $this->generateTransactionId();
            }

            // 检查是否已存在相同交易ID的记录（幂等性检查）
            $existing = $this->getTransactionByTransactionId($transaction_id);
            if ($existing) {
                // 如果已成功，直接返回之前的结果
                if ($existing['status'] === 'success') {
                    return [
                        'id' => $existing['id'],
                        'platform_amount' => floatval($existing['platform_amount']),
                        'project_amount' => abs(floatval($existing['project_amount'])),
                        'before_balance' => floatval($existing['before_balance']),
                        'after_balance' => floatval($existing['after_balance']),
                        'transaction_id' => $transaction_id,
                        'is_replay' => true
                    ];
                }
                // 如果处理中或失败，继续处理
            }

            // 先创建 pending 状态的记录
            $before_balance = null;
            $record_id = $this->createExchangeRecord([
                'agent_uid' => $agent_uid,
                'project_id' => $project_id,
                'exchange_type' => 'withdraw',
                'platform_amount' => $platform_amount,
                'project_amount' => -$project_amount,
                'exchange_rate' => $actual_price,
                'before_balance' => $before_balance,
                'after_balance' => 0,
                'remark' => "从项目提现，单价: {$actual_price}",
                'transaction_id' => $transaction_id,
                'status' => 'pending'
            ]);

            // 调用源平台 adjustCustomerBalance API 减少客户余额
            $adjust_response = $this->callSourceAPI('adjustCustomerBalance', [
                'api_key' => $source_api_key,
                'customer_id' => $customer_id,
                'project_id' => $source_project_id,
                'amount' => -$project_amount, // 负数表示减少
                'remark' => "项目提现：扣除{$project_amount}km，到账平台金额{$platform_amount}元，单价{$actual_price}元/km",
                'transaction_id' => $transaction_id
            ]);

            // 解析源台响应
            $source_success = ($adjust_response && $adjust_response['status'] === 'success');
            $before_balance = $source_success && isset($adjust_response['balance_before'])
                ? floatval($adjust_response['balance_before']) : $before_balance;
            $after_balance = $source_success ? ($adjust_response['balance_after'] ?? 0) : 0;
            $source_msg = $adjust_response['message'] ?? ($source_success ? 'success' : '未知错误');

            // 更新本地记录状态
            $this->updateTransactionStatus($record_id, [
                'status' => $source_success ? 'success' : 'failed',
                'before_balance' => $before_balance,
                'after_balance' => $after_balance,
                'source_response' => json_encode($adjust_response)
            ]);

            if (!$source_success) {
                throw new Exception('调用源平台API失败: ' . $source_msg);
            }

            return [
                'id' => $record_id,
                'platform_amount' => $platform_amount,
                'project_amount' => $project_amount,
                'before_balance' => $before_balance,
                'after_balance' => $after_balance,
                'transaction_id' => $transaction_id
            ];
        } catch (PDOException $e) {
            throw new Exception('从项目提现失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取兑换记录
     * @param int $agent_uid 代理用户ID（可选，管理员可查看所有）
     * @param int $project_id 项目ID（可选）
     * @param int $limit 限制数量
     * @return array 兑换记录列表
     */
    public function getExchangeRecords($agent_uid = null, $project_id = null, $limit = 50) {
        try {
            $sql = "
                SELECT er.*, p.name as project_name
                FROM exchange_records er
                LEFT JOIN projects p ON er.project_id = p.id
                WHERE 1=1
            ";
            $params = [];

            if ($agent_uid !== null) {
                $sql .= " AND er.agent_uid = :agent_uid";
                $params[':agent_uid'] = $agent_uid;
            }

            if ($project_id !== null) {
                $sql .= " AND er.project_id = :project_id";
                $params[':project_id'] = $project_id;
            }

            $sql .= " ORDER BY er.created_at DESC LIMIT :limit";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception('查询兑换记录失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取兑换统计数据
     * @param int $agent_uid 代理用户ID（可选）
     * @return array 统计数据
     */
    public function getExchangeStatistics($agent_uid = null) {
        try {
            $where = $agent_uid ? "WHERE agent_uid = :agent_uid" : "WHERE 1=1";
            $params = $agent_uid ? [':agent_uid' => $agent_uid] : [];

            // 充值统计
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as charge_count,
                    SUM(ABS(platform_amount)) as total_charged
                FROM exchange_records
                {$where} AND exchange_type = 'charge'
            ");
            $stmt->execute($params);
            $charge_stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // 提现统计
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as withdraw_count,
                    SUM(platform_amount) as total_withdrawn
                FROM exchange_records
                {$where} AND exchange_type = 'withdraw'
            ");
            $stmt->execute($params);
            $withdraw_stats = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'total_charged' => floatval($charge_stats['total_charged'] ?? 0),
                'charge_count' => intval($charge_stats['charge_count'] ?? 0),
                'total_withdrawn' => floatval($withdraw_stats['total_withdrawn'] ?? 0),
                'withdraw_count' => intval($withdraw_stats['withdraw_count'] ?? 0)
            ];
        } catch (PDOException $e) {
            throw new Exception('查询兑换统计失败: ' . $e->getMessage());
        }
    }

    // ==================== 项目管理 ====================

    /**
     * 获取所有启用的项目
     * @return array 项目列表
     */
    public function getProjects() {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM projects
                WHERE status = 1
                ORDER BY id ASC
            ");

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception('查询项目列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取所有项目（包括禁用的）
     * @return array 项目列表
     */
    public function getAllProjects() {
        try {
            $stmt = $this->db->prepare("SELECT * FROM projects ORDER BY id ASC");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception('查询项目列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 根据ID获取项目
     * @param int $project_id 项目ID
     * @return array|null 项目信息
     */
    public function getProjectById($project_id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM projects WHERE id = :id");
            $stmt->execute([':id' => $project_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception('查询项目失败: ' . $e->getMessage());
        }
    }

    /**
     * 添加项目
     * @param string $name 项目名称
     * @param string $source_api_url 源平台API地址
     * @param int $source_project_id 源平台项目ID
     * @param float $exchange_rate 兑换比例
     * @param string $description 项目描述
     * @param string $redirect_url 项目访问地址
     * @param string $docs_url 源台项目文档地址
     * @return int 项目ID
     */
    public function addProject($name, $source_api_url, $source_project_id, $exchange_rate = 1.0, $description = '', $redirect_url = '', $docs_url = '') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO projects (name, source_api_url, source_project_id, exchange_rate, price, description, redirect_url, docs_url)
                VALUES (:name, :source_api_url, :source_project_id, :exchange_rate, 1, :description, :redirect_url, :docs_url)
            ");

            $stmt->execute([
                ':name' => $name,
                ':source_api_url' => $source_api_url,
                ':source_project_id' => $source_project_id,
                ':exchange_rate' => $exchange_rate,
                ':description' => $description,
                ':redirect_url' => $redirect_url,
                ':docs_url' => $docs_url
            ]);

            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new Exception('添加项目失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新项目
     * @param int $project_id 项目ID
     * @param array $data 更新数据
     * @return bool 是否成功
     */
    public function updateProject($project_id, $data) {
        try {
            $fields = [];
            $params = [':id' => $project_id];

            $allowed_fields = ['name', 'source_api_url', 'source_project_id', 'exchange_rate', 'price', 'allow_charge', 'description', 'status', 'redirect_url', 'docs_url'];

            foreach ($allowed_fields as $field) {
                if (isset($data[$field])) {
                    $fields[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $data[$field];
                }
            }

            if (empty($fields)) {
                return false;
            }

            $sql = "UPDATE projects SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new Exception('更新项目失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除项目
     * @param int $project_id 项目ID
     * @return bool
     */
    public function deleteProject($project_id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM projects WHERE id = :id");
            return $stmt->execute([':id' => $project_id]);
        } catch (PDOException $e) {
            throw new Exception('删除项目失败: ' . $e->getMessage());
        }
    }

    // ==================== 配置管理 ====================

    /**
     * 获取配置
     * @param string $key 配置键
     * @return string|null 配置值
     */
    public function getConfig($key) {
        try {
            $stmt = $this->db->prepare("SELECT config_value FROM config WHERE config_key = :key");
            $stmt->execute([':key' => $key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['config_value'] : null;
        } catch (PDOException $e) {
            throw new Exception('获取配置失败: ' . $e->getMessage());
        }
    }

    /**
     * 设置配置
     * @param string $key 配置键
     * @param string $value 配置值
     * @param string $description 配置描述
     * @return bool 是否成功
     */
    public function setConfig($key, $value, $description = '') {
        try {
            $stmt = $this->db->prepare("
                INSERT OR REPLACE INTO config (config_key, config_value, description, updated_at)
                VALUES (:key, :value, :description, datetime('now', 'localtime'))
            ");

            $stmt->execute([
                ':key' => $key,
                ':value' => $value,
                ':description' => $description
            ]);

            return true;
        } catch (PDOException $e) {
            throw new Exception('设置配置失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取所有配置
     * @return array 配置列表
     */
    public function getAllConfig() {
        try {
            $stmt = $this->db->prepare("SELECT * FROM config ORDER BY id ASC");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception('获取配置列表失败: ' . $e->getMessage());
        }
    }

    // ==================== 代理管理 ====================

    /**
     * 获取代理列表统计数据
     * @return array 代理列表
     */
    public function getAgentListStats() {
        try {
            // 一次性查询所有代理的项目数统计
            $stmt = $this->db->query("
                SELECT agent_uid, COUNT(*) as project_count
                FROM project_api_keys
                WHERE status = 1
                GROUP BY agent_uid
                ORDER BY agent_uid
            ");
            $project_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($project_stats)) {
                return [];
            }

            // 一次性查询所有代理的充值提现统计
            $stmt = $this->db->query("
                SELECT
                    agent_uid,
                    COUNT(*) as exchange_count,
                    SUM(CASE WHEN exchange_type = 'charge' THEN ABS(platform_amount) ELSE 0 END) as total_charge,
                    SUM(CASE WHEN exchange_type = 'withdraw' THEN platform_amount ELSE 0 END) as total_withdraw
                FROM exchange_records
                GROUP BY agent_uid
            ");
            $exchange_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 以 agent_uid 为键建立索引，方便合并
            $exchange_map = [];
            foreach ($exchange_rows as $row) {
                $exchange_map[$row['agent_uid']] = $row;
            }

            $agents = [];
            foreach ($project_stats as $ps) {
                $uid = $ps['agent_uid'];
                $ex = $exchange_map[$uid] ?? [];
                $agents[] = [
                    'uid' => $uid,
                    'project_count' => intval($ps['project_count']),
                    'exchange_count' => intval($ex['exchange_count'] ?? 0),
                    'total_charge' => floatval($ex['total_charge'] ?? 0),
                    'total_withdraw' => floatval($ex['total_withdraw'] ?? 0),
                ];
            }

            return $agents;
        } catch (PDOException $e) {
            throw new Exception('获取代理列表失败: ' . $e->getMessage());
        }
    }

    // ==================== 辅助函数 ====================

    /**
     * 根据交易ID查询交易记录
     * @param string $transaction_id 交易ID
     * @return array|null 交易记录
     */
    private function getTransactionByTransactionId($transaction_id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM exchange_records WHERE transaction_id = :tid LIMIT 1");
            $stmt->execute([':tid' => $transaction_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('[getTransactionByTransactionId] 查询失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 创建交易记录
     * @param array $data 交易数据
     * @return int 记录ID
     */
    private function createExchangeRecord($data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO exchange_records
                (agent_uid, project_id, exchange_type, platform_amount, project_amount, exchange_rate, before_balance, after_balance, remark, transaction_id, status, created_at, updated_at)
                VALUES
                (:agent_uid, :project_id, :exchange_type, :platform_amount, :project_amount, :exchange_rate, :before_balance, :after_balance, :remark, :transaction_id, :status, datetime('now', 'localtime'), datetime('now', 'localtime'))
            ");
            $stmt->execute([
                ':agent_uid' => $data['agent_uid'],
                ':project_id' => $data['project_id'],
                ':exchange_type' => $data['exchange_type'],
                ':platform_amount' => $data['platform_amount'],
                ':project_amount' => $data['project_amount'],
                ':exchange_rate' => $data['exchange_rate'],
                ':before_balance' => $data['before_balance'],
                ':after_balance' => $data['after_balance'],
                ':remark' => $data['remark'],
                ':transaction_id' => $data['transaction_id'],
                ':status' => $data['status']
            ]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log('[createExchangeRecord] 创建失败: ' . $e->getMessage());
            throw new Exception('创建交易记录失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新交易状态
     * @param int $record_id 记录ID
     * @param array $data 更新数据
     * @return bool 是否成功
     */
    private function updateTransactionStatus($record_id, $data) {
        try {
            $fields = [];
            $params = [':id' => $record_id];

            foreach (['status', 'before_balance', 'after_balance', 'source_response'] as $field) {
                if (isset($data[$field])) {
                    $fields[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $data[$field];
                }
            }

            if (!empty($fields)) {
                $fields[] = "updated_at = datetime('now', 'localtime')";
                $sql = "UPDATE exchange_records SET " . implode(', ', $fields) . " WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                return $stmt->execute($params);
            }

            return false;
        } catch (PDOException $e) {
            error_log('[updateTransactionStatus] 更新失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 对账：查询并处理pending状态的交易
     * @param int $limit 最大处理数量
     * @return array 对账结果
     */
    public function reconcilePendingTransactions($limit = 10) {
        try {
            // 查询pending状态的交易记录（超过5分钟的）
            $stmt = $this->db->prepare("
                SELECT er.*, pak.customer_id, pak.source_project_id
                FROM exchange_records er
                LEFT JOIN project_api_keys pak ON er.agent_uid = pak.agent_uid AND er.project_id = pak.project_id
                WHERE er.status = 'pending'
                AND datetime(er.created_at, '+5 minutes') < datetime('now', 'localtime')
                ORDER BY er.created_at ASC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $pending_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($pending_records)) {
                return ['total' => 0, 'success' => 0, 'failed' => 0, 'details' => []];
            }

            $source_api_key = $this->getConfig('source_api_key');
            if (!$source_api_key) {
                throw new Exception('未配置源平台API密钥');
            }

            $success_count = 0;
            $failed_count = 0;
            $details = [];

            foreach ($pending_records as $record) {
                $record_id = intval($record['id']);
                $transaction_id = $record['transaction_id'] ?? '';
                $customer_id = intval($record['customer_id'] ?? 0);
                $source_project_id = intval($record['source_project_id'] ?? 0);
                $exchange_type = $record['exchange_type'] ?? '';
                $project_amount = floatval($record['project_amount'] ?? 0);

                if (empty($transaction_id) || $customer_id <= 0) {
                    // 无法对账的记录，标记为失败
                    $this->updateTransactionStatus($record_id, ['status' => 'failed']);
                    $failed_count++;
                    $details[] = ['record_id' => $record_id, 'result' => 'failed', 'reason' => '缺少必要信息'];
                    continue;
                }

                // 调用源台API查询该交易的最终状态
                // 这里假设源台提供了查询接口，如果没有则需要通过余额推断
                // 简化版本：根据交易ID重新发起请求（幂等性）

                $adjust_params = [
                    'api_key' => $source_api_key,
                    'customer_id' => $customer_id,
                    'project_id' => $source_project_id,
                    'amount' => $project_amount, // 充值为正，提现为负
                    'remark' => "V3对账：确认交易状态",
                    'transaction_id' => $transaction_id // 使用相同的交易ID
                ];

                // 充值操作
                if ($exchange_type === 'charge') {
                    $response = $this->callSourceAPI('adjustCustomerBalance', $adjust_params);
                } else {
                    // 提现操作（负数）
                    $adjust_params['amount'] = -$project_amount;
                    $response = $this->callSourceAPI('adjustCustomerBalance', $adjust_params);
                }

                $source_success = ($response && $response['status'] === 'success');
                $after_balance = $source_success ? ($response['balance_after'] ?? 0) : 0;

                // 更新本地状态
                $this->updateTransactionStatus($record_id, [
                    'status' => $source_success ? 'success' : 'failed',
                    'after_balance' => $after_balance,
                    'source_response' => json_encode($response)
                ]);

                if ($source_success) {
                    $success_count++;
                    $details[] = ['record_id' => $record_id, 'result' => 'success', 'balance' => $after_balance];
                } else {
                    $failed_count++;
                    $details[] = ['record_id' => $record_id, 'result' => 'failed', 'reason' => $response['message'] ?? '未知错误'];
                }

                // 避免请求过快
                usleep(100000); // 0.1秒
            }

            return [
                'total' => count($pending_records),
                'success' => $success_count,
                'failed' => $failed_count,
                'details' => $details
            ];
        } catch (PDOException $e) {
            throw new Exception('对账失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取交易状态统计
     * @return array 状态统计
     */
    public function getTransactionStatusStats() {
        try {
            $stats = [];

            // 统计各状态的交易数量
            $stmt = $this->db->query("
                SELECT status, COUNT(*) as count
                FROM exchange_records
                GROUP BY status
            ");
            $status_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($status_counts as $row) {
                $stats[$row['status']] = intval($row['count']);
            }

            // 统计pending超过5分钟的数量
            $stmt = $this->db->query("
                SELECT COUNT(*) as count
                FROM exchange_records
                WHERE status = 'pending'
                AND datetime(created_at, '+5 minutes') < datetime('now', 'localtime')
            ");
            $stats['pending_overdue'] = intval($stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0);

            return $stats;
        } catch (PDOException $e) {
            throw new Exception('获取交易统计失败: ' . $e->getMessage());
        }
    }

    /**
     * 从源台日志对账：找出源台有但本地没有的操作记录（白嫖检测）
     * 注意：此功能只检测不修改，用于查看缺失记录
     * 优化：直接从源台获取有余额操作的日志，用customer_id匹配本地客户
     * @param string $source_api_key 源台管理员API密钥
     * @param string $date_start 开始日期（可选）
     * @param string $date_end 结束日期（可选）
     * @param int $agent_uid 指定代理UID（可选，0表示所有代理）
     * @param int $page 页码（默认1，传0表示检查所有页）
     * @param int $per_page 每页数量（默认100，最大500）
     * @return array 对账结果
     */
    public function reconcileFromSourceLogs($source_api_key, $date_start = '', $date_end = '', $agent_uid = 0, $page = 1, $per_page = 100) {
        try {
            $source_api_url = $this->getConfig('source_api_url') ?: 'https://syyrun.com/api.php';
            if (strpos($source_api_url, 'http://') !== 0 && strpos($source_api_url, 'https://') !== 0) {
                $source_api_url = 'https://' . $source_api_url;
            }

            $per_page = min(500, max(1, intval($per_page)));
            $check_all_pages = ($page == 0);  // page=0 表示检查所有页
            $page = max(1, intval($page));

            // 获取本地所有 customer_id 的映射表（customer_id => local_info）
            $local_map = [];
            $map_sql = "
                SELECT pak.customer_id, pak.agent_uid, pak.project_id, pak.source_api_key, p.name as project_name
                FROM project_api_keys pak
                LEFT JOIN projects p ON pak.project_id = p.id
                WHERE pak.status = 1 AND pak.customer_id > 0
            ";
            if ($agent_uid > 0) {
                $map_sql .= " AND pak.agent_uid = " . intval($agent_uid);
            }
            $map_stmt = $this->db->query($map_sql);
            while ($row = $map_stmt->fetch(PDO::FETCH_ASSOC)) {
                $cid = intval($row['customer_id']);
                if ($cid > 0) {
                    $local_map[$cid] = [
                        'agent_uid' => intval($row['agent_uid']),
                        'project_id' => intval($row['project_id']),
                        'project_name' => $row['project_name'] ?? '',
                        'source_api_key' => $row['source_api_key'] ?? ''
                    ];
                }
            }

            if (empty($local_map)) {
                return ['total' => 0, 'matched_count' => 0, 'missing_count' => 0, 'details' => [], 'message' => '本地没有绑定的客户'];
            }

            // 调用源台API获取余额操作日志（分页）
            $api_url = $source_api_url . '?action=getCustomerLogs'
                . '&api_key=' . urlencode($source_api_key)
                . '&action_filter=balance_add' // 获取充值记录
                . '&page=' . $page
                . '&per_page=' . $per_page;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new Exception('获取源台日志失败: ' . $error);
            }

            $data = json_decode($response, true);
            if (!$data || $data['status'] !== 'success') {
                throw new Exception('源台API返回错误: ' . ($data['message'] ?? '未知错误'));
            }

            $logs = $data['logs'] ?? [];
            $pagination = $data['pagination'] ?? [];

            $missing_records = [];
            $total_checked = 0;
            $matched_count = 0;
            $current_page = $page;
            $total_pages = 1;

            // 如果要检查所有页，先获取总页数
            if ($check_all_pages) {
                // 先请求一次获取总页数
                $first_url = $source_api_url . '?action=getCustomerLogs'
                    . '&api_key=' . urlencode($source_api_key)
                    . '&action_filter=balance_add'
                    . '&page=1&per_page=' . $per_page;

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $first_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                $response = curl_exec($ch);
                curl_close($ch);

                $first_data = json_decode($response, true);
                if ($first_data && isset($first_data['pagination']['total_pages'])) {
                    $total_pages = intval($first_data['pagination']['total_pages']);
                    $current_page = 1;
                }
            }

            // 循环检查每一页
            for ($p = $current_page; $p <= $total_pages; $p++) {
                $api_url = $source_api_url . '?action=getCustomerLogs'
                    . '&api_key=' . urlencode($source_api_key)
                    . '&action_filter=balance_add'
                    . '&page=' . $p
                    . '&per_page=' . $per_page;

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 降低超时时间到10秒
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                $response = curl_exec($ch);
                $error = curl_error($ch);
                curl_close($ch);

                if ($error) {
                    error_log("[对账] 第{$p}页获取失败: {$error}");
                    continue;
                }

                $data = json_decode($response, true);
                if (!$data || $data['status'] !== 'success') {
                    error_log("[对账] 第{$p}页API错误: " . ($data['message'] ?? '未知错误'));
                    continue;
                }

                $logs = $data['logs'] ?? [];

                foreach ($logs as $log) {
                    $action = $log['action'] ?? '';
                    $log_time = $log['created_at'] ?? '';
                    $log_amount = floatval($log['amount'] ?? 0);
                    $log_id = intval($log['id'] ?? 0);
                    $customer_id = intval($log['customer_id'] ?? 0);

                    // 日期过滤
                    if ($date_start && $log_time < $date_start . ' 00:00:00') {
                        continue;
                    }
                    if ($date_end && $log_time > $date_end . ' 23:59:59') {
                        continue;
                    }

                    // 检查该customer_id是否在本地有对应
                    if (!isset($local_map[$customer_id])) {
                        continue; // 不是本地的客户，跳过
                    }

                    $local_info = $local_map[$customer_id];
                    $local_agent_uid = $local_info['agent_uid'];
                    $local_project_id = $local_info['project_id'];
                    $project_name = $local_info['project_name'];

                    $total_checked++;
                    $matched_count++;

                    // 检查本地是否已有该记录
                    $check_stmt = $this->db->prepare("
                        SELECT id FROM exchange_records
                        WHERE agent_uid = :agent_uid
                        AND project_id = :project_id
                        AND created_at = :created_at
                        AND ABS(project_amount - :project_amount) < 0.01
                        LIMIT 1
                    ");
                    $check_stmt->execute([
                        ':agent_uid' => $local_agent_uid,
                        ':project_id' => $local_project_id,
                        ':created_at' => $log_time,
                        ':project_amount' => abs($log_amount)
                    ]);
                    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$existing) {
                        // 找到缺失的记录
                        $missing_records[] = [
                            'source_log_id' => $log_id,
                            'customer_id' => $customer_id,
                            'source_api_key' => substr($local_info['source_api_key'], 0, 16) . '...',
                            'agent_uid' => $local_agent_uid,
                            'project_id' => $local_project_id,
                            'project_name' => $project_name,
                            'action' => $action,
                            'amount' => $log_amount,
                            'created_at' => $log_time,
                            'operator' => $log['operator_username'] ?? ''
                        ];
                    }
                }

                // 如果不是检查所有页，只检查一页就退出
                if (!$check_all_pages) {
                    $pagination = $data['pagination'] ?? [];
                    break;
                }

                // 避免请求过快
                usleep(100000); // 0.1秒
            }

            return [
                'total' => $total_checked,
                'matched_count' => $matched_count,
                'missing_count' => count($missing_records),
                'details' => $missing_records,
                'pagination' => $pagination ?? [],
                'local_customer_count' => count($local_map),
                'checked_pages' => $check_all_pages ? $total_pages : 1,
                'message' => sprintf(
                    '%s检查了 %d 条记录（其中 %d 条匹配本地客户，本地共有 %d 个客户），发现 %d 条本地缺失记录',
                    $check_all_pages ? '共' : '本页',
                    $total_checked,
                    $matched_count,
                    count($local_map),
                    count($missing_records)
                )
            ];
        } catch (PDOException $e) {
            throw new Exception('源台对账失败: ' . $e->getMessage());
        }
    }

    /**
     * 触发审计：将客户标记为待审计状态
     * @param int $agent_uid 指定代理/对接用户UID（可选）
     * @param string $audit_type upstream/downstream/all
     * @return array 触发结果
     */
    public function triggerAudit($agent_uid = 0, $audit_type = 'upstream') {
        $audit_type = $this->normalizeAuditType($audit_type, 'upstream');
        try {
            $upstream_count = 0;
            $downstream_count = 0;

            if ($audit_type === 'upstream' || $audit_type === 'all') {
                $upstream_count = $this->triggerUpstreamAudit($agent_uid);
            }
            if ($audit_type === 'downstream' || $audit_type === 'all') {
                $downstream_count = $this->triggerDownstreamAudit($agent_uid);
            }

            $triggered_count = $upstream_count + $downstream_count;
            return [
                'triggered_count' => $triggered_count,
                'upstream_count' => $upstream_count,
                'downstream_count' => $downstream_count,
                'audit_type' => $audit_type,
                'message' => "已触发 {$triggered_count} 个客户的审计任务（上游 {$upstream_count}，下游 {$downstream_count}）"
            ];
        } catch (PDOException $e) {
            throw new Exception('触发审计失败: ' . $e->getMessage());
        }
    }

    private function normalizeAuditType($audit_type, $default = '') {
        $audit_type = trim(strval($audit_type));
        if ($audit_type === '') return $default;
        return in_array($audit_type, ['upstream', 'downstream', 'all'], true) ? $audit_type : $default;
    }

    private function triggerUpstreamAudit($agent_uid = 0) {
        $sql = "
            SELECT pak.customer_id, pak.agent_uid, pak.project_id, p.name as project_name, pak.source_api_key
            FROM project_api_keys pak
            LEFT JOIN projects p ON pak.project_id = p.id
            WHERE pak.status = 1 AND pak.customer_id > 0
        ";
        if ($agent_uid > 0) {
            $sql .= " AND pak.agent_uid = " . intval($agent_uid);
        }

        $customers = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $triggered_count = 0;
        $upsert = $this->db->prepare("
            INSERT INTO customer_audit (
                audit_type, subject_key, customer_id, agent_uid, owner_uid, project_id, project_name,
                source_api_key, customer_api_key, audit_status, audit_error, created_at, updated_at
            ) VALUES (
                'upstream', :subject_key, :cid, :aid, 0, :pid, :pname,
                :skey, :skey, 'pending', NULL, datetime('now', 'localtime'), datetime('now', 'localtime')
            )
            ON CONFLICT(subject_key) DO UPDATE SET
                agent_uid = excluded.agent_uid,
                project_id = excluded.project_id,
                project_name = excluded.project_name,
                source_api_key = excluded.source_api_key,
                customer_api_key = excluded.customer_api_key,
                audit_status = 'pending',
                audit_error = NULL,
                updated_at = datetime('now', 'localtime')
        ");

        foreach ($customers as $cust) {
            $cid = intval($cust['customer_id']);
            $upsert->execute([
                ':subject_key' => 'upstream:' . $cid,
                ':cid' => $cid,
                ':aid' => intval($cust['agent_uid']),
                ':pid' => intval($cust['project_id']),
                ':pname' => $cust['project_name'] ?? '',
                ':skey' => $cust['source_api_key'] ?? ''
            ]);
            $triggered_count++;
        }
        return $triggered_count;
    }

    private function triggerDownstreamAudit($agent_uid = 0) {
        $sql = "
            SELECT c.id AS customer_id, c.owner_uid, c.project_id, c.api_key, c.balance, p.name AS project_name
            FROM openapi_customers c
            LEFT JOIN projects p ON c.project_id = p.id
            WHERE c.status = 1
        ";
        $params = [];
        if ($agent_uid > 0) {
            $sql .= " AND c.owner_uid = :agent_uid";
            $params[':agent_uid'] = intval($agent_uid);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $triggered_count = 0;
        $upsert = $this->db->prepare("
            INSERT INTO customer_audit (
                audit_type, subject_key, customer_id, agent_uid, owner_uid, project_id, project_name,
                source_api_key, customer_api_key, audit_status, audit_error, created_at, updated_at
            ) VALUES (
                'downstream', :subject_key, :cid, :owner_uid, :owner_uid, :pid, :pname,
                :ckey, :ckey, 'pending', NULL, datetime('now', 'localtime'), datetime('now', 'localtime')
            )
            ON CONFLICT(subject_key) DO UPDATE SET
                agent_uid = excluded.agent_uid,
                owner_uid = excluded.owner_uid,
                project_id = excluded.project_id,
                project_name = excluded.project_name,
                source_api_key = excluded.source_api_key,
                customer_api_key = excluded.customer_api_key,
                audit_status = 'pending',
                audit_error = NULL,
                updated_at = datetime('now', 'localtime')
        ");

        foreach ($customers as $cust) {
            $cid = intval($cust['customer_id']);
            $upsert->execute([
                ':subject_key' => 'downstream:' . $cid,
                ':cid' => $cid,
                ':owner_uid' => intval($cust['owner_uid']),
                ':pid' => intval($cust['project_id']),
                ':pname' => $cust['project_name'] ?? '',
                ':ckey' => $cust['api_key'] ?? ''
            ]);
            $triggered_count++;
        }
        return $triggered_count;
    }

    /**
     * 执行审计：处理一批 pending 状态的客户
     * @param int $limit 每次处理数量
     * @param string $audit_type upstream/downstream/all/空
     * @return array 处理结果
     */
    public function processAudit($limit = 10, $audit_type = '') {
        try {
            $audit_type = $this->normalizeAuditType($audit_type, '');
            $limit = max(1, intval($limit));
            $where = "audit_status = 'pending'";
            if ($audit_type === 'upstream' || $audit_type === 'downstream') {
                $where .= " AND audit_type = " . $this->db->quote($audit_type);
            }

            $stmt = $this->db->query("SELECT * FROM customer_audit WHERE {$where} ORDER BY id ASC LIMIT {$limit}");
            $pending_audits = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($pending_audits)) {
                return ['processed_count' => 0, 'success_count' => 0, 'failed_count' => 0, 'message' => '没有待审计的客户'];
            }

            $processed_count = 0;
            $success_count = 0;
            $failed_count = 0;

            foreach ($pending_audits as $audit) {
                try {
                    if (($audit['audit_type'] ?? 'upstream') === 'downstream') {
                        $this->processDownstreamAudit($audit);
                    } else {
                        $this->processUpstreamAudit($audit);
                    }
                    $success_count++;
                } catch (Exception $e) {
                    $this->markAuditFailed(intval($audit['id']), $e->getMessage());
                    $failed_count++;
                }
                $processed_count++;
                usleep(200000);
            }

            return [
                'processed_count' => $processed_count,
                'success_count' => $success_count,
                'failed_count' => $failed_count,
                'message' => "处理了 {$processed_count} 个客户，成功 {$success_count} 个，失败 {$failed_count} 个"
            ];
        } catch (PDOException $e) {
            throw new Exception('执行审计失败: ' . $e->getMessage());
        }
    }

    private function processUpstreamAudit($audit) {
        $source_api_key = $this->getConfig('source_api_key');
        if (empty($source_api_key)) {
            throw new Exception('未配置源平台API密钥');
        }

        $source_api_url = $this->getConfig('source_api_url') ?: 'https://syyrun.com/api.php';
        if (strpos($source_api_url, 'http://') !== 0 && strpos($source_api_url, 'https://') !== 0) {
            $source_api_url = 'https://' . $source_api_url;
        }

        $audit_id = intval($audit['id']);
        $cid = intval($audit['customer_id']);
        $agent_uid = intval($audit['agent_uid']);
        $project_id = intval($audit['project_id']);

        $local_stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN exchange_type = 'charge' THEN ABS(project_amount) ELSE 0 END) as charge_total,
                SUM(CASE WHEN exchange_type = 'withdraw' THEN ABS(project_amount) ELSE 0 END) as withdraw_total
            FROM exchange_records
            WHERE agent_uid = :aid AND project_id = :pid AND status = 'success'
        ");
        $local_stmt->execute([':aid' => $agent_uid, ':pid' => $project_id]);
        $local_stat = $local_stmt->fetch(PDO::FETCH_ASSOC);
        $local_charge = floatval($local_stat['charge_total'] ?? 0);
        $local_withdraw = floatval($local_stat['withdraw_total'] ?? 0);
        $local_net = $local_charge - $local_withdraw;

        $customer_api_key = $audit['source_api_key'] ?? '';
        if (empty($customer_api_key)) {
            throw new Exception('客户项目密钥为空');
        }

        $data = $this->httpGetJson($source_api_url . '?action=getCustomerNetInflowByKey&customer_api_key=' . urlencode($customer_api_key));
        if (!$data || ($data['status'] ?? '') !== 'success') {
            throw new Exception('API错误: ' . ($data['message'] ?? '未知错误'));
        }

        $source_net = floatval($data['net_inflow'] ?? 0);
        $source_add = floatval($data['details']['total_in'] ?? 0);
        $source_subtract = floatval($data['details']['total_out'] ?? 0);
        $difference = $source_net - $local_net;
        $has_discrepancy = (abs($difference) > 0.01) ? 1 : 0;

        $discrepancy_details = [];
        if ($has_discrepancy) {
            $log_data = $this->httpGetJson($source_api_url . '?action=getCustomerLogs'
                . '&api_key=' . urlencode($source_api_key)
                . '&customer_id=' . $cid
                . '&per_page=500');
            if (!$log_data || ($log_data['status'] ?? '') !== 'success') {
                throw new Exception('获取日志API错误: ' . ($log_data['message'] ?? '未知错误'));
            }

            $source_logs = [];
            foreach (($log_data['logs'] ?? []) as $log) {
                $action = $log['action'] ?? '';
                if (in_array($action, ['balance_add', 'balance_subtract', 'refund'], true)) {
                    $key = $action . '_' . floatval($log['amount'] ?? 0) . '_' . ($log['created_at'] ?? '');
                    $source_logs[$key] = [
                        'action' => $action,
                        'amount' => floatval($log['amount'] ?? 0),
                        'created_at' => $log['created_at'] ?? '',
                        'action_text' => $log['action_text'] ?? $action
                    ];
                }
            }

            $local_logs = $this->db->prepare("
                SELECT exchange_type, project_amount, created_at
                FROM exchange_records
                WHERE agent_uid = :aid AND project_id = :pid AND status = 'success'
                ORDER BY created_at ASC
            ");
            $local_logs->execute([':aid' => $agent_uid, ':pid' => $project_id]);
            $local_log_keys = [];
            while ($local_row = $local_logs->fetch(PDO::FETCH_ASSOC)) {
                $mapped_action = '';
                if (($local_row['exchange_type'] ?? '') === 'charge') $mapped_action = 'balance_add';
                if (($local_row['exchange_type'] ?? '') === 'withdraw') $mapped_action = 'balance_subtract';
                if ($mapped_action) {
                    $local_log_keys[$mapped_action . '_' . floatval($local_row['project_amount'] ?? 0) . '_' . ($local_row['created_at'] ?? '')] = true;
                }
            }

            foreach ($source_logs as $key => $source_log) {
                if (!isset($local_log_keys[$key])) {
                    $discrepancy_details[] = [
                        'type' => 'source_only',
                        'action' => $source_log['action'],
                        'amount' => $source_log['amount'],
                        'created_at' => $source_log['created_at'],
                        'description' => '源台有记录但本地没有'
                    ];
                }
            }
        }

        $this->updateAuditCompleted($audit_id, $source_add, $source_subtract, $source_net, $local_charge, $local_withdraw, $local_net, $difference, $has_discrepancy, $discrepancy_details);
    }

    private function processDownstreamAudit($audit) {
        $audit_id = intval($audit['id']);
        $customer_id = intval($audit['customer_id']);
        $owner_uid = intval($audit['owner_uid'] ?: $audit['agent_uid']);
        $project_id = intval($audit['project_id']);

        $customer = $this->getOpenApiCustomer($customer_id, $project_id, $owner_uid);
        if (!$customer) {
            throw new Exception('下游客户不存在或已禁用');
        }

        $stat = $this->calculateDownstreamCustomerNetInflow($owner_uid, $customer_id, $project_id);
        $charge_total = floatval($stat['charge_total']);
        $withdraw_total = floatval($stat['withdraw_total']);
        $ledger_net = $charge_total - $withdraw_total;
        $current_balance = floatval($customer['balance'] ?? 0);
        $difference = $current_balance - $ledger_net;
        $has_discrepancy = abs($difference) > 0.01 ? 1 : 0;

        $details = [];
        if ($has_discrepancy) {
            $details = $this->buildDownstreamDiscrepancyDetails($owner_uid, $customer_id, $project_id, $current_balance, $charge_total, $withdraw_total, $ledger_net, $difference);
        }

        $this->updateAuditCompleted($audit_id, $charge_total, $withdraw_total, $ledger_net, $charge_total, $withdraw_total, $current_balance, $difference, $has_discrepancy, $details);
    }

    private function calculateDownstreamCustomerNetInflow($owner_uid, $customer_id, $project_id) {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN direction = 'charge' THEN ABS(project_amount) ELSE 0 END), 0) AS charge_total,
                COALESCE(SUM(CASE WHEN direction = 'withdraw' THEN ABS(project_amount) ELSE 0 END), 0) AS withdraw_total
            FROM openapi_ledger
            WHERE owner_uid = :owner_uid AND customer_id = :customer_id AND project_id = :project_id
        ");
        $stmt->execute([':owner_uid' => $owner_uid, ':customer_id' => $customer_id, ':project_id' => $project_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['charge_total' => floatval($row['charge_total'] ?? 0), 'withdraw_total' => floatval($row['withdraw_total'] ?? 0)];
    }

    private function buildDownstreamDiscrepancyDetails($owner_uid, $customer_id, $project_id, $current_balance, $charge_total, $withdraw_total, $ledger_net, $difference) {
        $details = [[
            'type' => 'downstream_balance_mismatch',
            'description' => '下游客户当前余额与 openapi_ledger 账本净流入不一致',
            'customer_balance' => $current_balance,
            'ledger_charge_total' => $charge_total,
            'ledger_withdraw_total' => $withdraw_total,
            'ledger_net' => $ledger_net,
            'difference' => $difference
        ]];

        $recent = $this->db->prepare("
            SELECT id, action, direction, project_amount, project_balance_before, project_balance_after, remark, created_at
            FROM openapi_ledger
            WHERE owner_uid = :owner_uid AND customer_id = :customer_id AND project_id = :project_id
            ORDER BY id DESC LIMIT 20
        ");
        $recent->execute([':owner_uid' => $owner_uid, ':customer_id' => $customer_id, ':project_id' => $project_id]);
        $details[] = ['type' => 'recent_ledger', 'items' => $recent->fetchAll(PDO::FETCH_ASSOC)];

        $abnormal = $this->db->prepare("
            SELECT id, action, direction, project_amount, remark, created_at
            FROM openapi_ledger
            WHERE owner_uid = :owner_uid AND customer_id = :customer_id AND project_id = :project_id
              AND (direction NOT IN ('charge', 'withdraw') OR project_amount = 0 OR project_amount IS NULL)
            ORDER BY id DESC LIMIT 50
        ");
        $abnormal->execute([':owner_uid' => $owner_uid, ':customer_id' => $customer_id, ':project_id' => $project_id]);
        $bad = $abnormal->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($bad)) {
            $details[] = ['type' => 'abnormal_ledger', 'items' => $bad];
        }
        return $details;
    }

    private function updateAuditCompleted($audit_id, $source_add, $source_subtract, $source_net, $local_charge, $local_withdraw, $local_net, $difference, $has_discrepancy, $details = []) {
        $update = $this->db->prepare("
            UPDATE customer_audit SET
                source_balance_add = :sadd,
                source_balance_subtract = :ssub,
                source_net = :snet,
                local_charge_total = :lcharge,
                local_withdraw_total = :lwithdraw,
                local_net = :lnet,
                difference = :diff,
                has_discrepancy = :has_disc,
                discrepancy_details = :disc_details,
                audit_status = 'completed',
                audit_error = NULL,
                last_audit_at = datetime('now', 'localtime'),
                updated_at = datetime('now', 'localtime')
            WHERE id = :id
        ");
        $update->execute([
            ':sadd' => $source_add,
            ':ssub' => $source_subtract,
            ':snet' => $source_net,
            ':lcharge' => $local_charge,
            ':lwithdraw' => $local_withdraw,
            ':lnet' => $local_net,
            ':diff' => $difference,
            ':has_disc' => $has_discrepancy,
            ':disc_details' => !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ':id' => $audit_id
        ]);
    }

    private function markAuditFailed($audit_id, $message) {
        $error_update = $this->db->prepare("
            UPDATE customer_audit SET audit_status = 'failed', audit_error = :err, updated_at = datetime('now', 'localtime')
            WHERE id = :id
        ");
        $error_update->execute([':err' => substr($message, 0, 255), ':id' => $audit_id]);
    }

    private function httpGetJson($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            throw new Exception('CURL错误: ' . $error);
        }
        return json_decode($response, true);
    }

    /**
     * 获取审计结果（分页）
     */
    public function getAuditResults($agent_uid = 0, $has_discrepancy = -1, $page = 1, $page_size = 20, $audit_type = '') {
        try {
            $audit_type = $this->normalizeAuditType($audit_type, '');
            $page = max(1, intval($page));
            $page_size = min(100, max(1, intval($page_size)));
            $offset = ($page - 1) * $page_size;

            $where = ["1=1"];
            $params = [];

            if ($agent_uid > 0) {
                $where[] = "agent_uid = :agent_uid";
                $params[':agent_uid'] = $agent_uid;
            }
            if ($audit_type === 'upstream' || $audit_type === 'downstream') {
                $where[] = "audit_type = :audit_type";
                $params[':audit_type'] = $audit_type;
            }
            if ($has_discrepancy >= 0) {
                $where[] = "has_discrepancy = :has_disc";
                $params[':has_disc'] = $has_discrepancy;
            }

            $where_sql = implode(' AND ', $where);
            $count_stmt = $this->db->prepare("SELECT COUNT(*) as total FROM customer_audit WHERE {$where_sql}");
            $count_stmt->execute($params);
            $total = intval($count_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

            $data_stmt = $this->db->prepare("
                SELECT * FROM customer_audit
                WHERE {$where_sql}
                ORDER BY ABS(difference) DESC, id DESC
                LIMIT :limit OFFSET :offset
            ");
            foreach ($params as $k => $v) $data_stmt->bindValue($k, $v);
            $data_stmt->bindValue(':limit', $page_size, PDO::PARAM_INT);
            $data_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $data_stmt->execute();
            $results = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as &$row) {
                $row['source_api_key'] = $this->maskApiKey($row['source_api_key'] ?? '');
                $row['customer_api_key'] = $this->maskApiKey($row['customer_api_key'] ?? ($row['source_api_key'] ?? ''));
                $row['has_discrepancy'] = intval($row['has_discrepancy']);
                $row['owner_uid'] = intval($row['owner_uid'] ?? 0);
                $row['audit_type'] = $row['audit_type'] ?? 'upstream';
                if (!empty($row['discrepancy_details'])) {
                    $decoded = json_decode($row['discrepancy_details'], true);
                    $row['discrepancy_details'] = is_array($decoded) ? $decoded : [];
                } else {
                    $row['discrepancy_details'] = [];
                }
            }
            unset($row);

            return [
                'total' => $total,
                'page' => $page,
                'page_size' => $page_size,
                'total_pages' => $page_size > 0 ? ceil($total / $page_size) : 1,
                'list' => $results
            ];
        } catch (PDOException $e) {
            throw new Exception('获取审计结果失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取审计统计
     */
    public function getAuditStats($audit_type = '') {
        try {
            $audit_type = $this->normalizeAuditType($audit_type, '');
            $stats = $this->buildAuditStats($audit_type);
            if ($audit_type === '' || $audit_type === 'all') {
                $stats['by_type'] = [
                    'upstream' => $this->buildAuditStats('upstream'),
                    'downstream' => $this->buildAuditStats('downstream')
                ];
            }
            return $stats;
        } catch (PDOException $e) {
            throw new Exception('获取审计统计失败: ' . $e->getMessage());
        }
    }

    private function buildAuditStats($audit_type = '') {
        $where = '1=1';
        if ($audit_type === 'upstream' || $audit_type === 'downstream') {
            $where = "audit_type = " . $this->db->quote($audit_type);
        }
        $stats = [];
        $row = $this->db->query("SELECT COUNT(*) as total FROM customer_audit WHERE {$where}")->fetch(PDO::FETCH_ASSOC);
        $stats['total'] = intval($row['total'] ?? 0);
        $row = $this->db->query("
            SELECT
                COUNT(*) as count,
                SUM(CASE WHEN has_discrepancy = 1 THEN 1 ELSE 0 END) as discrepancy_count,
                SUM(CASE WHEN has_discrepancy = 1 THEN ABS(difference) ELSE 0 END) as total_diff
            FROM customer_audit
            WHERE {$where} AND audit_status = 'completed'
        ")->fetch(PDO::FETCH_ASSOC);
        $stats['completed'] = intval($row['count'] ?? 0);
        $stats['discrepancy_count'] = intval($row['discrepancy_count'] ?? 0);
        $stats['total_diff'] = floatval($row['total_diff'] ?? 0);
        $row = $this->db->query("SELECT COUNT(*) as count FROM customer_audit WHERE {$where} AND audit_status = 'pending'")->fetch(PDO::FETCH_ASSOC);
        $stats['pending'] = intval($row['count'] ?? 0);
        $row = $this->db->query("SELECT COUNT(*) as count FROM customer_audit WHERE {$where} AND audit_status = 'failed'")->fetch(PDO::FETCH_ASSOC);
        $stats['failed'] = intval($row['count'] ?? 0);
        return $stats;
    }

    private function maskApiKey($key) {
        $key = strval($key);
        if ($key === '') return '';
        if (strlen($key) <= 16) return substr($key, 0, 4) . '...';
        return substr($key, 0, 8) . '...' . substr($key, -6);
    }

    // ==================== 辅助函数 ====================

    /**
     * 调用源平台API
     * @param string $action API操作
     * @param array $params 请求参数
     * @return array|null API响应
     */
    private function callSourceAPI($action, $params = []) {
        $api_url = $this->getConfig('source_api_url') ?: 'https://syyrun.com/api.php';
        if (strpos($api_url, 'http://') !== 0 && strpos($api_url, 'https://') !== 0) {
            $api_url = 'https://' . $api_url;
        }

        $params['action'] = $action;

        // 按爽歪歪公开文档：管理 API 密钥调用开户/禁用/启用/查询时走 GET，
        // 仅余额调整必须走 POST。
        $post_actions = ['adjustCustomerBalance'];
        $use_post = in_array($action, $post_actions);

        $ch = curl_init();

        if ($use_post) {
            // POST方式
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: UniversalAPI-V3/1.0',
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'X-API-Key: ' . strval($params['api_key'] ?? '')
            ]);
        } else {
            // GET方式
            $url_with_params = $api_url . '?' . http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $url_with_params);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: UniversalAPI-V3/1.0',
                'Accept: application/json',
                'X-API-Key: ' . strval($params['api_key'] ?? '')
            ]);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            return ['status' => 'error', 'message' => 'CURL错误: ' . $error];
        }

        if ($httpCode != 200) {
            return ['status' => 'error', 'message' => 'HTTP错误: ' . $httpCode];
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['status' => 'error', 'message' => 'JSON解析错误: ' . json_last_error_msg()];
        }

        return $data;
    }

}
