// common.js —— 共享基础库（API 封装 / SSE / 格式化 / UX 增强 / 安全渲染）
// 页面统一加载本文件。全部方法挂到 window 下暴露给 user.js / admin.js。

'use strict'

/* ==================== 安全渲染 ==================== */

// h(tag, attrs, children) 构建元素：attrs.text 用 textContent（防 XSS），
// attrs.html 仅限受信任的硬编码字符串，其余属性/class/dataset 照常。
// children 为数组或元素/文本，文本一律 textContent，不信任用户输入进 innerHTML。
// 常用 SVG 元素名：SVG 必须用 createElementNS 建在 SVG 命名空间里，
// 否则 path/circle/text 会被当成普通 HTML 元素，矢量图全部渲染空白（平台图标/进度环）。
const SVG_TAGS = new Set(['svg', 'path', 'circle', 'rect', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'g', 'defs', 'use'])

function h(tag, attrs, children) {
  const node = SVG_TAGS.has(tag)
    ? document.createElementNS('http://www.w3.org/2000/svg', tag)
    : document.createElement(tag)
  if (attrs) {
    for (const key of Object.keys(attrs)) {
      const v = attrs[key]
      if (v === null || v === undefined || v === false) continue
      if (key === 'text') node.textContent = v
      else if (key === 'html') node.innerHTML = v
      else if (key === 'class') node.setAttribute('class', v) // SVG/HTML 通吃
      else if (key === 'dataset') Object.assign(node.dataset, v)
      else if (key.startsWith('on') && typeof v === 'function') node.addEventListener(key.slice(2), v)
      else if (key === 'value' || key === 'checked' || key === 'disabled' || key === 'selected') {
        // 表单控件用 property 赋值，确保与 DOM 状态同步（修复 setAttribute 与 property 不同步导致的复选框/输入框异常）
        node[key] = v
        // 保留 attribute 以兼容 CSS 选择器 :disabled 等
        if (v === true) node.setAttribute(key, '')
        else if (v !== true && typeof v !== 'boolean') node.setAttribute(key, String(v))
      } else if (key in node) {
        // 优先用 property（如 id、type、placeholder、href 等）
        try { node[key] = v } catch (_) { node.setAttribute(key, v) }
      } else node.setAttribute(key, v)
    }
  }
  if (children) {
    const list = Array.isArray(children) ? children : [children]
    for (const c of list) {
      if (c === null || c === undefined) continue
      if (typeof c === 'string' || typeof c === 'number') node.appendChild(document.createTextNode(String(c)))
      else node.appendChild(c)
    }
  }
  return node
}

// esc 安全转义（配合少量 innerHTML 使用；新增代码优先用 h() 的 textContent）
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, (m) =>
    m === '&' ? '&amp;' : m === '<' ? '&lt;' : m === '>' ? '&gt;' : m === '"' ? '&quot;' : '&#39;')
}

const $ = (sel) => document.querySelector(sel)

/* ==================== 格式化工具 ==================== */

const STATUS_LABELS = { done: '已完成', in_progress: '进行中', not_started: '未开始' }

function fmtTime(s) {
  return s ? new Date(s * 1e3).toLocaleString('zh-CN', { hour12: false }) : '-'
}
function fmtMoney(fen) {
  return `¥${((fen || 0) / 100).toFixed(2)}`
}
function fmtNum(v) {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '0'
  const n = Number(v)
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '')
}
function fmtSigned(v) {
  return (v >= 0 ? '+' : '') + fmtNum(v)
}
function fmtCode(s) {
  return /^[A-Z0-9]{16}$/.test(s || '') ? `${s.slice(0, 4)}-${s.slice(4, 8)}-${s.slice(8, 12)}-${s.slice(12, 16)}` : (s || '')
}
function debounce(fn, delay = 300) {
  let t
  return (...args) => {
    clearTimeout(t)
    t = setTimeout(() => fn(...args), delay)
  }
}

/* ==================== API 封装 ==================== */

const TIMEOUT = 10000

