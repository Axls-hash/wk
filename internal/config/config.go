// Package config 应用配置：backend 同风格的 .env 加载 + 环境变量。
//
// .env 位于项目根目录（与 go.mod 同级），逐行 key=value；
// 已存在的真实环境变量优先（setdefault 语义）。
package config

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"golang.org/x/crypto/pbkdf2"
)

var (
	Dir string // 项目根目录（main.go 里初始化）

	DBPath string // MySQL DATABASE_URL；仅测试调用 store.Open 时允许 SQLite 路径

	MixueUID          string
	MixueKey          string
	MixuePlatform     string // 智慧职教商品 ID
	MixuePlatformZHS  string // 智慧树商品 ID
	MixuePlatformYXY  string // 优学院商品 ID
	MixuePlatformMOOC string // 中国大学MOOC 商品 ID

	SkyriverURL               string
	SkyriverUID               string
	SkyriverKey               string
	// 丸子网课：与天河同协议（/api.php?act=get|add|getclass…），可并存，由 CourseUpstream 决定查课/下单走哪边。
	WanziURL  string
	WanziUID  string
	WanziKey  string
	CourseUpstream string // "skyriver" | "wanzi"
	SkyriverPlatformZHS       string
	SkyriverPlatformZJY       string
	SkyriverPlatformYXY       string
	SkyriverPlatformMOOC      string
	SkyriverPlatformXuexitong string
	SkyriverCategoryXuexitong string
	SkyriverPlatformWelearn   string
	SkyriverCategoryWelearn   string
	SkyriverPlatformChiguan   string
	SkyriverCategoryChiguan   string
	SkyriverPlatformUxiaoyuan string

	// 分类id：天河货源里各平台所属分类标识（查课 act=get 不用，
	// 仅登记 .env 以防丢失；与学习通 _CATEGORY=22 同理）。
	SkyriverCategoryZHS       string
	SkyriverCategoryZJY       string
	SkyriverCategoryMOOC      string
	SkyriverCategoryYXY       string
	SkyriverCategoryUxiaoyuan string

	SiteURL        string
	CoursePriceFen int

	EpayAPIURL string
	EpayPID    string
	EpayKey    string
	EpayType   string
	PayEnabled bool

	TrustProxy              bool
	TrustedProxyCIDRs       []string
	AdminUsername           string
	AdminPassword           string
	AdminPasswordHash       string
	AdminSecret             string
	CredentialEncryptionKey string
	SecureCookie            bool

	RegisterOpen bool
	RegisterCode string

	AgentSessionMaxAge int // 秒

	ProxyURL        string
	ProxyAPIURL     string
	ProxyRefreshTTL int
	ProxyAPIFormat  string
	ProxyRequired   bool

	FetchWorkers int

	// 冷门网课商品同步（天河 getclass 差量同步）
	SyncCategories    []string // 要同步的天河分类ID列表（如 8=龙龙）
	SyncPriceMult     float64  // 价格倍率（1=与天河同价）
	SyncIntervalHours int      // 自动同步间隔(小时)

	// 实习打卡（sxdk）：对接天河 /sxdk/upstream.php 上级网关
	SxdkUpstreamURL string  // 上游网关地址（末尾不带 /）
	SxdkAdmin       string  // 上游对接 uid（= skyriver uid）
	SxdkToken       string  // 上游对接 key（= skyriver key）
	SxdkPriceYuan   float64 // 本站售价（积分/天，1积分=1元）

	// 我爱运动（syy）：作为套娃下游对接天河 syytianheban/openapi.php
	SyyOpenapiURL      string   // 套娃接口地址
	SyyAPIKey          string   // 下游对接密钥（资金凭证）
	SyyPriceMult       float64  // 售价倍率：售价(分/单位) = 上游项目单价 × 倍率 × 100
	SyyDisabledProject []string // 不上架的项目ID（如停摆项目）
	SyyUpstreamName    string   // 展示用上游名（默认"运动平台"）

	// 病例（bingli）：独立对接 case.zmoee.com
	BingAPIURL         string  // 上游 API 根地址
	BingUserEmail      string  // 上游账号邮箱（API 密钥所属账号）；为空则模块停用
	BingAPIKey         string  // 上游 API 密钥（仅服务端使用）
	BingPriceMult      float64 // 售价倍率：本站价 = 上游模板价 × 倍率
	BingTimeoutSeconds int     // 上游请求超时（秒）
	BingProxyURL       string  // 可选：访问上游的 HTTP 代理

	// 学习通自动签到（qd）：对接签到引擎（29 协议 /api.php，同机部署走内网地址）
	QdEngineURL  string // 引擎接口地址，如 http://127.0.0.1:5881/api.php
	QdUIDWeek    string // 周卡引擎商户号（引擎 merchants.json 里的 uid/key）
	QdKeyWeek    string
	QdUIDMonth   string // 月卡引擎商户号（引擎一个商户号只带一种套餐，周/月须分开配置）
	QdKeyMonth   string
	QdPriceWeek  string // 默认零售积分价（管理端可在后台改，存 qd_settings）
	QdPriceMonth string
)

