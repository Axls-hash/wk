// Package sxdk 实习打卡（sxdk）独立对接模块。
//
// 对接方式：wk_dl 作为「套娃子站」直连天河部署的上级网关
//
//	GET/POST  http://skyriver.top/sxdk/upstream.php?act=XXX
//
// 鉴权：admin = 你的 skyriver uid，token = 你的 skyriver key，ts_version = 协议版本。
//
// 协议字段、act 命名、天数算法（时间打卡日期统计）均复刻自
// skyriver-jie/cop/sxdk/upstream.php；本包与课程/综合网课完全独立，
// 不影响任何既有查课/下单/进度逻辑。
package sxdk

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"time"

	"daili-go/internal/config"
	"daili-go/internal/logger"
)

// TSVersion 上游协议版本号（对齐 cop 模板 ts_version）。
const TSVersion = "260807"

// Platform 实习打卡平台。
type Platform struct {
	Code string `json:"code"` // 上游代号（zxjy/qzt/...）
	Name string `json:"name"` // 展示名
}

// Platforms 上游支持的实习打卡平台列表（对齐 setting.php sxdk_platform_price 与 copilot 前端）。
var Platforms = []Platform{
	{Code: "zxjy", Name: "职校家园"},
	{Code: "qzt", Name: "黔职通"},
	{Code: "xyb", Name: "校友帮"},
	{Code: "gxy", Name: "工学云"},
	{Code: "xxy", Name: "习讯云"},
	{Code: "xxt", Name: "学习通"},
	{Code: "hzj", Name: "慧职教"},
	{Code: "gxzy", Name: "广西职业"},
	{Code: "jxzhjy", Name: "江西智慧教育"},
	{Code: "cxy", Name: "成学云"},
	{Code: "bx", Name: "博行"},
}

// validPlatform 判断平台代号是否合法。
func ValidPlatform(code string) bool {
	for _, p := range Platforms {
		if p.Code == code {
			return true
		}
	}
	return false
}

// PlatformName 平台代号 → 展示名。
func PlatformName(code string) string {
	for _, p := range Platforms {
		if p.Code == code {
			return p.Name
		}
	}
	return code
}

// Client 上游网关客户端。
type Client struct {
	base  string
	admin string
	token string
	http  *http.Client
}

// NewClient 按配置构建；未配置上游地址时返回 nil（模块不可用）。
func NewClient() *Client {
	if config.SxdkUpstreamURL == "" || config.SxdkAdmin == "" || config.SxdkToken == "" {
		return nil
	}
	return &Client{
		base:  config.SxdkUpstreamURL,
		admin: config.SxdkAdmin,
		token: config.SxdkToken,
		http:  &http.Client{Timeout: 30 * time.Second},
	}
}

// call 向上游网关发起 JSON POST（与 cop/cron.php sendPostRequest 一致：整个 body 带鉴权）。
func (c *Client) call(act string, payload map[string]interface{}) (map[string]interface{}, error) {
	if payload == nil {
		payload = map[string]interface{}{}
	}
	payload["admin"] = c.admin
	payload["token"] = c.token
	payload["ts_version"] = TSVersion

	body, err := json.Marshal(payload)
	if err != nil {
		return nil, err
	}
	u := fmt.Sprintf("%s?act=%s", c.base, act)
	req, err := http.NewRequest("POST", u, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Content-Type", "application/json;charset=UTF-8")

	resp, err := c.http.Do(req)
	if err != nil {
		logger.Errorf("sxdk request failed act=%s err=%v", act, err)
		return nil, fmt.Errorf("实习打卡上游请求失败，请稍后再试")
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 4<<20))

	var out map[string]interface{}
	if err := json.Unmarshal(raw, &out); err != nil {
		logger.Errorf("sxdk non-json response act=%s: %s", act, truncateStr(string(raw), 300))
		return nil, fmt.Errorf("实习打卡上游返回异常，请稍后再试")
	}
	return out, nil
}

// ok 判断上游 code 是否为成功（0）。
func okCode(res map[string]interface{}) bool {
	return fmt.Sprintf("%v", res["code"]) == "0"
}

// upstreamMsg 取上游错误文案（优先 msg）。
func upstreamMsg(res map[string]interface{}, def string) string {
	if m := fmt.Sprintf("%v", res["msg"]); m != "" && m != "<nil>" {
		return m
	}
	return def
}

