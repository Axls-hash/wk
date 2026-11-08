package web

import (
	"database/sql"
	"fmt"
	"net/http"
	"strings"

	"daili-go/internal/config"
	"daili-go/internal/logger"
	"daili-go/internal/ratelimit"
	"daili-go/internal/store"
)

// 公告系统：管理员发布，全员可见
// 安全：仅管理员可写，读为公开；标题/内容长度/级别严格校验；纯文本存储前端转义；限流+审计

var (
	annCreateLimiter = ratelimit.New(20, 60)
	annCreateGlobal  = ratelimit.New(60, 60)
)

type announcement struct {
	ID        int64   `json:"id"`
	Title     string  `json:"title"`
	Content   string  `json:"content"`
	Level     string  `json:"level"`
	IsPinned  bool    `json:"is_pinned"`
	IsActive  bool    `json:"is_active"`
	CreatedAt float64 `json:"created_at"`
	UpdatedAt float64 `json:"updated_at"`
	CreatedBy string  `json:"created_by"`
}

func scanAnnouncement(s rowScanner) *announcement {
	var a announcement
	var isPinned, isActive int
	var level string
	if err := s.Scan(&a.ID, &a.Title, &a.Content, &level, &isPinned, &isActive, &a.CreatedAt, &a.UpdatedAt, &a.CreatedBy); err != nil {
		return nil
	}
	a.Level = level
	a.IsPinned = isPinned == 1
	a.IsActive = isActive == 1
	return &a
}

// HandleListAnnouncements GET /api/announcements —— 公开，已发布的有效公告（最多 20）
func HandleListAnnouncements(w http.ResponseWriter, r *http.Request) {
	// 轻量缓存：公告变动低频，允许 60s CDN/浏览器缓存？
	// 但为确保管理员发布后立即可见，设 no-cache，由前端自行轮询（30s）
	w.Header().Set("Cache-Control", "no-cache, no-store, must-revalidate")
	rows, err := store.DB.Query(
		`SELECT id, title, content, level, is_pinned, is_active, created_at, updated_at, created_by
		 FROM announcements WHERE is_active=1
		 ORDER BY is_pinned DESC, created_at DESC LIMIT 20`)
	if err != nil {
		logger.Err(err, "list announcements failed")
		errJSON(w, 500, "加载失败，请稍后再试")
		return
	}
	defer rows.Close()
	items := []*announcement{}
	for rows.Next() {
		if a := scanAnnouncement(rows); a != nil {
			items = append(items, a)
		}
	}
	writeJSON(w, 200, map[string]interface{}{"items": items})
}

// HandleAdminListAnnouncements GET /api/admin/announcements —— 全量分页（管理侧）
func HandleAdminListAnnouncements(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	page := clamp(queryInt(r, "page", 1), 1, 1<<30)
	pageSize := clamp(queryInt(r, "page_size", 20), 1, 100)
	var total int64
	_ = store.DB.QueryRow(`SELECT count(*) FROM announcements`).Scan(&total)
	rows, err := store.DB.Query(
		`SELECT id, title, content, level, is_pinned, is_active, created_at, updated_at, created_by
		 FROM announcements ORDER BY is_pinned DESC, created_at DESC LIMIT ? OFFSET ?`,
		pageSize, (page-1)*pageSize)
	if err != nil {
		errJSON(w, 500, err.Error())
		return
	}
	defer rows.Close()
	items := []*announcement{}
	for rows.Next() {
		if a := scanAnnouncement(rows); a != nil {
			items = append(items, a)
		}
	}
	writeJSON(w, 200, map[string]interface{}{
		"total": total, "page": page, "page_size": pageSize, "items": items,
	})
}

