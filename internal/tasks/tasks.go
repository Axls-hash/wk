// Package tasks 后台抓取任务管理：SQLite 承载状态 + 固定 worker 池。
//
// 对齐 Python tasks.py：
//   - 提交即返回 task_id，抓取在 worker goroutine 中执行
//   - 账号互斥靠 partial unique index（并发插入撞索引 → AlreadyRunning）
//   - done/error 行 TTL 600s 清理；pending/running 卡 30min 回收为 error
//   - 密码只在任务闭包内存，不落库
package tasks

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"daili-go/internal/config"
	"daili-go/internal/fetchers"
	"daili-go/internal/logger"
	"daili-go/internal/store"
)

const (
	taskTTLSeconds  = 600
	stuckTTLSeconds = 1800
)

var ErrAlreadyRunning = errors.New("该账号正在查询中，一般几秒到几分钟完成，请稍后重试")

// Snapshot 任务快照（对齐 Python TaskSnapshot）。
type Snapshot struct {
	ID       string
	Status   string // pending/running/done/error
	Courses  []map[string]interface{}
	Error    string
	UserID   int64
	Platform string // 从 account_key 解析（供前端恢复时回填平台）
}

type job struct {
	taskID   string
	platform string
	username string
	password string // 仅存内存，不落库
	school   string
}

// FetchFn 抓取函数签名（可注入 mock 做测试）。
type FetchFn func(platform, username, password, school string) ([]fetchers.Course, error)

// Manager 任务管理器。
type Manager struct {
	jobs           chan job
	FetchFn        FetchFn // nil 时使用真实抓取器
	lastCleanup    time.Time
	cleanupMu      sync.Mutex
	cleanupRunning atomic.Bool
}

var M *Manager

func Init() {
	InitWithFetch(nil, config.FetchWorkers)
}

// InitWithFetch 测试用：注入抓取函数与 worker 数。
func InitWithFetch(fn FetchFn, workers int) {
	if fn == nil {
		fn = fetchers.FetchCourses
	}
	if workers <= 0 {
		workers = 1
	}
	M = &Manager{jobs: make(chan job, 4096), FetchFn: fn}
	for i := 0; i < workers; i++ {
		go M.worker()
	}
	// 启动即回收上次进程遗留的任务（单进程模型，进程没了任务必然中断，
	// 立刻标 error 让用户能重新提交，而不是傻等 30 分钟 STUCK 回收）
	_, _ = store.DB.Exec(
		`UPDATE scrape_tasks SET status='error', error='服务已重启，请重新提交查询'
		 WHERE status IN ('pending','running')`)
	M.cleanup()
	go M.cleaner()
}

func newTaskID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// Submit 提交抓取任务，立即返回 task_id。同账号有活跃任务时返回 ErrAlreadyRunning。
// 已移除前置 SELECT 探测，直接依赖 partial unique index 原子排他，避免并发窗口竞态。
func (m *Manager) Submit(platform, username, password, school, accountKey string, userID int64) (string, error) {
	m.maybeCleanup()

	taskID := newTaskID()
	res, err := store.DB.Exec(
		`INSERT INTO scrape_tasks(task_id, account_key, user_id, status, courses, error, created_at)
		 VALUES(?,?,?,?, '[]', '', ?)`,
		taskID, accountKey, userID, "pending", nowFloat(),
	)
	if err != nil {
		if isUniqueViolation(err) {
			return "", ErrAlreadyRunning
		}
		return "", err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return "", ErrAlreadyRunning
	}
	select {
	case m.jobs <- job{taskID: taskID, platform: platform, username: username, password: password, school: school}:
	default:
		// 队列满（极端情况）：任务标记失败，不留僵尸 pending 行
		_, _ = store.DB.Exec(`UPDATE scrape_tasks SET status='error', error=? WHERE task_id=?`,
			"服务繁忙，请稍后再试", taskID)
		return "", errors.New("服务繁忙，请稍后再试")
	}
	return taskID, nil
}

// Get 按 task_id 查快照；不存在/已过期返回 nil。
func (m *Manager) Get(taskID string) *Snapshot {
	row := store.DB.QueryRow(
		`SELECT task_id, status, courses, error, user_id, account_key FROM scrape_tasks WHERE task_id=?`, taskID)
	var s Snapshot
	var coursesJSON, accountKey string
	var uid sql.NullInt64
	err := row.Scan(&s.ID, &s.Status, &coursesJSON, &s.Error, &uid, &accountKey)
	if err != nil {
		return nil
	}
	s.UserID = uid.Int64
	if i := strings.IndexByte(accountKey, ':'); i > 0 { // 「平台:账号」前缀即平台名
		s.Platform = accountKey[:i]
	}
	s.Courses = []map[string]interface{}{}
	_ = json.Unmarshal([]byte(coursesJSON), &s.Courses)
	clean := make([]map[string]interface{}, 0, len(s.Courses))
	for _, c := range s.Courses {
		if c != nil {
			clean = append(clean, c)
		}
	}
	s.Courses = clean
	return &s
}

