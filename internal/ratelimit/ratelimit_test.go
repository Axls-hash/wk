package ratelimit

import (
	"sync"
	"testing"

	"daili-go/internal/store"
	"os"
)

func openTestDB(t *testing.T) {
	t.Helper()
	path := "./test_rl.db"
	_ = os.Remove(path)
	if err := store.Open(path); err != nil {
		t.Fatalf("open: %v", err)
	}
	t.Cleanup(func() {
		os.Remove(path)
		os.Remove(path + "-wal")
		os.Remove(path + "-shm")
	})
}

// TestAllowFixedWindow 固定窗口准入与拒绝。
func TestAllowFixedWindow(t *testing.T) {
	openTestDB(t)
	l := New(3, 60)
	key := "k1"

	for i := 0; i < 3; i++ {
		if !l.Allow(key) {
			t.Fatalf("第 %d 次应放行", i+1)
		}
	}
	for i := 0; i < 5; i++ {
		if l.Allow(key) {
			t.Fatal("超限后应拒绝")
		}
	}
	// 不同 key 互不影响
	if !l.Allow("k2") {
		t.Fatal("独立 key 应放行")
	}
}

// TestCountUnbounded count 无上限累加（封禁阈值判定）。
func TestCountUnbounded(t *testing.T) {
	openTestDB(t)
	l := New(2, 60)
	for i := 1; i <= 7; i++ {
		if n := l.Count("c"); n != i {
			t.Fatalf("count 返回应递增: %d 次 -> %d", i, n)
		}
	}
	if l.Peek("c") != 7 {
		t.Fatalf("peek 应为 7: %d", l.Peek("c"))
	}
}

// TestPeekReadOnly peek 不产生计数。
func TestPeekReadOnly(t *testing.T) {
	openTestDB(t)
	l := New(5, 60)
	for i := 0; i < 3; i++ {
		l.Peek("p") // 大量 peek
	}
	if l.Peek("p") != 0 {
		t.Fatal("peek 不应计数")
	}
}

// TestConcurrentAllow 并发下不超发（原子条件更新）。
func TestConcurrentAllow(t *testing.T) {
	openTestDB(t)
	const limit, goroutines = 10, 50
	l := New(limit, 60)

	var wg sync.WaitGroup
	passed := make(chan struct{}, goroutines)
	for i := 0; i < goroutines; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if l.Allow("race") {
				passed <- struct{}{}
			}
		}()
	}
	wg.Wait()
	close(passed)

	n := len(passed)
	if n != limit {
		t.Fatalf("并发放行应恰好 %d，实际 %d（超发即有竞态）", limit, n)
	}
}
