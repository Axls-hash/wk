// user.js —— 用户端页面逻辑（index.html / agent.html / pay.html）
// 所有内容渲染用 h() 的 textContent 或受信任的硬编码 svg，杜绝 XSS。
'use strict'

/* ==================== 平台元数据（复制自 platformMeta.js） ==================== */

const PLATFORM_NOTES = {
  智慧树: '视频课件+作业+习惯分+互动分+见面课+考试等等。有习惯分的课程会规律学习每天1:1观看30分钟视频，结课前未完成会一次性看完。官方题库，包高分',
  学习通: '此项目为章节测试视频时长代看和习题的作答，8点-20点一比一跑单',
  智慧职教: '此项目支持【Ai课】【mooc】【资源库】【职教云】四合一，需要哪个选哪个课程，支持课件，作业，讨论，时长，考试的作答，作答包高分',
  welearn: '此项目为整本的全部单元习题，视频，需要多少时长自己备注好，不备注默认为10个小时，习题正确率百分之95以上',
  池馆: '新时代外语，视频+作业全包，全天一比一跑，几个小时完成，具体时间和课程内容多少有关',
  u校园: '此项目为u校园ai版，可以控时长和分数，自己备注需要的时长和分数，备注的格式：10h，90分，此为格式，具体的自己填写',
  MOOC: '包视频，文档，文本，客观题测验（暂时不作答主观题）',
  优学院: '视频, 测验，作业，文档，考试仅保存（自己提交），习题包高分',
  龙龙: '覆盖学习通及大量成教/继教平台的代刷服务，按所选课程下单，具体刷法以选中的项目为准',
  pup: 'U校园等平台的代刷服务，按所选商品下单',
  灵珠: '大量成教/继教/专技平台的代刷服务，按所选商品下单',
  一流: '大量成教/继教/职教/实训平台的代刷服务，按所选商品下单',
  毛豆: '智慧职教MOOC/SPOC/资源库/AI优课为主，另有U校园、智慧树、中国大学等，按所选商品下单',
  奶昔: '学习通/智慧树/U校园/Utalk/随行课堂/学习公社/中国大学mooc等，按所选商品下单',
  曙光: '学习通/U校园/Welearn/池馆/青书学堂/智慧树/中国大学MOOC等，按所选商品下单',
  小青: '青书学堂/学起plus/随行课堂Welearn/智慧树AI课/雨课堂/优学院/U校园等大量成教/继教平台的代刷服务，按所选商品下单',
  五福: '学习通/学起plus/随行课堂welearn/成教继教平台等，按所选商品下单',
  茉莉: '学起Plus/青书学堂/U校园/清华社等平台的代刷服务，按所选商品下单',
  tenu: '麦能学堂/广财慕课/云南大学继教/学堂在线等成教继教平台，按所选商品下单',
  国开: '国家开放大学（国开）/广东开放大学等，多货源可选，按所选商品下单',
  课代表: '学习通/智慧树/智慧职教等平台的代刷服务，按所选商品下单',
}