func getenv(key, def string) string {
	if v := strings.TrimSpace(os.Getenv(key)); v != "" {
		return v
	}
	return def
}

func getenvBool(key string) bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv(key)))
	return v == "1" || v == "true" || v == "yes"
}

// Load 加载 .env 并填充配置。root 为项目根目录。
func Load(root string) {
	Dir = root
	loadEnvFile(filepath.Join(root, ".env"))

	// 生产环境强制使用 MySQL DATABASE_URL；SQLite 仅保留给单元测试直接调用 store.Open
	dbURL := getenv("DATABASE_URL", "")
	switch {
	case dbURL == "":
		DBPath = ""
	case strings.HasPrefix(dbURL, "sqlite:///"):
		DBPath = ""
	case strings.HasPrefix(dbURL, "sqlite://"):
		DBPath = ""
	default:
		DBPath = dbURL // 非 sqlite 一律回退本地库
	}

	MixueUID = getenv("MIXUE_UID", "")
	MixueKey = getenv("MIXUE_KEY", "")
	MixuePlatform = getenv("MIXUE_PLATFORM", "")
	MixuePlatformZHS = getenv("MIXUE_PLATFORM_ZHS", "")
	MixuePlatformYXY = getenv("MIXUE_PLATFORM_YXY", "317")
	MixuePlatformMOOC = getenv("MIXUE_PLATFORM_MOOC", "1657")

	SkyriverURL = strings.TrimRight(getenv("SKYRIVER_URL", ""), "/")
	SkyriverUID = getenv("SKYRIVER_UID", "")
	SkyriverKey = getenv("SKYRIVER_KEY", "")
	WanziURL = strings.TrimRight(getenv("WANZI_URL", getenv("WK_URL", "")), "/")
	WanziUID = getenv("WANZI_UID", getenv("WK_UID", ""))
	WanziKey = getenv("WANZI_KEY", getenv("WK_KEY", ""))
	CourseUpstream = strings.ToLower(strings.TrimSpace(getenv("COURSE_UPSTREAM", "skyriver")))
	if CourseUpstream != "wanzi" {
		CourseUpstream = "skyriver"
	}
	SkyriverPlatformZHS = getenv("SKYRIVER_PLATFORM_ZHS", "")
	SkyriverPlatformZJY = getenv("SKYRIVER_PLATFORM_ZJY", "")
	SkyriverPlatformYXY = getenv("SKYRIVER_PLATFORM_YXY", "")
	SkyriverPlatformMOOC = getenv("SKYRIVER_PLATFORM_MOOC", "")
	SkyriverPlatformXuexitong = getenv("SKYRIVER_PLATFORM_XUEXITONG", "")
	SkyriverCategoryXuexitong = getenv("SKYRIVER_PLATFORM_XUEXITONG_CATEGORY", "")
	SkyriverPlatformWelearn = getenv("SKYRIVER_PLATFORM_WELEARN", "")
	SkyriverCategoryWelearn = getenv("SKYRIVER_PLATFORM_WELEARN_CATEGORY", "")
	SkyriverPlatformChiguan = getenv("SKYRIVER_PLATFORM_CHIGUAN", "")
	SkyriverCategoryChiguan = getenv("SKYRIVER_PLATFORM_CHIGUAN_CATEGORY", "")
	SkyriverPlatformUxiaoyuan = getenv("SKYRIVER_PLATFORM_UXIAOYUAN", "")

	SkyriverCategoryZHS = getenv("SKYRIVER_PLATFORM_ZHS_CATEGORY", "")
	SkyriverCategoryZJY = getenv("SKYRIVER_PLATFORM_ZJY_CATEGORY", "")
	SkyriverCategoryMOOC = getenv("SKYRIVER_PLATFORM_MOOC_CATEGORY", "")
	SkyriverCategoryYXY = getenv("SKYRIVER_PLATFORM_YXY_CATEGORY", "")
	SkyriverCategoryUxiaoyuan = getenv("SKYRIVER_PLATFORM_UXIAOYUAN_CATEGORY", "")

	SiteURL = strings.TrimRight(getenv("SITE_URL", ""), "/")

	CoursePriceFen = atoiFen(getenv("COURSE_PRICE", "3"))

	EpayAPIURL = strings.TrimRight(getenv("EPAY_API_URL", ""), "/")
	EpayPID = getenv("EPAY_PID", "")
	EpayKey = getenv("EPAY_KEY", "")
	EpayType = getenv("EPAY_TYPE", "wxpay")
	if EpayType == "" {
		EpayType = "wxpay"
	}
	PayEnabled = EpayAPIURL != "" && EpayPID != "" && EpayKey != ""

	TrustProxy = getenvBool("TRUST_PROXY")
	TrustedProxyCIDRs = csvList(getenv("TRUSTED_PROXY_CIDRS", ""))

	AdminUsername = getenv("ADMIN_USERNAME", "admin")
	AdminPassword = getenv("ADMIN_PASSWORD", "")
	AdminPasswordHash = getenv("ADMIN_PASSWORD_HASH", "")
	if AdminPasswordHash == "" {
		if AdminPassword == "" {
			AdminPassword = randomToken(24)
		}
		AdminPasswordHash = hashConfigPassword(AdminPassword)
	}
	AdminPassword = ""
	AdminSecret = loadOrCreateSecret()
	CredentialEncryptionKey = loadOrCreateCredentialKey()

	SecureCookie = getenvBoolDefault("SECURE_COOKIE", true)

	RegisterOpen = parseRegisterOpen()
	RegisterCode = getenv("REGISTER_CODE", "")

	AgentSessionMaxAge = atoiDefault(getenv("AGENT_SESSION_MAX_AGE", ""), 7*24*3600)

	ProxyURL = getenv("PROXY_URL", "")
	ProxyAPIURL = getenv("PROXY_API_URL", "")
	ProxyRefreshTTL = atoiDefault(getenv("PROXY_REFRESH_TTL", ""), 60)
	ProxyAPIFormat = getenv("PROXY_API_FORMAT", "auto")
	if ProxyAPIFormat == "" {
		ProxyAPIFormat = "auto"
	}
	ProxyRequired = getenvBool("PROXY_REQUIRED")

	FetchWorkers = atoiDefault(getenv("FETCH_WORKERS", ""), 16)
	if FetchWorkers <= 0 {
		FetchWorkers = 16
	}

	SyncCategories = csvList(getenv("SKYRIVER_SYNC_CATEGORIES", "8"))
	SyncPriceMult = floatEnv(getenv("SKYRIVER_SYNC_PRICE_MULT", "1"), 1)
	if SyncPriceMult <= 0 {
		SyncPriceMult = 1
	}
	SyncIntervalHours = atoiDefault(getenv("SKYRIVER_SYNC_INTERVAL_HOURS", "24"), 24)
	if SyncIntervalHours <= 0 {
		SyncIntervalHours = 24
	}

	SxdkUpstreamURL = strings.TrimRight(getenv("SXDK_UPSTREAM_URL", ""), "/")
	SxdkAdmin = getenv("SXDK_ADMIN", "")
	SxdkToken = getenv("SXDK_TOKEN", "")
	SxdkPriceYuan = floatEnv(getenv("SXDK_PRICE_YUAN", "1"), 1)
	if SxdkPriceYuan <= 0 {
		SxdkPriceYuan = 1
	}

	// 我爱运动（syy 套娃下游）
	SyyOpenapiURL = strings.TrimRight(getenv("SYY_OPENAPI_URL", ""), "/")
	SyyAPIKey = strings.TrimSpace(getenv("SYY_API_KEY", ""))
	SyyPriceMult = floatEnv(getenv("SYY_PRICE_MULT", "2"), 2)
	if SyyPriceMult <= 0 {
		SyyPriceMult = 2
	}
	SyyDisabledProject = csvList(getenv("SYY_DISABLED_PROJECTS", ""))
	SyyUpstreamName = getenv("SYY_UPSTREAM_NAME", "")
	if SyyUpstreamName == "" {
		SyyUpstreamName = "运动平台"
	}

	// 病例（bingli 独立模块）：邮箱未配置时整体停用，不发起任何上游请求
	BingAPIURL = strings.TrimRight(getenv("BING_API_URL", "https://case.zmoee.com/api/v1"), "/")
	if BingAPIURL == "" {
		BingAPIURL = "https://case.zmoee.com/api/v1"
	}
	BingUserEmail = strings.TrimSpace(getenv("BING_USER_EMAIL", ""))
	BingAPIKey = strings.TrimSpace(getenv("BING_API_KEY", ""))
	BingPriceMult = floatEnv(getenv("BING_PRICE_MULT", "2"), 2)
	if BingPriceMult <= 0 {
		BingPriceMult = 2
	}
	BingTimeoutSeconds = atoiDefault(getenv("BING_TIMEOUT_SECONDS", "90"), 90)
	if BingTimeoutSeconds < 10 {
		BingTimeoutSeconds = 90
	}
	BingProxyURL = strings.TrimSpace(getenv("BING_PROXY_URL", ""))

	// 学习通自动签到（qd 独立模块）：引擎地址 + 商户号任一周/月配置齐全才算可用
	QdEngineURL = strings.TrimRight(getenv("QD_ENGINE_URL", ""), "/")
	QdUIDWeek = strings.TrimSpace(getenv("QD_UID_WEEK", ""))
	QdKeyWeek = strings.TrimSpace(getenv("QD_KEY_WEEK", ""))
	QdUIDMonth = strings.TrimSpace(getenv("QD_UID_MONTH", ""))
	QdKeyMonth = strings.TrimSpace(getenv("QD_KEY_MONTH", ""))
	QdPriceWeek = strings.TrimSpace(getenv("QD_PRICE_WEEK", "3"))
	QdPriceMonth = strings.TrimSpace(getenv("QD_PRICE_MONTH", "7"))
}