// InterruptAll 优雅关停时调用：所有未完成任务即时标记为可重试错误。
func (m *Manager) InterruptAll() {
	_, _ = store.DB.Exec(
		`UPDATE scrape_tasks SET status='error', error='服务正在维护，请稍后重新提交'
		 WHERE status IN ('pending','running')`)
}

func (m *Manager) worker() {
	for j := range m.jobs {
		m.run(j)
	}
}

func (m *Manager) run(j job) {
	// 任何抓取器的意外 panic 都不允许打崩进程：
	// Python 版靠 except Exception 兜底，Go 版必须显式 recover
	defer func() {
		if r := recover(); r != nil {
			logger.Errorf("fetcher panic: task=%s platform=%s err=%v", j.taskID, j.platform, r)
			_, _ = store.DB.Exec(
				`UPDATE scrape_tasks SET status='error', error=? WHERE task_id=? AND status IN ('running','pending')`,
				truncateStr(fmt.Sprintf("抓取内部错误: %v", r), 1000), j.taskID)
		}
	}()

	// pending → running（若已被 cleanup 回收则放弃执行）
	res, err := store.DB.Exec(
		`UPDATE scrape_tasks SET status='running' WHERE task_id=? AND status='pending'`, j.taskID)
	if err != nil {
		return
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return
	}

	courses, err := m.FetchFn(j.platform, j.username, j.password, j.school)

	// 密码即焚：j 离开作用域即回收
	j.password = ""

	if err != nil {
		msg := err.Error()
		_, _ = store.DB.Exec(
			`UPDATE scrape_tasks SET status='error', error=? WHERE task_id=? AND status='running'`,
			truncateStr(msg, 1000), j.taskID)
		return
	}
	raw, jsonErr := json.Marshal(courses)
	if jsonErr != nil || courses == nil {
		raw = []byte("[]")
	}
	_, _ = store.DB.Exec(
		`UPDATE scrape_tasks SET status='done', courses=? WHERE task_id=? AND status='running'`,
		string(raw), j.taskID)
	logger.Infof("fetch done: task=%s platform=%s courses=%d", j.taskID, j.platform, len(courses))
}

// maskAccount 日志里脱敏账号（保留前 3 字符）。
func maskAccount(s string) string {
	if len(s) <= 3 {
		return "***"
	}
	return s[:3] + "***"
}

// cleanup 删除过期 done/error；卡死 pending/running 回收为 error。
func (m *Manager) cleanup() {
	now := time.Now().Unix()
	_, _ = store.DB.Exec(
		`DELETE FROM scrape_tasks WHERE status IN ('done','error') AND created_at < ?`,
		float64(now-taskTTLSeconds))
	_, _ = store.DB.Exec(
		`UPDATE scrape_tasks SET status='error', error='抓取任务超时（超过 30 分钟）已回收'
		 WHERE status IN ('pending','running') AND created_at < ?`,
		float64(now-stuckTTLSeconds))
}

// maybeCleanup 带节流的清理：30 秒内最多执行一次，避免高并发时重复扫描。
func (m *Manager) maybeCleanup() {
	const throttle = 30 * time.Second
	m.cleanupMu.Lock()
	tooSoon := time.Since(m.lastCleanup) < throttle
	if !tooSoon {
		m.lastCleanup = time.Now()
	}
	m.cleanupMu.Unlock()
	if tooSoon {
		return
	}
	if !m.cleanupRunning.CompareAndSwap(false, true) {
		return
	}
	go func() {
		defer func() {
			if r := recover(); r != nil {
				logger.Errorf("cleanup panic: %v", r)
			}
			m.cleanupRunning.Store(false)
		}()
		m.cleanup()
	}()
}

func (m *Manager) cleaner() {
	for {
		time.Sleep(60 * time.Second)
		func() {
			defer func() { recover() }()
			m.cleanup()
		}()
	}
}

func nowFloat() float64 { return float64(time.Now().UnixNano()) / 1e9 }

func truncateStr(s string, n int) string {
	b := []byte(s)
	if len(b) <= n {
		return s
	}
	for n > 0 && (b[n]&0xC0) == 0x80 {
		n--
	}
	return string(b[:n])
}

// isUniqueViolation 仅匹配唯一索引冲突；NOT NULL/CHECK 等其他约束
// 失败不能误报成「账号正在抓取中」（否则真实 DB 错误被掩盖）。
func isUniqueViolation(err error) bool {
	if err == nil {
		return false
	}
	msg := err.Error()
	return contains(msg, "UNIQUE constraint failed") ||
		contains(msg, "unique constraint") ||
		contains(msg, "2067") || // modernc/sqlite: SQLITE_CONSTRAINT_UNIQUE
		contains(msg, "2596") || // SQLITE_CONSTRAINT_PRIMARYKEY
		contains(msg, "1555") || // SQLITE_CONSTRAINT_PRIMARYKEY (旧码)
		contains(msg, "1062") // MySQL duplicate key
}

func contains(s, sub string) bool {
	return len(s) >= len(sub) && indexOf(s, sub) >= 0
}

func indexOf(s, sub string) int {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return i
		}
	}
	return -1
}

var _ = fetchers.Platforms
