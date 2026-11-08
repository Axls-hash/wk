package web

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"net/http"
	"strings"

	"daili-go/internal/ratelimit"
	"daili-go/internal/securestore"
	"daili-go/internal/store"
)

// 课程提交 + /admin1 旧版管理页（兼容保留；新管理后台在 /admin）。

var (
	submitCoursesLimiter  = ratelimit.New(30, 60)
	submitCoursesGlobal   = ratelimit.New(60, 60)
	adminLoginLimiter     = ratelimit.New(10, 60)
	adminLoginGlobalLimit = ratelimit.New(30, 60)
	// 24h 大窗口防滥用，超限仅 429（不自动永久封 IP，避免误伤共享出口）
	submitCoursesDaily = ratelimit.New(5000, 24*3600)
)

// HandleSubmitCourses POST /api/submit-courses —— 登录制。
func HandleSubmitCourses(w http.ResponseWriter, r *http.Request) {
	if _, ok := requireAgent(w, r); !ok {
		return
	}
	var data orderCreateBody
	if !readJSON(w, r, &data) {
		return
	}
	if !isPlatformName(data.Platform) {
		errJSON(w, 400, fmt.Sprintf("不支持的平台：%s", data.Platform))
		return
	}
	if data.Username == "" || data.Password == "" {
		errJSON(w, 400, "账号和密码不能为空")
		return
	}
	if len(data.Courses) == 0 {
		errJSON(w, 400, "请先勾选至少一门课程")
		return
	}
	ip := clientIP(r)
	if ipBanned(ip) {
		errJSON(w, 403, "该 IP 已被永久封禁，禁止提交")
		return
	}
	if !submitCoursesDaily.Allow("daily:" + ip) {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	if !submitCoursesLimiter.Allow("submit-courses:" + ip) {
		errJSON(w, 429, "提交过于频繁，请稍后再试")
		return
	}
	if !submitCoursesGlobal.Allow("submit-courses-global") {
		errJSON(w, 429, "服务繁忙，请稍后再试")
		return
	}

	encryptedPassword, err := securestore.Encrypt(data.Password)
	if err != nil {
		errJSON(w, 500, "凭据加密失败")
		return
	}

	b := make([]byte, 16)
	_, _ = rand.Read(b)
	submitID := hex.EncodeToString(b)
	_, err = store.DB.Exec(
		`INSERT INTO course_submissions(submit_id, platform, username, password, courses, remark, created_at)
		 VALUES(?,?,?,?,?,?,?)`,
		submitID, data.Platform, truncateStr(data.Username, 255), encryptedPassword,
		marshalCourses(data.Courses), cutStr(strings.TrimSpace(data.Remark), 500), nowFloat())
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	writeJSON(w, 201, map[string]string{"submit_id": submitID})
}

// ---------------- /admin1 旧版管理页（精简兼容版） ----------------