async function request(method, path, body, timeout = TIMEOUT) {
  const headers = {}
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeout)
  let res
  try {
    const csrf = document.cookie.split(`;`).map(v => v.trim()).find(v => v.startsWith("csrf_token="))?.slice(11) || ""
    if (csrf) headers["X-CSRF-Token"] = csrf
    res = await fetch(`/api${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal: controller.signal,
    })
  } catch (e) {
    throw e.name === 'AbortError' ? new Error('请求超时，请重试') : e
  } finally {
    clearTimeout(timer)
  }
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    let msg = `请求失败 (${res.status})`
    if (Array.isArray(data.detail)) msg = data.detail.map((n) => n.msg || n.detail || JSON.stringify(n)).join('; ')
    else if (typeof data.detail === 'string' && data.detail) msg = data.detail
    else if (data.detail != null && typeof data.detail === 'object') msg = JSON.stringify(data.detail)
    const err = new Error(msg)
    err.status = res.status
    if (res.status === 401) err.auth = true
    throw err
  }
  return res.status === 204 ? null : res.json()
}

// requestForm multipart 上传（FormData 直传，浏览器自动设 boundary）。
async function requestForm(method, path, file, timeout = TIMEOUT) {
  const headers = {}
  const csrf = document.cookie.split(`;`).map(v => v.trim()).find(v => v.startsWith("csrf_token="))?.slice(11) || ""
  if (csrf) headers["X-CSRF-Token"] = csrf
  const fd = new FormData()
  fd.append('file', file)
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeout)
  let res
  try {
    res = await fetch(`/api${path}`, { method, headers, body: fd, signal: controller.signal })
  } catch (e) {
    throw e.name === 'AbortError' ? new Error('请求超时，请重试') : e
  } finally {
    clearTimeout(timer)
  }
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    const msg = typeof data.detail === 'string' && data.detail ? data.detail : `上传失败 (${res.status})`
    const err = new Error(msg)
    err.status = res.status
    throw err
  }
  return res.json()
}

// 用户端 API（与后端路由一一对应）
const api = {
  platforms: () => request('GET', '/platforms'),
  morePlatforms: () => request('GET', '/more-platforms'),
  moreProducts: (fenlei) => request('GET', `/more-products${fenlei ? '?fenlei=' + encodeURIComponent(fenlei) : ''}`),
  config: () => request('GET', '/config'),
  submitAccount: (data) => request('POST', '/accounts', data),
  getTask: (id) => request('GET', `/tasks/${id}`),
  createOrder: (data) => request('POST', '/orders', data),
  getOrder: (id) => request('GET', `/orders/${id}`),
  agentRegister: (data) => request('POST', '/agent/register', data),
  agentLogin: (data) => request('POST', '/agent/login', data),
  agentLogout: () => request('POST', '/agent/logout'),
  agentMe: () => request('GET', '/agent/me'),
  agentOrders: () => request('GET', '/agent/orders'),
  agentOrderProgress: (orderId) => request('GET', `/agent/orders/${orderId}/progress`, undefined, 45000),
  agentOrderRebrush: (orderId) => request('POST', `/agent/orders/${orderId}/rebrush`, {}, 45000),
  agentPoints: () => request('GET', '/agent/points'),
  agentRedeem: (code) => request('POST', '/agent/redeem', { code }),
  agentPassword: (oldPassword, newPassword) => request('POST', '/agent/password', { old_password: oldPassword, new_password: newPassword }),
  announcements: () => request('GET', '/announcements'),
  // 实习打卡（sxdk，独立模块）
  sxdkPlatforms: () => request('GET', '/sxdk/platforms'),
  sxdkSearchPhone: (data) => request('POST', '/sxdk/search-phone', data),
  sxdkCreateOrder: (data) => request('POST', '/sxdk/orders', data),
  sxdkOrders: () => request('GET', '/sxdk/orders'),
  sxdkSync: () => request('POST', '/sxdk/sync'),
  // 我爱运动（syy，套娃下游对接天河 syytianheban）
  syyProjects: () => request('GET', '/syy/projects'),
  syyOpen: (projectId) => request('POST', '/syy/open', { project_id: projectId }, 45000),
  syyCreateOrder: (data) => request('POST', '/syy/orders', data, 45000),
  syyOrders: () => request('GET', '/syy/orders'),
  syyBalance: (projectId) => request('GET', `/syy/balance?project_id=${encodeURIComponent(projectId)}`),
  syyWithdraw: (data) => request('POST', '/syy/withdraw', data, 45000),
  // 病例（bingli，独立模块：模板/预览图/下单/订单图片）
  bingApplications: () => request('GET', '/bing/applications'),
  bingApplication: (id) => request('GET', `/bing/applications/${encodeURIComponent(id)}`),
  bingCreateOrder: (data) => request('POST', '/bing/orders', data, 120000),
  bingOrders: () => request('GET', '/bing/orders'),
  bingOrder: (id) => request('GET', `/bing/orders/${encodeURIComponent(id)}`),
  bingRetryOrder: (id) => request('POST', `/bing/orders/${encodeURIComponent(id)}/retry`, {}, 120000),
  // 学习通自动签到（qd，独立模块：卡型/下单/列表/详情/重新上号/人脸）
  qdPlans: () => request('GET', '/qd/plans'),
  qdCreateOrder: (data) => request('POST', '/qd/orders', data, 120000),
  qdOrders: () => request('GET', '/qd/orders'),
  qdOrder: (id) => request('GET', `/qd/orders/${encodeURIComponent(id)}`),
  qdRelogin: (id, pass) => request('POST', `/qd/orders/${encodeURIComponent(id)}/relogin`, { pass: pass || '' }, 120000),
  qdPhoto: (id, file) => requestForm('POST', `/qd/orders/${encodeURIComponent(id)}/photo`, file, 120000),
  qdPush: (id, pushUrl) => request('POST', `/qd/orders/${encodeURIComponent(id)}/push`, { push_url: pushUrl }),
}

// 管理端 API
const adminApi = {
  login: (u, p) => request('POST', '/admin/login', { username: u, password: p }),
  moreSync: () => request('POST', '/more/sync'), // 综合网课商品手动同步
  logout: () => request('POST', '/admin/logout'),
  me: () => request('GET', '/admin/me'),
  changeAdminPassword: (oldPassword, newPassword) => request('POST', '/admin/change-password', { old_password: oldPassword, new_password: newPassword }),
  dashboard: () => request('GET', '/admin/dashboard'),
  prices: () => request('GET', '/admin/prices'),
  setPrice: (platform, priceYuan) => request('POST', `/admin/prices/${encodeURIComponent(platform)}`, { price_yuan: priceYuan }),
  moreProducts: (params) => request('GET', `/admin/more-products${qs(params)}`), // 综合网课同步商品（管理改价）
  setMorePrice: (cid, priceYuan) => request('POST', `/admin/more-products/${cid}/price`, { price_yuan: priceYuan }),
  setMoreStatus: (cid, status) => request('POST', `/admin/more-products/${cid}/status`, { status }),
  setMoreContent: (cid, content) => request('POST', `/admin/more-products/${cid}/content`, { content }),
  moreFenlei: () => request('GET', '/admin/more-fenlei'),
  hideMoreFenlei: (fenlei) => request('POST', `/admin/more-fenlei/${encodeURIComponent(fenlei)}/hide`),
  showMoreFenlei: (fenlei) => request('POST', `/admin/more-fenlei/${encodeURIComponent(fenlei)}/show`),
  frontCategories: () => request('GET', '/admin/front-categories'),
  createFrontCategory: (name, sortOrder = 0, priceMult = 1) => request('POST', '/admin/front-categories', { name, sort_order: sortOrder, price_mult: priceMult }),
  updateFrontCategory: (id, body) => request('PUT', `/admin/front-categories/${id}`, body),
  skyriverBalance: () => request('GET', '/admin/syscfg/skyriver-balance'),
  deleteFrontCategory: (id) => request('DELETE', `/admin/front-categories/${id}`),
  frontCategoryItems: (id) => request('GET', `/admin/front-categories/${id}/items`),
  addFrontCategoryItems: (id, cids) => request('POST', `/admin/front-categories/${id}/items`, { cids }),
  removeFrontCategoryItems: (id, cids) => request('POST', `/admin/front-categories/${id}/items/remove`, { cids }),
  syyProjectsAdmin: () => request('GET', '/admin/syy-projects'), // 运动项目售价一览
  syySetPrice: (id, priceYuan) => request('POST', `/admin/syy-projects/${id}/price`, { price_yuan: priceYuan }),
  bingSettings: () => request('GET', '/admin/bing/settings'), // 病例开关状态
  bingSetEnabled: (enabled) => request('POST', '/admin/bing/settings', { enabled }),
  bingApplicationsAdmin: () => request('GET', '/admin/bing/applications'), // 病例模板售价一览
  bingSetPrice: (id, priceYuan) => request('POST', `/admin/bing/applications/${encodeURIComponent(id)}/price`, { price_yuan: priceYuan }),
  qdSettings: () => request('GET', '/admin/qd/settings'), // 自动签到开关/价格/引擎余额
  qdSetEnabled: (enabled) => request('POST', '/admin/qd/settings', { enabled }),
  qdSetPrice: (plan, points) => request('POST', '/admin/qd/price', { plan, points }),
  qdOrdersAdmin: () => request('GET', '/admin/qd/orders'), // 自动签到订单列表
  syscfg: () => request('GET', '/admin/syscfg'), // 支付 + 天河对接
  saveSyscfg: (data) => request('POST', '/admin/syscfg', data),
  users: (params) => request('GET', `/admin/users${qs(params)}`),
  adjustPoints: (id, delta, reason) => request('POST', `/admin/users/${id}/points`, { delta, reason }),
  banUser: (id) => request('POST', `/admin/users/${id}/ban`),
  unbanUser: (id) => request('POST', `/admin/users/${id}/unban`),
  resetPassword: (id, newPassword) => request('POST', `/admin/users/${id}/reset-password`, { new_password: newPassword }),
  deleteUser: (id) => request('DELETE', `/admin/users/${id}`),
  orders: (params) => request('GET', `/admin/orders${qs(params)}`),
  setProcessStatus: (id, status) => request('PATCH', `/admin/orders/${id}`, { process_status: status }),
  exportOrdersUrl: (params) => `/api/admin/orders/export.csv${qs(params)}`,
  orderCredentials: (id) => request('GET', `/admin/orders/${id}/credentials`),
  pauseOrder: (id, kcids) => request('POST', `/admin/orders/${id}/pause`, kcids ? { kcids } : {}),
  codes: (params) => request('GET', `/admin/redeem-codes${qs(params)}`),
  generateCodes: (count, points, note) => request('POST', '/admin/redeem-codes', { count, points, note }),
  disableCode: (code) => request('POST', `/admin/redeem-codes/${encodeURIComponent(code)}/disable`),
  exportCodesUrl: (status) => `/api/admin/redeem-codes/export.csv${qs({ status })}`,
  pointLogs: (params) => request('GET', `/admin/point-logs${qs(params)}`),
  auditLogs: (params) => request('GET', `/admin/audit-logs${qs(params)}`),
  loginLogs: (params) => request('GET', `/admin/login-logs${qs(params)}`),
  bannedIPs: (params) => request('GET', `/admin/banned-ips${qs(params)}`),
  unbanIP: (ip) => request('POST', `/admin/banned-ips/${encodeURIComponent(ip)}/unban`),
  announcements: (params) => request('GET', '/admin/announcements' + qs(params)),
  createAnnouncement: (data) => request('POST', '/admin/announcements', data),
  updateAnnouncement: (id, data) => request('PUT', `/admin/announcements/${id}`, data),
  deleteAnnouncement: (id) => request('DELETE', `/admin/announcements/${id}`),
}

function qs(params) {
  const usp = new URLSearchParams()
  Object.entries(params || {}).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined && v !== 'all') usp.set(k, v)
  })
  const s = usp.toString()
  return s ? `?${s}` : ''
}

/* ==================== SSE 任务订阅 ==================== */

// 订阅抓取任务：result 事件返回快照；timeout/error 走 onTimeout（调用方切轮询兜底）
function subscribeTask(taskId, onResult, onTimeout) {
  const es = new EventSource(`/api/tasks/${taskId}/events`)
  let done = false
  const finish = (fn, arg) => {
    if (done) return
    done = true
    es.close()
    fn && fn(arg)
  }
  es.addEventListener('result', (e) => {
    let data = null
    try {
      data = JSON.parse(e.data)
    } catch {}
    if (data) finish(onResult, data)
  })
  es.addEventListener('timeout', () => finish(onTimeout))
  es.onerror = () => finish(onTimeout)
  return es
}

/* ==================== Toast ==================== */

const TOAST_COLORS = { info: '#3b82f6', success: '#22c55e', warning: '#f59e0b', error: '#ef4444' }
const _toastStyleAdded = (() => {
  const st = document.createElement('style')
  st.textContent = `@keyframes slideInRight{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}@keyframes fadeOut{to{opacity:0}}`
  document.head.appendChild(st)
  return true
})()

function showToast(message, type = 'info') {
  const toast = h('div')
  const box = h('div')
  box.style.cssText = [
    'position: fixed', 'bottom: 24px', 'right: 24px', 'background: white', 'color: #0f172a',
    'padding: 14px 18px', 'border-radius: 10px', 'box-shadow: 0 4px 20px rgba(15,23,42,0.15)',
    'border-left: 4px solid ' + (TOAST_COLORS[type] || TOAST_COLORS.info), 'z-index: 10000',
    'animation: slideInRight 0.3s ease-out, fadeOut 0.3s ease-in 2.7s', 'max-width: 350px',
    'font-size: 14px', 'line-height: 1.5',
  ].join(';')
  box.textContent = message
  toast.appendChild(box)
  document.body.appendChild(toast)
  setTimeout(() => {
    toast.style.transition = 'opacity 0.3s, transform 0.3s'
    toast.style.opacity = '0'
    toast.style.transform = 'translateX(20px)'
    setTimeout(() => toast.remove(), 300)
  }, 3000)
}

/* ==================== UX 增强 ==================== */

// 全局加载条：patch window.fetch，活跃请求 >0 时显示顶部进度条
function enhanceLoadingBar() {
  const originalFetch = window.fetch
  let active = 0
  window.fetch = function (...args) {
    active++
    update()
    return originalFetch.apply(this, args).finally(() => {
      active--
      update()
    })
  }
  function update() {
    let bar = document.getElementById('global-loading-indicator')
    if (active > 0) {
      if (!bar) {
        bar = h('div', { id: 'global-loading-indicator' })
        bar.innerHTML = `<div style="position:fixed;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#3b82f6 0%,#8b5cf6 50%,#3b82f6 100%);background-size:200% 100%;animation:loadingBar 1.5s ease-in-out infinite;z-index:9999;box-shadow:0 0 10px rgba(59,130,246,0.5)"></div>`
        document.body.appendChild(bar)
        if (!document.getElementById('loading-bar-animation')) {
          const anim = h('style', { id: 'loading-bar-animation' })
          anim.textContent = `@keyframes loadingBar{0%{background-position:0% 0%}50%{background-position:100% 0%}100%{background-position:0% 0%}}`
          document.head.appendChild(anim)
        }
      }
    } else if (bar) {
      bar.style.opacity = '0'
      setTimeout(() => bar.remove(), 300)
    }
  }
}

// 防重复提交：表单短冷却防连点，错误后允许快速重试
function enhanceSubmitCooldown() {
  const cooldowns = new WeakMap()
  const COOLDOWN = 1200
  document.addEventListener(
    'submit',
    (e) => {
      const form = e.target
      if (!(form && form.nodeName === 'FORM')) return
      const now = Date.now()
      const prev = cooldowns.get(form) || 0
      if (now - prev < COOLDOWN) {
        e.preventDefault()
        e.stopImmediatePropagation()
        showToast(`请勿重复提交，等待 ${Math.ceil((COOLDOWN - (now - prev)) / 1000)} 秒`, 'warning')
        return
      }
      cooldowns.set(form, now)
      // 成功/失败后 1.5s 自动解锁，失败允许更快重试：监听一次性结果
      const clear = () => cooldowns.delete(form)
      // 若表单内有错误提示节点出现，提前解锁
      const mo = new MutationObserver(() => {
        const errNode = form.querySelector('.error, .err')
        if (errNode && errNode.textContent.trim()) { cooldowns.delete(form); mo.disconnect() }
      })
      mo.observe(form, { childList: true, subtree: true })
      setTimeout(() => { mo.disconnect(); clear() }, COOLDOWN + 300)
    },
    true,
  )
}

// 密码可见性快捷键 Ctrl+Shift+P
function enhancePasswordFields() {
  document.querySelectorAll('input[type="password"]').forEach((input) => {
    if (input.dataset.pwBound) return
    input.dataset.pwBound = '1'
    const toggle = input.closest('.input-wrap')?.querySelector('.input-action')
    if (!toggle) return
    toggle.addEventListener('mousedown', (e) => e.preventDefault())
    input.addEventListener('keydown', (e) => {
      if (e.ctrlKey && e.shiftKey && e.key === 'P') {
        e.preventDefault()
        toggle.click()
      }
    })
  })
}

// 移动端样式注入
function applyMobileEnhancements() {
  if (!/iPhone|iPad|iPod|Android/i.test(navigator.userAgent) && window.innerWidth > 640) return
  document.body.classList.add('mobile-device')
  const style = h('style')
  style.textContent = `
    html,body{touch-action:manipulation;-webkit-tap-highlight-color:transparent}
    .mobile-device input,.mobile-device select,.mobile-device button,.mobile-device .platform-card{min-height:44px;font-size:16px!important}
    .mobile-device .input{padding:12px 14px!important}
    .mobile-device .btn{padding:14px 20px!important;font-size:16px!important;min-height:48px}
    .mobile-device .platform-card{padding:14px!important}
    .mobile-device .course-item{padding:14px!important}
    .mobile-device .agent-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100vw}
    .mobile-device input[type=password]{letter-spacing:.1em;font-family:monospace}
    .mobile-device .platform-grid{grid-template-columns:repeat(2,1fr)!important}
    .mobile-device .input-action{min-width:40px;right:4px}
    .mobile-device .course-toolbar-actions{width:100%;margin-left:0}
    .mobile-device #pf-search{width:auto!important;flex:1;min-width:0}
    .mobile-device #pf-step2{scroll-margin-top:64px}
    .ag-cards{display:flex;flex-direction:column;gap:10px}
    .ag-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:12px 14px;display:flex;flex-direction:column;gap:8px}
    .ag-card-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .ag-card-top b{font-size:14.5px;color:var(--text)}
    .ag-card-money{color:#059669;font-weight:800;font-size:15px;font-variant-numeric:tabular-nums}
    .ag-card-line{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:12.5px;color:var(--text-muted);flex-wrap:wrap}
    .ag-card-progress{border-top:1px dashed var(--border-light);padding-top:8px}
    .ag-card-remark{font-size:12.5px;color:var(--text-muted);background:var(--bg-subtle);border-radius:8px;padding:8px 10px;word-break:break-word}
    @media(max-width:640px){.form-grid{grid-template-columns:1fr!important;gap:12px!important}}
  `
  document.head.appendChild(style)
}

/* ==================== 初始化 ==================== */

function initCommon() {
  enhanceLoadingBar()
  enhanceSubmitCooldown()
  applyMobileEnhancements()
  enhancePasswordFields()
  new MutationObserver(() => enhancePasswordFields()).observe(document.body, { childList: true, subtree: true })
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initCommon)
else initCommon()

// 暴露给页面脚本
window.h = h
window.esc = esc
window.$ = $
window.api = api
window.adminApi = adminApi
window.subscribeTask = subscribeTask
window.showToast = showToast
window.fmtTime = fmtTime
window.fmtMoney = fmtMoney
window.fmtNum = fmtNum
window.fmtSigned = fmtSigned
window.fmtCode = fmtCode
window.debounce = debounce
window.STATUS_LABELS = STATUS_LABELS