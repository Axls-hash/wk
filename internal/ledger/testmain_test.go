// Package ledger 内部测试辅助：临时文件库。
package ledger

import (
	"os"
	"testing"

	"daili-go/internal/store"
)

// OpenTestDB 打开独立临时库（每个测试进程一个，跑完删除）。
func OpenTestDB(t *testing.T) {
	t.Helper()
	path := "./test_ledger.db"
	_ = os.Remove(path)
	if err := store.Open(path); err != nil {
		t.Fatalf("open test db: %v", err)
	}
	t.Cleanup(func() {
		os.Remove(path)
		os.Remove(path + "-wal")
		os.Remove(path + "-shm")
	})
}

// SeedUser 建一个测试用户，返回 id。
func SeedUser(t *testing.T, username string, points int64) int64 {
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
