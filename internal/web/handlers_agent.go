package web

import (
	"database/sql"
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"sync"
	"sync/atomic"
	"time"
	"unicode/utf8"

	"daili-go/internal/auth"
	"daili-go/internal/config"
	"daili-go/internal/fetchers"
	"daili-go/internal/ledger"
	"daili-go/internal/logger"
	"daili-go/internal/ratelimit"
	"daili-go/internal/securestore"
	"daili-go/internal/store"
)

var (
	authLimiter          = ratelimit.New(5, 60)
	authGlobalLimiter    = ratelimit.New(30, 60)
	loginLock            = ratelimit.New(5, 900)
	progressLimiter      = ratelimit.New(20, 60)
	orderProgressLimiter = ratelimit.New(1, 10)
	// 全站闸：进度查询每请求最多 6 并发上游调用，无全局限流时 10 个 IP 就能
	// 打出 1200 上游调用/min，容易被上游限流/封对接。120/min ≈ 720 上游调用/min 封顶。
	progressGlobalLimiter = ratelimit.New(120, 60)
	rebrushLimiter        = ratelimit.New(10, 60)
	orderRebrushLimiter   = ratelimit.New(1, 10)
	rebrushGlobalLimiter  = ratelimit.New(60, 60) // 补刷同理：60/min 全站封顶
)

type agentInfo struct {
	ID       int64   `json:"id"`
	Username string  `json:"username"`
	Contact  string  `json:"contact"`
	Points   float64 `json:"points"`
}

func (u *userInfo) toAgentInfo() agentInfo {
	return agentInfo{ID: u.ID, Username: u.Username, Contact: u.Contact, Points: u.Points}
}

