package store

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestMySQLEscape(t *testing.T) {
	cases := map[string]string{
		"plain":     `plain`,
		`it's`:      `it\'s`,
		"a\nb":      `a\nb`,
		"c\rd":      `c\rd`,
		"e\\f":      `e\\f`,
		"g\x00h":    `g\0h`,
		"i\x1aj":    `i\Zj`,
		"中文'引号\"混合": `中文\'引号"混合`,
	}
	for in, want := range cases {
		if got := mysqlEscape(in); got != want {
			t.Errorf("mysqlEscape(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestMySQLSQLValue(t *testing.T) {
	if got := mysqlSQLValue(nil); got != "NULL" {
		t.Errorf("nil -> %q", got)
	}
	if got := mysqlSQLValue(int64(42)); got != "42" {
		t.Errorf("int64 -> %q", got)
	}
	if got := mysqlSQLValue(true); got != "1" {
		t.Errorf("true -> %q", got)
	}
	if got := mysqlSQLValue("o'clock"); got != `'o\'clock'` {
		t.Errorf("string -> %q", got)
	}
}

func TestPruneBackupsMixedExtensions(t *testing.T) {
	dir := t.TempDir()
	names := []string{
		"backup_20260101_000000.db",
		"backup_20260102_000000.sql",
		"backup_20260103_000000.db",
		"backup_20260104_000000.sql",
		"other.txt",        // 非备份文件，不参与
		"backup_notes.bak", // 非法扩展，不参与
	}
	for _, n := range names {
		if err := os.WriteFile(filepath.Join(dir, n), []byte("x"), 0o644); err != nil {
			t.Fatal(err)
		}
		time.Sleep(10 * time.Millisecond) // 保证 mtime 顺序稳定
	}
	removed := PruneBackups(dir, 2)
	if removed != 2 {
		t.Fatalf("removed = %d, want 2", removed)
	}
	remaining, _ := os.ReadDir(dir)
	var got []string
	for _, e := range remaining {
		got = append(got, e.Name())
	}
	// 保留最新两份（跨扩展名一起算）；other.txt / backup_notes.bak 非备份文件，不动
	wantHas := map[string]bool{
		"backup_20260103_000000.db": true, "backup_20260104_000000.sql": true,
		"other.txt": true, "backup_notes.bak": true,
	}
	if len(got) != len(wantHas) {
		t.Fatalf("remaining = %v", got)
	}
	for _, g := range got {
		if !wantHas[g] {
			t.Fatalf("unexpected file kept: %s (all=%v)", g, got)
		}
	}
}

func TestBackupSQLiteProducesValidDB(t *testing.T) {
	dir := t.TempDir()
	if err := Open(filepath.Join(dir, "src.db")); err != nil {
		t.Fatal(err)
	}
	defer DB.Close()
	if IsMySQL() {
		t.Skip("sqlite-only test")
	}
	if _, err := DB.Exec(`INSERT INTO announcements(title, content, created_at, updated_at) VALUES('t','c',1,1)`); err != nil {
		t.Fatal(err)
	}
	out, err := Backup(dir)
	if err != nil {
		t.Fatalf("backup: %v", err)
	}
	if !strings.HasSuffix(out, ".db") {
		t.Fatalf("sqlite backup should be .db, got %s", out)
	}
	if _, err := os.Stat(out); err != nil {
		t.Fatalf("backup file missing: %v", err)
	}
}
