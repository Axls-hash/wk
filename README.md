# wk_dl —— 课程进度抓取平台（纯 HTML 前端 + Go 后端）

从 `daili-go` 重写的可维护版本。**核心区别：前端不再是 React/打包/embed 进 exe 的黑盒**，
而是 `static/` 目录下可直接打开、直接编辑的纯 HTML/CSS/JS 文件；后端运行时直接读取它们。

API、登录会话、积分账务、抓取逻辑与 `daili-go` 完全一致。

## 目录结构

```
wk_dl/
├─ main.go              入口：路由 + 读取 static/ 静态目录（无 embed）
├─ internal/            Go 后端包（与 daili-go 一致，未改动）
├─ static/              ★ 前端（可编辑的源文件）
│  ├─ index.html        用户首页（服务大厅 + 查课下单流程）
│  ├─ agent.html        个人中心
│  ├─ pay.html          支付结果页
│  ├─ admin.html        管理后台
│  ├─ logo.jpg
│  └─ assets/
│     ├─ css/           main.css（用户端）· admin.css（管理端）
│     └─ js/            common.js（公共库）· user.js（用户端）· admin.js（管理端）
└─ daili-go.exe         编译产物
```

## 改前端（最常用）

**不用 npm、不用编译**：直接编辑 `static/` 下的 html/css/js，然后重启服务即可生效。

- 改文案/结构 → 对应页面 `.html`
- 改样式 → `assets/css/*.css`
- 改交互/接口调用 → `assets/js/**`
- 所有静态资源强制 `no-cache`，改完刷新浏览器就能看到，不会被缓存卡住

## 构建与运行

```bash
# 启动（.env 已配好 DATABASE_URL 与 ADDR）
.\daili-go.exe

# 改了 Go 后端才需要重编译（模块缓存已在 D 盘，不写 C）
$env:GOMODCACHE='D:\xxt_sk\.gopath\mod'; $env:GOCACHE='D:\xxt_sk\.gocache'
go build -o daili-go.exe .
```

运行前确保 8000 端口空闲（先停掉旧的 `daili-go` / Python 服务）。

## 配置（.env）

- `DATABASE_URL`：必须配置 MySQL `DATABASE_URL`（首次启动自动建表）
- `ADDR`：默认 `:8000`
- 蜜雪冰城/代理/管理员账号密码沿用原配置

> 想沿用旧数据：先停旧服务，把 `D:\xxt_sk\daili-go\app.db*` 复制到本目录覆盖，
> 生产环境请使用 `mysql://用户名:密码@主机:端口/数据库名`，不要配置 SQLite。

## 页面路由（后端分发）

| URL | 文件 |
|---|---|
| `/` | index.html |
| `/agent` | agent.html |
| `/pay/订单号` | pay.html |
| `/admin` | admin.html |
| 其它存在的文件 + SPA 兜底 | 指向 index.html |

API 路由全部与 `daili-go` 一致（`/api/*`）；未注册的 `/api/*` 返回 JSON 404。

## 病例模块（bingli，独立对接）

服务大厅「病例」与个人中心「病例」标签是独立模块，代码集中在：

- `internal/bingli/`：上游客户端 + 下单/退款/图片代理业务
  - `client.go`：上游 HTTP 客户端（凭据校验 `Configured()`）
  - `service.go`：模板/订单业务
  - `settings.go`：管理端运行开关（`Enabled()` = 已配置 && 开关开启）
  - `price.go`：按模板自定义售价与默认价计算
- `internal/web/handlers_bingli.go`：用户端 `/api/bing/*` 路由
- `internal/web/handlers_bingli_admin.go`：管理端开关/定价接口
- 数据表：`bing_orders`（订单）、`bing_settings`（运行开关）、`bing_prices`（模板自定义价），
  MySQL / SQLite 迁移均已加入
- 前端：`static/assets/js/user.js` 的 `renderBingliFlow` / `renderBingOrders`
- 管理后台：「🩺 病例管理」标签页（开关 + 按模板改价）

配置（`.env`）：

```ini
BING_API_URL=https://case.zmoee.com/api/v1
BING_USER_EMAIL=        # 上游账号邮箱；留空 = 模块停用
BING_API_KEY=...        # 仅服务端使用，绝不返回前端
BING_PRICE_MULT=2       # 本站售价 = 上游模板价 × 倍率
# BING_TIMEOUT_SECONDS=90
# BING_PROXY_URL=       # Cloudflare 拦截服务器 IP 时配置
```

接口（均需登录，POST 需 CSRF 头）：

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/bing/applications` | 模板列表 |
| GET | `/api/bing/applications/{id}` | 模板字段/价格 |
| GET | `/api/bing/applications/{id}/preview` | 模板预览图（后端代理） |
| POST | `/api/bing/orders` | 提交字段并扣积分 |
| GET | `/api/bing/orders` | 我的病例订单 |
| GET | `/api/bing/orders/{id}` | 订单详情（含本人字段） |
| GET | `/api/bing/orders/{id}/image` | 订单图片（PNG 代理，3 天保留） |
| POST | `/api/bing/orders/{id}/retry` | 重试 processing 订单 |

管理端接口（仅管理员）：

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/admin/bing/settings` | 开关状态（是否已配置 / 是否开放） |
| POST | `/api/admin/bing/settings` | 设置开关 `{"enabled": true}` |
| GET | `/api/admin/bing/applications` | 模板价格一览（上游价 / 默认价 / 当前价） |
| POST | `/api/admin/bing/applications/{id}/price` | 自定义售价 `{"price_yuan": 6}`；0=恢复默认 |

开关行为：关闭后用户端隐藏病例入口、模板与下单接口返回 503；
已支付订单的列表/详情/图片/重试仍可用（只影响新建订单）。
售价：默认价 = 上游模板价 × `BING_PRICE_MULT`，管理端自定义价优先且即时生效；
用户端下单会带 `expected_price_fen`，与实时价不一致时返回 409，不会按新价静默扣款。

安全/一致性：

- 填写字段用 `securestore`（AES-GCM）加密落库，日志不记录字段值
- 上游明确失败（400/401/403/404/410）自动退积分；网络/500 保持 processing，
  用同一 `externalRequestId`（本站订单号）重试，上游幂等不重复扣费
- 图片全部经后端代理，满足 CSP `img-src 'self'`，也不暴露上游密钥

## 安全说明

- 渲染统一走 `common.js` 的 `h()`/textContent，用户输入不进 innerHTML（公告、订单、账号字段均安全）
- 沿用后端 CSP / 安全头 / gzip / 限流 / 审计，未做任何削弱