// ---------- 只读：账户余额/倍率 ----------

type Userrow struct {
	Money    float64 `json:"money"`
	Addprice float64 `json:"addprice"`
	UID      string  `json:"uid"`
}

// GetUserrow 查询上游该对接账号的余额与倍率（只读，无副作用）。
func (c *Client) GetUserrow() (*Userrow, error) {
	res, err := c.call("get_userrow", nil)
	if err != nil {
		return nil, err
	}
	if !okCode(res) {
		return nil, fmt.Errorf("%s", upstreamMsg(res, "查询余额失败"))
	}
	data, _ := res["data"].(map[string]interface{})
	ur := &Userrow{}
	if data != nil {
		ur.Money, _ = toF64(data["money"])
		ur.Addprice, _ = toF64(data["addprice"])
		ur.UID = fmt.Sprintf("%v", data["uid"])
	}
	return ur, nil
}

// ---------- 验证账号（自动带出姓名/学校） ----------

// SearchPhoneInfo 向上游验证手机号+密码，返回原始 data（学校/姓名等，上游结构透传）。
func (c *Client) SearchPhoneInfo(platform, phone, password string) (map[string]interface{}, error) {
	res, err := c.call("searchPhoneInfo", map[string]interface{}{
		"platform": platform,
		"phone":    phone,
		"password": password,
	})
	if err != nil {
		return nil, err
	}
	if !okCode(res) {
		return nil, fmt.Errorf("%s", upstreamMsg(res, "获取账号信息失败"))
	}
	data, _ := res["data"].(map[string]interface{})
	if data == nil {
		data = map[string]interface{}{}
	}
	return data, nil
}

// ---------- 下单 ----------

// Bei 上游价格倍率：仅 xyb(校友帮) runType=3(绑微1号) 为 5，其余为 1。
// 与 cop upstream up_xybBei / 前端 yuji 完全一致。
func Bei(platform string, runType int) int {
	if platform == "xyb" && runType == 3 {
		return 5
	}
	return 1
}

// AddOrderReq 下单参数（对齐全套 cop addOrder 字段；缺省填空/默认值即可）。
type AddOrderReq struct {
	Platform    string
	Phone       string
	Password    string
	Name        string
	Address     string
	ProjectName string // 项目关键词（xyb 等）
	SchoolID    string // 学校ID/学校名（xxt/xxy/hzj）
	GwName      string // 岗位名称
	Country     string // 国家（xyb/gxy）
	City        string // 县（xyb/gxy）
	Adcode      string // 地区编码（xyb）
	CheckWeek   string // 每周几，如 "1,2,3,5"（1=周一…7=周日）
	CheckTime   string // 打卡时间（职校家园）
	EndTime     string // 结束日期 YYYY-MM-DD
	UpTime      string // 上班打卡时段
	DownTime    string // 下班打卡时段
	DayPaper    int    // 是否需要日报（1=需要 2=不需要）
	WeekPaper   int
	MonthPaper  int
	RunType     int  // 仅 xyb：1抓包 2账密1号 3绑微1号
	Holiday     bool // 法定节假日是否打卡
}

// AddOrderResp 下单成功后的关键返回（selectOrderById 带回上游订单ID）。
type AddOrderResp struct {
	OrderID int64  `json:"tianhe_id"`
	Msg     string `json:"msg"`
}

// 与 cop 前端 form 初始默认一致：国家默认中国、手机型号、上下班打卡类型、周报提交默认值。
const (
	sxdkDefaultUpRemark    = "0"
	sxdkDefaultDownRemark  = "8"
	sxdkDefaultPhoneName   = "HUAWEI|HUAWEIELE AL00|11"
	sxdkDefaultWeekSubmit  = 6 // 前端 weekPaperSubmitWeek=7 提交时 -1
	sxdkDefaultPaperRouter = 2
)

func sxdkPaperNumSettingJSON() string {
	return `{"day":{"minSize":0,"maxSize":0},"week":{"minSize":0,"maxSize":0},"month":{"minSize":0,"maxSize":0},"summary":{"minSize":0,"maxSize":0}}`
}

func bool01(b bool) int {
	if b {
		return 1
	}
	return 2
}

