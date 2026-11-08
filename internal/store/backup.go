// Package store 的在线备份。
//
//   - SQLite：VACUUM INTO 快照（WAL 安全，不阻塞业务）
//   - MySQL：纯 Go 导出 SQL 快照（schema + 数据），单连接一致性快照读，
//     不依赖服务器上是否装有 mysqldump；产出 backup_*.sql
//
// 两种驱动统一走 Backup() 入口，备份目录混存 .db/.sql，PruneBackups 统一裁剪。
package store

import (
	"bufio"
	"context"
	"database/sql"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// Backup 把当前数据库快照到 dir/backup_YYYYMMDD_HHMMSS.{db,sql}。
func Backup(dir string) (string, error) {
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return "", err
	}
	if IsMySQL() {
		name := filepath.ToSlash(filepath.Join(dir,
			fmt.Sprintf("backup_%s.sql", time.Now().Format("20060102_150405"))))
		if err := backupMySQL(name); err != nil {
			return "", err
		}
		return name, nil
	}
	name := filepath.ToSlash(filepath.Join(dir,
		fmt.Sprintf("backup_%s.db", time.Now().Format("20060102_150405"))))
	// 转义单引号防注入（dir 受控但仍做防御式处理），VACUUM INTO 不支持参数化
	escaped := strings.ReplaceAll(name, "'", "''")
	if _, err := DB.Exec(fmt.Sprintf("VACUUM INTO '%s'", escaped)); err != nil {
		return "", fmt.Errorf("vacuum into: %w", err)
	}
	return name, nil
}

// ---------------- MySQL SQL 快照导出 ----------------

const mysqlBackupTimeout = 10 * time.Minute

// backupMySQL 把整库导出为可回放的 SQL 文件。
// 单连接 + REPEATABLE READ 一致性快照：全程读到同一时点数据，不锁业务写。
func backupMySQL(path string) error {
	ctx, cancel := context.WithTimeout(context.Background(), mysqlBackupTimeout)
	defer cancel()

	conn, err := DB.Conn(ctx)
	if err != nil {
		return fmt.Errorf("mysql backup conn: %w", err)
	}
	defer conn.Close()
	// InnoDB 一致性快照：后续查询全部看到事务开始时的数据版本
	if _, err := conn.ExecContext(ctx, "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ"); err != nil {
		return fmt.Errorf("mysql backup isolation: %w", err)
	}
	if _, err := conn.ExecContext(ctx, "START TRANSACTION WITH CONSISTENT SNAPSHOT"); err != nil {
		return fmt.Errorf("mysql backup snapshot: %w", err)
	}
	defer func() { _, _ = conn.ExecContext(ctx, "COMMIT") }()

	out, err := os.Create(path)
	if err != nil {
		return err
	}
	w := bufio.NewWriterSize(out, 1<<20)
	if err := dumpMySQL(ctx, conn, w); err != nil {
		out.Close()
		os.Remove(path) // 半截快照不能留：恢复时会被当成完整备份误用
		return err
	}
	if err := w.Flush(); err != nil {
		out.Close()
		os.Remove(path)
		return err
	}
	return out.Close()
}

func dumpMySQL(ctx context.Context, conn *sql.Conn, w *bufio.Writer) error {
	fmt.Fprintf(w, "-- wk_dl MySQL backup %s\n", time.Now().Format(time.RFC3339))
	fmt.Fprintf(w, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n")

	rows, err := conn.QueryContext(ctx,
		`SELECT table_name FROM information_schema.tables
		 WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name`)
	if err != nil {
		return fmt.Errorf("list tables: %w", err)
	}
	var tables []string
	for rows.Next() {
		var t string
		if rows.Scan(&t) == nil && t != "" {
			tables = append(tables, t)
		}
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return fmt.Errorf("list tables: %w", err)
	}

	for _, t := range tables {
		if err := dumpMySQLTable(ctx, conn, w, t); err != nil {
			return err
		}
	}
	fmt.Fprint(w, "SET FOREIGN_KEY_CHECKS=1;\n")
	return nil
}

func dumpMySQLTable(ctx context.Context, conn *sql.Conn, w *bufio.Writer, table string) error {
	qt := quoteMySQLIdent(table)

	// 表结构
	var name, createSQL string
	if err := conn.QueryRowContext(ctx, "SHOW CREATE TABLE "+qt).Scan(&name, &createSQL); err != nil {
		return fmt.Errorf("show create %s: %w", table, err)
	}
	fmt.Fprintf(w, "DROP TABLE IF EXISTS %s;\n%s;\n\n", qt, createSQL)

	// 数据：流式读，分批写多值 INSERT，避免大表一次性载入内存
	rows, err := conn.QueryContext(ctx, "SELECT * FROM "+qt)
	if err != nil {
		return fmt.Errorf("select %s: %w", table, err)
	}
	defer rows.Close()
	cols, err := rows.Columns()
	if err != nil {
		return fmt.Errorf("columns %s: %w", table, err)
	}

	const batchLimit = 500
	vals := make([]interface{}, len(cols))
	ptrs := make([]interface{}, len(cols))
	for i := range vals {
		ptrs[i] = &vals[i]
	}
	batch := make([]string, 0, batchLimit)
	colList := quoteMySQLColumns(cols)
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		fmt.Fprintf(w, "INSERT INTO %s %s VALUES\n%s;\n\n", qt, colList, strings.Join(batch, ",\n"))
		batch = batch[:0]
		return w.Flush()
	}
	for rows.Next() {
		if err := rows.Scan(ptrs...); err != nil {
			return fmt.Errorf("scan %s: %w", table, err)
		}
		group := make([]string, len(cols))
		for i, v := range vals {
			group[i] = mysqlSQLValue(v)
		}
		batch = append(batch, "("+strings.Join(group, ",")+")")
		if len(batch) >= batchLimit {
			if err := flush(); err != nil {
				return fmt.Errorf("write %s: %w", table, err)
			}
		}
	}
	if err := rows.Err(); err != nil {
		return fmt.Errorf("iterate %s: %w", table, err)
	}
	return flush()
}