func checkPasswordPolicy(password string) string {
	if n := utf8.RuneCountInString(password); n < 8 || n > 64 {
		return "password length must be 8-64 characters"
	}
	hasLetter, hasDigit := false, false
	for _, c := range password {
		switch {
		case (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z'):
			hasLetter = true
		case c >= '0' && c <= '9':
			hasDigit = true
		}
	}
	if !hasLetter || !hasDigit {
		return "password must contain letters and digits"
	}
	return ""
}

func HandleAgentRegister(w http.ResponseWriter, r *http.Request) {
	if !config.RegisterOpen {
		errJSON(w, http.StatusForbidden, "registration is currently closed")
		return
	}
	var data struct {
		Username   string `json:"username"`
		Password   string `json:"password"`
		Contact    string `json:"contact"`
		InviteCode string `json:"invite_code"`
	}
	if !readJSON(w, r, &data) {
		return
	}
	if config.RegisterCode != "" && strings.TrimSpace(data.InviteCode) != config.RegisterCode {
		errJSON(w, http.StatusForbidden, "invalid invite code")
		return
	}
	ip := clientIP(r)
	if !authLimiter.Allow("agent-reg:"+ip) || !authGlobalLimiter.Allow("agent-reg-global") {
		errJSON(w, http.StatusTooManyRequests, "too many requests")
		return
	}
	if message := checkPasswordPolicy(data.Password); message != "" {
		errJSON(w, http.StatusBadRequest, message)
		return
	}
	name := strings.TrimSpace(data.Username)
	if n := utf8.RuneCountInString(name); n < 2 || n > 32 {
		err422(w)
		return
	}

	var exists int
	if err := store.DB.QueryRow(`SELECT COUNT(*) FROM agent_users WHERE LOWER(username)=LOWER(?)`, name).Scan(&exists); err != nil {
		logger.Err(err, "check agent username failed")
		errJSON(w, http.StatusInternalServerError, "registration failed")
		return
	}
	if exists > 0 {
		errJSON(w, http.StatusConflict, "username already exists")
		return
	}
	result, err := store.DB.Exec(
		`INSERT INTO agent_users(username, password_hash, contact, points, token_version, status, created_at)
		 VALUES(?,?,?,0,0,'active',?)`,
		name, auth.HashPassword(data.Password), truncateStr(strings.TrimSpace(data.Contact), 100), nowFloat(),
	)
	if err != nil {
		logger.Err(err, "create agent failed")
		errJSON(w, http.StatusConflict, "username already exists")
		return
	}
	id, err := result.LastInsertId()
	if err != nil {
		logger.Err(err, "get new agent id failed")
		errJSON(w, http.StatusInternalServerError, "registration failed")
		return
	}
	u := loadUser(id)
	if u == nil {
		errJSON(w, http.StatusInternalServerError, "registration failed")
		return
	}
	setAgentCookie(w, id, 0)
	writeJSON(w, http.StatusCreated, u.toAgentInfo())
}

func HandleAgentLogin(w http.ResponseWriter, r *http.Request) {
	var data struct {
		Username string `json:"username"`
		Password string `json:"password"`
	}
	if !readJSON(w, r, &data) {
		return
	}
	ip := clientIP(r)
	if !authLimiter.Allow("agent-login:"+ip) || !authGlobalLimiter.Allow("agent-login-global") {
		errJSON(w, http.StatusTooManyRequests, "too many requests")
		return
	}

	name := strings.TrimSpace(data.Username)
	lockKey := "agent-lock:" + strings.ToLower(name)
	var account struct {
		ID           int64
		Username     string
		Contact      string
		Points       float64
		TokenVersion int64
		Status       string
		PasswordHash string
	}
	err := store.DB.QueryRow(
		`SELECT id, username, contact, points, token_version, status, password_hash
		 FROM agent_users WHERE LOWER(username)=LOWER(?)`, name,
	).Scan(&account.ID, &account.Username, &account.Contact, &account.Points,
		&account.TokenVersion, &account.Status, &account.PasswordHash)
	valid := err == nil && auth.VerifyPassword(data.Password, account.PasswordHash)
	logLogin(name, ip, valid)
	if valid {
		loginLock.Reset(lockKey)
		if account.Status == "banned" {
			errJSON(w, http.StatusForbidden, "account is banned")
			return
		}
		setAgentCookie(w, account.ID, account.TokenVersion)
		writeJSON(w, http.StatusOK, agentInfo{ID: account.ID, Username: account.Username, Contact: account.Contact, Points: account.Points})
		return
	}
	loginLock.Count(lockKey)
	if loginLock.Peek(lockKey) >= loginLock.Limit() {
		errJSON(w, http.StatusTooManyRequests, "too many login attempts; retry in 15 minutes")
		return
	}
	errJSON(w, http.StatusUnauthorized, "invalid username or password")
}

func HandleAgentLogout(w http.ResponseWriter, r *http.Request) {
	clearAgentCookie(w)
	w.WriteHeader(http.StatusNoContent)
}

func HandleAgentMe(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	writeJSON(w, http.StatusOK, u.toAgentInfo())
}

func HandleAgentPassword(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	var data struct {
		OldPassword string `json:"old_password"`
		NewPassword string `json:"new_password"`
	}
	if !readJSON(w, r, &data) {
		return
	}
	if message := checkPasswordPolicy(data.NewPassword); message != "" {
		errJSON(w, http.StatusBadRequest, message)
		return
	}
	var currentHash string
	if err := store.DB.QueryRow(`SELECT password_hash FROM agent_users WHERE id=?`, u.ID).Scan(&currentHash); err != nil || !auth.VerifyPassword(data.OldPassword, currentHash) {
		errJSON(w, http.StatusBadRequest, "invalid current password")
		return
	}

	tx, err := store.DB.Begin()
	if err != nil {
		logger.Err(err, "begin change password failed", "agent_id", u.ID)
		errJSON(w, http.StatusInternalServerError, "password update failed")
		return
	}
	defer tx.Rollback()
	if _, err = tx.Exec(`UPDATE agent_users SET password_hash=?, token_version=token_version+1 WHERE id=?`, auth.HashPassword(data.NewPassword), u.ID); err != nil {
		logger.Err(err, "change password failed", "agent_id", u.ID)
		errJSON(w, http.StatusInternalServerError, "password update failed")
		return
	}
	var newVersion int64
	if err = tx.QueryRow(`SELECT token_version FROM agent_users WHERE id=?`, u.ID).Scan(&newVersion); err != nil {
		logger.Err(err, "read token version failed", "agent_id", u.ID)
		errJSON(w, http.StatusInternalServerError, "password update failed")
		return
	}
	if err = tx.Commit(); err != nil {
		logger.Err(err, "commit password change failed", "agent_id", u.ID)
		errJSON(w, http.StatusInternalServerError, "password update failed")
		return
	}
	setAgentCookie(w, u.ID, newVersion)
	if fresh := loadUser(u.ID); fresh != nil {
		writeJSON(w, http.StatusOK, fresh.toAgentInfo())
		return
	}
	errJSON(w, http.StatusInternalServerError, "password changed but user reload failed")
}

func HandleAgentRedeem(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	var data struct {
		Code string `json:"code"`
	}
	if !readJSON(w, r, &data) {
		return
	}
	newBalance, _, err := ledger.RedeemCode(u.ID, strings.TrimSpace(data.Code))
	if err != nil {
		var redeemErr *ledger.RedeemError
		if errors.As(err, &redeemErr) {
			errJSON(w, redeemErr.Code, redeemErr.Msg)
			return
		}
		logger.Err(err, "redeem failed", "agent_id", u.ID)
		errJSON(w, http.StatusInternalServerError, "redeem failed")
		return
	}
	writeJSON(w, http.StatusOK, agentInfo{ID: u.ID, Username: u.Username, Contact: u.Contact, Points: newBalance})
}

type orderBrief struct {
	OrderID       string   `json:"order_id"`
	Platform      string   `json:"platform"`
	Username      string   `json:"username"`
	CourseCount   int64    `json:"course_count"`
	AmountFen     int64    `json:"amount_fen"`
	Status        string   `json:"status"`
	ProcessStatus string   `json:"process_status"`
	PayMethod     string   `json:"pay_method"`
	PayType       string   `json:"pay_type"`
	Remark        string   `json:"remark"`
	CreatedAt     float64  `json:"created_at"`
	PaidAt        *float64 `json:"paid_at"`
}

func HandleAgentOrders(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	rows, err := store.DB.Query(
		`SELECT order_id, platform, username, course_count, amount_fen, status,
		        COALESCE(process_status,'pending'), pay_method, pay_type, remark, created_at, paid_at
		 FROM orders WHERE agent_id=? ORDER BY created_at DESC LIMIT 200`, u.ID,
	)
	if err != nil {
		logger.Err(err, "agent orders query failed", "agent_id", u.ID)
		errJSON(w, http.StatusInternalServerError, "failed to load orders")
		return
	}
	defer rows.Close()
	out := make([]orderBrief, 0)
	for rows.Next() {
		var item orderBrief
		var paidAt sql.NullFloat64
		if err := rows.Scan(&item.OrderID, &item.Platform, &item.Username, &item.CourseCount, &item.AmountFen, &item.Status, &item.ProcessStatus, &item.PayMethod, &item.PayType, &item.Remark, &item.CreatedAt, &paidAt); err != nil {
			logger.Err(err, "scan agent order failed", "agent_id", u.ID)
			continue
		}
		if paidAt.Valid {
			value := paidAt.Float64
			item.PaidAt = &value
		}
		out = append(out, item)
	}
	if err := rows.Err(); err != nil {
		logger.Err(err, "iterate agent orders failed", "agent_id", u.ID)
		errJSON(w, http.StatusInternalServerError, "failed to load orders")
		return
	}
	writeJSON(w, http.StatusOK, out)
}

type orderProgressCourse struct {
	Title string `json:"title"`
	Kcid  string `json:"kcid"`
}

type orderProgressItem struct {
	Title   string `json:"title"`
	Kcid    string `json:"kcid"`
	Yid     string `json:"yid,omitempty"`
	Status  string `json:"status,omitempty"`
	Process string `json:"process,omitempty"`
	Remarks string `json:"remarks,omitempty"`
	Error   string `json:"error,omitempty"`
}

const (
	queryProgressConcurrency = 6
	queryProgressMaxCourses  = 40
	queryProgressDeadline    = 30 * time.Second
)

func HandleAgentOrderProgress(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	orderID := r.PathValue("order_id")
	ip := clientIP(r)
	if ipBanned(ip) {
		errJSON(w, http.StatusForbidden, "client IP is banned")
		return
	}
	if !progressLimiter.Allow("order-progress-ip:"+ip) || !orderProgressLimiter.Allow("order-progress:"+orderID) ||
		!progressGlobalLimiter.Allow("order-progress-global") {
		errJSON(w, http.StatusTooManyRequests, "too many requests")
		return
	}

	var platform, username, encryptedPassword, school, coursesJSON, upstreamJSON, status string
	err := store.DB.QueryRow(
		`SELECT platform, username, password, school, courses, COALESCE(upstream,''), status
		 FROM orders WHERE order_id=? AND agent_id=?`, orderID, u.ID,
	).Scan(&platform, &username, &encryptedPassword, &school, &coursesJSON, &upstreamJSON, &status)
	if err != nil {
		errJSON(w, http.StatusNotFound, "order not found")
		return
	}
	if status != "paid" {
		errJSON(w, http.StatusBadRequest, "order is not paid")
		return
	}
	password, err := securestore.Decrypt(encryptedPassword)
	if err != nil {
		logger.Err(err, "decrypt order password failed", "order_id", orderID)
		errJSON(w, http.StatusInternalServerError, "order credentials are unavailable")
		return
	}

	var courses []orderProgressCourse
	if err := json.Unmarshal([]byte(coursesJSON), &courses); err != nil {
		errJSON(w, http.StatusInternalServerError, "invalid order course data")
		return
	}
	filtered := courses[:0]
	for _, course := range courses {
		if strings.TrimSpace(course.Title) != "" || strings.TrimSpace(course.Kcid) != "" {
			filtered = append(filtered, course)
		}
	}
	courses = filtered

	upstreamIDs := make(map[string]string)
	var upstream []skyriverUpstreamItem
	if json.Unmarshal([]byte(upstreamJSON), &upstream) == nil {
		for _, item := range upstream {
			if item.Kcid != "" && item.Yid != "" {
				upstreamIDs[item.Kcid] = item.Yid
			}
		}
	}
	var skipped atomic.Int64
	if len(courses) > queryProgressMaxCourses {
		skipped.Add(int64(len(courses) - queryProgressMaxCourses))
		courses = courses[:queryProgressMaxCourses]
	}

	items := make([]orderProgressItem, len(courses))
	type progressJob struct {
		Index int
		Title string
		Kcid  string
	}
	jobs := make(chan progressJob)
	var workers sync.WaitGroup
	deadline := time.Now().Add(queryProgressDeadline)
	for i := 0; i < queryProgressConcurrency; i++ {
		workers.Add(1)
		go func() {
			defer workers.Done()
			for job := range jobs {
				if time.Now().After(deadline) {
					items[job.Index] = orderProgressItem{Title: job.Title, Kcid: job.Kcid, Yid: upstreamIDs[job.Kcid], Error: "query timed out"}
					skipped.Add(1)
					continue
				}
				progress, queryErr := fetchers.SkyriverQueryProgress(platform, school, username, password, job.Kcid, job.Title)
				if queryErr != nil {
					items[job.Index] = orderProgressItem{Title: job.Title, Kcid: job.Kcid, Yid: upstreamIDs[job.Kcid], Error: queryErr.Error()}
					continue
				}
				if len(progress) == 0 {
					items[job.Index] = orderProgressItem{Title: job.Title, Kcid: job.Kcid, Yid: upstreamIDs[job.Kcid], Status: "queued", Remarks: "submitted; waiting for upstream"}
					continue
				}
				current := progress[0]
				yid := current.Yid
				if yid == "" {
					yid = upstreamIDs[job.Kcid]
				}
				items[job.Index] = orderProgressItem{Title: job.Title, Kcid: job.Kcid, Yid: yid, Status: current.Status, Process: current.Process, Remarks: truncateStr(current.Remarks, 500)}
			}
		}()
	}
	for index, course := range courses {
		jobs <- progressJob{Index: index, Title: course.Title, Kcid: course.Kcid}
	}
	close(jobs)
	workers.Wait()
	writeJSON(w, http.StatusOK, map[string]interface{}{"order_id": orderID, "items": items, "skipped": skipped.Load()})
}

func HandleAgentOrderRebrush(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	orderID := r.PathValue("order_id")
	ip := clientIP(r)
	if ipBanned(ip) {
		errJSON(w, http.StatusForbidden, "client IP is banned")
		return
	}
	if !rebrushLimiter.Allow("rebrush-ip:"+ip) || !orderRebrushLimiter.Allow("rebrush-order:"+orderID) ||
		!rebrushGlobalLimiter.Allow("rebrush-global") {
		errJSON(w, http.StatusTooManyRequests, "too many rebrush requests")
		return
	}

	var upstreamJSON, status string
	if err := store.DB.QueryRow(`SELECT COALESCE(upstream,''), status FROM orders WHERE order_id=? AND agent_id=?`, orderID, u.ID).Scan(&upstreamJSON, &status); err != nil {
		errJSON(w, http.StatusNotFound, "order not found")
		return
	}
	if status != "paid" {
		errJSON(w, http.StatusBadRequest, "order is not paid")
		return
	}
	var upstream []skyriverUpstreamItem
	if err := json.Unmarshal([]byte(upstreamJSON), &upstream); err != nil {
		errJSON(w, http.StatusBadRequest, "invalid upstream order data")
		return
	}
	targets := make([]skyriverUpstreamItem, 0, len(upstream))
	for _, item := range upstream {
		if strings.TrimSpace(item.Yid) != "" {
			targets = append(targets, item)
		}
	}
	if len(targets) == 0 {
		errJSON(w, http.StatusBadRequest, "no upstream tasks can be rebrushed")
		return
	}
	skipped := 0
	if len(targets) > queryProgressMaxCourses {
		skipped = len(targets) - queryProgressMaxCourses
		targets = targets[:queryProgressMaxCourses]
	}
	type rebrushItem struct {
		Title string `json:"title"`
		Kcid  string `json:"kcid"`
		Yid   string `json:"yid"`
		OK    bool   `json:"ok"`
		Msg   string `json:"msg"`
	}
	items := make([]rebrushItem, len(targets))
	type rebrushJob struct {
		Index int
		Yid   string
	}
	jobs := make(chan rebrushJob)
	var workers sync.WaitGroup
	deadline := time.Now().Add(queryProgressDeadline)
	for i := 0; i < queryProgressConcurrency; i++ {
		workers.Add(1)
		go func() {
			defer workers.Done()
			for job := range jobs {
				target := targets[job.Index]
				if time.Now().After(deadline) {
					items[job.Index] = rebrushItem{Title: target.Title, Kcid: target.Kcid, Yid: job.Yid, Msg: "rebrush timed out"}
					continue
				}
				message, err := fetchers.SkyriverBudan(job.Yid)
				if err != nil {
					items[job.Index] = rebrushItem{Title: target.Title, Kcid: target.Kcid, Yid: job.Yid, Msg: err.Error()}
					continue
				}
				items[job.Index] = rebrushItem{Title: target.Title, Kcid: target.Kcid, Yid: job.Yid, OK: true, Msg: message}
			}
		}()
	}
	for index, item := range targets {
		jobs <- rebrushJob{Index: index, Yid: item.Yid}
	}
	close(jobs)
	workers.Wait()
	success, failed := 0, 0
	for _, item := range items {
		if item.OK {
			success++
		} else {
			failed++
		}
	}
	logger.Infof("order rebrush: order=%s agent=%d total=%d ok=%d fail=%d skipped=%d", orderID, u.ID, len(items), success, failed, skipped)
	writeJSON(w, http.StatusOK, map[string]interface{}{"order_id": orderID, "total": len(items), "success": success, "failed": failed, "skipped": skipped, "items": items})
}

type pointLogBrief struct {
	ID           int64   `json:"id"`
	Delta        float64 `json:"delta"`
	BalanceAfter float64 `json:"balance_after"`
	Reason       string  `json:"reason"`
	OrderID      string  `json:"order_id"`
	CreatedAt    float64 `json:"created_at"`
}

func HandleAgentPoints(w http.ResponseWriter, r *http.Request) {
	u, ok := requireAgent(w, r)
	if !ok {
		return
	}
	rows, err := store.DB.Query(
		`SELECT id, delta, balance_after, reason, order_id, created_at
		 FROM point_logs WHERE agent_id=? ORDER BY id DESC LIMIT 200`, u.ID,
	)
	if err != nil {
		logger.Err(err, "agent points query failed", "agent_id", u.ID)
		errJSON(w, http.StatusInternalServerError, "failed to load points")
		return
	}
	defer rows.Close()
	out := make([]pointLogBrief, 0)
	for rows.Next() {
		var item pointLogBrief
		if err := rows.Scan(&item.ID, &item.Delta, &item.BalanceAfter, &item.Reason, &item.OrderID, &item.CreatedAt); err != nil {
			logger.Err(err, "scan point log failed", "agent_id", u.ID)
			continue
		}
		out = append(out, item)
	}
	if err := rows.Err(); err != nil {
		logger.Err(err, "iterate point logs failed", "agent_id", u.ID)
		errJSON(w, http.StatusInternalServerError, "failed to load points")
		return
	}
	writeJSON(w, http.StatusOK, out)
}