// AddOrder 向上游下单；成功返回上游订单ID（即天河的 sxdk id）。
// 载荷字段 = cop 前端 form 展开 + 转换后的全集，保证各平台字段齐全。
func (c *Client) AddOrder(r AddOrderReq) (*AddOrderResp, error) {
	// 平台细节对齐 cop：
	// - 时间统一 HH:mm:ss（职校家园用 check_time，其余用 up/down_check_time；up 缺省回退 check_time）
	// - 岗位：zxjy 用 customizedGwName，其余用 gwName
	// - 上下班打卡类型：gxy 为 1/2，其余 0/8（cop 前端 get_price 处理）
	upTime := r.UpTime
	if upTime == "" {
		upTime = r.CheckTime
	}
	gwName, cgwName := "", ""
	if r.GwName != "" {
		if r.Platform == "zxjy" {
			cgwName = r.GwName
		} else {
			gwName = r.GwName
		}
	}
	upRemark, downRemark := sxdkDefaultUpRemark, sxdkDefaultDownRemark
	if r.Platform == "gxy" {
		upRemark, downRemark = "1", "2"
	}

	payload := map[string]interface{}{
		// 账号身份 / 岗位
		"platform":         r.Platform,
		"phone":            r.Phone,
		"password":         r.Password,
		"name":             r.Name,
		"projectName":      r.ProjectName,
		"gwName":           gwName,
		"customizedGwName": cgwName,
		"school":           "",
		"schoolName":       "",
		"schoolId":         r.SchoolID,
		"url":              "",
		"addressName":      "",
		// 地址/地区
		"country":         orStr(r.Country, "中国"),
		"province":        "",
		"city":            r.City,
		"area":            "",
		"adcode":          r.Adcode,
		"addressOld":      "",
		"officialAddress": "",
		"jobAddress":      "",
		"address":         r.Address,
		"lat":             "",
		"lng":             "",
		"reason":          "",
		"desctext":        "",
		"phone_name":      sxdkDefaultPhoneName,
		// 打卡安排
		"check_time":       r.CheckTime,
		"up_check_time":    upTime,
		"down_check_time":  r.DownTime,
		"check_week":       r.CheckWeek,
		"end_time":         r.EndTime,
		"projectStartTime": "",
		"nowWeekNum":       0,
		"up_remark":        upRemark,
		"down_remark":      downRemark,
		"randomLocation":   1, // 浮动打卡（cop 前端默认开）
		"holiday":          bool01(r.Holiday),
		"runType":          r.RunType,
		"wxpush":           "",
		// 报告
		"day_paper":             r.DayPaper,
		"week_paper":            r.WeekPaper,
		"month_paper":           r.MonthPaper,
		"weekPaperSubmitWeek":   sxdkDefaultWeekSubmit,
		"monthPaperSubmitMonth": 0,
		"paper_router":          sxdkDefaultPaperRouter,
		"payType":               0,
		"paperNumSetting":       sxdkPaperNumSettingJSON(),
	}
	res, err := c.call("addOrder", payload)
	if err != nil {
		return nil, err
	}
	if !okCode(res) {
		return nil, fmt.Errorf("%s", upstreamMsg(res, "上游下单失败"))
	}
	out := &AddOrderResp{Msg: upstreamMsg(res, "")}
	// 上游成功会带 selectOrderById → data[0].id 作为上游单号
	if sel, ok := res["selectOrderById"].(map[string]interface{}); ok && okCode(sel) {
		if arr, ok := sel["data"].([]interface{}); ok && len(arr) > 0 {
			if first, ok := arr[0].(map[string]interface{}); ok {
				out.OrderID = int64(toInt64(first["id"]))
			}
		}
	}
	if out.OrderID == 0 {
		return nil, fmt.Errorf("上游已接单但未返回订单号，请稍后在订单里确认")
	}
	return out, nil
}

// ---------- 订单进度同步（yunOrder 全量拉取该对接账号所有订单） ----------

// YunOrderItem 上游同步返回的一条订单状态。
type YunOrderItem struct {
	ID       int64  `json:"id"` // 上游订单ID
	Platform string `json:"platform"`
	Code     int    `json:"code"`
	Wxpush   string `json:"wxpush"`
	EndTime  string `json:"end_time"`
	Phone    string `json:"phone"`
}