// csvList 逗号分隔串 → 去空白切片（空串返回空切片）。
func csvList(s string) []string {
	parts := strings.Split(s, ",")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		if v := strings.TrimSpace(p); v != "" {
			out = append(out, v)
		}
	}
	return out
}

func floatEnv(s string, def float64) float64 {
	v, err := strconv.ParseFloat(strings.TrimSpace(s), 64)
	if err != nil {
		return def
	}
	return v
}

// loadOrCreateSecret 与 Python 版一致的 ADMIN_SECRET 持久化逻辑。
func loadOrCreateSecret() string {
	if s := getenv("ADMIN_SECRET", ""); s != "" {
		return s
	}
	secretFile := filepath.Join(Dir, ".admin_secret")
	if data, err := os.ReadFile(secretFile); err == nil {
		if s := strings.TrimSpace(string(data)); s != "" {
			return s
		}
	}
	s := randomToken(32)
	if err := os.WriteFile(secretFile, []byte(s), 0o600); err != nil {
		log.Println("[管理后台] ADMIN_SECRET 落盘失败，使用内存随机密钥（重启后登录态失效）")
		return randomToken(32)
	}
	fmt.Printf("[管理后台] 未配置 ADMIN_SECRET，已生成随机密钥保存到 %s\n", secretFile)
	return s
}

