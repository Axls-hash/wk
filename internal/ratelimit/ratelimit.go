// Package ratelimit 固定窗口限流（SQLite 承载，跨进程共享）。
//
// 逻辑与 Python 版一致：
//   - allow：准入计数（超限走纯读快路径拒绝）
//   - count：无条件累加（封禁阈值判定用）
//   - peek：只读当前窗口计数
//   - 过期窗口清理按时间节流
package ratelimit

import (
	"database/sql"
	"sync"
	"sync/atomic"
	"time"

	"daili-go/internal/store"
)

var (
	cleanupMu       sync.Mutex
	lastCleanupAt   time.Time // 按时间节流（与窗口粒度无关，60s 内最多一次）
	cleanupInFlight atomic.Bool
)

const cleanupInterval = 30 * time.Second

func insertIgnoreRateSQL() string {
	if store.IsMySQL() {
		return `INSERT IGNORE INTO rate_limits(rate_key,window_id,hit_count,expires_at) VALUES(?,?,?,?)`
	}
	return `INSERT OR IGNORE INTO rate_limits(rate_key,window_id,hit_count,expires_at) VALUES(?,?,?,?)`
}

func maybeCleanup(_ int64) {
	cleanupMu.Lock()
	tooSoon := time.Since(lastCleanupAt) < cleanupInterval
	if !tooSoon {
		lastCleanupAt = time.Now()
	}
	cleanupMu.Unlock()
	if tooSoon {
		return
	}
	if !cleanupInFlight.CompareAndSwap(false, true) {
		return
	}
	go func() {
		defer func() {
			if r := recover(); r != nil {
				// panic 不能让 cleanupInFlight 永久为 true，否则后续清理全部失效
			}
			cleanupInFlight.Store(false)
		}()
		// 按绝对过期时间清理：不同窗口粒度的行互不干扰（修复跨粒度误删 bug）
		_, _ = store.DB.Exec(`DELETE FROM rate_limits WHERE expires_at > 0 AND expires_at < ?`,
			float64(time.Now().Unix()))
	}()
}

type Limiter struct {
	limit  int
	window int64 // 秒
}

func New(limit int, windowSeconds int) *Limiter {
	return &Limiter{limit: limit, window: int64(windowSeconds)}
}

// Limit 返回窗口限额（供锁定阈值等使用同一来源，避免魔数漂移）。
func (l *Limiter) Limit() int { return l.limit }

func (l *Limiter) current() int64 { return time.Now().Unix() / l.window }

// Allow 记录一次请求；未超限返回 true 并计数。
func (l *Limiter) Allow(key string) bool {
	w := l.current()
	maybeCleanup(w)
	res, err := l.execAllow(key, w, l.limit)
	if err != nil {
		return false
	}
	return res
}

// Count 无条件 +1 并返回新值（超过 limit 也继续累加）。
func (l *Limiter) Count(key string) int {
	w := l.current()
	maybeCleanup(w)
	n, err := l.execCount(key, w)
	if err != nil {
		return l.limit + 1
	}
	return n
}

// Reset 清零某 key 当前窗口的计数（登录成功时清空失败计数用）。
func (l *Limiter) Reset(key string) {
	w := l.current()
	_, _ = store.DB.Exec(`DELETE FROM rate_limits WHERE rate_key=? AND window_id=?`, key, w)
}

// Peek 只读返回当前窗口已计次数。
func (l *Limiter) Peek(key string) int {
	var n int
	_ = store.DB.QueryRow(
		`SELECT hit_count FROM rate_limits WHERE rate_key=? AND window_id=?`, key, l.current(),
	).Scan(&n)
	return n
}

// expiresAt 计算该窗口行的绝对过期时刻（多留 2 个窗口供 peek 读旧值）。
// 关键：不同限流器的窗口粒度不同，窗口号数值不可互比；
// 清理必须基于绝对时间，否则大号小窗的清理会误删长窗口计数（历史 bug）。
func (l *Limiter) expiresAt(w int64) float64 {
	return float64((w + 2) * l.window)
}

func (l *Limiter) execAllow(key string, w int64, limit int) (bool, error) {
	tx, err := store.DB.Begin()
	if err != nil {
		return false, err
	}
	defer tx.Rollback()

	var hits int
	err = tx.QueryRow(`SELECT hit_count FROM rate_limits WHERE rate_key=? AND window_id=?`, key, w).Scan(&hits)
	switch {
	case err == nil:
	case err == sql.ErrNoRows:
		res, execErr := tx.Exec(
			insertIgnoreRateSQL(),
			key, w, 1, l.expiresAt(w))
		if execErr != nil {
			return false, execErr
		}
		inserted, rowsErr := res.RowsAffected()
		if rowsErr != nil {
			return false, rowsErr
		}
		if inserted > 0 {
			if err = tx.Commit(); err != nil {
				return false, err
			}
			return true, nil
		}
		// Another transaction created the row after the initial SELECT.
		// Continue through the guarded UPDATE so this request is counted once.
	default:
		return false, err
	}

	if hits >= limit {
		return false, nil // 纯读快路径：不写库
	}
	res, err := tx.Exec(
		`UPDATE rate_limits SET hit_count=hit_count+1 WHERE rate_key=? AND window_id=? AND hit_count<?`,
		key, w, limit,
	)
	if err != nil {
		return false, err
	}
	if n, _ := res.RowsAffected(); n == 0 {
		return false, nil
	}
	return true, tx.Commit()
}

func (l *Limiter) execCount(key string, w int64) (int, error) {
	tx, err := store.DB.Begin()
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()
	if _, err = tx.Exec(
		insertIgnoreRateSQL(),
		key, w, 0, l.expiresAt(w)); err != nil {
		return 0, err
	}
	if _, err = tx.Exec(`UPDATE rate_limits SET hit_count=hit_count+1 WHERE rate_key=? AND window_id=?`, key, w); err != nil {
		return 0, err
	}
	if err = tx.Commit(); err != nil {
		return 0, err
	}
	var n int
	err = store.DB.QueryRow(`SELECT hit_count FROM rate_limits WHERE rate_key=? AND window_id=?`, key, w).Scan(&n)
	return n, err
}