// YunOrder 拉取上游该 admin 账号下的全部订单（只读，无副作用）。
func (c *Client) YunOrder() ([]YunOrderItem, error) {
	res, err := c.call("yunOrder", nil)
	if err != nil {
		return nil, err
	}
	if !okCode(res) {
		return nil, fmt.Errorf("%s", upstreamMsg(res, "同步订单失败"))
	}
	raw, _ := res["data"].([]interface{})
	out := make([]YunOrderItem, 0, len(raw))
	for _, it := range raw {
		m, ok := it.(map[string]interface{})
		if !ok {
			continue
		}
		item := YunOrderItem{
			ID:       int64(toInt64(m["id"])),
			Platform: fmt.Sprintf("%v", m["platform"]),
			Code:     int(toInt64(m["code"])),
			Wxpush:   fmt.Sprintf("%v", m["wxpush"]),
			EndTime:  fmt.Sprintf("%v", m["end_time"]),
			Phone:    fmt.Sprintf("%v", m["phone"]),
		}
		out = append(out, item)
	}
	return out, nil
}

// ---------- 天数计算（复刻 cop upstream timeCalcTrueday） ----------

// CalcDays 复刻上游 timeCalcTrueday：从 now 到 endYMD（含）之间，按 checkWeek（1=周一…7=周日）
// 统计需要打卡的自然日数。checkWeek 为 "1,2,3,5" 形式。
func CalcDays(now time.Time, endYMD, checkWeek string) int {
	days := parseCheckWeek(checkWeek)
	if len(days) == 0 {
		return 0
	}
	sort.Ints(days)

	end, err := time.ParseInLocation("2006-01-02", endYMD, time.UTC)
	if err != nil {
		return 0
	}
	endSjc := end.Unix() + 86399 // 该日 23:59:59
	nowSjc := now.Unix()
	if endSjc < nowSjc {
		return 0
	}

	nowISO := isoWeekday(now) // 1=周一…7=周日
	nowWD := nowISO - 1       // 0=周一…6=周日
	// 本周日 23:59:59
	weekEndTs := nowSjc + int64(6-nowWD)*86400
	weekEndDay := time.Unix(weekEndTs, 0).UTC()
	weekEnd := time.Date(weekEndDay.Year(), weekEndDay.Month(), weekEndDay.Day(), 0, 0, 0, 0, time.UTC).Unix() + 86399

	nowWeekLast := 0
	for _, w := range days {
		if w >= nowISO {
			nowWeekLast++
		}
	}

	endISO := isoWeekday(time.Unix(endSjc, 0).UTC())
	endWD := endISO - 1

	if endSjc <= weekEnd {
		last := 0
		for _, w := range days {
			if w >= nowISO && w <= endISO {
				last++
			}
		}
		return last
	}
	endWeekLast := 0
	for _, w := range days {
		if w <= endISO {
			endWeekLast++
		}
	}
	intSjc := endSjc - int64(endWD+1)*86400 - weekEnd
	fullWeeks := int(intSjc / (7 * 86400)) // PHP 浮点除，这里整数天截断，语义一致（整数周）
	return nowWeekLast + fullWeeks*len(days) + endWeekLast
}

// parseCheckWeek 解析 "1,2,3,5" → []int（过滤非 1-7）。
func parseCheckWeek(s string) []int {
	var out []int
	for _, part := range strings.Split(s, ",") {
		n, err := strconv.Atoi(strings.TrimSpace(part))
		if err == nil && n >= 1 && n <= 7 {
			out = append(out, n)
		}
	}
	return out
}

// isoWeekday 返回 ISO 8601 星期：1=周一 … 7=周日。
func isoWeekday(t time.Time) int {
	w := int(t.Weekday()) // Go: 0=周日…6=周六
	if w == 0 {
		return 7
	}
	return w
}

// ---------- 工具 ----------

func truncateStr(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n])
}

func orStr(s, def string) string {
	if strings.TrimSpace(s) == "" {
		return def
	}
	return s
}

func toF64(v interface{}) (float64, bool) {
	switch n := v.(type) {
	case nil:
		return 0, false
	case float64:
		return n, true
	case json.Number:
		f, _ := n.Float64()
		return f, true
	case string:
		f, err := strconv.ParseFloat(n, 64)
		return f, err == nil
	default:
		return 0, false
	}
}

func toInt64(v interface{}) int64 {
	f, ok := toF64(v)
	if !ok {
		return 0
	}
	return int64(f)
}
