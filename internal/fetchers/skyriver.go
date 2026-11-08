package fetchers

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"daili-go/internal/config"
	"daili-go/internal/logger"
	"daili-go/internal/proxy"
)

type CatalogRow struct {
	Cid        int64
	Name       string
	Fenlei     string
	FenleiName string // 上游 fenleiname（如「龙龙项目」），前台平台卡片用
	Content    string
	Price      float64
}
type Progress struct{ Yid, Status, Process, Remarks string }

func skyriverClient() *http.Client {
	proxyURL, _ := proxy.Default.Get("skyriver")
	return &http.Client{Transport: proxy.TransportFor(proxyURL), Timeout: 20 * time.Second}
}

func skyriverPost(act string, params map[string]string) (map[string]interface{}, error) {
	if config.SkyriverURL == "" || config.SkyriverUID == "" || config.SkyriverKey == "" {
		return nil, loginErr("upstream service is not configured")
	}
	form := url.Values{"uid": {config.SkyriverUID}, "key": {config.SkyriverKey}}
	for key, value := range params {
		form.Set(key, value)
	}
	req, err := http.NewRequest(http.MethodPost, strings.TrimRight(config.SkyriverURL, "/")+"/api.php?act="+url.QueryEscape(act), strings.NewReader(form.Encode()))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("User-Agent", DefaultUA)
	resp, err := skyriverClient().Do(req)
	if err != nil {
		return nil, loginErr("upstream request failed; please retry later")
	}
	defer resp.Body.Close()
	// getclass 单分类实测 ~5.4MB/9500+ 条（9558 条=5.4MB），1MB 上限会把 JSON 截断
	// 导致同步永远失败（JSON 解析报错且日志只见合法 JSON 开头）。放宽到 32MB。
	body, err := io.ReadAll(io.LimitReader(resp.Body, 32<<20))
	if err != nil {
		return nil, loginErr("failed to read upstream response")
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, loginErr("upstream HTTP error")
	}
	var result map[string]interface{}
	if err := json.Unmarshal(body, &result); err != nil {
		text := strings.TrimSpace(string(body))
		if len(text) > 500 {
			text = text[:500]
		}
		logger.Errorf("skyriver non-json response: %s", text)
		return nil, loginErr("invalid upstream response")
	}
	return result, nil
}

func skyriverNoun(platform string) string {
	if product, ok := productBy(platform); ok {
		return product.Noun
	}
	for _, item := range MorePlatforms {
		if item.Name == platform {
			return item.Noun
		}
	}
	switch platform {
	case "\u5b66\u4e60\u901a":
		if config.SkyriverPlatformXuexitong != "" {
			return config.SkyriverPlatformXuexitong
		}
	case "\u667a\u6167\u6811":
		if config.SkyriverPlatformZHS != "" {
			return config.SkyriverPlatformZHS
		}
	case "\u667a\u6167\u804c\u6559":
		if config.SkyriverPlatformZJY != "" {
			return config.SkyriverPlatformZJY
		}
	case "welearn":
		if config.SkyriverPlatformWelearn != "" {
			return config.SkyriverPlatformWelearn
		}
	case "\u6c60\u9c7c":
		if config.SkyriverPlatformChiguan != "" {
			return config.SkyriverPlatformChiguan
		}
	case "MOOC":
		if config.SkyriverPlatformMOOC != "" {
			return config.SkyriverPlatformMOOC
		}
	case "\u4f18\u5b66\u9662":
		if config.SkyriverPlatformYXY != "" {
			return config.SkyriverPlatformYXY
		}
	case "u\u6821\u56ed":
		if config.SkyriverPlatformUxiaoyuan != "" {
			return config.SkyriverPlatformUxiaoyuan
		}
	}
	return platform
}
func stringValue(value interface{}) string {
	if value == nil {
		return ""
	}
	return fmt.Sprint(value)
}
func floatValue(value interface{}) float64 {
	switch n := value.(type) {
	case float64:
		return n
	case json.Number:
		v, _ := n.Float64()
		return v
	case string:
		v, _ := strconv.ParseFloat(strings.TrimSpace(n), 64)
		return v
	}
	return 0
}

