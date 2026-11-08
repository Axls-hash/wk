package tasks

import (
	"errors"
	"os"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"daili-go/internal/fetchers"
	"daili-go/internal/store"
)

func openTestDB(t *testing.T) {
	t.Helper()
	path := "./test_tasks.db"
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

func okFetch(platform, username, password, school string) ([]fetchers.Course, error) {
	return []fetchers.Course{{Title: "课", Status: "in_progress"}}, nil
}

// TestSubmitAndResult 提交→执行→查询全链路。
func TestSubmitAndResult(t *testing.T) {
	openTestDB(t)
	InitWithFetch(okFetch, 2)
	time.Sleep(50 * time.Millisecond) // 等 worker 起来

	id, err := M.Submit("学习通", "acc1", "pw", "", "学习通:acc1", 7)
	if err != nil {
		t.Fatalf("submit: %v", err)
	}
	waitStatus(t, id, "done", 3*time.Second)
	task := M.Get(id)
	if task == nil || task.UserID != 7 || len(task.Courses) != 1 {
		t.Fatalf("快照不符: %+v", task)
	}
}

// TestAccountMutex 同账号互斥（409 语义）。
func TestAccountMutex(t *testing.T) {
	openTestDB(t)
	release := make(chan struct{})
	InitWithFetch(func(p, u, p2, s string) ([]fetchers.Course, error) {
		<-release // 卡住模拟慢抓取
		return []fetchers.Course{}, nil
	}, 1)
	time.Sleep(50 * time.Millisecond)

	id1, err := M.Submit("平台", "same", "pw", "", "平台:same", 1)
	if err != nil {
		t.Fatalf("first submit: %v", err)
	}
	if _, err = M.Submit("平台", "other-acct-differs-key", "pw", "", "平台:same", 2); !errors.Is(err, ErrAlreadyRunning) {
		t.Fatalf("同账号第二次提交应互斥: %v", err)
	}
	close(release)
	waitStatus(t, id1, "done", 3*time.Second)
}

// TestFetcherPanicBecomesError 抓取器 panic 不崩进程、任务转 error。
func TestFetcherPanicBecomesError(t *testing.T) {
	openTestDB(t)
	InitWithFetch(func(p, u, p2, s string) ([]fetchers.Course, error) {
		panic("boom")
	}, 1)
	time.Sleep(50 * time.Millisecond)

	id, err := M.Submit("p", "u", "pw", "", "p:panic-user", 1)
	if err != nil {
		t.Fatalf("submit: %v", err)
	}
	waitStatus(t, id, "error", 3*time.Second)
	task := M.Get(id)
	if task == nil || task.Error == "" {
		t.Fatalf("error 信息缺失: %+v", task)
	}
}

// TestConcurrentDistinctAccounts 不同账号并发都成功。
func TestConcurrentDistinctAccounts(t *testing.T) {
	openTestDB(t)
	var done int64
	InitWithFetch(func(p, u, p2, s string) ([]fetchers.Course, error) {
		atomic.AddInt64(&done, 1)
		return []fetchers.Course{}, nil
	}, 4)
	time.Sleep(50 * time.Millisecond)

	var wg sync.WaitGroup
	for i := 0; i < 6; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			key := "p:user" + itoa(i)
			if id, err := M.Submit("p", "user"+itoa(i), "pw", "", key, int64(i)); err == nil {
				waitStatus(t, id, "done", 5*time.Second)
			}
		}(i)
	}
	wg.Wait()
	if atomic.LoadInt64(&done) != 6 {
		t.Fatalf("应完成 6 个任务，实际 %d", atomic.LoadInt64(&done))
	}
}

func waitStatus(t *testing.T, id, want string, timeout time.Duration) {
	t.Helper()
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if task := M.Get(id); task != nil && task.Status == want {
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatalf("等待状态 %s 超时", want)
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	digits := ""
	for n > 0 {
		digits = string(rune('0'+n%10)) + digits
		n /= 10
	}
	return digits
}