// loadEnvFile 极简 .env 解析：key=value，# 注释，已存在环境变量优先。
func loadEnvFile(path string) {
	data, err := os.ReadFile(path)
	if err != nil {
		return
	}
	for _, line := range strings.Split(string(data), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		// 行内注释（标准 .env 语法）：截掉 # 及之后内容
		if i := strings.IndexByte(line, '#'); i >= 0 {
			line = strings.TrimSpace(line[:i])
			if line == "" {
				continue
			}
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		k = strings.TrimSpace(k)
		v = strings.TrimSpace(v)
		v = strings.Trim(v, `"'`)
		if k != "" && os.Getenv(k) == "" {
			_ = os.Setenv(k, v)
		}
	}
}

// COURSE_PRICE 元 → 分（四舍五入）。
func atoiFen(yuan string) int {
	var whole, frac int
	var fracDigits int
	neg := false
	i := 0
	if i < len(yuan) && (yuan[i] == '-' || yuan[i] == '+') {
		neg = yuan[i] == '-'
		i++
	}
	state := 0 // 0=整数 1=小数
	for ; i < len(yuan); i++ {
		c := yuan[i]
		if c == '.' {
			state = 1
			continue
		}
		if c < '0' || c > '9' {
			break
		}
		if state == 0 {
			whole = whole*10 + int(c-'0')
		} else {
			if fracDigits >= 2 {
				if c >= '5' {
					frac++ // 进位
				}
				break
			}
			frac = frac*10 + int(c-'0')
			fracDigits++
		}
	}
	for fracDigits < 2 {
		frac *= 10
		fracDigits++
	}
	fen := whole*100 + frac
	if neg {
		fen = -fen
	}
	if fen <= 0 {
		return 300
	}
	return fen
}

func atoiDefault(s string, def int) int {
	if s == "" {
		return def
	}
	n := 0
	neg := false
	for i := 0; i < len(s); i++ {
		c := s[i]
		if i == 0 && (c == '-' || c == '+') {
			neg = c == '-'
			continue
		}
		if c < '0' || c > '9' {
			return def
		}
		n = n*10 + int(c-'0')
	}
	if neg {
		n = -n
	}
	if n == 0 {
		return def
	}
	return n
}

// parseRegisterOpen REGISTER_OPEN 解析：1/true/yes 开放，其余（含未配置）关闭。
// 默认关闭：开放注册是管理员显式决定的事，避免漏配导致无门槛白嫖查课。
func parseRegisterOpen() bool {
	v := strings.ToLower(strings.TrimSpace(os.Getenv("REGISTER_OPEN")))
	return v == "1" || v == "true" || v == "yes"
}

func getenvBoolDefault(key string, def bool) bool {
	if strings.TrimSpace(os.Getenv(key)) == "" {
		return def
	}
	return getenvBool(key)
}

func hashConfigPassword(password string) string {
	salt := make([]byte, 16)
	if _, err := rand.Read(salt); err != nil {
		panic(err)
	}
	dk := pbkdf2.Key([]byte(password), salt, 120000, 32, sha256.New)
	return "pbkdf2$120000$" + hex.EncodeToString(salt) + "$" + hex.EncodeToString(dk)
}

func loadOrCreateCredentialKey() string {
	if value := getenv("CREDENTIAL_ENCRYPTION_KEY", ""); value != "" {
		return value
	}
	path := filepath.Join(Dir, ".credential_key")
	if data, err := os.ReadFile(path); err == nil && strings.TrimSpace(string(data)) != "" {
		return strings.TrimSpace(string(data))
	}
	value := randomToken(48)
	if err := os.WriteFile(path, []byte(value), 0o600); err == nil {
		return value
	}
	return value
}