func skyriverGetCourse(platform, school, username, password string) ([]Course, error) {
	noun := skyriverNoun(platform)
	if school == "" {
		school = platform
	}
	result, err := skyriverPost("get", map[string]string{"platform": noun, "school": school, "user": username, "pass": password, "kcid": ""})
	if err != nil {
		return nil, err
	}
	data, _ := result["data"].([]interface{})
	courses := make([]Course, 0, len(data))
	for _, raw := range data {
		item, ok := raw.(map[string]interface{})
		if !ok {
			continue
		}
		// 上游不同货源/自营项目的课程字段名不完全一致：主流是 kcname/kcid，
		// 兼容常见变体，取第一个非空值，避免整表课程名空白
		courses = append(courses, Course{
			Title:          firstStr(item, "kcname", "kc_name", "kcmc", "name", "title", "kc"),
			Link:           firstStr(item, "link", "url"),
			Teacher:        firstStr(item, "teacher", "js"),
			School:         firstStr(item, "school", "xx"),
			TotalHours:     firstFloat(item, "total_hours", "zxs", "hours"),
			CompletedHours: firstFloat(item, "completed_hours", "wcxs"),
			Status:         firstStr(item, "status", "zt"),
			Deadline:       firstStr(item, "deadline", "jztime"),
			Progress:       firstFloat(item, "process", "jdt", "progress"),
			Kcid:           idString(firstAny(item, "kcid", "kc_id", "id")),
		})
	}
	// 诊断：数量对但字段全空 = 上游返回格式变化，打一条原始样例进日志定位
	empty := 0
	for _, c := range courses {
		if c.Title == "" && c.Kcid == "" {
			empty++
		}
	}
	if len(courses) > 0 && empty == len(courses) && len(data) > 0 {
		if sample, ok := data[0].(map[string]interface{}); ok {
			logger.Errorf("skyriver get: %d 门课程字段全空，原始样例键值: %s", len(courses), sampleStr(sample))
		}
	}
	if len(courses) == 0 {
		if msg := stringValue(result["msg"]); msg != "" {
			return nil, loginErr("%s", msg)
		}
	}
	return courses, nil
}

// firstStr 返回第一个非空字符串字段
func firstStr(item map[string]interface{}, keys ...string) string {
	for _, k := range keys {
		if v := stringValue(item[k]); v != "" {
			return v
		}
	}
	return ""
}

// firstFloat 返回第一个非零数值字段
func firstFloat(item map[string]interface{}, keys ...string) float64 {
	for _, k := range keys {
		if v := floatValue(item[k]); v != 0 {
			return v
		}
	}
	return 0
}

// firstAny 返回第一个非 nil 字段
func firstAny(item map[string]interface{}, keys ...string) interface{} {
	for _, k := range keys {
		if v, ok := item[k]; ok && v != nil {
			return v
		}
	}
	return nil
}

// sampleStr 原始样例压缩为可读键值串（防日志爆量，截断 600 字节）
func sampleStr(item map[string]interface{}) string {
	parts := make([]string, 0, len(item))
	for k, v := range item {
		s := stringValue(v)
		if len(s) > 60 {
			s = s[:60] + "…"
		}
		parts = append(parts, k+"="+s)
	}
	out := strings.Join(parts, ", ")
	if len(out) > 600 {
		out = out[:600]
	}
	return out
}
func FetchSkyriverXuexitong(u, p, s string) ([]Course, error) {
	return skyriverGetCourse("\u5b66\u4e60\u901a", s, u, p)
}
func FetchSkyriverZhihuishu(u, p, s string) ([]Course, error) {
	return skyriverGetCourse("\u667a\u6167\u6811", s, u, p)
}
func FetchSkyriverZhijiao(u, p, s string) ([]Course, error) {
	return skyriverGetCourse("\u667a\u6167\u804c\u6559", s, u, p)
}
func FetchSkyriverWelearn(u, p, s string) ([]Course, error) {
	return skyriverGetCourse("welearn", s, u, p)
}
func FetchSkyriverChiguan(u, p, s string) ([]Course, error) {
	return skyriverGetCourse("\u6c60\u9c7c", s, u, p)
}
func FetchSkyriverUxiaoyuan(u, p, s string) ([]Course, error) {
	return skyriverGetCourse("u\u6821\u56ed", s, u, p)
}
func FetchSkyriverYouxueyuan(u, p, s string) ([]Course, error) {
	return skyriverGetCourse("\u4f18\u5b66\u9662", s, u, p)
}
func FetchSkyriverMooc(u, p, s string) ([]Course, error) { return skyriverGetCourse("MOOC", s, u, p) }