// HandleAdminCreateAnnouncement POST /api/admin/announcements
func HandleAdminCreateAnnouncement(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	ip := clientIP(r)
	if !annCreateLimiter.Allow("ann-create:"+ip) || !annCreateGlobal.Allow("ann-create-global") {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	var body struct {
		Title    string `json:"title"`
		Content  string `json:"content"`
		Level    string `json:"level"`
		IsPinned *bool  `json:"is_pinned"`
		IsActive *bool  `json:"is_active"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	title := strings.TrimSpace(body.Title)
	content := strings.TrimSpace(body.Content)
	level := strings.TrimSpace(body.Level)
	if level == "" {
		level = "info"
	}
	if !isValidAnnLevel(level) {
		errJSON(w, 400, "公告级别仅支持 info/warning/urgent")
		return
	}
	if len([]rune(title)) < 1 || len([]rune(title)) > 100 {
		errJSON(w, 400, "标题长度需 1-100 字")
		return
	}
	if len([]rune(content)) < 1 || len([]rune(content)) > 3000 {
		errJSON(w, 400, "内容长度需 1-3000 字")
		return
	}
	// 安全：拒绝包含不可见控制字符（除换行/制表），防止注入与展示异常
	if containsControl(title) || containsControl(content) {
		errJSON(w, 400, "标题或内容包含非法字符")
		return
	}
	isPinned := 0
	if body.IsPinned != nil && *body.IsPinned {
		isPinned = 1
	}
	isActive := 1
	if body.IsActive != nil && !*body.IsActive {
		isActive = 0
	}
	now := nowFloat()
	res, err := store.DB.Exec(
		`INSERT INTO announcements(title, content, level, is_pinned, is_active, created_at, updated_at, created_by)
		 VALUES(?,?,?,?,?,?,?,?)`,
		title, content, level, isPinned, isActive, now, now, config.AdminUsername)
	if err != nil {
		logger.Err(err, "create announcement failed")
		errJSON(w, 500, "创建失败，请稍后再试")
		return
	}
	id, _ := res.LastInsertId()
	audit("announcement.create", fmt.Sprintf(`{"id":%d,"title":%q,"level":%q,"pinned":%v,"active":%v}`, id, title, level, isPinned == 1, isActive == 1))
	writeJSON(w, 201, map[string]interface{}{"id": id})
}

// HandleAdminUpdateAnnouncement PUT /api/admin/announcements/{id}
func HandleAdminUpdateAnnouncement(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "id")
	if !ok {
		return
	}
	ip := clientIP(r)
	if !annCreateLimiter.Allow("ann-update:"+ip) || !annCreateGlobal.Allow("ann-update-global") {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	var body struct {
		Title    *string `json:"title"`
		Content  *string `json:"content"`
		Level    *string `json:"level"`
		IsPinned *bool   `json:"is_pinned"`
		IsActive *bool   `json:"is_active"`
	}
	if !readJSON(w, r, &body) {
		return
	}
	// 校验存在
	var exists int
	_ = store.DB.QueryRow(`SELECT count(*) FROM announcements WHERE id=?`, id).Scan(&exists)
	if exists == 0 {
		errJSON(w, 404, "公告不存在")
		return
	}
	sets := []string{}
	args := []interface{}{}
	if body.Title != nil {
		t := strings.TrimSpace(*body.Title)
		if len([]rune(t)) < 1 || len([]rune(t)) > 100 {
			errJSON(w, 400, "标题长度需 1-100 字")
			return
		}
		if containsControl(t) {
			errJSON(w, 400, "标题包含非法字符")
			return
		}
		sets = append(sets, "title=?")
		args = append(args, t)
	}
	if body.Content != nil {
		c := strings.TrimSpace(*body.Content)
		if len([]rune(c)) < 1 || len([]rune(c)) > 3000 {
			errJSON(w, 400, "内容长度需 1-3000 字")
			return
		}
		if containsControl(c) {
			errJSON(w, 400, "内容包含非法字符")
			return
		}
		sets = append(sets, "content=?")
		args = append(args, c)
	}
	if body.Level != nil {
		lv := strings.TrimSpace(*body.Level)
		if !isValidAnnLevel(lv) {
			errJSON(w, 400, "公告级别仅支持 info/warning/urgent")
			return
		}
		sets = append(sets, "level=?")
		args = append(args, lv)
	}
	if body.IsPinned != nil {
		v := 0
		if *body.IsPinned {
			v = 1
		}
		sets = append(sets, "is_pinned=?")
		args = append(args, v)
	}
	if body.IsActive != nil {
		v := 0
		if *body.IsActive {
			v = 1
		}
		sets = append(sets, "is_active=?")
		args = append(args, v)
	}
	if len(sets) == 0 {
		errJSON(w, 400, "无修改字段")
		return
	}
	sets = append(sets, "updated_at=?")
	args = append(args, nowFloat())
	args = append(args, id)
	q := fmt.Sprintf("UPDATE announcements SET %s WHERE id=?", strings.Join(sets, ", "))
	if _, err := store.DB.Exec(q, args...); err != nil {
		logger.Err(err, "update announcement failed", "id", id)
		errJSON(w, 500, "更新失败，请稍后再试")
		return
	}
	audit("announcement.update", fmt.Sprintf(`{"id":%d}`, id))
	writeJSON(w, 200, map[string]bool{"ok": true})
}

// HandleAdminDeleteAnnouncement DELETE /api/admin/announcements/{id}
func HandleAdminDeleteAnnouncement(w http.ResponseWriter, r *http.Request) {
	if !requireAdmin(w, r) {
		return
	}
	id, ok := pathInt64(w, r, "id")
	if !ok {
		return
	}
	ip := clientIP(r)
	if !annCreateLimiter.Allow("ann-delete:"+ip) || !annCreateGlobal.Allow("ann-delete-global") {
		errJSON(w, 429, "操作过于频繁，请稍后再试")
		return
	}
	var title string
	_ = store.DB.QueryRow(`SELECT title FROM announcements WHERE id=?`, id).Scan(&title)
	res, err := store.DB.Exec(`DELETE FROM announcements WHERE id=?`, id)
	if err != nil {
		logger.Err(err, "delete announcement failed", "id", id)
		errJSON(w, 500, "删除失败，请稍后再试")
		return
	}
	if n, _ := res.RowsAffected(); n == 0 {
		errJSON(w, 404, "公告不存在")
		return
	}
	audit("announcement.delete", fmt.Sprintf(`{"id":%d,"title":%q}`, id, title))
	writeJSON(w, 200, map[string]bool{"ok": true})
}

func isValidAnnLevel(s string) bool {
	return s == "info" || s == "warning" || s == "urgent"
}

func containsControl(s string) bool {
	for _, r := range s {
		if r < 0x20 && r != '\n' && r != '\r' && r != '\t' {
			return true
		}
		if r == 0x7f {
			return true
		}
	}
	return false
}

var _ = sql.ErrNoRows
