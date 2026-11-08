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
}
const FALLBACK_ICON = { bg: '#f4f4f5', fg: '#52525b', svg: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>' }
const PLATFORM_DISPLAY_NAMES = { 智慧树: '智慧树/知道', MOOC: '中国大学mooc', pup: 'pup / 波妞' }

/* ==================== 全局状态 ==================== */

let platforms = []
let morePlatforms = [] // 综合网课平台/商品：[{name, price_fen?, note?}]（/more-platforms）
let priceFen = 300
let platformPrices = {}
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

// 服务大厅 4 个模块的卡片图标：与平台图标同风格的线性 SVG（白描边），
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
}

function showHomeGrid() {
  const el = $('#home-view')
  homeGridActive = true
  const sections = [
    ['platforms', '主流网课', `主流网课学习进度查询，覆盖 ${platforms.length} 个平台：学习通、智慧树、welearn 等`],
    ['cold', '综合网课', `小众平台课程进度，${morePlatforms.length ? '已支持 ' + morePlatforms.map((p) => p.name).join('、') : '待开发'}`],
    ['intern', '实习打卡', '实习打卡与签到，待开发'],
    ['sport', '我爱运动', '运动打卡与阳光体育，待开发'],
  ]
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
  let s = String(name || '').replace(/^(yl|一流|少年|龙龙|灵珠|毛豆|奶昔|曙光|小青|星星)[-－]?\s*/i, '')
  const i = s.search(/[（(\s【0-9]/)
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

const PLACEHOLDER_TITLES = { cold: '综合网课', intern: '实习打卡', sport: '我爱运动' }

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

function openSection(key) {
  const el = $('#home-view')
  if (key === 'platforms') {
    // 进主流入口先彻底重置查询状态（platform/结果/选中课程/冷门商品残留），
    // 避免把上次冷门商品平台名带进来误发到冷门商品、或默认价与冷门商品价错位
    resetPurchase()
    PF.scope = 'all'
    PF._module = null
    PF.moreItem = null
    renderPurchaseFlow(el)
  } else if (key === 'cold') {
    renderMoreFlow(el) // 综合网课：冷门平台列表页
  } else if (key === 'intern') {
    renderSxdkFlow(el) // 实习打卡（独立模块）
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
  // 冷门商品：查课页直接显示所选商品的天河介绍（.platform-note 的 :before 自带 💡）
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

  // 学习通课程来自天河，通常不带状态/进度，隐藏无意义的「已完成」「平均进度」统计
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
    })
    showToast(`积分支付成功！已提交 ${chosen.length} 门课程`, 'success')
    PF.selected = []
    PF.creating = false
    history.replaceState(null, '', '/')
    const me = await api.agentMe().catch(() => null)
    if (me) setUserState(me)
    refreshResult(false)
  } catch (err) {
    PF.creating = false
    showToast(err.message, 'error')
    updatePayButton()
  }
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

/* —— 天河实时进度：每笔订单手动查询，结果按 order_id 缓存 —— */

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
  // 补刷：把该订单已下到天河的课程逐门触发重刷（仅已支付订单显示）
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

// 解析天河进度原文（"62%" / "100%" / "无"）为 0-100 数值；解析不出返回 null（不画环）。
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

// 订单补刷：把该订单已下到天河的课程逐门触发重刷（上游 act=budan，按 yid）
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

  const interval = setInterval(async () => {
    total += 1
    try {
      const o = await api.getOrder(orderId)
      if (o.status === 'paid') {
        clearInterval(interval)
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
        if (total >= POLL_MAX_PAY) { clearInterval(interval); $('#pay-foot').hidden = false }
      }
    } catch (err) {
      // 401/404 终止轮询，其余网络抖动继续重试
      if (err.auth || err.status === 401 || err.status === 404) {
        clearInterval(interval)
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
        if (total >= POLL_MAX_PAY) { clearInterval(interval); $('#pay-foot').hidden = false }
      }
    }
  }, POLL_INTERVAL)
  // 页面卸载时清理定时器，避免后台空转
  window.addEventListener('beforeunload', () => clearInterval(interval))
  window.addEventListener('pagehide', () => clearInterval(interval))
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