// quoteMySQLIdent 标识符转义（表/列名来自 SHOW TABLES，防御性处理反引号）。
func quoteMySQLIdent(s string) string {
	return "`" + strings.ReplaceAll(s, "`", "``") + "`"
}

func quoteMySQLColumns(cols []string) string {
	parts := make([]string, len(cols))
	for i, c := range cols {
		parts[i] = quoteMySQLIdent(c)
	}
	return "(" + strings.Join(parts, ",") + ")"
}

// mysqlSQLValue 值 → SQL 字面量。nil→NULL；数值原样；[]byte/字符串转义引号与控制符。
func mysqlSQLValue(v interface{}) string {
	switch n := v.(type) {
	case nil:
		return "NULL"
	case bool:
		if n {
			return "1"
		}
		return "0"
	case int64:
		return fmt.Sprintf("%d", n)
	case float64:
		return fmt.Sprintf("%v", n)
	case []byte:
		return "'" + mysqlEscape(string(n)) + "'"
	case string:
		return "'" + mysqlEscape(n) + "'"
	case time.Time:
		return "'" + n.Format("2006-01-02 15:04:05.999999") + "'"
	default:
		return "'" + mysqlEscape(fmt.Sprintf("%v", n)) + "'"
	}
}

// mysqlEscape MySQL 字符串字面量转义（反斜杠语义，no_backslash_escapes 模式未启用）。
func mysqlEscape(s string) string {
	r := strings.NewReplacer(
		"\\", "\\\\",
		"\x00", "\\0",
		"\n", "\\n",
		"\r", "\\r",
		"\x1a", "\\Z",
		"'", "\\'",
	)
	return r.Replace(s)
}

// ---------------- 备份裁剪 ----------------

// PruneBackups 只保留最新的 keep 份备份（.db 与 .sql 都算），返回删除数量。
func PruneBackups(dir string, keep int) int {
	return pruneBackupFiles(dir, keep, nil)
}

// pruneBackupFiles 裁剪备份文件；latest 非空时回写最新文件修改时间（StartBackupLoop 用）。
func pruneBackupFiles(dir string, keep int, latest *time.Time) int {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return 0
	}
	var files []string
	for _, e := range entries {
		if e.IsDir() || !strings.HasPrefix(e.Name(), "backup_") {
			continue
		}
		if strings.HasSuffix(e.Name(), ".db") || strings.HasSuffix(e.Name(), ".sql") {
			files = append(files, filepath.Join(dir, e.Name()))
		}
	}
	sort.Strings(files) // 文件名含时间戳，字典序即时间序
	removed := 0
	for i := 0; i < len(files)-keep; i++ {
		if os.Remove(files[i]) == nil {
			removed++
		}
	}
	if latest != nil && len(files) > 0 {
		if info, err := os.Stat(files[len(files)-1]); err == nil {
			*latest = info.ModTime()
		}
	}
	return removed
}

// StartBackupLoop 每 checkInterval 检查一次：最新备份超过 interval 就补一份。
// 进程启动即检查（覆盖「上次备份后服务一直没重启」的场景），常驻后台。
func StartBackupLoop(dir string, interval time.Duration) {
	go func() {
		defer func() { recover() }()
		for {
			var latest time.Time
			pruneBackupFiles(dir, 14, &latest)
			if time.Since(latest) >= interval {
				if path, err := Backup(dir); err != nil {
					fmt.Fprintf(os.Stderr, "[backup] failed: %v\n", err)
				} else {
					PruneBackups(dir, 14)
					_ = path
				}
			}
			time.Sleep(6 * time.Hour)
		}
	}()
}
