// Package logger 结构化日志（log/slog）：生产 JSON 文件可检索，开发文本可读。
//
// 用法：
//
//	logger.Init("logs", false)          // 进程启动时一次
//	logger.Info("task done", "task_id", id, "courses", n)
//	logger.Err(err, "redeem failed", "agent_id", uid)   // 带错误的便捷封装
package logger

import (
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"time"
)

var L *slog.Logger = slog.Default()

// Level 环境变量 LOG_LEVEL：debug/info/warn/error，默认 info。
var Level = slog.LevelInfo

// Init 初始化全局 logger。dir 为空则只写 stdout。
func Init(dir string, debug bool) {
	if debug {
		Level = slog.LevelDebug
	}
	opts := &slog.HandlerOptions{Level: Level}

	if dir == "" {
		L = slog.New(slog.NewTextHandler(os.Stdout, opts))
	} else {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			fmt.Fprintf(os.Stderr, "logger: mkdir %s failed: %v\n", dir, err)
			L = slog.New(slog.NewTextHandler(os.Stdout, opts))
			return
		}
		name := filepath.Join(dir, time.Now().Format("2006-01-02")+".log")
		f, err := os.OpenFile(name, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
		if err != nil {
			fmt.Fprintf(os.Stderr, "logger: open %s failed: %v\n", name, err)
			L = slog.New(slog.NewTextHandler(os.Stdout, opts))
			return
		}
		// stdout + 文件双写：本地调试直观，留档可检索
		L = slog.New(slog.NewTextHandler(io_MultiWriter(os.Stdout, f), opts))
		cleanOldLogs(dir, 14)
	}
	slog.SetDefault(L)
}

// cleanOldLogs 删除超过 keepDays 的历史日志文件（启动时执行一次）。
func cleanOldLogs(dir string, keepDays int) {
	go func() {
		cutoff := time.Now().AddDate(0, 0, -keepDays)
		entries, err := os.ReadDir(dir)
		if err != nil {
			return
		}
		for _, e := range entries {
			if e.IsDir() || !strings.HasSuffix(e.Name(), ".log") {
				continue
			}
			info, err := e.Info()
			if err != nil || info.ModTime().After(cutoff) {
				continue
			}
			_ = os.Remove(filepath.Join(dir, e.Name()))
		}
	}()
}

// Err 记录一条 error 级日志（err 非空才记）。
func Err(err error, msg string, args ...any) {
	if err == nil {
		return
	}
	L.Error(msg, append([]any{"err", err.Error()}, args...)...)
}

// Warnf/Infof/Errorf 轻量格式化封装（内部 Sprintf，避免到处拼 args）。
func Infof(format string, args ...any) { L.Info(fmt.Sprintf(format, args...)) }
func Warnf(format string, args ...any) { L.Warn(fmt.Sprintf(format, args...)) }
func Errorf(format string, args ...any) {
	L.Error(fmt.Sprintf(format, args...))
}