func SkyriverAddOrder(platform, school, username, password, courseName, kcid, remarks string) (string, error) {
	if school == "" {
		school = platform
	}
	result, err := skyriverPost("add", map[string]string{"platform": skyriverNoun(platform), "school": school, "user": username, "pass": password, "kcname": courseName, "kcid": kcid, "miaoshua": "", "city": "", "config": "", "remarks": remarks})
	if err != nil {
		return "", err
	}
	if stringValue(result["code"]) != "0" {
		return "", loginErr("%s", stringValue(result["msg"]))
	}
	return idString(result["id"]), nil
}
func SkyriverQueryProgress(platform, school, username, password, kcid, courseName string) ([]Progress, error) {
	result, err := skyriverPost("jdtb", map[string]string{"username": username, "pass": password, "kcid": kcid, "noun": skyriverNoun(platform), "school": school, "kcname": courseName})
	if err != nil {
		return nil, err
	}
	data, _ := result["data"].([]interface{})
	out := make([]Progress, 0, len(data))
	for _, raw := range data {
		item, ok := raw.(map[string]interface{})
		if !ok {
			continue
		}
		out = append(out, Progress{Yid: idString(item["id"]), Status: stringValue(item["status"]), Process: stringValue(item["process"]), Remarks: stringValue(item["remarks"])})
	}
	return out, nil
}
// SkyriverMoney 查上游余额（丸子/同类 api.php act=money）。
// 成功时返回余额字符串（上游字段多为 data.balance）；失败返回错误信息。
func SkyriverMoney() (string, error) {
	result, err := skyriverPost("money", nil)
	if err != nil {
		return "", err
	}
	code := stringValue(result["code"])
	if code != "0" && code != "1" {
		msg := stringValue(result["msg"])
		if msg == "" {
			msg = "查询余额失败"
		}
		return "", loginErr("%s", msg)
	}
	if data, ok := result["data"].(map[string]interface{}); ok {
		if bal := stringValue(data["balance"]); bal != "" {
			return bal, nil
		}
		if bal := stringValue(data["money"]); bal != "" {
			return bal, nil
		}
	}
	if bal := stringValue(result["balance"]); bal != "" {
		return bal, nil
	}
	if bal := stringValue(result["money"]); bal != "" {
		return bal, nil
	}
	return "0", nil
}

func SkyriverBudan(yid string) (string, error) {
	result, err := skyriverPost("budan", map[string]string{"id": yid})
	if err != nil {
		return "", err
	}
	code := stringValue(result["code"])
	if code != "0" && code != "1" {
		return "", loginErr("%s", stringValue(result["msg"]))
	}
	return stringValue(result["msg"]), nil
}

// SkyriverPauseOrder 暂停上游订单（act=zt，对接文档「订单暂停接口」）。
// 入参 yid = 下单时天河返回的源站订单 id。code 0/1 均视为成功（与 budan 口径一致）。
func SkyriverPauseOrder(yid string) (string, error) {
	result, err := skyriverPost("zt", map[string]string{"id": yid})
	if err != nil {
		return "", err
	}
	code := stringValue(result["code"])
	if code != "0" && code != "1" {
		return "", loginErr("%s", stringValue(result["msg"]))
	}
	return stringValue(result["msg"]), nil
}
func FetchSkyriverCatalog() ([]CatalogRow, error) {
	rows := make([]CatalogRow, 0)
	categories := config.SyncCategories
	if len(categories) == 0 {
		categories = []string{"8"}
	}
	// 上游 getclass 实测无视 fenlei 参数，恒返回同一份全量目录（9558 条）。
	// 先拉第一个分类，若返回数据的 fenlei 值已覆盖全部目标分类就不再重复请求
	// （省掉 7/8 流量与解析；实测每份 5.4MB）；若上游将来修复了参数过滤，
	// 缺失的分类仍会逐个补拉，行为始终正确。
	pending := append([]string(nil), categories...)
	for len(pending) > 0 {
		category := pending[0]
		pending = pending[1:]
		result, err := skyriverPost("getclass", map[string]string{"fenlei": category})
		if err != nil {
			return nil, err
		}
		if code, ok := result["code"]; ok {
			value := stringValue(code)
			if value != "0" && value != "1" {
				msg := stringValue(result["msg"])
				if msg == "" {
					msg = "upstream catalog sync failed"
				}
				return nil, loginErr("%s", msg)
			}
		}
		data, _ := result["data"].([]interface{})
		if len(data) == 0 {
			continue
		}
		covered := map[string]bool{}
		for _, raw := range data {
			item, ok := raw.(map[string]interface{})
			if !ok {
				continue
			}
			fenlei := stringValue(item["fenlei"])
			covered[fenlei] = true
			rows = append(rows, CatalogRow{
				Cid:        int64(floatValue(item["cid"])),
				Name:       stringValue(item["name"]),
				Fenlei:     fenlei,
				FenleiName: strings.TrimSpace(stringValue(item["fenleiname"])),
				Content:    stringValue(item["content"]),
				Price:      floatValue(item["price"]),
			})
		}
		next := make([]string, 0, len(pending))
		for _, c := range pending {
			if !covered[c] {
				next = append(next, c)
			}
		}
		pending = next
	}
	return rows, nil
}