const PLATFORM_ICONS = {
  智慧树: { bg: '#eef2ff', fg: '#4f46e5', svg: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>' },
  学习通: { bg: '#eff6ff', fg: '#2563eb', svg: '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>' },
  智慧职教: { bg: '#f0f9ff', fg: '#0284c7', svg: '<rect x="3" y="7" width="18" height="14" rx="2" ry="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>' },
  welearn: { bg: '#f5f3ff', fg: '#7c3aed', svg: '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>' },
  池馆: { bg: '#fdf2f8', fg: '#db2777', svg: '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>' },
  MOOC: { bg: '#f0fdf4', fg: '#16a34a', svg: '<polygon points="5 3 19 12 5 21 5 3"/>' },
  优学院: { bg: '#fff7ed', fg: '#ea580c', svg: '<path d="M22 9L12 4 2 9l10 5 10-5z"/><path d="M6 11.5V16c0 1.5 2.7 3 6 3s6-1.5 6-3v-4.5"/><path d="M22 9v5"/>' },
  u校园: { bg: '#f0fdfa', fg: '#0d9488', svg: '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>' },
  龙龙: { bg: '#fefce8', fg: '#ca8a04', svg: '<path d="M12 2l8 4v6c0 5-3.5 8-8 10-4.5-2-8-5-8-10V6z"/><path d="M9 9h6"/><path d="M12 13v4"/>' },
  pup: { bg: '#faf5ff', fg: '#9333ea', svg: '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>' },
  灵珠: { bg: '#fffbeb', fg: '#d97706', svg: '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>' },
  一流: { bg: '#fef2f2', fg: '#dc2626', svg: '<path d="M5 3v18"/><path d="M5 4h13l-3 5 3 5H5"/>' },
  毛豆: { bg: '#ecfeff', fg: '#0891b2', svg: '<ellipse cx="9" cy="12" rx="5" ry="8"/><ellipse cx="15" cy="12" rx="5" ry="8"/>' },
  奶昔: { bg: '#fff1f2', fg: '#e11d48', svg: '<path d="M6 8h12l-2 13a2 2 0 0 1-2 1.7H10a2 2 0 0 1-2-1.7L6 8z"/><path d="M6 8h12"/><path d="M8 5h8"/>' },
  曙光: { bg: '#f7fee7', fg: '#65a30d', svg: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="M4.9 4.9l1.4 1.4"/><path d="M17.7 17.7l1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="M4.9 19.1l1.4-1.4"/><path d="M17.7 6.3l1.4-1.4"/>' },
  小青: { bg: '#ecfeff', fg: '#155e75', svg: '<path d="M5 3h14a1 1 0 0 1 1 1v16l-3-2-3 2-3-2-3 2V4a1 1 0 0 1 1-1z"/><path d="M9 8h6"/><path d="M9 12h6"/>' },
  五福: { bg: '#fef3c7', fg: '#b45309', svg: '<path d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.3 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>' },
  茉莉: { bg: '#fce7f3', fg: '#be185d', svg: '<circle cx="12" cy="8" r="3"/><circle cx="8" cy="12" r="3"/><circle cx="16" cy="12" r="3"/><circle cx="12" cy="16" r="3"/><circle cx="12" cy="12" r="1.5"/>' },
  tenu: { bg: '#e0e7ff', fg: '#4338ca', svg: '<path d="M22 9L12 4 2 9l10 5 10-5z"/><path d="M6 11.5V16c0 1.5 2.7 3 6 3s6-1.5 6-3v-4.5"/>' },
  国开: { bg: '#dcfce7', fg: '#15803d', svg: '<path d="M3 21h18"/><path d="M5 21V10"/><path d="M19 21V10"/><path d="M9 21V10"/><path d="M15 21V10"/><path d="M2 10l10-6 10 6z"/>' },
  课代表: { bg: '#ede9fe', fg: '#6d28d9', svg: '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V2h6v2"/><path d="M9 13l2 2 4-4"/>' },
}
const FALLBACK_ICON = { bg: '#f4f4f5', fg: '#52525b', svg: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>' }
const PLATFORM_DISPLAY_NAMES = { 智慧树: '智慧树/知道', MOOC: '中国大学mooc', pup: 'pup / 波妞', 国开: '国开 / 开放大学' }

/* ==================== 全局状态 ==================== */

let platforms = []
let morePlatforms = [] // 综合网课平台/商品：[{name, price_fen?, note?}]（/more-platforms）
let priceFen = 300
let platformPrices = {}
let bingEnabled = true // 病例入口是否开放（/api/config 下发，管理端可随时开关）
let qdEnabled = true // 学习通自动签到入口是否开放（/api/config 下发，管理端可随时开关）
let user = undefined // undefined=加载中 null=未登录 object=已登录
const PAGE = document.body.dataset.page

// 移动端视图判断：与 applyMobileEnhancements 加 mobile-device 类的条件保持一致
// （UA 匹配或窄窗口）。用于手机上切换卡片式布局等渲染分支；桌面渲染分支完全不变。
function isMobileView() {
  return document.body.classList.contains('mobile-device') || window.innerWidth <= 640
}
const ORDER_STATUS = { pending: '待处理', processing: '处理中', done: '已完成' }
const POLL_INTERVAL = 2000
const POLL_MAX = 300
let homeGridActive = false // 当前是否停在服务大厅 grid（配置加载铺平台数时据此重绘，避免误覆盖已进入的子流程）

/* ==================== 顶部导航 ==================== */

function renderNav() {
  const nav = $('#navbar')
  if (!nav) return
  // 登录页（未登录的首页）不显示导航栏；登录后恢复
  if (!user && PAGE === 'index') {
    nav.style.display = 'none'
    nav.replaceChildren()
    if (nav._onScroll) { window.removeEventListener('scroll', nav._onScroll); nav._onScroll = null }
    return
  }
  nav.style.display = ''
  nav.className = 'nav'
  const inner = h('div', { class: 'nav-inner' })
  inner.appendChild(h('a', { class: 'nav-brand', href: '/' }, [
    h('img', { class: 'nav-logo', src: '/assets/img/logo.jpg', alt: '' }),
    h('span', null, '海鸥爱学习'),
  ]))
  inner.appendChild(h('div', { class: 'nav-spacer' }))

  if (user) {
    inner.appendChild(h('a', { class: 'nav-points', href: '/agent', title: '可用积分，点击进入个人中心' }, `⚡ ${fmtNum(user.points)} 积分`))
    inner.appendChild(h('span', { class: 'nav-divider' }))
    inner.appendChild(h('a', { class: 'nav-tab-link' + (PAGE === 'agent' ? ' active' : ''), href: '/agent' }, '个人中心'))
    inner.appendChild(h('span', { class: 'nav-user' }, [
      h('span', { class: 'nav-avatar' }, (user.username || '?')[0].toUpperCase()),
      h('span', null, user.username),
    ]))
    inner.appendChild(h('a', { class: 'nav-exit', href: '#', title: '退出登录', onclick: (e) => { e.preventDefault(); logout() } }, '退出'))
  } else {
    inner.appendChild(h('a', { class: 'nav-tab-link active', href: '/' }, '登录'))
  }
  nav.replaceChildren(inner)

  const onScroll = () => nav.classList.toggle('scrolled', window.scrollY > 6)
  window.removeEventListener('scroll', nav._onScroll)
  nav._onScroll = onScroll
  window.addEventListener('scroll', onScroll, { passive: true })
  onScroll()
}

async function logout() {
  try { await api.agentLogout() } catch {}
  setUserState(null)
  if (PAGE === 'agent') window.location.href = '/'
}

/* ==================== 用户状态切换 ==================== */

function setUserState(u) {
  user = u
  renderNav()
  if (PAGE === 'index') applyIndexView()
  else if (PAGE === 'agent') applyAgentView()
  ensureAnnouncements()
}

// 公告只在「首页且已登录」时初始化/显示：登录页不显示公告，
// agent/pay 页也不拉公告接口；退出登录时清空横幅并停止轮询。
let annInited = false
let annTimer = null
let annRefresh = null // 最新一次 init 的刷新函数（visibilitychange 恢复时用）
let annVisBound = false
function ensureAnnouncements() {
  if (PAGE !== 'index') return
  if (!user) {
    if (annInited) { annInited = false; stopAnnouncements() }
    return
  }
  if (!annInited) { annInited = true; initAnnouncements() }
}

function stopAnnouncements() {
  if (annTimer) { clearInterval(annTimer); annTimer = null }
  const wrap = $('#announce-wrap')
  if (wrap) wrap.replaceChildren()
}

function applyIndexView() {
  if (PAGE !== 'index') return
  $('#boot-spinner').hidden = user !== undefined
  $('#auth-split').hidden = !(user === null)
  $('#home-view').hidden = !(user != null)
  if (user != null) renderHomeView()
}

/* ==================== 公告横幅 ==================== */

function initAnnouncements() {
  const wrap = $('#announce-wrap')
  if (!wrap) return
  const dismissed = (() => {
    try { return new Set(JSON.parse(localStorage.getItem('dismissed_ann') || '[]')) } catch { return new Set() }
  })()

  async function load() {
    let list = []
    try { list = (await api.announcements()).items || [] } catch {}
    render(list)
  }

  function render(list) {
    const items = list.filter((a) => !dismissed.has(a.id))
    if (items.length === 0) { wrap.replaceChildren(); return }
    const LEVELS = {
      info: { bg: '#eff6ff', border: '#bfdbfe', label: '通知' },
      warning: { bg: '#fffbeb', border: '#fde68a', label: '提醒' },
      urgent: { bg: '#fef2f2', border: '#fecaca', label: '重要' },
    }
    const nodes = items.map((a) => {
      const st = LEVELS[a.level] || LEVELS.info
      const icon = a.level === 'urgent' ? '🔥' : a.level === 'warning' ? '⚠️' : '📢'
      return h('div', {
        role: 'note',
        style: `display:flex;gap:14px;align-items:flex-start;background:${st.bg};border:1px solid ${st.border};border-radius:14px;padding:14px 16px;position:relative;overflow:hidden`,
      }, [
        h('span', {
          style: `flex-shrink:0;width:30px;height:30px;border-radius:9px;background:#fff;border:1px solid ${st.border};display:flex;align-items:center;justify-content:center;font-size:15px;box-shadow:0 1px 4px rgba(15,23,42,.06)`,
        }, icon),
        h('div', { style: 'flex:1;min-width:0' }, [
          h('div', { style: 'display:flex;align-items:center;gap:8px;flex-wrap:wrap' }, [
            h('span', { style: 'font-size:14px;font-weight:800;letter-spacing:-.015em;color:#0f172a' }, a.title),
            a.is_pinned ? h('span', { style: 'font-size:11px;font-weight:700;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;padding:1px 7px;border-radius:999px' }, '置顶') : null,
            h('span', { style: `font-size:11px;color:#64748b;background:#fff;border:1px solid ${st.border};padding:1px 7px;border-radius:999px` }, st.label),
            h('span', { style: 'font-size:11px;color:#94a3b8;margin-left:auto' }, fmtTime(a.created_at)),
          ]),
          h('div', { style: 'margin-top:6px;font-size:13.5px;line-height:1.7;color:#334155;white-space:pre-wrap;word-break:break-word' }, a.content),
        ]),
        h('button', {
          'aria-label': '关闭公告',
          style: 'flex-shrink:0;width:36px;height:36px;border-radius:10px;border:1px solid transparent;background:rgba(255,255,255,.8);color:#64748b;cursor:pointer;display:flex;align-items:center;justify-content:center',
          onclick: () => { dismissed.add(a.id); try { localStorage.setItem('dismissed_ann', JSON.stringify([...dismissed])) } catch {}; render(list) },
        }, '✕'),
      ])
    })
    wrap.replaceChildren(...nodes)
  }

  load()
  annTimer = setInterval(load, 30000)
  annRefresh = load
  // 标签页切后台暂停轮询，回前台立即刷新一次（全局只绑一次监听）
  if (!annVisBound) {
    annVisBound = true
    document.addEventListener('visibilitychange', () => {
      if (!annInited) return
      if (document.hidden) {
        if (annTimer) { clearInterval(annTimer); annTimer = null }
      } else if (!annTimer && annRefresh) {
        annRefresh()
        annTimer = setInterval(annRefresh, 30000)
      }
    })
  }
}

/* ==================== 登录/注册表单 ==================== */

function initAuthForm(prefix, onSuccess) {
  const form = $('#' + prefix + '-form')
  if (!form) return
  let mode = 'login'
  const usernameEl = $('#' + prefix + '-username')
  const passwordEl = $('#' + prefix + '-password')
  const inviteEl = $('#' + prefix + '-invite')
  const contactEl = $('#' + prefix + '-contact')
  const extras = $('#' + prefix + '-extras')
  const errBox = $('#' + prefix + '-err')
  const submitBtn = $('#' + prefix + '-submit')
  const titleEl = $('#' + prefix + '-title')
  const switchLabel = $('#' + prefix + '-switch-label')
  const switchLink = $('#' + prefix + '-switch-link')
  const showBtn = $('#' + prefix + '-showpw')

  if (showBtn) {
    showBtn.addEventListener('click', () => {
      const t = passwordEl.type
      passwordEl.type = t === 'password' ? 'text' : 'password'
      showBtn.textContent = t === 'password' ? '🙈' : '👁'
    })
  }

  function applyMode() {
    const isLogin = mode === 'login'
    if (titleEl) titleEl.textContent = isLogin ? (prefix === 'auth' ? '登录后查课' : '代理登录') : '注册账号'
    extras.hidden = isLogin
    switchLabel.textContent = isLogin ? '没有账号？' : '已有账号？'
    switchLink.textContent = isLogin ? '注册' : (prefix === 'auth' ? '去登录' : '去登录')
    submitBtn.textContent = isLogin ? '登录' : '注册并登录'
    passwordEl.autocomplete = isLogin ? 'current-password' : 'new-password'
  }
  switchLink.addEventListener('click', (e) => { e.preventDefault(); mode = mode === 'login' ? 'register' : 'login'; applyMode() })

  form.addEventListener('submit', async (e) => {
    e.preventDefault()
    errBox.replaceChildren()
    submitBtn.disabled = true
    submitBtn.innerHTML = '<span class="spinner"></span> 处理中…'
    try {
      if (mode === 'register') {
        await api.agentRegister({ username: usernameEl.value.trim(), password: passwordEl.value, contact: contactEl ? contactEl.value.trim() : '', invite_code: inviteEl.value.trim() })
      } else {
        await api.agentLogin({ username: usernameEl.value.trim(), password: passwordEl.value })
      }
      const me = await api.agentMe()
      onSuccess(me)
    } catch (err) {
      errBox.appendChild(h('div', { class: 'error' }, err.message))
    } finally {
      submitBtn.disabled = false
      submitBtn.textContent = mode === 'login' ? '登录' : '注册并登录'
    }
  })
}

/* ==================== 首页：服务大厅 / 查课流程 ==================== */

function renderHomeView() {
  const el = $('#home-view')
  if (!el || el.dataset.built) return
  el.dataset.built = '1'
  showHomeGrid()
}

// 服务大厅 5 个模块的卡片图标：与平台图标同风格的线性 SVG（白描边），
// 每个模块配各自的柔和渐变底色，替代原来参差不齐的 emoji，视觉更精致统一。
const HOME_SECTION_ICONS = {
  // 主流网课：学士帽
  platforms: {
    bg: 'linear-gradient(135deg,#6366f1,#8b5cf6)',
    paths: [
      h('path', { d: 'M22 10 12 5 2 10l10 5 10-5z' }),
      h('path', { d: 'M6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5' }),
      h('path', { d: 'M22 10v6' }),
    ],
  },
  // 综合网课：四宫格（聚合多平台）
  cold: {
    bg: 'linear-gradient(135deg,#0ea5e9,#22d3ee)',
    paths: [
      h('rect', { x: '3', y: '3', width: '7', height: '7', rx: '1.5' }),
      h('rect', { x: '14', y: '3', width: '7', height: '7', rx: '1.5' }),
      h('rect', { x: '14', y: '14', width: '7', height: '7', rx: '1.5' }),
      h('rect', { x: '3', y: '14', width: '7', height: '7', rx: '1.5' }),
    ],
  },
  // 实习打卡：打卡板（带对勾）
  intern: {
    bg: 'linear-gradient(135deg,#f59e0b,#fb923c)',
    paths: [
      h('rect', { x: '5', y: '4', width: '14', height: '17', rx: '2' }),
      h('path', { d: 'M9 4V2h6v2' }),
      h('path', { d: 'M9 13l2 2 4-4' }),
    ],
  },
  // 我爱运动：奖杯
  sport: {
    bg: 'linear-gradient(135deg,#22c55e,#84cc16)',
    paths: [
      h('path', { d: 'M8 21h8' }),
      h('path', { d: 'M12 17v4' }),
      h('path', { d: 'M6 3h12v6a6 6 0 0 1-12 0z' }),
      h('path', { d: 'M6 5H3v2a3 3 0 0 0 3 3' }),
      h('path', { d: 'M18 5h3v2a3 3 0 0 1-3 3' }),
    ],
  },
  // 病例：医疗箱（带十字）
  case: {
    bg: 'linear-gradient(135deg,#f43f5e,#fb7185)',
    paths: [
      h('rect', { x: '3', y: '6', width: '18', height: '14', rx: '3' }),
      h('path', { d: 'M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2' }),
      h('path', { d: 'M12 10v6' }),
      h('path', { d: 'M9 13h6' }),
    ],
  },
  // 学习通自动签到：时钟+对勾
  qd: {
    bg: 'linear-gradient(135deg,#0ea5e9,#22d3ee)',
    paths: [
      h('circle', { cx: '12', cy: '12', r: '9' }),
      h('path', { d: 'M12 7v5l3.5 2' }),
      h('path', { d: 'M8.5 13.5l2 2 4.5-5' }),
    ],
  },
}

function showHomeGrid() {
  const el = $('#home-view')
  homeGridActive = true
  const sections = [
    ['cold', '综合网课', morePlatforms.length ? `已上架 ${morePlatforms.map((p) => p.name).join('、')}` : '选平台项目，填账号查课下单'],
    ['intern', '实习打卡', '选平台填打卡安排，按天扣积分下单'],
    ['sport', '我爱运动', '运动项目代刷，按数量扣积分，拿链接去平台完成'],
  ]
  // 病例入口由管理端开关控制；关闭后不再展示（个人中心仍可查看历史订单）
  if (bingEnabled) sections.push(['case', '病例', '选择病例模板，在线填写并生成图片，按模板扣积分'])
  // 学习通自动签到入口由管理端开关控制
  if (qdEnabled) sections.push(['qd', '学习通自动签到', '登录学习通账号，全天自动监控签到，周卡/月卡按积分开通'])
  const grid = h('div', { class: 'home-grid' })
  for (const [key, name, desc] of sections) {
    const ic = HOME_SECTION_ICONS[key]
    grid.appendChild(h('button', { type: 'button', class: 'home-card', onclick: () => openSection(key) }, [
      h('span', { class: 'home-card-icon', style: `background:${ic.bg}` }, h('svg', { viewBox: '0 0 24 24', width: '27', height: '27', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.7', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, ic.paths)),
      h('span', { class: 'home-card-main' }, [h('span', { class: 'home-card-title' }, name), h('span', { class: 'home-card-desc' }, desc)]),
    ]))
  }
  el.replaceChildren(h('div', { class: 'home-view' }, [
    h('div', { class: 'page-head' }, [h('h1', null, '服务大厅'), h('p', null, '选择你需要的服务板块')]),
    grid,
  ]))
}

/* 综合网课列表页：平台模块卡片（点到商品选择框挑具体刷法）+ 跨渠道搜索直达下单 */
function renderMoreFlow(el) {
  homeGridActive = false
  const cards = []
  if (morePlatforms.length === 0) {
    cards.push(h('div', { class: 'empty', style: 'grid-column:1/-1' }, '暂无可用的综合网课平台，请在管理后台同步'))
  } else {
    morePlatforms.forEach((m) => cards.push(moreModuleCard(m)))
  }

  // —— 跨渠道商品搜索：输入网课名 → 结果标注所属渠道 → 点击直达该商品下单 ——
  const searchInput = h('input', {
    class: 'input', type: 'search', autocomplete: 'off',
    placeholder: '搜索网课名称，如：青书学堂 / 学起plus / 二倍速',
  })
  const resultList = h('div')
  const retryBtn = h('button', {
    class: 'btn btn-sm', style: 'margin-top:10px',
    onclick: () => { moreSearchIndexErr = false; moreSearchIndex = null; renderResults(); loadSearchIndex() },
  }, '重新加载')

  function renderResults() {
    resultList.replaceChildren()
    if (moreSearchIndexErr) {
      resultList.appendChild(h('div', { class: 'tip', style: 'margin-top:10px;color:var(--danger)' }, '商品索引加载失败，请稍后重试'))
      resultList.appendChild(retryBtn)
      return
    }
    if (!moreSearchIndex) {
      resultList.appendChild(h('div', { class: 'tip', style: 'margin-top:10px' }, '商品索引加载中…'))
      return
    }
    if (moreSearchIndex.length === 0) {
      resultList.appendChild(h('div', { class: 'empty', style: 'padding:18px 12px' }, '暂无可下单的商品，请联系管理员在后台同步'))
      return
    }
    const raw = (searchInput.value || '').trim()
    if (!raw) return
    const q = raw.toLowerCase()
    const hits = []
    for (const it of moreSearchIndex) {
      if ((it.name || '').toLowerCase().includes(q)) {
        hits.push(it)
        if (hits.length >= 50) break // 防超宽泛关键词渲染过长列表
      }
    }
    if (hits.length === 0) {
      resultList.appendChild(h('div', { class: 'empty', style: 'padding:18px 12px' }, `没有找到与「${raw}」匹配的商品，换个关键词试试`))
      return
    }
    resultList.appendChild(h('div', { class: 'tip', style: 'margin-top:10px' },
      `匹配到 ${hits.length}${hits.length >= 50 ? '+' : ''} 个商品，点击结果直接下单`))
    hits.forEach((it) => {
      const ch = moreChannelOf(it.fenlei)
      const money = parseFloat(((it.price_fen || 0) / 100).toFixed(2))
      resultList.appendChild(h('div', {
        style: 'display:flex;gap:12px;align-items:center;padding:10px 4px;border-bottom:1px solid var(--border-light);cursor:pointer',
        title: it.note || '',
        onclick: () => enterMoreProduct({ name: ch || '综合网课', category: String(it.fenlei) }, it),
      }, [
        h('span', { style: 'flex:1;min-width:0;word-break:break-word;font-size:13px;line-height:1.4' }, it.name),
        h('span', { style: 'color:#059669;font-weight:700;font-size:13px;flex-shrink:0' }, `¥${money}/门`),
        ch ? h('span', { style: 'flex-shrink:0;font-size:11.5px;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;padding:2px 8px;border-radius:999px' }, `可在「${ch}」下单`) : null,
      ]))
    })
  }

  async function loadSearchIndex() {
    if (moreSearchIndex || moreSearchIndexErr) { renderResults(); return }
    try {
      const items = await api.moreProducts('')
      moreSearchIndex = Array.isArray(items) ? items : []
    } catch {
      moreSearchIndexErr = true
    }
    renderResults()
  }

  searchInput.addEventListener('input', debounce(renderResults, 200))

  el.replaceChildren(h('div', { class: 'home-view' }, [
    h('div', { class: 'subnav' }, [
      h('a', { class: 'back-link', href: '#', onclick: (e) => { e.preventDefault(); showHomeGrid() } }, '← 返回服务首页'),
      h('span', { class: 'subnav-title' }, '综合网课'),
    ]),
    h('div', { class: 'page-head' }, [h('h1', null, '综合网课'), h('p', null, '选择平台模块，在弹出来的商品列表里挑一个具体刷法，再填账号查课下单')]),
    h('div', { class: 'home-grid' }, cards),
    h('div', { class: 'card', style: 'margin-top:18px;padding:16px 18px' }, [
      h('div', { style: 'font-weight:800;font-size:14px;margin-bottom:10px' }, '🔍 搜索网课（跨全部渠道）'),
      searchInput,
      h('div', { class: 'tip', style: 'margin-top:8px' }, '输入名称自动搜索全部渠道商品；结果标注可在哪个渠道下单，点击直达查课下单流程'),
      resultList,
    ]),
  ]))
  loadSearchIndex()
}

/* 全局搜索的商品索引：null=未加载，数组=已加载（含 fenlei）；进出页面复用，避免重复拉全量 */
let moreSearchIndex = null
let moreSearchIndexErr = false

/* 分类ID → 渠道名（映射来自 /more-platforms，与管理后台同口径）；解析不出回退「分类 X」 */
function moreChannelOf(fenlei) {
  const m = morePlatforms.find((x) => String(x.category) === String(fenlei))
  return m ? m.name : ''
}

function moreModuleCard(m) {
  const name = m.name
  const icon = PLATFORM_ICONS[name] || FALLBACK_ICON
  const note = PLATFORM_NOTES[name] || '点击选择商品开始'
  return h('button', {
    type: 'button', class: 'home-card',
    onclick: () => showModuleSelect(m),
  }, [
    h('span', { class: 'home-card-icon', style: `background:${icon.bg};color:${icon.fg}` }, h('svg', { viewBox: '0 0 24 24', width: '22', height: '22', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.7', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', html: icon.svg })),
    h('span', { class: 'home-card-main' }, [
      h('span', { class: 'home-card-title' }, PLATFORM_DISPLAY_NAMES[name] || name),
      h('span', { class: 'home-card-desc' }, note),
    ]),
  ])
}

/* 冷门商品选择框：搜索 + 按主平台分组折叠，选一个直接进入查课下单 */
function showModuleSelect(m) {
  const mask = h('div', {
    style: 'position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;z-index:1000;padding:16px',
    onclick: (e) => { if (e.target === mask) mask.remove() },
  })
  const panel = h('div', { class: 'card', style: 'width:min(640px,95vw);max-height:82vh;display:flex;flex-direction:column;padding:0;overflow:hidden' })
  const body = h('div', { style: 'display:flex;flex-direction:column;flex:1;min-height:0' })
  body.appendChild(h('div', { style: 'padding:30px;text-align:center;color:var(--text-muted);font-size:13px' }, '加载商品中…'))
  panel.appendChild(body)
  mask.appendChild(panel)
  document.body.appendChild(mask)

  api.moreProducts(m.category || '').then((items) => {
    const all = Array.isArray(items) ? items : []
    body.replaceChildren()

    body.appendChild(h('div', { style: 'padding:16px 20px;border-bottom:1px solid var(--border);flex-shrink:0' }, [
      h('div', { style: 'display:flex;align-items:center;justify-content:space-between' }, [
        h('div', null, [
          h('div', { style: 'font-weight:800;font-size:15px' }, `选择 ${m.name} 的商品`),
          h('div', { style: 'font-size:12px;color:var(--text-muted);margin-top:2px' }, `共 ${all.length} 项 · 按平台分组收起，可搜索；选一个具体刷法`),
        ]),
        h('button', { style: 'border:none;background:transparent;font-size:18px;cursor:pointer;color:var(--text-muted)', onclick: () => mask.remove() }, '✕'),
      ]),
      h('input', { class: 'input', style: 'margin-top:12px', type: 'search', placeholder: '搜索商品名，如 学习通、考试、二倍速', value: '', oninput: (e) => render(e.target.value) }),
    ]))

    const listEl = h('div', { style: 'overflow-y:auto;padding:12px;flex:1;min-height:0' })
    body.appendChild(listEl)

    if (all.length === 0) {
      listEl.appendChild(h('div', { style: 'padding:30px;text-align:center;color:var(--text-muted);font-size:13px' }, '该平台暂无商品，请先在管理后台同步'))
      return
    }

    function render(q) {
      q = (q || '').trim()
      const arr = q
        ? all.filter((it) => (it.name || '').toLowerCase().includes(q.toLowerCase()))
        : all
      listEl.replaceChildren()
      if (arr.length === 0) {
        listEl.appendChild(h('div', { style: 'padding:30px;text-align:center;color:var(--text-muted);font-size:13px' }, `没有匹配「${q}」的商品`))
        return
      }
      if (q) {
        arr.forEach((it) => listEl.appendChild(prodRow(it))) // 搜索时平铺
        return
      }
      // 按主平台分组，默认展开前 3 组
      const groups = []
      const gmap = {}
      arr.forEach((it) => {
        const k = productGroupKey(it.name)
        if (!gmap[k]) {
          gmap[k] = { key: k, items: [] }
          groups.push(gmap[k])
        }
        gmap[k].items.push(it)
      })
      groups.sort((a, b) => a.key.localeCompare(b.key, 'zh'))
      const open = new Set(groups.slice(0, 3).map((g) => g.key))
      groups.forEach((g) => {
        const groupEl = h('div', { style: 'border:1px solid var(--border-light);border-radius:10px;margin-bottom:8px;overflow:hidden' })
        const headBtn = h('button', { style: 'width:100%;display:flex;align-items:center;justify-content:space-between;padding:11px 14px;background:var(--bg-subtle);border:none;cursor:pointer;font-size:13.5px;font-weight:700;font-family:inherit;color:var(--text)' }, [
          h('span', null, g.key),
          h('span', { style: 'font-size:12px;color:var(--text-muted);font-weight:600' }, `${g.items.length} 项 ${open.has(g.key) ? '▾' : '▸'}`),
        ])
        const groupBody = h('div', { style: open.has(g.key) ? '' : 'display:none' })
        g.items.forEach((it) => groupBody.appendChild(prodRow(it)))
        headBtn.addEventListener('click', () => {
          const nowOpen = groupBody.style.display !== 'none'
          groupBody.style.display = nowOpen ? 'none' : ''
        })
        groupEl.appendChild(headBtn)
        groupEl.appendChild(groupBody)
        listEl.appendChild(groupEl)
      })
    }

    function prodRow(it) {
      const money = parseFloat(((it.price_fen || 0) / 100).toFixed(2))
      return h('div', {
        style: 'display:flex;gap:12px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--border-light);cursor:pointer',
        title: it.note || '',
        onclick: () => { enterMoreProduct(m, it); mask.remove() },
      }, [
        h('span', { style: 'flex:1;min-width:0;word-break:break-word;font-size:13px;line-height:1.4' }, it.name),
        h('span', { style: 'color:#059669;font-weight:700;font-size:13px;flex-shrink:0' }, `¥${money}/门`),
      ])
    }

    render('')
  }).catch((e) => {
    body.replaceChildren(h('div', { style: 'padding:30px;text-align:center;color:var(--danger);font-size:13px' }, (e && e.message) || '加载失败'))
  })
}

/* 商品主平台分组：`龙龙-学习通全包（复习模式）` → `学习通` */
function productGroupKey(name) {
  let s = String(name || '').replace(/^(yl|一流|少年|龙龙|灵珠|毛豆|奶昔|曙光|小青|星星|五福|茉莉|tenu|tneu|霍希|kk|龙猫|课代表|天河独家优质)[-－]?\s*/i, '')
  const i = s.search(/[（(\s【0-9\-－]/)
  let head = i >= 0 ? s.slice(0, i) : s
  head = head.replace(/(全包|仅作业|仅视频|仅试卷|继续教育|复习模式)$/, '')
  return head || s
}

function enterMoreProduct(m, it) {
  PF._module = m
  PF.moreItem = it
  PF.scope = 'more'
  PF.platform = it.name
  renderPurchaseFlow()
}

const PLACEHOLDER_TITLES = { cold: '综合网课', intern: '实习打卡', sport: '我爱运动', case: '病例' }

/* ==================== 实习打卡（sxdk，独立模块） ==================== */

let sxdkPlatforms = [] // [{code,name,price_yuan}] 由 /api/sxdk/platforms 加载

function renderSxdkFlow(el) {
  homeGridActive = false
  el.replaceChildren()
  const view = h('div', { class: 'home-view' })
  el.appendChild(view)

  // —— 平台选择（卡片 + 单选）——
  const platCards = h('div', { class: 'platform-grid' })
  let platSelIdx = 0
  function drawPlats() {
    platCards.replaceChildren()
    sxdkPlatforms.forEach((p, i) => {
      platCards.appendChild(h('div', {
        class: 'platform-card' + (i === platSelIdx ? ' active' : ''),
        onclick: () => { platSelIdx = i; drawPlats() },
      }, [
        h('span', { class: 'platform-name' }, p.name),
        h('span', { class: 'platform-price' }, `¥${fmtNum(p.price_yuan)}/天`),
        h('span', { class: 'platform-check' }, '✓'),
      ]))
    })
  }

  // —— 表单控件 ——
  const phoneIn = h('input', { class: 'input', id: 'sxdk-phone', placeholder: '打卡手机号', autocomplete: 'off', required: true })
  const passIn = h('input', { class: 'input', id: 'sxdk-password', type: 'password', placeholder: '密码', autocomplete: 'off', required: true })
  const nameIn = h('input', { class: 'input', placeholder: '姓名（选填，点“获取信息”自动带出）' })
  const schoolIn = h('input', { class: 'input', placeholder: '学校/单位（选填，部分平台必填）' })
  const projectIn = h('input', { class: 'input', placeholder: '项目/岗位关键词（选填）' })
  const upIn = h('input', { class: 'input', placeholder: '打卡开始时段，如 08:30' })
  const downIn = h('input', { class: 'input', placeholder: '打卡结束时段，如 18:00' })
  const endIn = h('input', { class: 'input', type: 'date', required: true })
  const weekCk = ['一', '二', '三', '四', '五', '六', '日'].map((d) => ({ name: '周' + d, el: h('input', { type: 'checkbox' }) }))
  // 报告开关默认关闭（与 cop 前端 form 默认一致）；校友帮专属字段
  const dayPaper = h('input', { type: 'checkbox' })
  const weekPaper = h('input', { type: 'checkbox' })
  const monthPaper = h('input', { type: 'checkbox' })
  const runTypeSel = h('select', { class: 'input' }, [
    h('option', { value: '1' }, '抓包'),
    h('option', { value: '2', selected: true }, '账密1号'),
    h('option', { value: '3' }, '绑微1号（单价×5）'),
  ])
  const countryIn = h('input', { class: 'input', value: '中国', placeholder: '国家（默认中国）' })
  const cityIn = h('input', { class: 'input', placeholder: '城市（选填）' })
  const adcodeIn = h('input', { class: 'input', placeholder: '地区编码（选填）' })
  const checkTimeIn = h('input', { class: 'input', placeholder: '打卡时间 如 08:30:00（职校家园）' })
  const gwIn = h('input', { class: 'input', placeholder: '岗位名称（选填）' })
  // 时间统一为 HH:mm:ss（对齐上游 el-time-picker value-format）
  const normTime = (v) => {
    const s = (v || '').trim()
    const m = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/)
    return m ? `${m[1].padStart(2, '0')}:${m[2]}:${(m[3] || '00')}` : s
  }

  const getBtn = h('button', { type: 'button', class: 'btn btn-sm', onclick: async () => {
    const p = sxdkPlatforms[platSelIdx]
    if (!p) return
    getBtn.disabled = true; getBtn.textContent = '获取中…'
    try {
      const d = await api.sxdkSearchPhone({ platform: p.code, phone: phoneIn.value.trim(), password: passIn.value })
      let got = false
      if (d.name) { nameIn.value = d.name; got = true }
      const school = d.school || d.schoolName || d.xx || d.address || ''
      if (school) { schoolIn.value = school; got = true }
      if (d.country) { countryIn.value = d.country; got = true }
      if (d.city) { cityIn.value = d.city; got = true }
      if (d.projectName || d.planName) { projectIn.value = d.projectName || d.planName; got = true }
      showToast(got ? '已自动获取部分信息，请核对' : '账号识别成功，请手动补充机构信息', 'success')
    } catch (e) { showToast('获取失败：' + e.message, 'error') }
    finally { getBtn.disabled = false; getBtn.textContent = '获取信息' }
  } }, '获取信息')

  const submitBtn = h('button', { class: 'btn btn-primary', type: 'submit' }, '积分下单')
  const form = h('form', { class: 'card step-card', onsubmit: async (e) => {
    e.preventDefault()
    const p = sxdkPlatforms[platSelIdx]
    if (!p) { showToast('平台暂不可用', 'warning'); return }
    const phone = phoneIn.value.trim(), pass = passIn.value
    if (!phone || !pass) { showToast('请填写手机号和密码', 'warning'); return }
    const cw = weekCk.map((w, i) => (w.el.checked ? i + 1 : null)).filter(Boolean).join(',')
    if (!cw) { showToast('请至少勾选一个打卡日', 'warning'); return }
    if (!endIn.value) { showToast('请选择结束日期', 'warning'); return }
    submitBtn.disabled = true; submitBtn.textContent = '提交中…'
    try {
      const res = await api.sxdkCreateOrder({
        platform: p.code, phone, password: pass,
        name: nameIn.value.trim(), school_text: schoolIn.value.trim(), project_name: projectIn.value.trim(),
        check_time: normTime(checkTimeIn.value), up_check_time: normTime(upIn.value), down_check_time: normTime(downIn.value),
        gw_name: gwIn.value.trim(),
        check_week: cw, end_time: endIn.value,
        day_paper: dayPaper.checked ? 1 : 2, week_paper: weekPaper.checked ? 1 : 2, month_paper: monthPaper.checked ? 1 : 2,
        run_type: Number(runTypeSel.value), country: countryIn.value.trim(), city: cityIn.value.trim(), adcode: adcodeIn.value.trim(),
      })
      showToast(`下单成功！共 ${res.days} 天，扣 ${fmtNum(res.amount_fen / 100)} 积分`, 'success')
      phoneIn.value = ''; passIn.value = ''
    } catch (err) { showToast(err.message, 'error') }
    finally { submitBtn.disabled = false; submitBtn.textContent = '积分下单' }
  } }, [
    h('div', { class: 'step-head' }, [h('span', { class: 'step-no' }, '1'), h('div', null, [h('h3', { class: 'step-title' }, '选择平台'), h('p', { class: 'step-desc' }, '按天计费，卡片上已标注单价')])]),
    platCards,
    h('div', { class: 'step-head', style: 'margin-top:14px' }, [h('span', { class: 'step-no' }, '2'), h('div', null, [h('h3', { class: 'step-title' }, '账号与打卡安排')])]),
    h('div', { class: 'form-grid' }, [
      h('div', { class: 'field' }, [h('label', null, '手机号'), phoneIn]),
      h('div', { class: 'field' }, [h('label', null, '密码'), passIn]),
    ]),
    h('div', { class: 'form-grid' }, [
      h('div', { class: 'field' }, [h('label', null, '姓名'), nameIn]),
      h('div', { class: 'field' }, [h('label', null, '学校/单位'), schoolIn]),
    ]),
    h('div', { class: 'form-grid' }, [
      h('div', { class: 'field' }, [h('label', null, '项目关键词'), projectIn]),
      h('div', { class: 'field', style: 'display:flex;align-items:center;gap:10px' }, [getBtn, h('span', { class: 'tip' }, '输入手机号密码后可自动获取')]),
    ]),
    h('div', { class: 'form-grid' }, [
      h('div', { class: 'field' }, [h('label', null, '岗位名称'), gwIn]),
      h('div', { class: 'field' }, [h('label', null, '打卡时间'), checkTimeIn]),
    ]),
    h('div', { class: 'field' }, [h('label', null, '每周打卡日'), h('div', { style: 'display:flex;gap:12px;flex-wrap:wrap' }, weekCk.map((w) => h('label', { style: 'display:flex;gap:5px;align-items:center;cursor:pointer' }, [w.el, w.name])))]),
    h('div', { class: 'form-grid' }, [
      h('div', { class: 'field' }, [h('label', null, ['结束日期', h('span', { style: 'font-weight:400;color:#94a3b8' }, '（必填）')]), endIn]),
      h('div', { class: 'field' }, [h('label', null, '打卡时段'), h('div', { style: 'display:flex;gap:8px' }, [upIn, downIn])]),
    ]),
    h('div', { class: 'form-grid' }, [
      h('div', { class: 'field' }, [h('label', null, ['运行方式', h('span', { style: 'font-weight:400;color:#94a3b8' }, '（校友帮）')]), runTypeSel]),
      h('div', { class: 'field' }, [h('label', null, '地区编码'), adcodeIn]),
    ]),
    h('div', { class: 'form-grid' }, [
      h('div', { class: 'field' }, [h('label', null, '国家'), countryIn]),
      h('div', { class: 'field' }, [h('label', null, '城市'), cityIn]),
    ]),
    h('div', { class: 'field' }, [h('label', null, '报告'), h('div', { style: 'display:flex;gap:14px;flex-wrap:wrap' }, [
      h('label', { style: 'display:flex;gap:5px;align-items:center;cursor:pointer' }, [dayPaper, '日报']),
      h('label', { style: 'display:flex;gap:5px;align-items:center;cursor:pointer' }, [weekPaper, '周报']),
      h('label', { style: 'display:flex;gap:5px;align-items:center;cursor:pointer' }, [monthPaper, '月报']),
    ])]),
    h('div', { class: 'step-footer' }, [
      h('span', { class: 'pay-total' }, '下单后自动进入处理，订单可在个人中心“实习打卡”查看进度'),
      submitBtn,
    ]),
  ])

  view.appendChild(h('div', { class: 'subnav' }, [
    h('a', { class: 'back-link', href: '#', onclick: (e) => { e.preventDefault(); showHomeGrid() } }, '← 返回服务首页'),
    h('span', { class: 'subnav-title' }, '实习打卡'),
  ]))
  view.appendChild(h('div', { class: 'page-head' }, [h('h1', null, '实习打卡'), h('p', null, '选平台 → 填打卡账号 → 设打卡安排，按天扣积分下单')]))
  view.appendChild(form)

  // 异步加载平台
  ;(async () => {
    try {
      const d = await api.sxdkPlatforms()
      sxdkPlatforms = Array.isArray(d.items) ? d.items : []
    } catch { sxdkPlatforms = [] }
    if (sxdkPlatforms.length === 0) {
      view.appendChild(h('div', { class: 'empty' }, '实习打卡功能暂未开放，请稍后再试'))
      return
    }
    drawPlats()
  })()
}

/* ==================== 我爱运动（syy，套娃下游） ==================== */

let syyProjects = []

// 我爱运动：横向项目胶囊 + Hero 详情 + 一体化下单/退还面板（手机原生手感）
function renderSportFlow(el) {
  homeGridActive = false
  el.replaceChildren()
  injectSyyStyle()
  const view = h('div', { class: 'home-view' })
  el.appendChild(view)

  let selIdx = 0
  let wdBalance = null // 所选项目的上游剩余单位（查余额后）

  const SYY_PALETTES = [
    ['#eef2ff', '#4f46e5'], ['#ecfeff', '#0891b2'], ['#fef2f2', '#dc2626'], ['#f0fdf4', '#16a34a'],
    ['#fff7ed', '#ea580c'], ['#faf5ff', '#9333ea'], ['#fffbeb', '#d97706'], ['#eff6ff', '#2563eb'],
  ]
  const resultBox = h('div')

  // ---- 项目胶囊（横向滑动，选中自动滚到中间）----
  const tabsScroll = h('div', { class: 'syy-tabs' })
  function drawTabs() {
    tabsScroll.replaceChildren()
    syyProjects.forEach((p, i) => {
      const [bg, fg] = SYY_PALETTES[i % SYY_PALETTES.length]
      tabsScroll.appendChild(h('button', {
        type: 'button',
        class: 'syy-tab' + (i === selIdx ? ' active' : ''),
        title: p.description || '',
        onclick: () => {
          if (selIdx === i) return
          selIdx = i
          wdBalance = null
          drawTabs()
          updHero()
          updTotal()
          renderAcct()
          updWd()
        },
      }, [
        h('span', { class: 'syy-tab-ico', style: `background:${bg};color:${fg}` }, (p.name || '?')[0]),
        h('span', { class: 'syy-tab-body' }, [
          h('span', { class: 'syy-tab-name' }, p.name),
          h('span', { class: 'syy-tab-price' }, `${fmtMoney(p.unit_fen)}/单位`),
        ]),
      ]))
    })
    const act = tabsScroll.querySelector('.syy-tab.active')
    if (act) act.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' })
  }

  // ---- Hero：选中项目大卡 ----
  const heroIco = h('span', { class: 'syy-hero-ico' })
  const heroName = h('div', { class: 'syy-hero-name' })
  const heroPrice = h('span', { class: 'platform-price' })
  const heroDesc = h('div', { class: 'syy-hero-desc' })
  function updHero() {
    const p = syyProjects[selIdx]
    if (!p) return
    const [bg, fg] = SYY_PALETTES[selIdx % SYY_PALETTES.length]
    heroIco.style.background = bg
    heroIco.style.color = fg
    heroIco.textContent = (p.name || '?')[0]
    heroName.textContent = p.name
    heroPrice.textContent = `${fmtMoney(p.unit_fen)}/单位`
    heroDesc.textContent = p.description || '按数量计费，下单后拿专属链接去平台完成任务'
  }

  // ---- 我的运动账户：免费开户（0积分拿链接）→ 进站后按需充值 ----
  let acctCache = {} // project_id → {balance,link} | {none:true}
  const acctNum = h('span', { class: 'num' }, '--')
  const acctMeta = h('span', { class: 'meta', style: 'flex:1' }, '查询中…')
  const acctBtn = h('button', { class: 'btn btn-sm btn-primary', type: 'button', hidden: true }, '免费开通账户')
  const acctGo = h('a', { class: 'btn btn-sm btn-primary', target: '_blank', rel: 'noopener', hidden: true }, '进入平台 →')
  function renderAcct() {
    const p = syyProjects[selIdx]
    if (!p) return
    const st = acctCache[p.project_id]
    if (st === undefined) {
      acctNum.textContent = '--'
      acctMeta.textContent = '查询中…'
      acctBtn.hidden = true
      acctGo.hidden = true
      refreshAcct(p.project_id)
      return
    }
    if (st.link) {
      acctNum.textContent = `剩余 ${fmtNum(st.balance ?? 0)} 单位`
      acctMeta.textContent = '账户已开通，链接长期有效'
      acctGo.href = st.link
      acctGo.hidden = false
      acctBtn.hidden = true
    } else {
      acctNum.textContent = '未开通'
      acctMeta.textContent = '开通免费，不扣积分'
      acctGo.hidden = true
      acctBtn.hidden = false
    }
    updSubmitText()
  }
  async function refreshAcct(pid) {
    try {
      const b = await api.syyBalance(pid)
      acctCache[pid] = { balance: b.balance, link: b.customer_link }
    } catch (e) {
      if (e.status === 404) acctCache[pid] = { none: true }
      else {
        // 查询失败（上游/网络）不缓存，明确反馈避免卡在“查询中…”
        const cur = syyProjects[selIdx]
        if (cur && cur.project_id === pid) acctMeta.textContent = '查询失败：' + e.message
        return
      }
    }
    const cur = syyProjects[selIdx]
    if (cur && cur.project_id === pid) renderAcct()
  }
  acctBtn.addEventListener('click', async () => {
    const p = syyProjects[selIdx]
    if (!p) return
    acctBtn.disabled = true
    try {
      const res = await api.syyOpen(p.project_id)
      acctCache[p.project_id] = { balance: res.balance ?? 0, link: res.customer_link }
      showToast('开通成功！点击「进入平台」开始', 'success')
      if (res.customer_link) {
        // await 之后用户激活可能已过期，window.open 会被浏览器静默拦截，返回 null
        const win = window.open(res.customer_link, '_blank', 'noopener')
        if (!win) showToast('浏览器拦截了自动弹窗，请点下方「进入平台」按钮', 'warning')
      }
      renderAcct()
    } catch (e) {
      showToast(e.message, 'error')
    } finally {
      acctBtn.disabled = false
    }
  })
  function updSubmitText() {
    const p = syyProjects[selIdx]
    const st = p ? acctCache[p.project_id] : undefined
    submitBtn.textContent = st && st.link ? '积分充值' : '积分下单并开通'
  }
  const acctBox = h('div', { class: 'syy-acct' }, [
    h('span', { class: 'num' }, [acctNum]),
    h('span', { class: 'meta', style: 'flex:1' }, [acctMeta]),
    acctBtn,
    acctGo,
  ])

  // ---- 数量与支付 ----
  const kmIn = h('input', { class: 'input', type: 'number', min: '1', step: '1', placeholder: '输入数量（公里 / 次）', required: true })
  const totalAmt = h('span', { class: 'amt' }, '--')
  const totalMeta = h('span', { class: 'meta' })
  function updTotal() {
    const p = syyProjects[selIdx]
    const km = parseFloat(kmIn.value)
    if (!p || !Number.isFinite(km) || km <= 0) {
      totalAmt.textContent = '--'
      totalMeta.textContent = `单价 ${fmtMoney(p ? p.unit_fen : 0)} · 余额 ${fmtNum(user?.points)} 积分`
      return
    }
    const total = (km * p.unit_fen) / 100
    totalAmt.textContent = total.toFixed(2) + ' 积分'
    const lack = (user?.points ?? 0) < total
    totalAmt.classList.toggle('lack', lack)
    totalMeta.textContent = `${km} 单位 × ${fmtMoney(p.unit_fen)} · 余额 ${fmtNum(user?.points)}${lack ? ' · 余额不足' : ''}`
  }
  kmIn.addEventListener('input', updTotal)
  const chip = (v, target) => h('button', { class: 'syy-chip', type: 'button', onclick: () => { target.value = v; target.dispatchEvent(new Event('input')) } }, v)
  const chipsRow = h('div', { class: 'syy-chips' }, [1, 5, 10, 20, 50, 100].map((v) => chip(v, kmIn)))

  const submitBtn = h('button', { class: 'btn btn-primary syy-btn', type: 'submit' }, '积分下单并开通')
  const orderForm = h('form', { onsubmit: async (e) => {
    e.preventDefault()
    const p = syyProjects[selIdx]
    const km = parseFloat(kmIn.value)
    if (!p) { showToast('请先选择项目', 'warning'); return }
    if (!Number.isFinite(km) || km <= 0) { showToast('请填写有效数量', 'warning'); return }
    const enough = (user?.points ?? 0) >= (km * p.unit_fen) / 100
    if (!enough) { showToast('积分余额不足，请先充值', 'warning'); return }
    submitBtn.disabled = true
    submitBtn.innerHTML = '<span class="spinner"></span> 下单中…'
    resultBox.replaceChildren()
    try {
      const res = await api.syyCreateOrder({ project_id: p.project_id, km })
      if (res.status === 'done' && res.customer_link) {
        resultBox.appendChild(h('div', { class: 'order-ok' }, [
          h('div', null, `✓ 下单成功：已开通 ${res.km} 个单位，扣 ${fmtNum(res.amount_fen / 100)} 积分`),
          h('div', { class: 'order-ok-sub' }, '点击下方按钮进入运动平台完成任务；链接长期有效，同一项目可随时追加数量或退还。'),
        ]))
        resultBox.appendChild(h('div', { class: 'syy-chips', style: 'margin-top:12px' }, [
          h('a', { class: 'btn btn-primary', href: res.customer_link, target: '_blank', rel: 'noopener' }, `进入${syyUpstreamName} →`),
          h('button', { class: 'btn', type: 'button', onclick: () => {
            navigator.clipboard?.writeText(res.customer_link)
            showToast('链接已复制', 'success')
          } }, '复制链接'),
        ]))
        resultBox.scrollIntoView({ behavior: 'smooth', block: 'center' })
      } else {
        resultBox.appendChild(h('div', { class: 'task-status' }, [
          h('span', { class: 'spinner' }),
          h('div', null, ['订单已提交，上游处理中，结果请稍后到', h('a', { href: '/agent' }, ' 个人中心-我爱运动 '), '查看']),
        ]))
      }
      kmIn.value = ''
      const me = await api.agentMe().catch(() => null)
      if (me) setUserState(me)
      updTotal()
      refreshAcct(p.project_id)
      showToast('下单成功', 'success')
    } catch (err) {
      showToast(err.message, 'error')
    } finally {
      submitBtn.disabled = false
      updSubmitText()
    }
  } }, [
    h('div', { class: 'field' }, [h('label', null, '充值数量（公里 / 次）'), kmIn, chipsRow]),
    h('div', { class: 'syy-summary' }, [
      h('span', { class: 'meta' }, [totalMeta]),
      h('span', { class: 'amt' }, [totalAmt]),
    ]),
    submitBtn,
  ])

  // ---- 退还公里数 ----
  const wdKmIn = h('input', { class: 'input', type: 'number', min: '0.01', step: '0.01', placeholder: '要退还的数量' })
  const wdBalNum = h('span', { class: 'num' }, '--')
  const wdBalMeta = h('span', { class: 'meta' }, '先查余额，再填写要退还的数量')
  function updWd() {
    wdBalance = null
    wdBalNum.textContent = '--'
    wdBalMeta.textContent = '先查余额，再填写要退还的数量'
    wdKmIn.value = ''
  }
  const checkWd = h('button', { class: 'btn btn-sm', type: 'button', onclick: async () => {
    const p = syyProjects[selIdx]
    if (!p) { showToast('请先选择项目', 'warning'); return }
    wdBalMeta.textContent = '查询中…'
    try {
      const b = await api.syyBalance(p.project_id)
      wdBalance = b.balance
      wdBalNum.textContent = `${b.balance} 单位`
      wdBalMeta.textContent = `≈ 可退 ${fmtNum((b.balance * p.unit_fen) / 100)} 积分`
    } catch (e) {
      wdBalance = null
      wdBalNum.textContent = '--'
      wdBalMeta.textContent = e.status === 404 ? '该项目下暂无运动客户，先开通账户' : ('查询失败：' + e.message)
    }
  } }, '查余额')
  const wdBtn = h('button', { class: 'btn btn-outline syy-btn', type: 'button' }, '退还到积分')
  wdBtn.addEventListener('click', async () => {
    const p = syyProjects[selIdx]
    const km = parseFloat(wdKmIn.value)
    if (!p) { showToast('请先选择项目', 'warning'); return }
    if (!Number.isFinite(km) || km <= 0) { showToast('请填写要退还的数量', 'warning'); return }
    if (!confirm(`确定退还 ${km} 单位？将按当前单价退回 ${fmtNum((km * p.unit_fen) / 100)} 积分。`)) return
    wdBtn.disabled = true
    wdBtn.textContent = '退还中…'
    try {
      const res = await api.syyWithdraw({ project_id: p.project_id, km })
      showToast(`已退还 ${res.km} 单位，返 ${fmtNum(res.refund_fen / 100)} 积分`, 'success')
      updWd()
      const me = await api.agentMe().catch(() => null)
      if (me) setUserState(me)
    } catch (e) {
      showToast(e.message, 'error')
    } finally {
      wdBtn.disabled = false
      wdBtn.textContent = '退还到积分'
    }
  })
  const wdChips = h('div', { class: 'syy-chips' }, [chip(1, wdKmIn), chip(5, wdKmIn), h('button', { class: 'syy-chip', type: 'button', onclick: () => {
    if (wdBalance == null) { showToast('先点「查余额」', 'warning'); return }
    wdKmIn.value = wdBalance
  } }, '全部退')])

  // ---- 组装：单列 720px 居中 ----
  view.appendChild(h('div', { class: 'subnav' }, [
    h('a', { class: 'back-link', href: '#', onclick: (e) => { e.preventDefault(); showHomeGrid() } }, '← 返回服务首页'),
    h('span', { class: 'subnav-title' }, '我爱运动'),
  ]))
  view.appendChild(h('div', { class: 'page-head' }, [h('h1', null, '我爱运动'), h('p', null, '选项目 → 免费开通拿专属链接 → 按需充值，未用完随时退还')]))
  view.appendChild(resultBox)
  view.appendChild(h('div', { class: 'card syy-main' }, [
    tabsScroll,
    h('div', { class: 'syy-hero' }, [
      heroIco,
      h('div', { class: 'syy-hero-main' }, [
        h('div', { class: 'syy-hero-row' }, [heroName, heroPrice]),
        heroDesc,
      ]),
    ]),
    acctBox,
    orderForm,
    h('div', { class: 'syy-divider' }),
    h('div', { class: 'syy-subtitle' }, '退还公里数'),
    h('div', { class: 'syy-acct' }, [
      h('span', { class: 'num' }, [wdBalNum]),
      h('span', { class: 'meta', style: 'flex:1' }, [wdBalMeta]),
      checkWd,
    ]),
    h('div', { class: 'field', style: 'margin-top:12px' }, [h('label', null, '退还数量'), wdKmIn]),
    wdChips,
    wdBtn,
  ]))

  // 异步加载项目
  ;(async () => {
    try {
      const d = await api.syyProjects()
      syyProjects = Array.isArray(d.items) ? d.items : []
      syyUpstreamName = d.upstream_name || '运动平台'
    } catch { syyProjects = [] }
    if (syyProjects.length === 0) {
      view.appendChild(h('div', { class: 'empty' }, '运动业务暂未开放，请稍后再试'))
      return
    }
    drawTabs()
    updHero()
    updTotal()
    renderAcct()
  })()
}

// 我爱运动页专用样式（注入一次）
let _syyStyleInjected = false
function injectSyyStyle() {
  if (_syyStyleInjected) return
  _syyStyleInjected = true
  const st = h('style')
  st.textContent = `
    .syy-main{max-width:720px;margin:0 auto;padding:18px 18px 22px}
    .syy-tabs{display:flex;gap:9px;overflow-x:auto;padding:2px 2px 10px;scrollbar-width:none;-webkit-overflow-scrolling:touch;scroll-snap-type:x proximity}
    .syy-tabs::-webkit-scrollbar{display:none}
    .syy-tab{flex-shrink:0;display:flex;align-items:center;gap:9px;padding:7px 14px 7px 7px;border:1.5px solid var(--border);border-radius:999px;background:var(--surface);cursor:pointer;transition:all .16s;font-family:inherit;scroll-snap-align:center}
    .syy-tab:hover{border-color:var(--border-strong)}
    .syy-tab.active{border-color:var(--text);background:#f8fafc;box-shadow:0 0 0 1.5px var(--text),var(--shadow-md)}
    .syy-tab-ico{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;flex-shrink:0}
    .syy-tab-body{display:flex;flex-direction:column;align-items:flex-start;min-width:0}
    .syy-tab-name{font-size:12.5px;font-weight:700;color:var(--text);max-width:118px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .syy-tab-price{font-size:11px;color:#059669;font-weight:700;font-variant-numeric:tabular-nums}
    .syy-hero{display:flex;gap:14px;align-items:flex-start;background:linear-gradient(135deg,#f8fafc,#eef2ff);border:1px solid var(--border);border-radius:var(--radius);padding:15px 16px;margin-bottom:12px}
    .syy-hero-ico{width:52px;height:52px;border-radius:15px;display:flex;align-items:center;justify-content:center;font-size:21px;font-weight:800;flex-shrink:0;box-shadow:var(--shadow-soft)}
    .syy-hero-main{flex:1;min-width:0}
    .syy-hero-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .syy-hero-name{font-size:17px;font-weight:800;letter-spacing:-.01em}
    .syy-hero-desc{font-size:12.5px;color:var(--text-muted);margin-top:5px;line-height:1.6}
    .syy-acct{display:flex;align-items:center;gap:12px;background:var(--bg-subtle);border:1px solid var(--border);border-radius:var(--radius-sm);padding:11px 14px;margin-top:12px;flex-wrap:wrap}
    .syy-acct .num{font-size:15.5px;font-weight:850;font-variant-numeric:tabular-nums;color:var(--text)}
    .syy-acct .meta{font-size:12.5px;color:var(--text-muted)}
    .syy-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
    .syy-chip{padding:6px 13px;border:1px solid var(--border);border-radius:999px;background:var(--surface);font-size:12.5px;font-weight:600;color:var(--text-soft);cursor:pointer;transition:all .15s;font-family:inherit}
    .syy-chip:hover{border-color:var(--border-strong)}
    .syy-summary{display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--bg-subtle);border:1px solid var(--border);border-radius:var(--radius-sm);padding:13px 15px;margin-top:14px;flex-wrap:wrap}
    .syy-summary .amt{font-size:19px;font-weight:850;color:var(--accent);font-variant-numeric:tabular-nums}
    .syy-summary .amt.lack{color:var(--danger)}
    .syy-summary .meta{font-size:12.5px;color:var(--text-muted)}
    .syy-btn{width:100%;justify-content:center;margin-top:14px}
    .syy-divider{height:1px;background:var(--border);margin:18px 0 14px}
    .syy-subtitle{font-size:13.5px;font-weight:750;margin-bottom:10px}
  `
  document.head.appendChild(st)
}
let syyUpstreamName = '运动平台'

// 个人中心：我的运动购买记录（同项目复用同一链接，可重复充值）
function renderSyyOrders(panel) {
  let items = null
  const body = h('tbody')
  const cardsBox = h('div', { class: 'ag-cards' })
  const mobile = isMobileView()

  const load = async () => {
    try {
      const d = await api.syyOrders()
      items = Array.isArray(d.items) ? d.items : []
      draw()
    } catch (e) {
      showToast('加载失败：' + e.message, 'error')
    }
  }

  const syyBadge = (s) => s === 'done' ? h('span', { class: 'badge badge-done' }, '已到账')
    : s === 'error' ? h('span', { class: 'badge badge-banned' }, '下单失败')
    : h('span', { class: 'badge badge-progress' }, '处理中')

  function draw() {
    if (!items) return
    if (!items.length) {
      const node = h('div', { class: 'empty' }, '还没有运动订单，去首页【我爱运动】下一单吧')
      if (mobile) cardsBox.replaceChildren(node)
      else body.replaceChildren(h('tr', null, h('td', { colSpan: 7 }, node)))
      return
    }
    const linkBtn = (o) => o.customer_link
      ? h('a', { class: 'btn btn-sm', href: o.customer_link, target: '_blank', rel: 'noopener' }, '打开平台')
      : h('span', { class: 'progress-tip' }, o.remark || '-')
    const kmText = (o) => o.km < 0 ? `退还 ${fmtNum(-o.km)}` : `${o.km} 单位`
    const amtText = (o) => o.amount_fen < 0 ? `返 ${fmtNum(-o.amount_fen / 100)} 积分` : `扣 ${fmtNum(o.amount_fen / 100)} 积分`
    if (mobile) {
      cardsBox.replaceChildren(...items.map((o) => h('div', { class: 'ag-card' }, [
        h('div', { class: 'ag-card-top' }, [h('b', null, o.project_name), syyBadge(o.status)]),
        h('div', { class: 'ag-card-line' }, [
          h('span', null, `${kmText(o)} · ${amtText(o)}`),
          h('span', { class: 'ag-card-money' }, fmtTime(o.created_at)),
        ]),
        h('div', { class: 'ag-card-line' }, [h('span', null, o.balance > 0 ? `剩余约 ${o.balance} 单位` : ''), linkBtn(o)]),
      ])))
    } else {
      body.replaceChildren(...items.map((o) => h('tr', {}, [
        h('td', null, fmtTime(o.created_at)),
        h('td', null, o.project_name),
        h('td', null, kmText(o)),
        h('td', null, fmtMoney(o.amount_fen)),
        h('td', null, syyBadge(o.status)),
        h('td', null, o.balance > 0 ? String(o.balance) : '-'),
        h('td', null, linkBtn(o)),
      ])))
    }
  }

  const refreshBtn = h('button', { class: 'btn btn-sm', onclick: load }, '↻ 刷新')
  const listWrap = mobile
    ? cardsBox
    : h('div', { class: 'agent-table-wrap ag-table-card' }, h('table', { class: 'agent-table' }, [
      h('thead', null, h('tr', null, ['时间', '项目', '数量', '扣分', '状态', '剩余(参考)', '链接'].map((x) => h('th', null, x)))),
      body,
    ]))
  panel.replaceChildren(h('section', null, [
    panelHead('我爱运动', '运动项目购买记录；同一项目多次充值共用同一专属链接', h('span', null, [refreshBtn])),
    listWrap,
  ]))
  load()
}

/* ==================== 病例（bingli，独立模块） ==================== */

function bingStatusBadge(o) {
  if (o.status === 'error') return h('span', { class: 'badge badge-banned' }, '失败已退款')
  if (o.status === 'processing') return h('span', { class: 'badge badge-progress' }, '处理中')
  if (o.image_ready) return h('span', { class: 'badge badge-done' }, '已完成')
  return h('span', { class: 'badge badge-progress' }, '图片生成中')
}

// 病例字段排序：患者基本信息在前，诊断/建议等长文本在中间，签名/时间在最后。
// 上游返回的字段是按 fieldKey 字母序的（advice、age、name…），直接展示会很乱。
const BING_FIELD_ORDER = [
  ['姓名', 'name'],
  ['性别', 'gender'],
  ['年龄', 'age'],
  ['病历号', 'caseNo', '门诊号', '住院号'],
  ['科别', 'dept', '科室'],
  ['医院名称', 'hospital'],
  ['文书名称', 'docTitle'],
  ['诊断', 'diagnosis'],
  ['建议', 'advice', '医嘱'],
  ['医师签名', 'doctor', '医生'],
  ['记录时间', 'recordTime', '日期'],
]

function bingOrderedFields(fields) {
  const rank = (f) => {
    const key = String(f.field_key || '').toLowerCase()
    const label = String(f.label || '')
    for (let i = 0; i < BING_FIELD_ORDER.length; i++) {
      for (const alias of BING_FIELD_ORDER[i]) {
        const a = String(alias).toLowerCase()
        if (key === a || label === alias) return i
      }
    }
    return BING_FIELD_ORDER.length
  }
  return (fields || [])
    .map((f, i) => ({ f, i, r: rank(f) }))
    .sort((a, b) => a.r - b.r || a.i - b.i)
    .map((x) => x.f)
}

// 首页服务大厅 → 病例：选模板 → 按模板动态字段填写 → 扣积分生成图片
function renderBingliFlow(el) {
  homeGridActive = false
  el.replaceChildren()
  const view = h('div', { class: 'home-view' })
  el.appendChild(view)

  let apps = []
  let selId = ''
  let detail = null
  let submitting = false

  const cardsBox = h('div', { class: 'platform-grid' })
  const statusBox = h('div', { class: 'empty' }, '病例模板加载中…')
  const formCard = h('div', { class: 'card step-card', style: 'display:none' })
  const resultBox = h('div')

  view.appendChild(h('div', { class: 'subnav' }, [
    h('a', { class: 'back-link', href: '#', onclick: (e) => { e.preventDefault(); showHomeGrid() } }, '← 返回服务首页'),
    h('span', { class: 'subnav-title' }, '病例'),
  ]))
  view.appendChild(h('div', { class: 'page-head' }, [
    h('h1', null, '病例'),
    h('p', null, '选择模板 → 在线填写 → 提交后生成病例图片，按模板扣积分'),
  ]))
  view.appendChild(cardsBox)
  view.appendChild(statusBox)
  view.appendChild(formCard)
  view.appendChild(resultBox)

  function drawCards() {
    cardsBox.replaceChildren(...apps.map((a) => h('div', {
      class: 'platform-card' + (a.id === selId ? ' active' : ''),
      onclick: () => selectApp(a.id),
    }, [
      h('span', { class: 'platform-name' }, a.name),
      h('span', { class: 'platform-price' }, `${fmtNum(a.price_points)} 积分`),
      h('span', { style: 'font-size:12px;color:var(--text-muted);line-height:1.5' },
        [a.category, a.page_size].filter(Boolean).join(' · ') || a.description || ''),
      h('span', { class: 'platform-check' }, '✓'),
    ])))
  }

  function formError(msg, retry) {
    formCard.style.display = ''
    formCard.replaceChildren(h('div', { class: 'tip', style: 'color:var(--danger)' }, msg),
      retry ? h('button', { class: 'btn btn-sm', style: 'margin-top:10px', onclick: retry }, '重新加载') : null)
  }

  async function selectApp(id) {
    if (submitting || !id) return
    selId = id
    detail = null
    drawCards()
    resultBox.replaceChildren()
    formCard.style.display = ''
    formCard.replaceChildren(h('div', { class: 'tip' }, '模板加载中…'))
    try {
      const d = await api.bingApplication(id)
      if (selId !== id) return
      detail = d
      drawForm()
    } catch (e) {
      if (selId !== id) return
      formError(e.status === 503 ? '病例功能暂未开放' : '模板加载失败：' + e.message, () => selectApp(id))
    }
  }

  function drawForm() {
    const fieldsBox = h('div', { class: 'form-grid' })
    const inputs = new Map()
    const orderedFields = bingOrderedFields(detail.fields)
    for (const f of orderedFields) {
      const label = f.label || f.field_key
      const attrs = { class: 'input', placeholder: label }
      if (f.max_length > 0) attrs.maxlength = f.max_length
      const isTextarea = String(f.type || '').toLowerCase() === 'textarea'
      const input = isTextarea
        ? h('textarea', { ...attrs, rows: '3' })
        : h('input', { ...attrs, type: 'text' })
      inputs.set(f.field_key, input)
      // 长文本（诊断/建议）占满整行，短字段保持两列，避免表单显得零散
      fieldsBox.appendChild(h('div', { class: 'field', style: isTextarea ? 'grid-column:1/-1' : '' }, [
        h('label', null, [label, f.required ? h('span', { style: 'color:var(--danger)' }, ' *') : null]),
        input,
      ]))
    }

    const stampInputs = new Map()
    let stampBox = null
    if (detail.stamp) {
      const orgKey = detail.stamp.org_field || 'stampOrg'
      const codeKey = detail.stamp.code_field || 'stampCode'
      const org = h('input', { class: 'input', placeholder: detail.stamp.default_org ? `默认：${detail.stamp.default_org}` : '不填使用模板默认印章' })
      const code = h('input', { class: 'input', placeholder: detail.stamp.default_code ? `默认：${detail.stamp.default_code}` : '不填使用模板默认印章文字' })
      stampInputs.set(orgKey, org)
      stampInputs.set(codeKey, code)
      stampBox = h('div', { class: 'form-grid', style: 'margin-top:12px' }, [
        h('div', { class: 'field' }, [h('label', null, '印章名称（选填）'), org]),
        h('div', { class: 'field' }, [h('label', null, '印章文字（选填）'), code]),
      ])
    }

    const preview = h('div', { style: 'margin-top:14px' }, [
      h('div', { style: 'font-size:13px;color:var(--text-muted);margin-bottom:6px' }, '模板样式预览（提交后生成含填写内容的图片）'),
      h('img', {
        src: `/api/bing/applications/${encodeURIComponent(detail.id)}/preview`,
        alt: '模板预览',
        style: 'display:block;width:100%;max-width:720px;border-radius:12px;border:1px solid var(--border);background:#fff',
        onerror: (e) => { e.target.replaceWith(h('div', { class: 'tip' }, '该模板暂无预览图')) },
      }),
    ])

    const submitBtn = h('button', { class: 'btn btn-primary', type: 'submit' }, `提交生成（${fmtNum(detail.price_points)} 积分）`)
    const form = h('form', { onsubmit: async (e) => {
      e.preventDefault()
      if (submitting || !detail) return
      const fields = {}
      for (const [key, input] of inputs) {
        const v = input.value.trim()
        if (v) fields[key] = v
      }
      const missing = orderedFields.find((f) => f.required && !fields[f.field_key])
      if (missing) {
        showToast('请填写：' + (missing.label || missing.field_key), 'warning')
        return
      }
      for (const [key, input] of stampInputs) {
        const v = input.value.trim()
        if (v) fields[key] = v
      }
      submitting = true
      submitBtn.disabled = true
      submitBtn.textContent = '提交中，正在生成…'
      try {
        const v = await api.bingCreateOrder({
          application_id: selId,
          fields,
          // 后端按实时价复核；期间管理员改价会返回 409，不会按新价静默扣款
          expected_price_fen: detail.price_fen,
        })
        for (const input of inputs.values()) input.value = ''
        for (const input of stampInputs.values()) input.value = ''
        showBingResult(v, resultBox)
        showToast(v.status === 'processing' ? '订单已提交，正在生成图片' : '下单成功', 'success')
      } catch (err) {
        // 409 = 模板价格已被后台更新：只刷新价格与按钮，保留用户已填内容
        if (err.status === 409) {
          try {
            detail = await api.bingApplication(selId)
            submitBtn.textContent = `提交生成（${fmtNum(detail.price_points)} 积分）`
            const total = form.querySelector('.pay-total')
            if (total) total.textContent = `本次扣 ${fmtNum(detail.price_points)} 积分`
          } catch {}
        }
        showToast(err.message, 'error')
      } finally {
        submitting = false
        submitBtn.disabled = false
        submitBtn.textContent = `提交生成（${fmtNum(detail.price_points)} 积分）`
      }
    } }, [
      h('div', { class: 'step-head' }, [
        h('span', { class: 'step-no' }, '1'),
        h('div', null, [h('h3', { class: 'step-title' }, detail.name), h('p', { class: 'step-desc' }, detail.description || '请按模板要求填写以下内容')]),
      ]),
      fieldsBox,
      stampBox,
      preview,
      h('div', { class: 'tip', style: 'margin-top:12px' }, '填写内容仅用于生成病例图片，服务端加密存储，不会展示给其他用户。'),
      h('div', { class: 'step-footer' }, [
        h('span', { class: 'pay-total' }, `本次扣 ${fmtNum(detail.price_points)} 积分`),
        submitBtn,
      ]),
    ])

    formCard.replaceChildren(h('div', { class: 'step-head' }, [
      h('span', { class: 'step-no' }, '2'),
      h('div', null, [h('h3', { class: 'step-title' }, '填写并提交'), h('p', { class: 'step-desc' }, '必填项已标注 *，图片生成后可在个人中心【病例】查看')]),
    ]), form)
  }

  function showBingResult(v, box) {
    const refresh = async () => {
      try {
        const nv = await api.bingOrder(v.order_id)
        showBingResult(nv, box)
      } catch (e) { showToast('刷新失败：' + e.message, 'error') }
    }
    const retry = async (btn) => {
      btn.disabled = true; btn.textContent = '重试中…'
      try {
        const nv = await api.bingRetryOrder(v.order_id)
        showBingResult(nv, box)
      } catch (e) {
        showToast(e.message, 'error')
        btn.disabled = false; btn.textContent = '重试生成'
      }
    }

    const children = [
      h('div', { class: 'step-head' }, [
        h('span', { class: 'step-no' }, '✓'),
        h('div', null, [
          h('h3', { class: 'step-title' }, v.status === 'processing' ? '订单已提交' : '下单成功'),
          h('p', { class: 'step-desc' }, v.application_name || ''),
        ]),
      ]),
      h('div', { style: 'display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px' }, [
        bingStatusBadge(v),
        h('span', { class: 'tip' }, `扣 ${fmtNum(v.price_points)} 积分 · 订单号 ${v.order_id}`),
      ]),
    ]

    if (v.image_ready && v.image_url) {
      children.push(h('img', {
        src: v.image_url, alt: '病例图片',
        style: 'max-width:100%;border-radius:12px;border:1px solid var(--border);margin-top:6px',
        onerror: (e) => { e.target.replaceWith(h('div', { class: 'tip' }, '图片加载失败，可能仍在生成，请稍后刷新')) },
      }))
      children.push(h('div', { class: 'tip', style: 'margin-top:10px' }, '图片仅本人可见，上游云端保留 3 天，请及时保存。'))
      children.push(h('div', { style: 'margin-top:12px;display:flex;gap:10px;flex-wrap:wrap' }, [
        h('a', { class: 'btn btn-primary btn-sm', href: v.image_url, download: `病例-${v.order_id}.png`, target: '_blank', rel: 'noopener' }, '保存图片'),
        h('a', { class: 'btn btn-sm', href: '/agent#bing' }, '去个人中心查看'),
      ]))
    } else if (v.status === 'processing') {
      children.push(h('div', { class: 'tip', style: 'margin-top:8px' }, '上游正在处理，图片稍后生成。订单已扣积分；若失败会自动退款。'))
      children.push(h('div', { style: 'margin-top:12px;display:flex;gap:10px;flex-wrap:wrap' }, [
        h('button', { class: 'btn btn-sm', onclick: () => refresh() }, '刷新状态'),
        h('button', { class: 'btn btn-sm', onclick: (e) => retry(e.currentTarget) }, '重试生成'),
        h('a', { class: 'btn btn-sm', href: '/agent#bing' }, '去个人中心查看'),
      ]))
    } else {
      children.push(h('div', { class: 'tip', style: 'margin-top:8px' }, v.render_error ? '图片暂未生成：' + v.render_error : '订单已完成，图片仍在准备中。'))
      children.push(h('div', { style: 'margin-top:12px;display:flex;gap:10px;flex-wrap:wrap' }, [
        h('button', { class: 'btn btn-sm', onclick: () => refresh() }, '刷新状态'),
        h('a', { class: 'btn btn-sm', href: `/api/bing/orders/${encodeURIComponent(v.order_id)}/image`, target: '_blank', rel: 'noopener' }, '尝试打开图片'),
        h('a', { class: 'btn btn-sm', href: '/agent#bing' }, '去个人中心查看'),
      ]))
    }
    box.replaceChildren(h('div', { class: 'card step-card' }, children))
  }

  ;(async () => {
    try {
      const d = await api.bingApplications()
      apps = Array.isArray(d.items) ? d.items : []
    } catch (e) {
      apps = []
      statusBox.textContent = e.status === 503 ? '病例功能暂未开放，请稍后再试' : '病例模板加载失败：' + e.message
      return
    }
    if (!apps.length) {
      statusBox.textContent = '暂无可用的病例模板，请稍后再试'
      return
    }
    statusBox.remove()
    selId = apps[0].id
    drawCards()
    selectApp(selId)
  })()
}

/* ==================== 学习通自动签到（qd，独立模块 · qd_auto 单页风格） ==================== */

let qdStylesInjected = false
function qdInjectStyles() {
  if (qdStylesInjected) return
  qdStylesInjected = true
  const st = document.createElement('style')
  st.id = 'qd-scoped-styles'
  st.textContent = `
.qd-wrap{max-width:880px;margin:0 auto;color:#151a26;
  background:radial-gradient(900px 320px at 18% -6%,rgba(91,91,214,.055),transparent 60%),
    radial-gradient(700px 300px at 100% 0%,rgba(16,150,110,.04),transparent 55%),
    linear-gradient(180deg,#f8f9fd,#f0f2f7);
  border:1px solid #e9ecf3;border-radius:20px;padding:26px 26px 30px;
  box-shadow:0 1px 2px rgba(21,26,38,.03),0 10px 34px -18px rgba(21,26,38,.10)}
.qd-topbar{display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:18px;
  border-bottom:1px solid #e9ecf3;flex-wrap:wrap;animation:qdRise .45s cubic-bezier(.22,.9,.32,1) both}
.qd-topbar .qd-bar{width:4px;height:38px;border-radius:99px;background:linear-gradient(180deg,#6366f1,#8b5cf6);flex:none}
.qd-topbar h1{font-size:20px;font-weight:750;letter-spacing:-.2px;margin:0;color:#151a26}
.qd-topbar .qd-sub{font-size:12.5px;color:#96a0b2;margin-top:4px;letter-spacing:.2px}
.qd-topbar .qd-sp{flex:1}
@keyframes qdRise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.qd-card{background:#fff;border:1px solid #e9ecf3;border-radius:16px;padding:16px;margin-bottom:14px;
  box-shadow:0 1px 2px rgba(21,26,38,.03),0 10px 34px -18px rgba(21,26,38,.10);
  animation:qdRise .5s cubic-bezier(.22,.9,.32,1) .06s both}
.qd-m-sec{font-size:11px;font-weight:700;letter-spacing:1.5px;color:#96a0b2;margin:2px 0 10px;text-transform:uppercase}
.qd-read-list{list-style:none;margin:0;padding:0}
.qd-read-list li{display:flex;gap:10px;padding:9px 0;border-bottom:1px dashed #f0f2f8;
  font-size:13px;color:#5c6575;line-height:1.75;align-items:flex-start}
.qd-read-list li:last-child{border-bottom:none;padding-bottom:2px}
.qd-read-list .qd-n{flex:none;width:20px;height:20px;border-radius:50%;background:#eef0fe;color:#5b5bd6;
  font-size:11.5px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-top:3px}
.qd-read-list li b{color:#151a26;font-weight:600}
.qd-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;animation:qdRise .5s cubic-bezier(.22,.9,.32,1) .03s both}
.qd-chip{font-size:12px;padding:5px 12px;border-radius:8px;background:#fff;border:1px solid #e9ecf3;
  color:#96a0b2;display:inline-flex;align-items:center;gap:6px;font-weight:500}
.qd-chip b{color:#151a26;font-variant-numeric:tabular-nums;font-weight:700}
.qd-chip.on b{color:#0a9e6e}
.qd-chip.bad b{color:#d93838}
.qd-pkgs{display:flex;gap:10px;margin-bottom:16px}
.qd-pkg{border:1.5px solid #e9ecf3;border-radius:12px;padding:12px 18px;cursor:pointer;transition:.18s;
  background:#fff;text-align:center;flex:1;position:relative}
.qd-pkg:hover{border-color:#c9cef0}
.qd-pkg.active{border-color:#5b5bd6;background:#fbfbff;box-shadow:0 0 0 3px rgba(99,102,241,.10)}
.qd-pkg.active::before{content:'✓';position:absolute;top:-7px;right:-5px;width:18px;height:18px;border-radius:50%;
  background:#5b5bd6;color:#fff;font-size:10.5px;font-weight:700;display:flex;align-items:center;justify-content:center}
.qd-pkg .qd-pkg-n{font-size:13.5px;font-weight:700;color:#151a26}
.qd-pkg .qd-pkg-m{font-size:11.5px;color:#96a0b2;margin-top:3px;font-variant-numeric:tabular-nums}
.qd-pkg.active .qd-pkg-m{color:#5b5bd6}
.qd-field{margin-bottom:13px}
.qd-field label{display:block;font-size:12.5px;font-weight:600;color:#5c6575;margin-bottom:6px}
.qd-input{background:#f8f9fc;border:1.5px solid #e9ecf3;border-radius:10px;
  padding:11px 13px;font-size:14px;color:#151a26;outline:none;width:100%;transition:.18s;font-family:inherit;box-sizing:border-box}
.qd-input::placeholder{color:#b6bdcc}
.qd-input:focus{border-color:#a6abf5;background:#fff;box-shadow:0 0 0 3.5px rgba(99,102,241,.10)}
.qd-pw-wrap{position:relative}
.qd-pw-wrap .qd-input{padding-right:42px}
.qd-eye{position:absolute;right:5px;top:50%;transform:translateY(-50%);border:none;background:transparent;
  cursor:pointer;font-size:15px;padding:7px 9px;border-radius:8px;line-height:1;opacity:.6;transition:.15s}
.qd-eye:hover{opacity:1;background:#eef0f7}
.qd-hint{font-size:12px;color:#96a0b2;line-height:1.7}
.qd-btn{border:none;border-radius:10px;padding:11px 20px;font-size:13.5px;font-weight:600;cursor:pointer;
  transition:.2s;font-family:inherit;display:inline-flex;align-items:center;gap:7px;justify-content:center}
.qd-btn:disabled{opacity:.55;cursor:not-allowed}
.qd-btn-go{color:#fff;position:relative;overflow:hidden;
  background:linear-gradient(135deg,#6366f1,#8b5cf6 62%,#8b5cf6);
  box-shadow:0 6px 18px -4px rgba(99,102,241,.42),inset 0 1px 0 rgba(255,255,255,.25)}
.qd-btn-go::after{content:'';position:absolute;top:0;left:-80%;width:55%;height:100%;
  background:linear-gradient(105deg,transparent,rgba(255,255,255,.32),transparent);
  transform:skewX(-18deg);transition:left .5s ease}
.qd-btn-go:hover:not(:disabled)::after{left:130%}
.qd-btn-go:hover:not(:disabled){transform:translateY(-1px)}
.qd-btn-sm{border-radius:8px;padding:6px 13px;font-size:12.5px;background:#fff;border:1px solid #e9ecf3;
  color:#5c6575;box-shadow:none;font-weight:500;cursor:pointer;transition:.18s;font-family:inherit}
.qd-btn-sm:hover{color:#5b5bd6;border-color:#ccd2f2;background:#fafbff}
.qd-btn-sm.qd-primary{background:#eef0fe;border-color:#dfe3fc;color:#5b5bd6;font-weight:600}
.qd-btn-sm.qd-primary:hover{background:#e4e8ff}
.qd-btn-sm:disabled{opacity:.55;cursor:not-allowed}
.qd-acct{display:flex;align-items:center;gap:14px;padding:13px 14px;border-radius:12px;margin-bottom:8px;
  border:1px solid transparent;transition:.18s;cursor:pointer}
.qd-acct:last-child{margin-bottom:0}
.qd-acct:hover{background:#fafbfe;border-color:#e9ecf3}
.qd-acct.off{opacity:.66}
.qd-avt{width:40px;height:40px;border-radius:11px;flex:none;display:flex;align-items:center;justify-content:center;
  font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums;letter-spacing:.3px}
.qd-avt.qd-run{background:#ecf9f3;color:#0a9e6e}
.qd-avt.qd-idle{background:#f1f3f8;color:#96a0b2}
.qd-avt.qd-err{background:#fdf0f0;color:#d93838}
.qd-acct .qd-info{flex:1;min-width:0}
.qd-acct .qd-ph{font-size:14.5px;font-weight:650;display:flex;align-items:center;gap:8px;flex-wrap:wrap;color:#151a26}
.qd-acct .qd-ph .qd-pkg-tag{font-size:10.5px;font-weight:600;color:#5b5bd6;background:#eef0fe;border-radius:5px;padding:1.5px 7px}
.qd-acct .qd-meta{font-size:12px;color:#96a0b2;margin-top:4px;display:flex;gap:11px;flex-wrap:wrap}
.qd-acct .qd-meta .qd-ok-c{color:#0a9e6e;font-weight:600}
.qd-acct .qd-meta .qd-bad-c{color:#d93838;font-weight:600}
.qd-acct .qd-meta .qd-last{min-width:100%;color:#96a0b2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.qd-acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;flex:none;max-width:250px}
.qd-pill{display:inline-flex;align-items:center;gap:5.5px;padding:3px 10px;border-radius:999px;
  font-size:11px;font-weight:600;white-space:nowrap}
.qd-pill.qd-on{background:#ecf9f3;color:#0a9e6e}
.qd-pill.qd-off{background:#f2f4f9;color:#96a0b2}
.qd-pill.qd-err{background:#fdf0f0;color:#d93838}
.qd-pill.qd-wrn{background:#fdf5e9;color:#b25e09}
.qd-dot{width:5.5px;height:5.5px;border-radius:50%;background:#c8cfdb;position:relative;flex:none}
.qd-pill.qd-on .qd-dot{background:#10b981}
.qd-pill.qd-on .qd-dot::after{content:'';position:absolute;inset:-3px;border-radius:50%;
  border:1.5px solid #10b981;animation:qdRipple 1.6s ease-out infinite}
@keyframes qdRipple{0%{transform:scale(.6);opacity:1}100%{transform:scale(1.7);opacity:0}}
.qd-term{font-size:12px;line-height:1.8;max-height:300px;overflow:auto;background:#f8f9fc;
  border:1px solid #f0f2f8;border-radius:12px;padding:9px 13px;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.qd-term::-webkit-scrollbar{width:6px}
.qd-term::-webkit-scrollbar-thumb{background:#dde2ee;border-radius:3px}
.qd-log-line{display:flex;gap:9px;padding:4px 2px;border-bottom:1px dashed #eef1f6;align-items:flex-start}
.qd-log-line:last-child{border-bottom:none}
.qd-log-line .qd-t{color:#96a0b2;flex:none;font-size:11px;padding-top:2.5px;font-variant-numeric:tabular-nums}
.qd-log-line .qd-lv{width:6px;height:6px;border-radius:50%;background:#c8cfdb;flex:none;margin-top:8px}
.qd-log-line.qd-ok .qd-lv{background:#10b981}
.qd-log-line.qd-bad .qd-lv{background:#ef4444}
.qd-log-line.qd-warn .qd-lv{background:#f59e0b}
.qd-log-line.qd-sign .qd-lv{background:#6366f1}
.qd-log-line .qd-m{flex:1;word-break:break-all;color:#5c6575}
.qd-log-line.qd-ok .qd-m{color:#0a9e6e}
.qd-log-line.qd-bad .qd-m{color:#d93838}
.qd-log-line.qd-warn .qd-m{color:#b25e09}
.qd-log-line.qd-sign .qd-m{color:#5b5bd6;font-weight:600;font-family:inherit}
.qd-rec-table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:8px}
.qd-rec-table th{font-size:11px;color:#96a0b2;text-align:left;font-weight:600;padding:6px 8px;border-bottom:1px solid #f0f2f8}
.qd-rec-table td{padding:7px 8px;border-bottom:1px dashed #eef1f6;color:#5c6575}
.qd-rec-table tr:last-child td{border-bottom:none}
.qd-face-row{display:flex;gap:12px;align-items:flex-start}
.qd-face-box{flex:none;width:88px;height:88px;border:1.5px dashed #ccd3e0;border-radius:14px;cursor:pointer;
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;
  background:#fbfcfe;transition:.2s;overflow:hidden;position:relative}
.qd-face-box:hover{border-color:#a6abf5;background:#fafbff}
.qd-face-box .qd-face-ph{font-size:19px;line-height:1}
.qd-face-box .qd-face-t{font-size:10.5px;color:#96a0b2}
.qd-face-box.qd-done{border-style:solid;border-color:#c2e9d8;background:#ecf9f3}
.qd-face-box.qd-done .qd-face-ph{color:#0a9e6e}
.qd-mask{position:fixed;inset:0;background:rgba(21,26,38,.40);backdrop-filter:blur(3px);z-index:90;
  opacity:0;pointer-events:none;transition:.22s}
.qd-mask.qd-show{opacity:1;pointer-events:auto}
.qd-modal{position:fixed;top:50%;left:50%;transform:translate(-50%,-46%) scale(.97);width:min(640px,94vw);
  max-height:88vh;overflow:auto;background:#fff;border-radius:18px;padding:22px;z-index:91;
  opacity:0;pointer-events:none;transition:.24s cubic-bezier(.22,.9,.32,1);
  box-shadow:0 24px 70px -12px rgba(21,26,38,.32)}
.qd-modal.qd-show{opacity:1;pointer-events:auto;transform:translate(-50%,-50%) scale(1)}
.qd-modal h3{font-size:16px;font-weight:700;margin:0 0 4px;color:#151a26}
.qd-modal .qd-m-sub{font-size:12px;color:#96a0b2;margin-bottom:16px}
.qd-m-close{position:absolute;top:14px;right:14px;border:none;background:#f3f5fa;width:28px;height:28px;
  border-radius:8px;cursor:pointer;font-size:13px;color:#5c6575;transition:.15s}
.qd-m-close:hover{background:#e9ecf4;color:#151a26}
.qd-spin{display:inline-block;width:12px;height:12px;border:2px solid rgba(255,255,255,.4);
  border-top-color:#fff;border-radius:50%;animation:qdSpin .7s linear infinite;vertical-align:-2px}
@keyframes qdSpin{to{transform:rotate(360deg)}}
.qd-success{text-align:center;padding:34px 20px}
.qd-success .qd-big{width:56px;height:56px;margin:0 auto 14px;border-radius:18px;font-size:26px;
  display:flex;align-items:center;justify-content:center;background:#ecf9f3;color:#0a9e6e}
.qd-success h3{font-size:16px;font-weight:750;margin:0 0 6px;color:#151a26}
.qd-success .qd-suc-sub{font-size:12.5px;color:#96a0b2;line-height:1.8;margin-bottom:16px}
@media (max-width:640px){
  .qd-wrap{padding:18px 14px 22px;border-radius:16px}
  .qd-topbar h1{font-size:17.5px}
  .qd-card{padding:12px;border-radius:14px}
  .qd-acct{flex-wrap:wrap}
  .qd-acct .qd-info{flex:1 1 100%}
  .qd-acts{max-width:none;width:100%;justify-content:flex-start}
  .qd-chips{width:100%}
  .qd-pkgs{flex-direction:column}
}
`
  document.head.appendChild(st)
}

// 状态 → 胶囊/头像样式（对齐 qd_auto.php statusView）
function qdStatusMeta(o) {
  if (o.status === 'error') return { pill: 'qd-err', label: '开通失败', avt: 'qd-err' }
  if (o.status === 'processing') return { pill: 'qd-off', label: '开通中', avt: 'qd-idle' }
  if (o.status === 'done') return { pill: 'qd-off', label: '已结束', avt: 'qd-idle' }
  if (o.status === 'pending_on') return { pill: 'qd-wrn', label: '待上号', avt: 'qd-idle' }
  if (o.status === 'active') {
    if (o.engine_status === 'failed') return { pill: 'qd-err', label: '登录失败', avt: 'qd-err' }
    if (o.engine_status === 'stopped') return { pill: 'qd-wrn', label: '已暂停', avt: 'qd-idle' }
    if (o.engine_status === 'logged_in') return { pill: 'qd-wrn', label: '待启动', avt: 'qd-idle' }
    if (o.engine_status === 'running') return { pill: 'qd-on', label: '监控中', avt: 'qd-run' }
  }
  return { pill: 'qd-off', label: '同步中…', avt: 'qd-idle' }
}

function qdPill(o) {
  const m = qdStatusMeta(o)
  return h('span', { class: `qd-pill ${m.pill}` }, [h('span', { class: 'qd-dot' }), m.label])
}

function qdAvt(phone, o) {
  const m = qdStatusMeta(o)
  return h('div', { class: `qd-avt ${m.avt}` }, String(phone || '').slice(-4) || '??')
}

// 倒计时文本：服务到期
function qdEndText(o) {
  if (!o.end_at) return '服务期待同步'
  const days = Math.ceil((o.end_at * 1000 - Date.now()) / 86400000)
  if (days <= 0) return '今日到期'
  return `剩 ${days} 天 · ${fmtTime(o.end_at)} 到期`
}

// 服务大厅：开通页（顶栏 + 必读 + 套餐/表单 + 成功态）
function renderQdFlow(el) {
  qdInjectStyles()
  const view = h('div', { class: 'qd-wrap' })
  el.replaceChildren(view)

  view.appendChild(h('div', { class: 'qd-topbar' }, [
    h('div', { class: 'qd-bar' }),
    h('div', { class: 'qd-tt' }, [
      h('h1', null, '学习通自动签到'),
      h('div', { class: 'qd-sub' }, '全天候监控签到活动 · 位置/拍照/二维码/手势/签到码/人脸 全类型支持'),
    ]),
  ]))

  const readCard = h('div', { class: 'qd-card' }, [
    h('div', { class: 'qd-m-sec' }, '下单必读'),
    h('ul', { class: 'qd-read-list' }, [
      ['开通即监控', '登录成功后引擎自动监控该账号全部课程的签到活动，无需挂机'],
      ['按期计费', '周卡 7 天 / 月卡 30 天，服务期内不限签到次数，重新上号不再收费'],
      ['密码安全', '学习通密码仅用于登录与掉线重连，服务器 AES 加密存储，页面永不显示'],
      ['微信提醒', '可在订单详情绑定推送，签到结果实时推到微信'],
    ].map((item, i) => h('li', null, [h('span', { class: 'qd-n' }, String(i + 1)), h('span', null, [h('b', null, item[0] + '　'), item[1]])])),
    ),
  ])
  view.appendChild(readCard)

  const statusBox = h('div', { class: 'qd-card', style: 'text-align:center;color:#96a0b2;font-size:13px' }, '卡型加载中…')
  const formCard = h('div', { class: 'qd-card', style: 'display:none' })
  const resultBox = h('div')
  view.appendChild(statusBox)
  view.appendChild(formCard)
  view.appendChild(resultBox)

  let plans = []
  let selPlan = ''
  let submitting = false

  function drawCards() {
    const p = plans.find((x) => x.key === selPlan)
    formCard.replaceChildren(
      h('div', { class: 'qd-m-sec' }, '选择套餐'),
      h('div', { class: 'qd-pkgs' }, plans.map((x) => h('div', {
        class: 'qd-pkg' + (x.key === selPlan ? ' active' : ''),
        onclick: () => { selPlan = x.key; drawCards() },
      }, [
        h('div', { class: 'qd-pkg-n' }, x.name),
        h('div', { class: 'qd-pkg-m' }, `${fmtNum(x.price)} 积分 · ${x.days} 天`),
      ]))),
      h('form', { onsubmit: async (e) => {
        e.preventDefault()
        if (submitting) return
        const plan = plans.find((x) => x.key === selPlan)
        if (!plan || !phone.value.trim() || !pass.value) return
        submitting = true
        submitBtn.disabled = true
        submitBtn.innerHTML = '<span class="qd-spin"></span> 开通中'
        resultBox.replaceChildren()
        try {
          const v = await api.qdCreateOrder({ phone: phone.value.trim(), pass: pass.value, plan: selPlan, expected_points: plan.price })
          formCard.style.display = 'none'
          resultBox.replaceChildren(qdSuccessCard(v))
          window.scrollTo({ top: 0, behavior: 'smooth' })
        } catch (err) {
          resultBox.replaceChildren(h('div', { class: 'qd-hint', style: 'color:#d93838;padding:10px 2px' }, err.message))
          submitBtn.disabled = false
          submitBtn.textContent = '立即开通'
        } finally {
          submitting = false
        }
      } }, [
        h('div', { class: 'qd-field' }, [h('label', null, '学习通手机号'), phone]),
        h('div', { class: 'qd-field' }, [h('label', null, '学习通密码'), pass]),
        h('div', { class: 'qd-hint', style: 'margin:2px 0 16px' },
          `开通即扣除 ${fmtNum(p ? p.price : 0)} 积分（余额在右上角），服务期内重新上号免费；遇到需要人脸的签到，开通后在订单详情上传一次档案照片即可。`),
        h('div', { style: 'display:flex;gap:10px;align-items:center;flex-wrap:wrap' }, [
          submitBtn,
          h('a', { class: 'qd-btn qd-btn-sm', style: 'text-decoration:none', href: '/agent#qd' }, '我的签到订单'),
        ]),
      ]),
    )
    formCard.style.display = ''
  }

  const phone = h('input', { class: 'qd-input', placeholder: '学习通手机号', autocomplete: 'username', inputmode: 'numeric' })
  const pass = h('input', { class: 'qd-input', placeholder: '学习通密码', type: 'password', autocomplete: 'current-password' })
  const eye = h('button', { class: 'qd-eye', type: 'button', tabindex: '-1', onclick: () => {
    pass.type = pass.type === 'password' ? 'text' : 'password'
    eye.textContent = pass.type === 'password' ? '👁' : '🙈'
  } }, '👁')
  const passWrap = h('div', { class: 'qd-pw-wrap' }, [pass, eye])
  const submitBtn = h('button', { class: 'qd-btn qd-btn-go', type: 'submit' }, '立即开通')

  function qdSuccessCard(v) {
    const okOpen = v.status === 'active'
    const pending = v.status === 'pending_on'
    return h('div', { class: 'qd-card qd-success', style: 'animation:qdRise .5s cubic-bezier(.22,.9,.32,1) both' }, [
      h('div', { class: 'qd-big' }, okOpen ? '✓' : (pending ? '⏳' : '!')),
      h('h3', null, okOpen ? '开通成功，自动监控已启动' : (pending ? '下单成功，等待上号' : '请查看订单状态')),
      h('div', { class: 'qd-suc-sub' }, [
        h('div', null, `手机号 ${v.phone} · ${v.plan_name} · 已扣 ${fmtNum(v.price_points)} 积分`),
        v.last_msg ? h('div', null, v.last_msg) : null,
        pending ? h('div', { style: 'color:#b25e09' }, '学习通密码可能有误：去个人中心「自动签到」点重新上号即可免费重试') : null,
      ]),
      h('div', { style: 'display:flex;gap:10px;justify-content:center;flex-wrap:wrap' }, [
        h('a', { class: 'qd-btn qd-btn-go', style: 'text-decoration:none', href: '/agent#qd' }, '查看监控状态 / 实时日志'),
      ]),
    ])
  }

  ;(async () => {
    try {
      const d = await api.qdPlans()
      plans = Array.isArray(d.plans) ? d.plans : []
    } catch (e) {
      statusBox.textContent = e.status === 503 ? '自动签到功能暂未开放' : '加载失败：' + e.message
      return
    }
    if (!plans.length) {
      statusBox.textContent = '暂无可用的卡型，请稍后再试'
      return
    }
    statusBox.remove()
    selPlan = plans[0].key
    drawCards()
  })()
}

// ---------- 个人中心：自动签到工作台 ----------

let qdDetailCleanup = null // 详情弹窗的轮询定时器（切页时清理）

function qdCloseDetail() {
  if (qdDetailCleanup) {
    qdDetailCleanup()
    qdDetailCleanup = null
  }
  document.querySelectorAll('.qd-mask,.qd-modal').forEach((n) => n.remove())
}

// 个人中心：账号卡片墙 + 详情弹窗（日志终端 / 签到记录 / 重新上号 / 人脸）
function renderQdOrders(panel) {
  // 自动签到的完整工作台（日志/推送/人脸/重新上号）在独立单页 /qd，
  // 这里只保留快速入口，避免两套界面功能不同步。
  qdInjectStyles()
  panel.replaceChildren(h('div', { class: 'qd-wrap', style: 'text-align:center;padding:40px 20px' }, [
    h('div', { style: 'width:56px;height:56px;margin:0 auto 14px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:26px;background:#eef0fe;color:#5b5bd6' }, '⏰'),
    h('div', { style: 'font-size:15px;font-weight:700;margin-bottom:6px' }, '学习通自动签到工作台'),
    h('div', { style: 'font-size:12.5px;color:#96a0b2;line-height:1.8;margin-bottom:18px' },
      '监控状态 · 实时日志 · 微信推送 · 人脸照片 · 重新上号，全部在独立工作台页管理'),
    h('a', { class: 'qd-btn qd-btn-go', style: 'text-decoration:none', href: '/qd' }, '打开签到工作台 →'),
  ]))
}

// 个人中心：病例订单列表
function renderBingOrders(panel) {
  let items = null
  const body = h('tbody')
  const cardsBox = h('div', { class: 'ag-cards' })
  const mobile = isMobileView()

  const load = async () => {
    try {
      const d = await api.bingOrders()
      items = Array.isArray(d.items) ? d.items : []
      draw()
    } catch (e) {
      showToast('加载失败：' + e.message, 'error')
    }
  }

  const detailBtn = (o) => h('button', { class: 'btn btn-sm', onclick: () => renderBingOrderDetail(panel, o.order_id) }, '详情')
  const imageBtn = (o) => o.image_url
    ? h('a', { class: 'btn btn-sm', href: o.image_url, target: '_blank', rel: 'noopener' }, '查看图片')
    : h('span', { class: 'progress-tip' }, o.status === 'error' ? '已退款' : '生成中')
  const retryBtn = (o) => o.status === 'processing'
    ? h('button', { class: 'btn btn-sm', onclick: async (e) => {
        const btn = e.currentTarget
        btn.disabled = true; btn.textContent = '重试中…'
        try {
          await api.bingRetryOrder(o.order_id)
          showToast('已重新提交生成', 'success')
          await load()
        } catch (err) {
          showToast(err.message, 'error')
          btn.disabled = false; btn.textContent = '重试'
        }
      } }, '重试')
    : null

  function draw() {
    if (!items) return
    if (!items.length) {
      const node = h('div', { class: 'empty' }, '还没有病例订单，去首页【病例】下一单吧')
      if (mobile) cardsBox.replaceChildren(node)
      else body.replaceChildren(h('tr', null, h('td', { colSpan: 6 }, node)))
      return
    }
    if (mobile) {
      cardsBox.replaceChildren(...items.map((o) => h('div', { class: 'ag-card' }, [
        h('div', { class: 'ag-card-top' }, [h('b', null, o.application_name || o.application_id), bingStatusBadge(o)]),
        h('div', { class: 'ag-card-line' }, [
          h('span', null, `扣 ${fmtNum(o.price_points)} 积分`),
          h('span', { class: 'ag-card-money' }, fmtTime(o.created_at)),
        ]),
        h('div', { class: 'ag-card-line', style: 'gap:8px;flex-wrap:wrap' }, [detailBtn(o), imageBtn(o), retryBtn(o)]),
      ])))
    } else {
      body.replaceChildren(...items.map((o) => h('tr', {}, [
        h('td', null, fmtTime(o.created_at)),
        h('td', null, o.application_name || o.application_id),
        h('td', null, fmtMoney(o.amount_fen)),
        h('td', null, bingStatusBadge(o)),
        h('td', null, imageBtn(o)),
        h('td', null, h('div', { style: 'display:flex;gap:8px' }, [detailBtn(o), retryBtn(o)])),
      ])))
    }
  }

  const refreshBtn = h('button', { class: 'btn btn-sm', onclick: load }, '↻ 刷新')
  const listWrap = mobile
    ? cardsBox
    : h('div', { class: 'agent-table-wrap ag-table-card' }, h('table', { class: 'agent-table' }, [
      h('thead', null, h('tr', null, ['时间', '模板', '金额', '状态', '图片', '操作'].map((x) => h('th', null, x)))),
      body,
    ]))
  panel.replaceChildren(h('section', null, [
    panelHead('病例', '病例图片订单；图片上游仅保留 3 天，请及时保存', h('span', null, [refreshBtn])),
    listWrap,
  ]))
  load()
}

// 个人中心：病例订单详情（含本人填写的字段）
function renderBingOrderDetail(panel, orderId) {
  const backBtn = h('button', { class: 'btn btn-sm', onclick: () => renderBingOrders(panel) }, '← 返回列表')
  panel.replaceChildren(h('section', null, [
    panelHead('病例订单详情', `订单号 ${orderId}`, backBtn),
    h('div', { class: 'empty' }, '加载中…'),
  ]))

  ;(async () => {
    let o
    try {
      o = await api.bingOrder(orderId)
    } catch (e) {
      panel.replaceChildren(h('section', null, [panelHead('病例订单详情', '', backBtn), h('div', { class: 'empty' }, '加载失败：' + e.message)]))
      return
    }
    let labels = {}
    try {
      const d = await api.bingApplication(o.application_id)
      ;(d.fields || []).forEach((f) => { labels[f.field_key] = f.label || f.field_key })
      if (d.stamp) {
        if (d.stamp.org_field) labels[d.stamp.org_field] = '印章名称'
        if (d.stamp.code_field) labels[d.stamp.code_field] = '印章文字'
      }
    } catch {}

    const retry = async (btn) => {
      btn.disabled = true; btn.textContent = '重试中…'
      try {
        await api.bingRetryOrder(orderId)
        showToast('已重新提交生成', 'success')
        renderBingOrderDetail(panel, orderId)
      } catch (e) {
        showToast(e.message, 'error')
        btn.disabled = false; btn.textContent = '重试生成'
      }
    }

    const info = h('div', { class: 'form-grid' }, [
      h('div', { class: 'field' }, [h('label', null, '模板'), h('div', null, o.application_name || o.application_id)]),
      h('div', { class: 'field' }, [h('label', null, '状态'), bingStatusBadge(o)]),
      h('div', { class: 'field' }, [h('label', null, '扣积分'), h('div', null, fmtNum(o.price_points))]),
      h('div', { class: 'field' }, [h('label', null, '下单时间'), h('div', null, fmtTime(o.created_at))]),
    ])

    const fieldsBox = h('div', { style: 'margin-top:14px' })
    const entries = Object.entries(o.fields || {})
    if (entries.length) {
      fieldsBox.appendChild(h('div', { class: 'syy-subtitle' }, '填写内容'))
      fieldsBox.appendChild(h('div', { class: 'agent-table-wrap' }, h('table', { class: 'agent-table' }, [
        h('thead', null, h('tr', null, [h('th', null, '字段'), h('th', null, '内容')])),
        h('tbody', null, entries.map(([k, v]) => h('tr', null, [h('td', null, labels[k] || k), h('td', { style: 'white-space:pre-wrap;word-break:break-word' }, v)]))),
      ])))
    }

    const children = [info]
    if (o.image_ready && o.image_url) {
      children.push(h('img', {
        src: o.image_url, alt: '病例图片',
        style: 'max-width:100%;border-radius:12px;border:1px solid var(--border);margin-top:16px',
        onerror: (e) => { e.target.replaceWith(h('div', { class: 'tip' }, '图片加载失败，可能仍在生成，请稍后刷新')) },
      }))
      children.push(h('div', { style: 'margin-top:12px;display:flex;gap:10px;flex-wrap:wrap' }, [
        h('a', { class: 'btn btn-primary btn-sm', href: o.image_url, download: `病例-${o.order_id}.png`, target: '_blank', rel: 'noopener' }, '保存图片'),
      ]))
      children.push(h('div', { class: 'tip', style: 'margin-top:10px' }, '图片仅本人可见，上游云端保留 3 天，请及时保存。'))
    } else {
      children.push(h('div', { class: 'tip', style: 'margin-top:14px' }, o.render_error ? '图片暂未生成：' + o.render_error : (o.status === 'processing' ? '上游处理中，图片稍后生成。' : '订单已完成，图片仍在准备中。')))
      children.push(h('div', { style: 'margin-top:10px;display:flex;gap:10px;flex-wrap:wrap' }, [
        o.status === 'processing' ? h('button', { class: 'btn btn-sm', onclick: (e) => retry(e.currentTarget) }, '重试生成') : null,
        h('button', { class: 'btn btn-sm', onclick: () => renderBingOrderDetail(panel, orderId) }, '刷新状态'),
        h('a', { class: 'btn btn-sm', href: `/api/bing/orders/${encodeURIComponent(o.order_id)}/image`, target: '_blank', rel: 'noopener' }, '尝试打开图片'),
      ]))
    }
    if (o.remark) children.push(h('div', { class: 'tip', style: 'margin-top:10px' }, '备注：' + o.remark))
    children.push(fieldsBox)
    panel.replaceChildren(h('section', null, [panelHead('病例订单详情', `订单号 ${o.order_id}`, backBtn), ...children]))
  })()
}

function openSection(key) {
  const el = $('#home-view')
  if (key === 'platforms') {
    // 主流网课已下线，入口并入综合网课
    renderMoreFlow(el)
  } else if (key === 'cold') {
    renderMoreFlow(el) // 综合网课：冷门平台列表页
  } else if (key === 'intern') {
    renderSxdkFlow(el) // 实习打卡（独立模块）
  } else if (key === 'sport') {
    renderSportFlow(el) // 我爱运动（套娃下游对接天河 syytianheban）
  } else if (key === 'case') {
    renderBingliFlow(el) // 病例（独立模块）
  } else if (key === 'qd') {
    renderQdFlow(el) // 学习通自动签到（独立模块，对接签到引擎）
  } else {
    const title = PLACEHOLDER_TITLES[key] || '服务'
    el.replaceChildren(h('div', { class: 'home-view' }, [
      h('div', { class: 'subnav' }, [
        h('a', { class: 'back-link', href: '#', onclick: (e) => { e.preventDefault(); showHomeGrid() } }, '← 返回服务首页'),
        h('span', { class: 'subnav-title' }, title),
      ]),
      h('div', { class: 'page-head' }, [h('h1', null, title), h('p', null, '功能开发中，敬请期待')]),
      h('div', { class: 'placeholder-box' }, [h('div', { class: 'placeholder-emoji' }, '🚧'), h('div', { class: 'placeholder-title' }, '正在开发中'), h('div', { class: 'placeholder-sub' }, '该板块即将上线，敬请期待')]),
    ]))
  }
}

/* ---------------- 三步查课下单流程 ---------------- */

const PF = {
  platform: '',
  scope: 'all', // 'all' 主流入口（主流+冷门都显示）| 'more' 冷门入口（只显示冷门平台）
  loading: false,
  creating: false,
  result: null,
  selected: [],
  search: '',
  filter: 'all',
  shownCount: 50, // 课程列表分批渲染：当前已显示条数（防大单一次性渲染卡顿）
  restored: false,
  pollTimer: null,
  es: null,
}

function renderPurchaseFlow() {
  homeGridActive = false
  const el = $('#home-view')
  const view = h('div', { class: 'home-view' })

  if (!PF.scope) PF.scope = 'all'

  // 子导航
  view.appendChild(h('div', { class: 'subnav' }, [
    h('a', { class: 'back-link', href: '#', onclick: (e) => { e.preventDefault(); resetPurchase(); showHomeGrid() } }, '← 返回服务首页'),
    h('span', { class: 'subnav-title' }, PF.scope === 'more' ? '综合网课' : '网课平台'),
  ]))
  view.appendChild(h('div', { class: 'page-head' }, [h('h1', null, '查课下单'), h('p', null, '选择平台 → 填写账号 → 勾选课程用积分支付，提交后自动处理')]))
  PF.renderCards = null // 占位，renderPlatformCards 内赋值

  // ① 平台选择
  const grid = h('div', { class: 'platform-grid' })
  const moreWrap = h('div', { style: 'margin-top:18px' }) // 综合网课分区
  const noteEl = h('div', { class: 'platform-note' })

  function redrawCards() {
    renderPlatformCards()
    updateStep2Desc()
    updatePlatformNote()
  }
  PF.renderCards = redrawCards

  function renderPlatformCards() {
    grid.replaceChildren()
    moreWrap.replaceChildren()

    // 冷门入口（scope='more'）：商品已在选择框里选好，这里只展示当前商品，可随时切换
    if (PF.scope === 'more') {
      const it = PF.moreItem || { name: PF.platform }
      const name = it.name || ''
      if (!name) {
        grid.appendChild(h('div', { class: 'empty', style: 'grid-column:1/-1;padding:30px 12px' }, '请先在综合网课里选择一个商品'))
        return
      }
      const icon = PLATFORM_ICONS[name] || FALLBACK_ICON
      // 冷门商品无价格信息（已下架/同步失败/恢复中缺失）时不回退默认价，卡片直接标「已下架」
      const fee = (it.price_fen != null ? it.price_fen : platformPrices[name])
      const money = fee != null ? parseFloat((fee / 100).toFixed(2)) : null
      grid.appendChild(h('div', { class: 'platform-card active' }, [
        h('div', { class: 'platform-icon', style: `background:${icon.bg}` }, h('svg', { viewBox: '0 0 24 24', fill: 'none', stroke: icon.fg, 'stroke-width': '1.7', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', style: 'width:24px;height:24px', html: icon.svg })),
        h('span', { class: 'platform-name' }, PLATFORM_DISPLAY_NAMES[name] || name),
        money != null
          ? h('span', { class: 'platform-price', title: `${name} 单价 ¥${money} / 门` }, `¥${money}/门`)
          : h('span', { class: 'platform-price', style: 'color:var(--danger)' }, '已下架'),
        h('span', { class: 'platform-check' }, '✓'),
      ]))
      if (PF._module) {
        moreWrap.appendChild(h('div', { style: 'text-align:center;margin-top:12px' }, h('button', { class: 'btn btn-sm', onclick: () => showModuleSelect(PF._module) }, '切换同平台其它商品')))
      }
      // 冷门商品无价格信息（已下架/同步失败/恢复中缺失）时明确提示，避免按默认价结算
      if (!PF.moreItem || PF.moreItem.price_fen == null) {
        moreWrap.appendChild(h('div', { style: 'margin-top:10px;text-align:center;color:var(--danger);font-size:13px' }, '⚠ 该商品已下架或不可下单，请返回重新选择'))
      }
      return
    }

    // 主流入口：只显示 8 大平台（不混冷门）
    if (!PF.platform && platforms.length > 0) PF.platform = platforms[0]
    for (const name of platforms) {
      const icon = PLATFORM_ICONS[name] || FALLBACK_ICON
      const fee = platformPrices[name] ?? priceFen
      const money = parseFloat((fee / 100).toFixed(2))
      grid.appendChild(h('div', {
        class: 'platform-card' + (PF.platform === name ? ' active' : ''),
        onclick: () => {
          if (name === PF.platform) return
          resetPurchase()
          PF.platform = name
          redrawCards()
          renderTaskArea()
          renderResultArea()
          // 手机上选完平台把账号表单滚入视野，免去手动长距离滚动
          if (isMobileView()) {
            const s2 = document.getElementById('pf-step2')
            if (s2) s2.scrollIntoView({ behavior: 'smooth', block: 'start' })
          }
        },
      }, [
        h('div', { class: 'platform-icon', style: `background:${icon.bg}` }, h('svg', { viewBox: '0 0 24 24', fill: 'none', stroke: icon.fg, 'stroke-width': '1.7', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', style: 'width:24px;height:24px', html: icon.svg })),
        h('span', { class: 'platform-name' }, PLATFORM_DISPLAY_NAMES[name] || name),
        h('span', { class: 'platform-price', title: `${name} 单价 ¥${money} / 门` }, `¥${money}/门`),
        h('span', { class: 'platform-check' }, '✓'),
      ]))
    }
  }

  view.appendChild(h('section', { class: 'card step-card' }, [
    h('div', { class: 'step-head' }, [
      h('span', { class: 'step-no' }, '1'),
      h('div', null, [h('h3', { class: 'step-title' }, '选择平台'), h('p', { class: 'step-desc' }, '各平台单价与服务范围不同，卡片上已标注')]),
    ]),
    grid,
    moreWrap,
    noteEl,
  ]))

  // ② 账号表单
  view.appendChild(h('section', { class: 'card step-card', id: 'pf-step2' }, [
    h('div', { class: 'step-head' }, [
      h('span', { class: 'step-no' }, '2'),
      h('div', null, [h('h3', { class: 'step-title' }, '填写账号信息')]),
    ]),
    h('form', { class: 'step-form', id: 'pf-form' }, [
      h('div', { class: 'form-grid' }, [
        h('div', { class: 'field' }, [
          h('label', null, '账号'),
          h('div', { class: 'input-wrap' }, [
            h('span', { class: 'input-icon', html: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' }),
            h('input', { class: 'input', id: 'pf-username', placeholder: '用户名 / 手机号 / 学号', autocomplete: 'off', required: true }),
          ]),
        ]),
        h('div', { class: 'field' }, [
          h('label', null, '密码'),
          h('div', { class: 'input-wrap' }, [
            h('span', { class: 'input-icon', html: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>' }),
            h('input', { class: 'input', id: 'pf-password', type: 'password', placeholder: '••••••••', autocomplete: 'off', required: true, style: 'padding-right:40px' }),
            h('button', { type: 'button', class: 'input-action', tabindex: '-1', onclick: () => { const t = $('#pf-password').type; $('#pf-password').type = t === 'password' ? 'text' : 'password' } }, '👁'),
          ]),
        ]),
      ]),
      h('div', { class: 'field' }, [
        h('label', null, ['学校', h('span', { style: 'font-weight:400;color:#94a3b8' }, '（选填）')]),
        h('div', { class: 'input-wrap' }, [
          h('span', { class: 'input-icon', html: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M22 10 12 5 2 10l10 5 10-5"/><path d="M6 12v5c0 1 2.5 3 6 3s6-2 6-3v-5"/></svg>' }),
          h('input', { class: 'input', id: 'pf-school', placeholder: '你的学校全称', autocomplete: 'off' }),
        ]),
      ]),
      h('div', { class: 'field field-remark' }, [
        h('label', null, ['备注', h('span', { style: 'font-weight:400;color:#94a3b8' }, '（选填，如时长、分数要求）')]),
        h('div', { class: 'input-wrap' }, [
          h('span', { class: 'input-icon', html: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>' }),
          h('input', { class: 'input', id: 'pf-remark', placeholder: '如：10h，90分；u校园请按 "10h，90分" 格式填写', autocomplete: 'off' }),
        ]),
        h('div', { style: 'font-size:11.5px;color:#94a3b8;margin-top:6px' }, '💡 提示：不同平台备注格式不同，详见步骤1的平台说明'),
      ]),
      h('button', { class: 'btn btn-primary', type: 'submit', id: 'pf-submit' }, '查询课程 →'),
    ]),
  ]))

  // 任务状态区域（动态）
  const taskArea = h('div', { class: 'pf-task-area' })
  view.appendChild(taskArea)

  // ③ 结果区域（动态）
  const resultEl = h('section', { class: 'card step-card', id: 'pf-result' })
  view.appendChild(resultEl)

  el.replaceChildren(view)
  renderPlatformCards()
  updateStep2Desc()
  updatePlatformNote() // 首次进入即显示平台/商品说明

  // 账号/密码输入实时更新支付按钮可用性（恢复场景下输入后即可下单）
  // 兼容浏览器自动填充：同时监听 input / change，并延迟复核一次
  const upd = () => updatePayButton()
  const uEl = $('#pf-username'), pEl = $('#pf-password')
  uEl.addEventListener('input', upd); uEl.addEventListener('change', upd)
  pEl.addEventListener('input', upd); pEl.addEventListener('change', upd)
  setTimeout(upd, 500); setTimeout(upd, 1500)
  $('#pf-form').addEventListener('submit', handleSubmitAccount)

  // 恢复 URL ?task=
  const t = new URLSearchParams(window.location.search).get('task')
  if (t && /^[0-9a-f]{16,64}$/.test(t)) {
    PF.restored = true
    renderTaskStatus('正在后台抓取课程列表，请稍候…', '正在恢复上次的查询结果…')
    startTask(t)
  }
}

function resetPurchase() {
  clearInterval(PF.pollTimer)
  if (PF.es) { PF.es.close(); PF.es = null }
  PF.loading = false
  PF.result = null
  PF.selected = []
  PF.search = ''
  PF.filter = 'all'
  PF.shownCount = 50
  PF.restored = false
  PF.creating = false
  PF.pollTimer = null
  PF.platform = '' // 清掉主流↔冷门切换残留的平台，避免带着上次的冷门商品名去主流入口查课/下单
  history.replaceState(null, '', '/')
}

function cleanupPollers() {
  clearInterval(PF.pollTimer)
  PF.pollTimer = null
  if (PF.es) { PF.es.close(); PF.es = null }
}

function updateStep2Desc() {
  const d = $('#pf-step2-desc')
  if (d) d.textContent = `用于登录「${PLATFORM_DISPLAY_NAMES[PF.platform] || PF.platform || '所选平台'}」查询课程，结果仅保留 10 分钟；账号密码仅用于本次下单`
}

function updatePlatformNote() {
  const el = document.querySelector('#home-view .platform-note')
  if (!el) return
  // 冷门商品：查课页直接显示所选商品的上游介绍（.platform-note 的 :before 自带 💡）
  if (PF.scope === 'more' && PF.moreItem && PF.moreItem.note) {
    el.textContent = PF.moreItem.note
    return
  }
  el.textContent = PLATFORM_NOTES[PF.platform] ? `💡 ${PLATFORM_NOTES[PF.platform]}` : ''
}

function renderTaskStatus(main, sub) {
  const area = document.querySelector('.pf-task-area')
  const old = document.getElementById('pf-task-status')
  if (old) old.remove()
  area.appendChild(h('div', { class: 'task-status', style: 'margin-top:16px', id: 'pf-task-status' }, [
    h('span', { class: 'spinner' }),
    h('div', null, [main, sub ? h('span', { class: 'task-status-sub' }, sub) : null]),
  ]))
}

function renderTaskError(msg) {
  const area = document.querySelector('.pf-task-area')
  const old = document.getElementById('pf-fetch-err')
  if (old) old.remove()
  if (msg) area.appendChild(h('div', { class: 'error', style: 'margin-top:14px', id: 'pf-fetch-err' }, msg))
}

function renderTaskArea() {
  // 状态清理 + 提交按钮复位
  const status = document.getElementById('pf-task-status')
  if (status) status.remove()
  const err = document.getElementById('pf-fetch-err')
  if (err) err.remove()
  const btn = $('#pf-submit')
  if (btn) {
    btn.disabled = PF.loading
    btn.innerHTML = PF.loading ? '<span class="spinner"></span> 正在抓取…' : '查询课程 →'
  }
  if (PF.loading && !PF.result) {
    renderTaskStatus('正在后台抓取课程列表，请稍候…', '通常几秒到几分钟完成，结果会自动出现在下一步')
  } else if (PF.result && PF.result.status === 'error') {
    renderTaskError('抓取失败：' + PF.result.error)
  }
  updatePayButton()
}

async function handleSubmitAccount(e) {
  e.preventDefault()
  const username = $('#pf-username').value.trim()
  const password = $('#pf-password').value
  if (!username || !password) {
    renderTaskError('请填写账号和密码')
    return
  }
  const school = ($('#pf-school')?.value.trim() || '')
  const err = document.getElementById('pf-fetch-err')
  if (err) err.remove()
  PF.loading = true
  renderTaskArea()
  renderResultArea()
  try {
    const { task_id } = await api.submitAccount({ platform: PF.platform, username, password, school })
    history.replaceState(null, '', '/?task=' + task_id)
    startTask(task_id)
  } catch (err) {
    PF.loading = false
    renderTaskArea()
    renderTaskError(err.message)
  }
}

function startTask(taskId) {
  cleanupPollers()
  PF.loading = true
  renderTaskArea()
  // 提交/恢复任务后把任务状态区滚入视野（block:center 桌面手机都不突兀）
  const statusEl = document.getElementById('pf-task-status')
  if (statusEl) statusEl.scrollIntoView({ behavior: 'smooth', block: 'center' })
  const onResult = async (snapshot) => {
    PF.loading = false
    PF.result = snapshot
    await boolRestored() // 先等平台/商品价回填完成再渲染，避免显示一次错误的默认价
    renderTaskArea()
    renderResultArea()
  }
  const onTimeout = () => pollFallback(taskId)
  PF.es = subscribeTask(taskId, onResult, onTimeout)
}

// 恢复任务时回填平台状态。主流平台直接回填；冷门同步商品必须找回商品价
// （费率=more_products 入库价，与后端下单一致），否则结算区会错用默认价。
// 找不到 = 已下架/同步失败 → moreItem 置 null，交由结算区/支付按钮禁止下单。
async function boolRestored() {
  if (!(PF.restored && PF.result && PF.result.platform)) return
  PF.restored = false
  const p = PF.result.platform
  if (platforms.includes(p)) {
    PF.platform = p
    PF.scope = 'all'
    if (PF.renderCards) PF.renderCards()
    return
  }
  PF.platform = p
  PF.scope = 'more'
  PF.moreItem = null
  PF._module = null
  try {
    const items = await api.moreProducts('')
    const it = Array.isArray(items) ? items.find((x) => x && x.name === p) : null
    if (it) PF.moreItem = it
  } catch {}
  if (PF.renderCards) PF.renderCards()
}

function pollFallback(taskId) {
  clearInterval(PF.pollTimer)
  PF.pollTimer = null
  let count = 0
  PF.pollTimer = setInterval(async () => {
    count += 1
    let ok = false
    try {
      const snapshot = await api.getTask(taskId)
      if (snapshot.status === 'done' || snapshot.status === 'error') {
        PF.result = snapshot
        PF.loading = false
        await boolRestored()
        renderTaskArea()
        renderResultArea()
        ok = true
      }
    } catch (e) {
      // 404/401 视为终态，避免无效轮询 10 分钟
      if (e && (e.status === 404 || e.auth)) {
        PF.result = { status: 'error', error: e.message || '任务已失效，请重新提交' }
        PF.loading = false
        renderTaskArea()
        renderResultArea()
        ok = true
      }
    }
    if (ok || count >= POLL_MAX) {
      clearInterval(PF.pollTimer)
      PF.pollTimer = null
      PF.loading = false
      if (count >= POLL_MAX && !ok) {
        PF.result = { status: 'error', error: '抓取超时，请稍后重新提交' }
        renderTaskArea()
        renderResultArea()
      }
    }
  }, POLL_INTERVAL)
}

/* ---------------- ③ 课程列表 + 支付 ---------------- */

function refreshResult(preferFocus) {
  renderResultArea()
  if (preferFocus) {
    const s = document.getElementById('pf-search')
    if (s) { s.focus(); s.setSelectionRange(s.value.length, s.value.length) }
  }
}

function renderResultArea() {
  const resultEl = document.getElementById('pf-result')
  if (!resultEl) return
  const result = PF.result
  resultEl.replaceChildren()

  if (!result || result.status !== 'done') {
    return
  }
  const courses = result.courses || []
  const price = currentPriceFen() // 冷门商品不可用时为 null（结算区据此禁止下单）
  const balance = user?.points ?? 0
  const totalPoints = price != null ? (PF.selected.length * price) / 100 : 0
  const enough = price != null && balance >= totalPoints && PF.selected.length > 0

  const statusDone = courses.filter((c) => c.status === 'done').length
  const avgProgress = courses.length > 0 ? Math.round(courses.reduce((s, c) => s + (c.progress || 0), 0) / courses.length) : 0

  // 可见课程（搜索 + 状态筛选）。过滤规则只实现一份（visibleCourses），
  // 全选范围、计数、列表渲染共用 —— 搜索结果下点「全选可见」必须基于当前筛选计算，
  // 避免闭包里的旧 visibleIndices 勾到旧平台/旧筛选的索引集合
  const visibleCourses = () => courses.map((c, i) => ({ c, i })).filter(({ c }) => {
    if (PF.filter !== 'all' && c.status !== PF.filter) return false
    if (PF.search.trim()) {
      const q = PF.search.trim().toLowerCase()
      return (c.title || '').toLowerCase().includes(q) || (c.teacher || '').toLowerCase().includes(q)
    }
    return true
  })
  let visibleIndices = visibleCourses().map(({ i }) => i)
  let allVisibleSelected = visibleIndices.length > 0 && visibleIndices.every((i) => PF.selected.includes(i))

  resultEl.appendChild(h('div', { class: 'step-head' }, [
    h('span', { class: 'step-no' }, '3'),
    h('div', null, [h('h3', { class: 'step-title' }, '选择课程并支付'), h('p', { class: 'step-desc' }, '勾选要代做的课程，确认后积分即时扣减并进入处理队列')]),
    h('div', { class: 'step-head-side' }, PF.selected.length > 0
      ? (price != null ? `已选 ${PF.selected.length} 门 · ${totalPoints} 积分` : '该商品不可下单')
      : `共 ${courses.length} 门`),
  ]))

  if (PF.restored) {
    resultEl.appendChild(h('div', { class: 'platform-note', style: 'margin-bottom:14px' },
      '已为你恢复上次的查询结果（10 分钟内有效）。如需下单，请回到上一步确认该平台的账号密码。'))
  }

  if (courses.length === 0) {
    resultEl.appendChild(h('div', { class: 'empty' }, [h('div', { class: 'empty-icon' }, '📭'), h('div', { class: 'empty-title' }, '该账号下没有课程'), h('div', { style: 'color:#94a3b8;font-size:13px;margin-top:4px' }, '请确认账号平台是否选择正确')]))
    return
  }

  // 学习通课程来自上游，通常不带状态/进度，隐藏无意义的「已完成」「平均进度」统计
  const stats = [h('div', { class: 'stat' }, [h('div', { class: 'stat-value' }, courses.length), h('div', { class: 'stat-label' }, '课程总数')])]
  if (PF.platform !== '学习通') {
    stats.push(h('div', { class: 'stat' }, [h('div', { class: 'stat-value success' }, statusDone), h('div', { class: 'stat-label' }, '已完成')]))
    stats.push(h('div', { class: 'stat' }, [h('div', { class: 'stat-value' }, avgProgress + '%'), h('div', { class: 'stat-label' }, '平均进度')]))
  }
  resultEl.appendChild(h('div', { class: 'stats' }, stats))

  const list = h('ul', { class: 'course-list' })

  function buildList() {
    const vis = visibleCourses()
    if (vis.length === 0) {
      list.replaceChildren(h('li', { class: 'empty', style: 'padding:24px 0' }, '没有匹配的课程，试试更换筛选条件'))
      return
    }
    // 大单分批渲染：一次最多 50 条，避免手机上长列表（上限 500 门）卡顿；
    // 「显示更多」原地追加，不影响已勾选状态与全选可见范围（后者始终基于全量可见集）
    const shown = vis.slice(0, Math.max(1, PF.shownCount || 50))
    list.replaceChildren(...shown.map(({ c, i }) => {
      const isSel = PF.selected.includes(i)
      return h('li', {
        class: 'course-item' + (isSel ? ' selected' : ''),
        style: 'cursor:pointer',
        onclick: () => { toggleSelect(i); refreshResult(false) },
      }, [
        PF.platform !== '学习通' ? progressRing(c.progress) : null,
        h('div', { class: 'course-body' }, [
          h('div', { class: 'course-head' }, [
            h('span', { class: 'course-title', title: c.title }, c.title),
            (PF.platform !== '学习通' && STATUS_LABELS[c.status]) ? h('span', { class: `badge badge-${c.status}` }, STATUS_LABELS[c.status]) : null,
          ]),
          (c.teacher || c.school) ? h('div', { class: 'course-teacher' }, [c.school && `学校：${c.school}`, c.teacher && `老师：${c.teacher}`].filter(Boolean).join(' · ')) : null,
          c.deadline ? h('div', { class: 'course-deadline' }, `⏰ 截止 ${c.deadline}`) : null,
        ]),
        h('label', { class: 'course-check', title: '勾选课程', onclick: (e) => e.stopPropagation() }, [
          h('input', { type: 'checkbox', checked: isSel || undefined, onchange: () => { toggleSelect(i); refreshResult(false) } }),
        ]),
      ])
    }))
    if (shown.length < vis.length) {
      list.appendChild(h('li', { style: 'list-style:none;padding:14px 0;text-align:center' },
        h('button', { class: 'btn', onclick: () => { PF.shownCount = (PF.shownCount || 50) + 50; buildList() } },
          `显示更多（已显示 ${shown.length} / ${vis.length} 门）`)))
    }
  }

  // 工具栏
  const countLabel = h('span')
  function updCount() {
    countLabel.textContent = `已选 ${PF.selected.length} 门`
  }
  const allCb = h('input', { type: 'checkbox', checked: allVisibleSelected || undefined })
  allCb.addEventListener('change', () => {
    PF.selected = allCb.checked
      ? Array.from(new Set([...PF.selected, ...visibleIndices]))
      : PF.selected.filter((i) => !visibleIndices.includes(i))
    refreshResult(false)
  })
  // 「全选可见（N）」计数随搜索实时更新（搜索只重建列表、不整块重绘搜索框）
  const allSelCount = h('span')
  allSelCount.textContent = `全选可见（${visibleIndices.length}）`
  const searchInput = h('input', { class: 'input', id: 'pf-search', value: PF.search, placeholder: '搜索课名/老师', style: 'width:150px;padding:7px 10px;font-size:13px' })
  searchInput.addEventListener('input', debounce((e) => {
    PF.search = e.target.value
    // 只重建课程列表，不动搜索框这个 DOM 节点：
    // refreshResult 会整块重绘（把输入框也换掉），正在输入中文时
    // 输入法组合状态被打断，还没选候选拼音就变残留字母。
    // 但全选的可见范围 / 勾选状态 / 计数必须同步重算，否则搜索后点「全选可见」会用到旧索引集合
    visibleIndices = visibleCourses().map(({ i }) => i)
    PF.shownCount = 50 // 搜索结果集变化，分批计数回到初始
    allVisibleSelected = visibleIndices.length > 0 && visibleIndices.every((i) => PF.selected.includes(i))
    allCb.checked = allVisibleSelected
    allSelCount.textContent = `全选可见（${visibleIndices.length}）`
    buildList()
    updCount()
  }, 200))
  const mkPill = (key, label) => h('button', {
    class: 'filter-pill' + (PF.filter === key ? ' active' : ''),
    onclick: () => { PF.filter = key; PF.shownCount = 50; refreshResult(false) },
  }, label)

  resultEl.appendChild(h('div', { class: 'course-toolbar' }, [
    h('div', { class: 'course-toolbar-left' }, [
      h('label', { style: 'display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600' }, [allCb, allSelCount]),
      h('span', { style: 'color:#e2e8f0' }, '|'),
      countLabel,
    ]),
    h('div', { class: 'course-toolbar-actions' }, [
      searchInput,
      mkPill('all', '全部'),
      mkPill('in_progress', '进行中'),
      mkPill('done', '已完成'),
    ]),
  ]))
  resultEl.appendChild(list)
  buildList()
  updCount()

  // 底部：合计 + 支付（冷门商品不可用时不展示误导的合计，改提示并禁用按钮）
  const payTotal = h('span', { class: 'pay-total' })
  payTotal.replaceChildren(price != null
    ? ['合计 ', h('b', null, totalPoints), ` 积分 · 当前余额 ${fmtNum(balance)}`]
    : h('span', { style: 'color:var(--danger)' }, '该商品已下架或不可下单，请返回重新选择'))
  resultEl.appendChild(h('div', { class: 'step-footer' }, [
    payTotal,
    h('button', { class: 'btn btn-primary', type: 'button', id: 'pf-pay', onclick: () => handlePay() }, payButtonText()),
  ]))

  // 余额不足提示（商品不可用时显示「已下架」而非误导性的余额不足）
  const insuff = document.createElement('div')
  if (price != null && PF.selected.length > 0 && !enough) {
    insuff.appendChild(h('div', { class: 'insufficient', style: 'margin-top:14px' }, [
      '本次需 ', h('b', null, totalPoints), ' 积分，余额 ', h('b', null, fmtNum(balance)), '。请到',
      h('a', { href: '/agent' }, ' 个人中心 '), '兑换充值码或联系管理员充值。',
    ]))
  }
  resultEl.appendChild(insuff)
  updatePayButton()
}

// 当前结算单价（分）。主流平台用平台价/默认价；冷门商品用商品价（与后端 more_products 一致）。
// 冷门商品不可用（已下架/同步失败/恢复中缺失）时返回 null —— 结算区与支付按钮必须禁止下单，
// 避免前端按默认价结算而实际与后端扣费不一致。
function currentPriceFen() {
  if (PF.scope === 'more') {
    if (PF.moreItem && PF.moreItem.price_fen != null) return PF.moreItem.price_fen
    return null
  }
  return platformPrices[PF.platform] ?? priceFen
}

function payButtonText() {
  return PF.creating ? '正在创建订单…' : `积分支付并提交${PF.selected.length > 0 ? `（${PF.selected.length} 门）` : ''}`
}

function credFilled() {
  return !!($('#pf-username')?.value.trim()) && !!($('#pf-password')?.value)
}

function updatePayButton() {
  const btn = document.getElementById('pf-pay')
  if (!btn) return
  const price = currentPriceFen()
  if (price == null) {
    // 冷门商品无效：禁单并明确提示，避免按错误的默认价结算
    btn.disabled = true
    btn.textContent = '商品已下架，请重新选择'
    return
  }
  const totalPoints = (PF.selected.length * price) / 100
  const enough = (user?.points ?? 0) >= totalPoints && PF.selected.length > 0
  btn.disabled = PF.selected.length === 0 || PF.loading || PF.creating || !enough || !credFilled()
  btn.textContent = payButtonText()
}

function toggleSelect(idx) {
  PF.selected = PF.selected.includes(idx) ? PF.selected.filter((i) => i !== idx) : [...PF.selected, idx]
}

async function handlePay() {
  if (PF.creating) return
  // 纵深防御：按钮虽已禁用，重置仍是保险，避免绕过 UI 直接触发时按错误默认价结算
  if (currentPriceFen() == null) { showToast('该商品已下架或不可下单，请重新选择', 'warning'); return }
  const result = PF.result
  const chosen = (result && result.status === 'done' ? result.courses : []).filter((_, i) => PF.selected.includes(i))
  if (chosen.length === 0) return
  const username = $('#pf-username')?.value.trim() || ''
  const password = $('#pf-password')?.value || ''
  if (!username || !password) {
    showToast('请先填写账号和密码（恢复的查询结果不保留账号密码，已选课程不会扣费）', 'warning')
    return
  }
  PF.creating = true
  updatePayButton()
  try {
    await api.createOrder({
      platform: PF.platform,
      username,
      password,
      school: $('#pf-school')?.value.trim() || '',
      remark: $('#pf-remark').value.trim(),
      courses: chosen,
      pay_method: 'points',
      // 后端会用实时库价复核；价格在浏览期间变了会返回 409，不会按新价静默扣款
      expected_price_fen: currentPriceFen(),
    })
    showToast(`积分支付成功！已提交 ${chosen.length} 门课程`, 'success')
    // 清掉课程结果与轮询，防止同一批课程被再次勾选重复扣积分；
    // 结果区改为「下单成功」终态卡片，重新查课请回步骤 2 重新提交
    PF.selected = []
    PF.result = null
    PF.creating = false
    cleanupPollers()
    history.replaceState(null, '', '/')
    const me = await api.agentMe().catch(() => null)
    if (me) setUserState(me)
    renderTaskArea()
    renderResultArea()
    renderOrderOk(chosen.length, totalPointsText(chosen.length))
  } catch (err) {
    PF.creating = false
    // 409 = 商品价格已被后台更新：先刷新价格并重绘结算区，再提示用户确认
    if (err.status === 409) await refreshPrices()
    showToast(err.message, 'error')
    updatePayButton()
  }
}

// 后台改价后下单会返回 409：重新拉取配置/商品价并重绘结算区。
// 商品已下架时 moreItem 置 null，结算区会自动禁用支付按钮。
async function refreshPrices() {
  const [cf, items] = await Promise.all([
    api.config().catch(() => null),
    PF.scope === 'more' ? api.moreProducts('').catch(() => null) : Promise.resolve(null),
  ])
  if (cf) {
    if (cf.course_price_fen) priceFen = cf.course_price_fen
    if (cf.platform_prices) platformPrices = cf.platform_prices
    if (typeof cf.bing_enabled === 'boolean') bingEnabled = cf.bing_enabled
  }
  if (PF.scope === 'more' && Array.isArray(items)) {
    PF.moreItem = items.find((x) => x && x.name === PF.platform) || null
  }
  renderResultArea()
}

// 本次订单合计（元），用于成功卡片展示
function totalPointsText(count) {
  const price = currentPriceFen()
  return price != null ? ((count * price) / 100).toFixed(2) : ''
}

// 下单成功终态卡片（.order-ok 样式已存在于 main.css）
function renderOrderOk(count, yuanText) {
  const area = document.querySelector('.pf-task-area')
  if (!area) return
  const old = document.getElementById('pf-order-ok')
  if (old) old.remove()
  area.appendChild(h('div', { class: 'order-ok', id: 'pf-order-ok' }, [
    h('div', null, `✓ 下单成功：已提交 ${count} 门课程${yuanText ? `，扣 ${yuanText} 积分` : ''}`),
    h('div', { class: 'order-ok-sub' }, '课程已进入处理队列，可到个人中心「我的订单」查询进度。如需再下新单，请回到上方步骤重新查课。'),
  ]))
}

/* ==================== 进度环 ==================== */

function progressRing(pct, size = 42) {
  // size 参数化：课程列表用默认 42，订单进度小环传 30 —— 数值/坐标全部随 size 缩放，直径一致等比例
  const mid = size / 2
  const r = mid - 5.5 // 半径留出描边宽度，`42 → 15.5` 保持与原实现完全一致
  const c = 2 * Math.PI * r
  const v = Math.min(Math.max(pct || 0, 0), 100)
  const offset = c * (1 - v / 100)
  const stroke = v >= 100 ? 'var(--success)' : v === 0 ? 'var(--border-strong)' : 'var(--primary)'
  return h('svg', { width: size, height: size, viewBox: `0 0 ${size} ${size}`, class: 'ring' }, [
    h('circle', { cx: mid, cy: mid, r, class: 'ring-track' }),
    h('circle', { cx: mid, cy: mid, r, class: 'ring-fill', stroke, 'stroke-dasharray': c, 'stroke-dashoffset': offset, transform: `rotate(-90 ${mid} ${mid})` }),
    h('text', { x: mid, y: mid + 0.6, 'text-anchor': 'middle', 'dominant-baseline': 'middle', class: 'ring-text' }, Math.round(v)),
  ])
}

/* ==================== 个人中心（agent.html） ==================== */

let agentUserData = { orders: [], points: [], orderProgress: {} }
let currentAgentTab = 'overview'

function applyAgentView() {
  const auth = $('#agent-auth')
  const dash = $('#agent-dash')
  if (user === undefined) {
    auth.hidden = true
    dash.hidden = true
  } else if (user === null) {
    auth.hidden = false
    dash.hidden = true
  } else {
    auth.hidden = true
    dash.hidden = false
    // 支持 /agent#bing 直接打开指定标签（病例订单分享/跳转）
    const hashTab = (location.hash || '').replace(/^#/, '')
    if (/^[a-z]+$/.test(hashTab) && document.querySelector(`#ag-tabs .ag-nav-item[data-tab="${hashTab}"]`)) {
      currentAgentTab = hashTab
      document.querySelectorAll('#ag-tabs .ag-nav-item').forEach((b) => b.classList.toggle('active', b.dataset.tab === hashTab))
    }
    loadAgentData()
  }
}

let _agentLoading = false
async function loadAgentData() {
  if (_agentLoading) return
  _agentLoading = true
  try {
    const [me, ord, pt] = await Promise.all([api.agentMe(), api.agentOrders(), api.agentPoints()])
    // 直接更新 user 与导航，避免经 setUserState 再次触发 applyAgentView 形成递归
    user = me
    renderNav()
    agentUserData.orders = ord
    agentUserData.points = pt
    agentUserData.orderProgress = {} // 订单列表已刷新，作废旧的进度缓存
    renderAgentStats()
    renderAgentTab(currentAgentTab)
  } catch {}
  finally { _agentLoading = false }
}

function renderAgentStats() {
  const paidOrders = agentUserData.orders.filter((o) => o.status === 'paid')
  const spentFen = paidOrders.reduce((s, o) => s + (o.amount_fen || 0), 0)
  const processing = paidOrders.filter((o) => o.process_status !== 'done').length
  $('#ag-points').textContent = fmtNum(user?.points)
  $('#ag-stat-orders').textContent = agentUserData.orders.length
  $('#ag-stat-processing').textContent = processing
  $('#ag-stat-spent').textContent = `¥${(spentFen / 100).toFixed(2)}`
}

function initAgentTabs() {
  $('#ag-tabs').addEventListener('click', (e) => {
    const btn = e.target.closest('.ag-nav-item')
    if (!btn) return
    switchTab(btn.dataset.tab)
  })
  $('#ag-go-redeem').addEventListener('click', () => switchTab('redeem'))
  window.addEventListener('hashchange', () => {
    if (PAGE !== 'agent' || !user) return
    const tab = (location.hash || '').replace(/^#/, '')
    if (/^[a-z]+$/.test(tab) && document.querySelector(`#ag-tabs .ag-nav-item[data-tab="${tab}"]`)) switchTab(tab)
  })
}

function switchTab(tab) {
  currentAgentTab = tab
  document.querySelectorAll('#ag-tabs .ag-nav-item').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab))
  renderAgentTab(tab)
}

function renderAgentTab(tab) {
  const panel = $('#ag-panel')
  if (!panel) return
  if (tab === 'overview') renderOverview(panel)
  else if (tab === 'orders') renderOrders(panel)
  else if (tab === 'points') renderPoints(panel)
  else if (tab === 'redeem') renderRedeem(panel)
  else if (tab === 'sxdk') renderSxdkOrders(panel)
  else if (tab === 'sport') renderSyyOrders(panel)
  else if (tab === 'bing') renderBingOrders(panel)
  else if (tab === 'qd') renderQdOrders(panel)
  else if (tab === 'settings') renderSettings(panel)
}

function panelHead(title, desc, extra) {
  return h('div', { class: 'ag-panel-head' }, [
    h('div', null, [h('div', { class: 'ag-panel-title' }, title), desc ? h('div', { class: 'ag-panel-desc' }, desc) : null]),
    extra || null,
  ])
}

function tableHeader(cells) {
  return h('thead', null, h('tr', null, cells.map((c) => h('th', null, c))))
}

function renderOverview(panel) {
  const orders = agentUserData.orders
  panel.replaceChildren(h('section', null, [
    panelHead('账户总览', '余额与订单动态一目了然'),
    h('div', { class: 'ag-quick' }, [
      h('a', { class: 'ag-quick-card', href: '/' }, [h('span', { class: 'ag-quick-icon' }, '＋'), h('b', null, '提交新订单'), h('span', { class: 'ag-quick-sub' }, '选择平台查询课程进度后下单')]),
      h('button', { class: 'ag-quick-card', onclick: () => switchTab('orders') }, [h('span', { class: 'ag-quick-icon' }, '☰'), h('b', null, '查看我的订单'), h('span', { class: 'ag-quick-sub' }, '跟踪每笔订单的处理进度')]),
      h('button', { class: 'ag-quick-card', onclick: () => switchTab('redeem') }, [h('span', { class: 'ag-quick-icon' }, '✦'), h('b', null, '充值兑换'), h('span', { class: 'ag-quick-sub' }, '输入充值码即时到账')]),
    ]),
    h('h4', { class: 'ag-sub-title' }, '最近订单'),
    orders.length === 0
      ? h('div', { class: 'empty', style: 'padding:26px 16px' }, '还没有订单，去首页提交第一单吧')
      : h('ul', { class: 'ag-recent' }, orders.slice(0, 5).map((o) => h('li', {}, [
        h('span', { class: 'ag-recent-main' }, `${o.platform} · ${o.course_count} 门 · ¥${(o.amount_fen / 100).toFixed(2)}`),
        h('span', { class: 'ag-recent-side' }, fmtTime(o.created_at)),
        h('span', { class: 'badge ' + (o.status === 'paid' ? 'badge-done' : 'badge-progress') }, o.status === 'paid' ? (ORDER_STATUS[o.process_status] || '已付') : '待支付'),
      ]))),
    orders.length > 5 ? h('div', { style: 'text-align:right;margin-top:8px' }, [h('a', { href: '#', class: 'ag-link', onclick: (e) => { e.preventDefault(); switchTab('orders') } }, '查看全部 →')]) : null,
  ]))
}

function renderOrders(panel) {
  const orders = agentUserData.orders
  const mobile = isMobileView()
  const listNode = orders.length === 0
    ? h('div', { class: 'empty' }, ['还没有订单，', h('a', { href: '/', class: 'footer-link' }, '去首页下单')])
    : mobile
      // 手机：卡片式。表格 9 列横向滚动时进度/补刷按钮藏在最右侧，够不到
      ? h('div', { class: 'ag-cards' }, orders.map((o) => orderCard(o)))
      : h('div', { class: 'agent-table-wrap ag-table-card' }, h('table', { class: 'agent-table' }, [
        tableHeader(['时间', '平台', '账号', '门数', '金额', '支付方式', '状态', '进度', '备注']),
        h('tbody', null, orders.map((o) => {
          const progressTd = h('td', { class: 'agent-progress' })
          renderOrderProgressCell(progressTd, o)
          return h('tr', {}, [
            h('td', null, fmtTime(o.created_at)),
            h('td', null, o.platform),
            h('td', null, o.username),
            h('td', null, o.course_count),
            h('td', null, `¥${(o.amount_fen / 100).toFixed(2)}`),
            h('td', null, o.pay_method === 'points' ? '积分' : (o.pay_type || '在线')),
            h('td', null, h('span', { class: `badge ${o.status === 'paid' ? 'badge-done' : 'badge-progress'}` }, o.status === 'paid' ? '已支付' : '待支付')),
            progressTd,
            h('td', { class: 'agent-remark' }, o.remark || '-'),
          ])
        })),
      ]))
  panel.replaceChildren(h('section', null, [
    panelHead('我的订单', `共 ${orders.length} 条记录`, h('a', { href: '#', class: 'ag-link', onclick: (e) => { e.preventDefault(); loadAgentData() } }, '↻ 刷新状态')),
    listNode,
  ]))
}

// 手机订单卡片：字段与表格版一一对应；进度区复用 renderOrderProgressCell（含查询/补刷按钮与缓存逻辑）
function orderCard(o) {
  const progressBox = h('div', { class: 'ag-card-progress' })
  renderOrderProgressCell(progressBox, o)
  return h('div', { class: 'ag-card' }, [
    h('div', { class: 'ag-card-top' }, [
      h('b', null, o.platform),
      h('span', { class: 'ag-card-money' }, fmtMoney(o.amount_fen)),
    ]),
    h('div', { class: 'ag-card-line' }, [
      h('span', null, `${o.course_count} 门 · ${o.username}`),
      h('span', null, fmtTime(o.created_at)),
    ]),
    h('div', { class: 'ag-card-line' }, [
      h('span', { class: `badge ${o.status === 'paid' ? 'badge-done' : 'badge-progress'}` }, o.status === 'paid' ? '已支付' : '待支付'),
      h('span', null, o.pay_method === 'points' ? '积分支付' : (o.pay_type || '在线支付')),
    ]),
    progressBox,
    o.remark ? h('div', { class: 'ag-card-remark' }, o.remark) : null,
  ])
}

/* —— 上游实时进度：每笔订单手动查询，结果按 order_id 缓存 —— */

function renderOrderProgressCell(td, o) {
  td.replaceChildren()
  if (o.status !== 'paid') {
    td.textContent = '—'
    return
  }
  const st = (agentUserData.orderProgress || {})[o.order_id]
  if (st && st.loading) {
    td.appendChild(h('span', { class: 'progress-tip' }, '查询中…'))
    return
  }
  td.appendChild(h('button', { class: 'btn progress-btn', onclick: () => fetchOrderProgress(o.order_id, td) }, st ? '↻ 刷新' : '查询进度'))
  // 补刷：把该订单已下到上游的课程逐门触发重刷（仅已支付订单显示）
  td.appendChild(h('button', {
    class: 'btn progress-btn', style: 'margin-left:6px',
    disabled: (st && st.rebrushing) || undefined,
    onclick: () => rebrushOrder(o, td),
  }, st && st.rebrushing ? '补刷中…' : '补刷'))
  if (!st) return
  if (st.error) {
    td.appendChild(h('div', { class: 'progress-err' }, '⚠ ' + st.error))
    return
  }
  const items = st.items || []
  const errCount = items.filter((it) => it.error).length
  const tip = `已查 ${items.length} 门` + (errCount ? `，${errCount} 门失败` : '') + (st.skipped ? `，${st.skipped} 门未查` : '')
  td.appendChild(h('div', { class: 'progress-tip' }, tip))
  items.forEach((it) => td.appendChild(orderProgressLine(it)))
}

// 解析上游进度原文（"62%" / "100%" / "无"）为 0-100 数值；解析不出返回 null（不画环）。
function parseProgressPct(p) {
  if (p == null) return null
  const n = parseFloat(String(p).replace(/[%\s ]/g, ''))
  return Number.isFinite(n) && n >= 0 ? Math.min(n, 100) : null
}

function orderProgressLine(it) {
  // 查询失败 / 排队中：没有有意义的进度数字，不画环，只显示状态+内容
  const pct = (it.error || it.status === 'queued') ? null : parseProgressPct(it.process)
  const yid = it.yid ? ` 单号${it.yid}` : ''
  let text
  if (it.error) {
    text = '查询失败：' + it.error
  } else if (it.status === 'queued') {
    text = (it.remarks || '排队中') + yid
  } else {
    const parts = [(it.status || '执行中') + yid]
    if (it.process) parts.push(`进度 ${it.process}`)
    if (it.remarks) parts.push(it.remarks)
    text = parts.join(' · ')
  }
  return h('div', { class: 'progress-item' }, [
    // 环形百分比进度（30px 小环；无有效进度时不占位，文本左对齐）
    pct != null ? h('div', { class: 'progress-item-ring' }, progressRing(pct, 30)) : null,
    h('div', { class: 'progress-item-main' }, [
      h('span', { class: 'progress-title' }, it.title || '未知课程'),
      h('span', { class: 'progress-text' }, text),
    ]),
  ])
}

async function fetchOrderProgress(orderId, td) {
  if (!agentUserData.orderProgress) agentUserData.orderProgress = {}
  const st = agentUserData.orderProgress[orderId] || {}
  if (st.loading) return
  st.loading = true
  agentUserData.orderProgress[orderId] = st
  const order = agentUserData.orders.find((x) => x.order_id === orderId)
  if (order) renderOrderProgressCell(td, order)
  try {
    const data = await api.agentOrderProgress(orderId)
    agentUserData.orderProgress[orderId] = { loading: false, items: data.items, skipped: data.skipped }
  } catch (e) {
    agentUserData.orderProgress[orderId] = { loading: false, error: (e && e.message) || '查询失败' }
  }
  if (order) renderOrderProgressCell(td, order)
}

// 订单补刷：把该订单已下到上游的课程逐门触发重刷（上游 act=budan，按 yid）
async function rebrushOrder(o, td) {
  if (!confirm(`确定补刷订单 ${o.order_id.slice(0, 8)}… 的全部课程？将重新执行，请稍候。`)) return
  if (!agentUserData.orderProgress) agentUserData.orderProgress = {}
  const st = agentUserData.orderProgress[o.order_id] || {}
  if (st.rebrushing || st.loading) return
  st.rebrushing = true
  agentUserData.orderProgress[o.order_id] = st
  renderOrderProgressCell(td, o)
  try {
    const res = await api.agentOrderRebrush(o.order_id)
    let msg = `已提交补刷 ${res.success}/${res.total} 门`
    if (res.skipped > 0) msg += `（另有 ${res.skipped} 门未处理）`
    showToast(msg, res.failed > 0 ? 'warning' : 'success')
  } catch (e) {
    showToast('补刷失败：' + ((e && e.message) || '请稍后再试'), 'error')
  } finally {
    st.rebrushing = false
    renderOrderProgressCell(td, o)
  }
}

function renderPoints(panel) {
  const points = agentUserData.points
  panel.replaceChildren(h('section', null, [
    panelHead('积分流水', `最近 ${points.length} 条`),
    points.length === 0
      ? h('div', { class: 'empty' }, '暂无积分流水')
      : h('div', { class: 'agent-table-wrap ag-table-card' }, h('table', { class: 'agent-table' }, [
        tableHeader(['时间', '变动', '余额', '说明']),
        h('tbody', null, points.map((p) => h('tr', {}, [
          h('td', null, fmtTime(p.created_at)),
          h('td', { class: p.delta >= 0 ? 'point-in' : 'point-out' }, fmtSigned(p.delta)),
          h('td', null, fmtNum(p.balance_after)),
          h('td', null, p.reason + (p.order_id ? `（订单 ${p.order_id.slice(0, 8)}…）` : '')),
        ]))),
      ])),
  ]))
}

function renderRedeem(panel) {
  const input = h('input', { class: 'input ag-redeem-input', placeholder: '输入充值码，如 XXXX-XXXX-XXXX-XXXX', autocomplete: 'off', required: true })
  const okMsg = h('div')
  const errMsg = h('div')
  const submitBtn = h('button', { class: 'btn btn-primary ag-redeem-btn', type: 'submit' }, '兑换')
  const form = h('form', { class: 'ag-redeem-form' })
  form.appendChild(input)
  form.appendChild(submitBtn)
  form.addEventListener('submit', async (e) => {
    e.preventDefault()
    okMsg.replaceChildren()
    errMsg.replaceChildren()
    submitBtn.disabled = true
    try {
      const res = await api.agentRedeem(input.value.trim())
      okMsg.appendChild(h('div', { class: 'submit-success' }, `兑换成功，当前余额 ${res.points} 积分`))
      input.value = ''
      setUserState({ ...user, points: res.points })
      loadAgentData()
    } catch (err) {
      errMsg.appendChild(h('div', { class: 'error' }, err.message))
    } finally {
      submitBtn.disabled = false
    }
  })

  panel.replaceChildren(h('section', null, [
    panelHead('充值兑换', `当前余额 ${fmtNum(user?.points)} 积分`),
    form,
    okMsg,
    errMsg,
    h('h4', { class: 'ag-sub-title' }, '如何充值'),
    h('ol', { class: 'ag-steps' }, [
      h('li', null, '联系管理员确认充值金额与方式；'),
      h('li', null, '管理员发放充值码（一般通过 QQ / 微信发送）；'),
      h('li', null, '在上方输入充值码完成兑换，积分实时到账。'),
    ]),
    h('div', { class: 'agent-note' }, '充值码不区分大小写，带不带连字符均可识别；每个充值码只能使用一次。也可让管理员直接为账户充值。'),
  ]))
}

// 个人中心：实习打卡订单列表 + 同步进度（独立业务，不含课程订单）
function sxdkStatusBadge(status, code) {
  // status：本系统处理状态；code：上游同步状态（>=2 通常表示打卡已结束）
  let cls, label
  if (status === 'error') { cls = 'badge-banned'; label = '下单失败' }
  else if (status === 'pending') { cls = 'badge-progress'; label = '处理中' }
  else if (code >= 2) { cls = 'badge-done'; label = '已结束' }
  else { cls = 'badge-done'; label = '打卡中' }
  return h('span', { class: `badge ${cls}` }, label)
}

function renderSxdkOrders(panel) {
  let items = null
  const body = h('tbody')
  const cardsBox = h('div', { class: 'ag-cards' })
  const plats = new Map()
  const mobile = isMobileView() // 渲染时确定布局分支；rotate 后重进 tab 即刷新

  const showError = () => {
    const node = h('div', { class: 'empty' }, '加载失败')
    if (mobile) cardsBox.replaceChildren(node)
    else body.replaceChildren(h('tr', null, h('td', { colSpan: 8 }, node)))
  }

  const load = async () => {
    try {
      const d = await api.sxdkOrders()
      items = Array.isArray(d.items) ? d.items : []
      draw()
    } catch (e) {
      showToast('加载失败：' + e.message, 'error')
      showError()
    }
  }

  function draw() {
    if (!items) return
    if (!items.length) {
      const node = h('div', { class: 'empty' }, '还没有实习打卡订单，去首页【实习打卡】下一单吧')
      if (mobile) cardsBox.replaceChildren(node)
      else body.replaceChildren(h('tr', null, h('td', { colSpan: 8 }, node)))
      return
    }
    if (mobile) cardsBox.replaceChildren(...items.map((o) => sxdkCard(o, plats)))
    else body.replaceChildren(...items.map((o) => h('tr', {}, [
      h('td', null, plats.get(o.platform) || o.platform),
      h('td', null, o.phone),
      h('td', null, `${o.days} 天`),
      h('td', null, `¥${fmtNum(o.amount_fen / 100)}`),
      h('td', null, sxdkStatusBadge(o.status, o.code)),
      h('td', null, o.end_time || '-'),
      h('td', null, o.tianhe_id ? String(o.tianhe_id) : '-'),
      h('td', null, fmtTime(o.created_at)),
    ])))
  }

  const syncBtn = h('button', { class: 'btn btn-sm', onclick: async () => {
    if (syncBtn.disabled) return
    syncBtn.disabled = true; syncBtn.textContent = '同步中…'
    try {
      const r = await api.sxdkSync()
      showToast(`已同步 ${r.updated} 单`, 'success')
      await load()
    } catch (e) { showToast('同步失败：' + e.message, 'error') }
    finally { syncBtn.disabled = false; syncBtn.textContent = '↻ 同步进度' }
  } }, '↻ 同步进度')

  // 平台代号 → 名字（用于列表展示）
  ;(async () => {
    try {
      const d = await api.sxdkPlatforms()
      ;(Array.isArray(d.items) ? d.items : []).forEach((p) => plats.set(p.code, p.name))
      if (items) draw()
    } catch {}
  })()

  const listWrap = mobile
    ? cardsBox
    : h('div', { class: 'agent-table-wrap ag-table-card' }, h('table', { class: 'agent-table' }, [
      h('thead', null, h('tr', null, ['平台', '账号', '天数', '金额', '状态', '结束日期', '上游单号', '下单时间'].map((x) => h('th', null, x)))),
      body,
    ]))
  panel.replaceChildren(h('section', null, [
    panelHead('实习打卡', '打卡订单与进度（独立于课程订单）', syncBtn),
    listWrap,
  ]))
  load()
}

// 手机实习打卡卡片：字段与表格版一一对应
function sxdkCard(o, plats) {
  return h('div', { class: 'ag-card' }, [
    h('div', { class: 'ag-card-top' }, [
      h('b', null, plats.get(o.platform) || o.platform),
      sxdkStatusBadge(o.status, o.code),
    ]),
    h('div', { class: 'ag-card-line' }, [
      h('span', null, o.phone),
      h('span', { class: 'ag-card-money' }, `¥${fmtNum(o.amount_fen / 100)}`),
    ]),
    h('div', { class: 'ag-card-line' }, [
      h('span', null, `${o.days} 天`),
      h('span', null, o.end_time || '-'),
    ]),
    h('div', { class: 'ag-card-line' }, [
      h('span', null, o.tianhe_id ? `上游单号 ${o.tianhe_id}` : ''),
      h('span', null, fmtTime(o.created_at)),
    ]),
  ])
}

function renderSettings(panel) {
  const oldPw = h('input', { type: 'password', class: 'input', placeholder: '输入当前密码', autocomplete: 'current-password', required: true })
  const pw1 = h('input', { type: 'password', class: 'input', placeholder: '输入新密码', autocomplete: 'new-password', required: true })
  const pw2 = h('input', { type: 'password', class: 'input', placeholder: '再次输入新密码', autocomplete: 'new-password', required: true })
  const errMsg = h('div')
  const doneBox = h('div')
  const submitBtn = h('button', { class: 'btn btn-primary', style: 'margin-top:16px' }, '保存新密码')

  const form = h('form', { class: 'ag-pw-form' })
  form.appendChild(h('div', { class: 'form-grid', style: 'margin-top:12px' }, [
    h('div', { class: 'field' }, [h('label', null, '当前密码'), oldPw]),
    h('div', { class: 'field' }, [h('label', null, '新密码（8-64 位，须含字母和数字）'), pw1]),
  ]))
  form.appendChild(h('div', { class: 'field', style: 'margin-top:12px' }, [h('label', null, '确认新密码'), pw2]))
  form.appendChild(errMsg)
  form.appendChild(submitBtn)

  form.addEventListener('submit', async (e) => {
    e.preventDefault()
    errMsg.replaceChildren()
    if (pw1.value !== pw2.value) { errMsg.appendChild(h('div', { class: 'error' }, '两次输入的新密码不一致')); return }
    submitBtn.disabled = true
    try {
      await api.agentPassword(oldPw.value, pw1.value)
      form.hidden = true
      doneBox.replaceChildren(h('div', { class: 'ag-done-box' }, [
        h('div', { class: 'ag-done-title' }, '✓ 密码修改成功'),
        h('div', { class: 'ag-done-desc' }, '所有旧登录已失效，请使用新密码重新登录。'),
        h('button', { class: 'btn btn-primary', style: 'margin-top:12px', onclick: () => logout() }, '前往重新登录'),
      ]))
    } catch (err) {
      errMsg.appendChild(h('div', { class: 'error' }, err.message))
    } finally {
      submitBtn.disabled = false
    }
  })

  panel.replaceChildren(h('section', null, [
    panelHead('账号设置', '账户资料与安全'),
    h('div', { class: 'ag-profile' }, [
      h('div', { class: 'ag-profile-row' }, [h('span', { class: 'ag-profile-label' }, '用户名'), h('b', null, user.username)]),
      h('div', { class: 'ag-profile-row' }, [h('span', { class: 'ag-profile-label' }, '账户 ID'), h('b', null, '#' + user.id)]),
      h('div', { class: 'ag-profile-row' }, [h('span', { class: 'ag-profile-label' }, '联系方式'), h('b', null, user.contact || '-')]),
      h('div', { class: 'ag-profile-row' }, [h('span', { class: 'ag-profile-label' }, '可用积分'), h('b', null, fmtNum(user.points))]),
    ]),
    h('h4', { class: 'ag-sub-title' }, '修改密码'),
    doneBox,
    form,
    h('div', { class: 'agent-note' }, '改密成功后会自动退出所有设备，包括本机当前会话。'),
  ]))
}

/* ==================== 支付结果页（pay.html） ==================== */

function initPay() {
  const m = window.location.pathname.match(/^\/pay\/([0-9a-f]{16,64})\/?$/i)
  if (!m) {
    $('#pay-title').textContent = '订单不存在'
    $('#pay-status-text').textContent = '订单号可能有误，请从下单页面重新发起。'
    return
  }
  const orderId = m[1].toLowerCase()
  const POLL_MAX_PAY = 150
  let total = 0
  let interval = null
  const stop = () => { if (interval) { clearInterval(interval); interval = null } }

  // 立即查一次（多数情况下用户秒见结果），之后进入 2s 轮询
  const check = async () => {
    total += 1
    try {
      const o = await api.getOrder(orderId)
      if (o.status === 'paid') {
        stop()
        $('#pay-title').textContent = '支付成功'
        $('#pay-body').replaceChildren(
          h('div', { class: 'pay-amount success' }, `¥${o.amount}`),
          h('p', { class: 'pay-sub' }, ['订单已完成支付，课程提交成功，请等待管理员处理。', h('br'), `建议截图保存本页（订单号：${orderId}）。`]),
          h('span', { class: 'pay-ok-icon' }, '✓'),
        )
        $('#pay-foot').hidden = false
      } else {
        $('#pay-status-text').textContent = total >= POLL_MAX_PAY
          ? '长时间未确认到支付结果。如已完成付款请稍后刷新本页；未支付可返回重新下单。'
          : '支付完成后本页会自动更新，请勿关闭…'
        if (total >= POLL_MAX_PAY) stop()
        if (total >= POLL_MAX_PAY) $('#pay-foot').hidden = false
      }
    } catch (err) {
      // 401/404 终止轮询，其余网络抖动继续重试
      if (err.auth || err.status === 401 || err.status === 404) {
        stop()
        const notFound = !!err.auth || err.status === 401
        $('#pay-title').textContent = notFound ? '请先登录' : '订单不存在'
        $('#pay-body').replaceChildren(h('p', { class: 'pay-sub' },
          notFound ? '为保护订单信息安全，请使用下单时的账号登录后再查看。' : '订单号可能有误，请从下单页面重新发起。'))
        $('#pay-foot').innerHTML = notFound
          ? '<a class="btn btn-primary btn-block" href="/agent">去登录</a>'
          : '<a class="btn btn-outline btn-block" href="/">返回首页</a>'
        $('#pay-foot').hidden = false
      } else {
        $('#pay-status-text').textContent = total >= 5 ? '网络波动，重试中…' : '支付完成后本页会自动更新，请勿关闭…'
        if (total >= POLL_MAX_PAY) { stop(); $('#pay-foot').hidden = false }
      }
    }
  }
  check()
  interval = setInterval(check, POLL_INTERVAL)
  // 页面卸载时清理定时器，避免后台空转
  window.addEventListener('beforeunload', stop)
  window.addEventListener('pagehide', stop)
}

/* ==================== 启动 ==================== */

async function boot() {
  renderNav()

  if (PAGE === 'pay') {
    initPay()
    return
  }

  if (PAGE === 'index') applyIndexView()
  else if (PAGE === 'agent') applyAgentView()

  // 并行拉取平台与配置，避免串行阻塞首屏
  const cfgPromise = (async () => {
    try {
      const [pls, mps, cf] = await Promise.all([
        api.platforms().catch(() => []),
        api.morePlatforms().catch(() => []),
        api.config().catch(() => ({})),
      ])
      platforms = Array.isArray(pls) ? pls : []
      morePlatforms = Array.isArray(mps) ? mps.map((p) => (typeof p === 'string' ? { name: p } : p)) : []
      if (cf.course_price_fen) priceFen = cf.course_price_fen
      if (cf.platform_prices) platformPrices = cf.platform_prices
    if (typeof cf.bing_enabled === 'boolean') bingEnabled = cf.bing_enabled
    if (typeof cf.qd_enabled === 'boolean') qdEnabled = cf.qd_enabled
      const heroQueries = $('#hero-queries')
      if (heroQueries) heroQueries.textContent = platforms.length ? '1,200+' : '1,000+'
      // 若大厅已渲染但平台列表刚就绪，补一次刷新以更新卡片与单价
      if (PAGE === 'index' && $('#home-view')?.dataset.built && user != null) {
        if (platforms.length && !platforms.includes(PF.platform)) PF.platform = platforms[0] || PF.platform
        if (PF.renderCards) PF.renderCards()
        if (homeGridActive) showHomeGrid() // 平台数据就绪后重绘大厅卡片，修复「覆盖 0 个平台」竞态
        renderTaskArea()
        updatePayButton()
      }
    } catch {}
  })()

  api.agentMe()
    .then((me) => setUserState(me))
    .catch(() => setUserState(null))

  // 保险丝：无论什么原因（网络卡死 / 被掐断 / 标签页恢复冻结），
  // 4 秒后仍未确定登录态就强制落到「未登录」视图，绝不让「正在进入…」定格。
  setTimeout(() => {
    if (user === undefined) setUserState(null)
  }, 4000)

  if (PAGE === 'index') {
    initAuthForm('auth', (me) => { setUserState(me) })
  } else if (PAGE === 'agent') {
    initAuthForm('agent-auth', (me) => { setUserState(me); applyAgentView() })
    initAgentTabs()
  }
  await cfgPromise
}

document.addEventListener('DOMContentLoaded', boot)