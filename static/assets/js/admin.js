// admin.js —— 管理后台逻辑（admin.html）
// 看板 / 公告 / 平台价格 / 订单汇总 / 用户管理 / 充值码 / 流水审计
'use strict'

const adminState = {
  user: undefined, // undefined=加载中 null=未登录 {username}=已登录
  tab: 'dashboard',
  toast: null,
}

const PAY_METHOD_LABELS = { epay: '在线', points: '积分' }
const PROCESS_OPTIONS = [
  ['pending', '待处理'],
  ['processing', '处理中'],
  ['done', '已完成'],
]
const CODE_STATUS = {
  unused: ['badge-progress', '未使用'],
  used: ['badge-done', '已使用'],
  disabled: ['badge-gray', '已作废'],
}
const AUDIT_ACTIONS = {
  'user.points': '调整积分',
  'user.ban': '封禁用户',
  'user.unban': '解封用户',
  'user.reset_password': '重置密码',
  'user.delete': '删除用户',
  'order.process': '订单流转',
  'order.pause': '暂停上游任务',
  'order.credentials': '查看订单凭据',
  'redeem.generate': '生成充值码',
  'redeem.disable': '作废充值码',
  'ip.ban': '封禁IP',
  'ip.unban': '解封IP',
  'price.set': '设置平台价',
  'syy.price.set': '设置运动售价',
  'syy.price.reset': '恢复运动默认价',
  'more.price.set': '设置商品售价',
  'more.price.reset': '恢复商品默认价',
  'more.fenlei.hide': '删除货源分类',
  'more.fenlei.show': '恢复货源分类',
  'more.status.on': '上架商品',
  'more.status.off': '下架商品',
  'frontcat.create': '新建前台分类',
  'frontcat.update': '改前台分类',
  'frontcat.delete': '删前台分类',
  'frontcat.items.add': '分类挂载商品',
  'frontcat.items.remove': '分类移除商品',
  'bing.price.set': '设置病例售价',
  'bing.price.reset': '恢复病例默认价',
  'bing.enable': '开启病例功能',
  'bing.disable': '关闭病例功能',
  'qd.price.set': '设置自动签到售价',
  'qd.enable': '开启自动签到功能',
  'qd.disable': '关闭自动签到功能',
  'syscfg.save': '保存支付/对接配置',
  'announcement.create': '发布公告',
  'announcement.update': '更新公告',
  'announcement.delete': '删除公告',
}
let logsActiveTab = 'points' // 「流水与审计」当前子页，跨重复进入保留（避免每次重开都跳回积分流水）

// 注意：不要在这里再声明 const $ —— common.js 已声明，跨脚本重复 const 会让整段脚本
// 被浏览器按 SyntaxError 拒绝执行（历史事故：曾导致管理页白屏）。直接用全局 $。

/* ==================== Toast ==================== */

function notify(text, isError = false) {
  const wrap = document.createElement('div')
  wrap.className = 'toast' + (isError ? ' error' : '')
  wrap.textContent = text
  document.body.appendChild(wrap)
  setTimeout(() => wrap.remove(), 2600)
}

/* ==================== 登录 / 布局 ==================== */

function applyAdminView() {
  const login = $('#admin-login')
  const layout = $('#admin-layout')
  if (adminState.user === undefined) {
    login.hidden = true
    layout.hidden = true
  } else if (adminState.user === null) {
    login.hidden = false
    layout.hidden = true
  } else {
    login.hidden = true
    layout.hidden = false
    $('#admin-me').textContent = adminState.user.username
    renderTab()
  }
}

function initAdminEvents() {
  $('#admin-login-form').addEventListener('submit', async (e) => {
    e.preventDefault()
    const errBox = $('#admin-login-err')
    errBox.innerHTML = ''
    const btn = e.target.querySelector('button[type=submit]')
    btn.disabled = true
    btn.textContent = '登录中…'
    try {
      await adminApi.login($('#admin-login-username').value.trim(), $('#admin-login-password').value)
      adminState.user = await adminApi.me()
      applyAdminView()
    } catch (err) {
      errBox.appendChild(h('div', { class: 'err' }, err.message))
    } finally {
      btn.disabled = false
      btn.textContent = '登录 →'
    }
  })

  $('#admin-logout').addEventListener('click', async () => {
    try { await adminApi.logout() } catch {}
    adminState.user = null
    applyAdminView()
  })

  $('#admin-tabs').addEventListener('click', (e) => {
    const btn = e.target.closest('.nav-tab')
    if (!btn) return
    adminState.tab = btn.dataset.tab
    document.querySelectorAll('#admin-tabs .nav-tab').forEach((b) => b.classList.toggle('active', b === btn))
    renderTab()
  })
}

function renderTab() {
  const panel = $('#admin-panel')
  if (!panel) return
  const t = adminState.tab
  panel.replaceChildren()
  if (t === 'dashboard') renderDashboard(panel)
  else if (t === 'announcements') renderAnnouncements(panel)
  else if (t === 'prices') renderPrices(panel)
  else if (t === 'more-prices') renderMorePrices(panel)
  else if (t === 'syy-prices') renderSyyPrices(panel)
  else if (t === 'bing-prices') renderBingPrices(panel)
  else if (t === 'qd') renderQd(panel)
  else if (t === 'orders') renderOrders(panel)
  else if (t === 'users') renderUsers(panel)
  else if (t === 'codes') renderCodes(panel)
  else if (t === 'syscfg') renderSyscfg(panel)
  else if (t === 'logs') renderLogs(panel)
}

/* ==================== 通用小组件 ==================== */

function pagerEl(page, setPage, total, pageSize) {
  const pages = Math.max(1, Math.ceil(total / pageSize))
  const prev = h('button', { class: 'btn btn-sm', disabled: page <= 1 || undefined, onclick: () => setPage(page - 1) }, '上一页')
  const next = h('button', { class: 'btn btn-sm', disabled: page >= pages || undefined, onclick: () => setPage(page + 1) }, '下一页')
  const info = h('span', null, `${page} / ${pages} · 共 ${total} 条`)
  return h('div', { class: 'pager' }, [prev, info, next])
}

function modalMask(body) {
  const mask = h('div', { class: 'modal-mask', onclick: (e) => { if (e.target === mask) mask.remove() } })
  mask.appendChild(body)
  document.body.appendChild(mask)
  return mask
}

/* ==================== 看板 ==================== */

function statCard(icon, label, value, sub) {
  return h('div', { class: 'stat-card' }, [
    h('div', { style: 'display:flex;align-items:center;gap:8px' }, [
      h('span', { style: 'width:32px;height:32px;border-radius:9px;background:#f1f5f9;border:1px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-size:16px' }, icon),
      h('div', { class: 'stat-label', style: 'margin-top:0' }, label),
    ]),
    h('div', { class: 'stat-value' }, value),
    sub ? h('div', { class: 'stat-sub' }, sub) : null,
  ])
}

function renderDashboard(panel) {
  const el = h('div')
  panel.appendChild(el)
  const errEl = h('div')
  el.appendChild(errEl)
  adminApi.dashboard().then((data) => {
    errEl.remove()
    const section = (title, cards) => {
      el.appendChild(h('div', { class: 'section-title' }, title))
      el.appendChild(h('div', { class: 'stat-grid' }, cards))
    }
    const t = data.today
    const w = data.week
    const m = data.month
    section('今日 · 已支付订单', [
      statCard('💵', '今日成交', fmtMoney(t.amount_fen), `${t.orders} 笔订单`),
      statCard('⚡', '积分消耗', t.points_used, '今日支付扣减'),
      statCard('👤', '新增用户', t.new_users),
    ])
    section('近 7 天', [
      statCard('📈', '7 日成交', fmtMoney(w.amount_fen), `${w.orders} 笔订单`),
      statCard('⚡', '积分消耗', w.points_used),
      statCard('👥', '新增用户', w.new_users),
    ])
    section('近 30 天', [
      statCard('📊', '30 日成交', fmtMoney(m.amount_fen), `${m.orders} 笔订单`),
      statCard('⚡', '积分消耗', m.points_used),
      statCard('👤', '新增用户', m.new_users),
    ])
    section('总览', [
      statCard('👥', '用户总数', data.users_total),
      statCard('⏳', '待支付订单', data.pending_orders),
      statCard('🛠️', '待处理已付单', data.unprocessed_paid, '已支付但未标记完成'),
      statCard('🎟️', '充值码', data.codes.unused, `未使用 · 已用 ${data.codes.used}`),
    ])
    if (data.platforms.length > 0) {
      el.appendChild(h('div', { class: 'section-title' }, '平台分布（已支付单量）'))
      el.appendChild(h('div', { class: 'card' }, h('div', { class: 'table-wrap' }, h('table', null, [
        h('thead', null, h('tr', null, [h('th', null, '平台'), h('th', null, '订单数')])),
        h('tbody', null, data.platforms.map((p) => h('tr', null, [h('td', null, p.platform), h('td', null, p.count)]))),
      ]))))
    }
    // 综合网课商品同步（手动触发入口；正常由启动/每日定时自动执行）
    const syncInfo = h('span', { style: 'color:#64748b;font-size:13px' }, '启动与每日自动同步；此按钮可随时手动重拉')
    el.appendChild(h('div', { class: 'card', style: 'display:flex;align-items:center;gap:14px;padding:14px 18px;margin-top:14px' }, [
      h('span', { style: 'font-weight:700;font-size:14px' }, '🛰️ 综合网课商品同步'),
      h('button', { class: 'btn btn-sm btn-ok', onclick: () => runMoreSync() }, '立即同步'),
      syncInfo,
    ]))
    async function runMoreSync() {
      if (syncInfo.textContent.includes('同步中…')) return
      syncInfo.textContent = '同步中（拉取上游商品可能需要十几秒）…'
      try {
        const data = await adminApi.moreSync()
        syncInfo.textContent = `✓ 完成：上新 ${data.inserted}，更新 ${data.updated}，下架 ${data.removed}，目录 ${data.catalog}（共 ${data.total} 项，耗时 ${data.dur}）`
        notify(`综合网课同步完成：上新 ${data.inserted} 更新 ${data.updated} 下架 ${data.removed}`)
      } catch (e) {
        syncInfo.textContent = '上次同步失败'
        notify((e && e.message) || '同步失败', true)
      }
    }
  }).catch((e) => {
    errEl.appendChild(h('div', { class: 'empty' }, `加载失败：${e.message}`))
  })
}

/* ==================== 平台价格 ==================== */

function renderPrices(panel) {
  const drafts = {}
  let items = null
  let saving = ''

  const tableBody = h('tbody')
  const root = h('div')
  panel.appendChild(root)

  async function load() {
    try {
      items = (await adminApi.prices()).items
      draw()
    } catch (e) { if (!e.auth) notify(e.message, true) }
  }

  function draw() {
    if (!items) return
    tableBody.replaceChildren(...items.map((item) => {
      const value = drafts[item.platform] !== undefined ? drafts[item.platform] : String(item.price_fen / 100)
      const input = h('input', {
        class: 'input', type: 'number', step: '0.01', min: '0', value,
        style: 'width:90px;padding:5px 8px',
      })
      input.placeholder = ((item.default_fen || 0) / 100).toFixed(2)
      const saveBtn = h('button', { class: 'btn btn-sm btn-primary', onclick: () => save(item.platform, input.value) }, '保存')
      // 保存按钮禁用态必须随输入实时刷新：首次渲染 drafts 为空 → 禁用，
      // 输入价格立即解锁；不能再只在 draw 时算一次（否则输入后按钮仍是灰的、无法保存）
      const syncSaveBtn = () => {
        const busy = saving === item.platform
        saveBtn.disabled = busy || drafts[item.platform] === undefined
        saveBtn.textContent = busy ? '保存中…' : '保存'
      }
      input.addEventListener('input', (e) => { drafts[item.platform] = e.target.value; syncSaveBtn() })
      syncSaveBtn()
      const restoreBtn = (item.source === 'custom' || drafts[item.platform] !== undefined) ? h('button', { class: 'btn btn-sm', title: '删除自定义价，回退默认', disabled: saving === item.platform || undefined, onclick: () => save(item.platform, '0') }, '恢复默认') : null
      return h('tr', null, [
        h('td', null, item.platform),
        h('td', { class: 'mono' }, `¥${value}`),
        h('td', null, h('span', { class: `badge ${item.source === 'custom' ? 'badge-progress' : 'badge-gray'}` }, item.source === 'custom' ? '自定义' : '默认')),
        h('td', null, input),
        h('td', null, [saveBtn, restoreBtn ? ' ' : null, restoreBtn]),
      ])
    }))
  }

  async function save(platform, value) {
    if (saving) return // 防连点：同一平台保存进行中不再处理
    const yuan = parseFloat(value)
    if (Number.isNaN(yuan) || yuan < 0) { notify('请输入合法价格', true); return }
    saving = platform
    draw()
    try {
      await adminApi.setPrice(platform, yuan)
      notify(`${platform} 价格已更新`)
      delete drafts[platform]
      await load()
    } catch (e) {
      notify(e.message, true)
    } finally {
      saving = ''
      draw()
    }
  }

  root.appendChild(h('div', { class: 'card' }, [
    h('h2', null, '平台价格（元/门）'),
    h('div', { class: 'tip', style: 'margin-bottom:12px' }, '未单独设置的平台使用默认价；下单与积分扣减即时按新价格生效，无需重启。'),
    h('div', { class: 'table-wrap' }, h('table', null, [
      h('thead', null, h('tr', null, ['平台', '当前价格', '来源', '修改', '操作'].map((x) => h('th', null, x)))),
      tableBody,
    ])),
  ]))
  load()
}

/* ==================== 货源上架（前台分类 + 选项目） ==================== */

// 同步只入库；前台只显示你自建的分类，并勾选挂上的商品。
function renderMorePrices(panel) {
  const state = {
    q: '', fenlei: '', page: 1, data: null,
    srcCats: [], front: [],
  }
  const body = h('tbody')
  const frontBody = h('tbody')
  const yuan = (v) => (v > 0 ? `¥${Number(v).toFixed(2)}` : '—')

  const fenleiName = (f) => {
    const c = state.srcCats.find((x) => String(x.fenlei) === String(f))
    return (c && c.name) ? c.name : `货源 ${f}`
  }

  const fenleiSel = h('select', { onchange: (e) => { state.fenlei = e.target.value; state.page = 1; loadProducts() } }, [h('option', { value: '' }, '全部货源')])

  function refillFenleiSel() {
    const cur = state.fenlei
    fenleiSel.replaceChildren(h('option', { value: '' }, '全部货源'))
    state.srcCats.forEach((c) => {
      fenleiSel.appendChild(h('option', { value: String(c.fenlei) }, `${c.name || ('货源' + c.fenlei)}（${c.total || 0}）`))
    })
    fenleiSel.value = cur
  }

  async function loadFront() {
    try {
      const data = await adminApi.frontCategories()
      state.front = Array.isArray(data.items) ? data.items : []
      drawFront()
    } catch (e) { notify(e.message, true) }
  }

  async function loadSrcCats() {
    try {
      const data = await adminApi.moreFenlei()
      state.srcCats = Array.isArray(data.items) ? data.items : []
      refillFenleiSel()
    } catch (_) {}
  }

  function drawFront() {
    if (!state.front.length) {
      frontBody.replaceChildren(h('tr', null, h('td', { colSpan: 4 }, h('div', { class: 'empty' }, '还没有前台分类：先点「新建分类」，再勾选商品挂上去'))))
      return
    }
    frontBody.replaceChildren(...state.front.map((c) => {
      const mult = Number(c.price_mult) > 0 ? Number(c.price_mult) : 1
      const badge = c.count > 0
        ? h('span', { class: 'badge badge-done' }, `前台 ${c.count} 项`)
        : h('span', { class: 'badge badge-gray' }, '未挂商品')
      return h('tr', null, [
        h('td', { style: 'font-weight:700' }, c.name),
        h('td', { class: 'mono' }, `${mult}×`),
        h('td', { class: 'mono' }, String(c.total || 0)),
        h('td', null, badge),
        h('td', null, [
          h('button', { class: 'btn btn-sm btn-primary', onclick: () => openPicker(c) }, '选项目上架'),
          ' ',
          h('button', { class: 'btn btn-sm', onclick: () => editFront(c) }, '改名/倍速'),
          ' ',
          h('button', { class: 'btn btn-sm btn-danger', onclick: () => delFront(c) }, '删除'),
        ]),
      ])
    }))
  }

  async function createFront() {
    const name = prompt('前台分类名称（用户看到的平台名）', '')
    if (name == null) return
    const n = name.trim()
    if (!n) { notify('名称不能为空', true); return }
    const multRaw = prompt('倍速（售价=进价×倍速，如 1.5）', '1')
    if (multRaw == null) return
    const mult = parseFloat(multRaw)
    if (!(mult > 0)) { notify('倍速需大于 0', true); return }
    try {
      await adminApi.createFrontCategory(n, 0, mult)
      notify(`已创建「${n}」· ${mult}×`)
      await loadFront()
    } catch (e) { notify(e.message, true) }
  }

  async function editFront(c) {
    const name = prompt('分类名称', c.name)
    if (name == null) return
    const n = name.trim()
    if (!n) return
    const multRaw = prompt('倍速（售价=进价×倍速；改完会重算已挂商品售价）', String(c.price_mult || 1))
    if (multRaw == null) return
    const mult = parseFloat(multRaw)
    if (!(mult > 0)) { notify('倍速需大于 0', true); return }
    try {
      await adminApi.updateFrontCategory(c.id, { name: n, price_mult: mult })
      notify('已保存（已按倍速重算挂载商品售价）')
      await loadFront()
    } catch (e) { notify(e.message, true) }
  }

  async function delFront(c) {
    if (!confirm(`删除前台分类「${c.name}」？挂在上面的商品会从前台拿掉（同步库仍保留）。`)) return
    try {
      await adminApi.deleteFrontCategory(c.id)
      notify('已删除')
      await loadFront()
    } catch (e) { notify(e.message, true) }
  }

  // 弹窗：从同步库勾选商品，挂到某个前台分类并上架
  async function openPicker(cat) {
    let attached = new Set()
    try {
      const d = await adminApi.frontCategoryItems(cat.id)
      ;(d.cids || []).forEach((id) => attached.add(Number(id)))
    } catch (e) { notify(e.message, true); return }

    const st = { q: '', fenlei: '', page: 1, data: null, selected: new Set(attached) }
    const listBody = h('tbody')
    const tip = h('div', { class: 'tip' }, '')
    const srcSel = h('select', { onchange: (e) => { st.fenlei = e.target.value; st.page = 1; reload() } }, [h('option', { value: '' }, '全部货源')])
    state.srcCats.forEach((c) => srcSel.appendChild(h('option', { value: String(c.fenlei) }, c.name || String(c.fenlei))))
    const qEl = h('input', { class: 'input grow', style: 'max-width:220px', placeholder: '搜索商品名' })
    qEl.addEventListener('input', debounce(() => { st.q = qEl.value; st.page = 1; reload() }, 300))
    const pagerWrap = h('div', { class: 'pager' })

    async function reload() {
      tip.textContent = '加载中…'
      try {
        st.data = await adminApi.moreProducts({ q: st.q.trim(), fenlei: st.fenlei, page: st.page, page_size: 30 })
        tip.textContent = `已勾选 ${st.selected.size} 项 · 库内共 ${st.data.total} 项（同步商品不会自动上前台）`
        listBody.replaceChildren(...(st.data.items || []).map((it) => {
          const ck = h('input', { type: 'checkbox', checked: st.selected.has(Number(it.cid)) || undefined })
          ck.addEventListener('change', () => {
            if (ck.checked) st.selected.add(Number(it.cid))
            else st.selected.delete(Number(it.cid))
            tip.textContent = `已勾选 ${st.selected.size} 项 · 库内共 ${st.data.total} 项`
          })
          return h('tr', null, [
            h('td', null, ck),
            h('td', { class: 'mono' }, it.cid),
            h('td', { class: 'wrap', style: 'max-width:280px' }, it.name),
            h('td', null, fenleiName(it.fenlei)),
            h('td', { class: 'mono' }, yuan(it.price_yuan)),
          ])
        }))
        if (!(st.data.items || []).length) {
          listBody.replaceChildren(h('tr', null, h('td', { colSpan: 5 }, h('div', { class: 'empty' }, '没有商品，请先在看板同步'))))
        }
        pagerWrap.replaceChildren(pagerEl(st.page, (p) => { st.page = p; reload() }, st.data.total, 30))
      } catch (e) { tip.textContent = e.message }
    }

    const saveBtn = h('button', { class: 'btn btn-primary', type: 'button' }, '保存挂载并上架')
    saveBtn.onclick = async () => {
      saveBtn.disabled = true
      try {
        const next = [...st.selected]
        const prev = [...attached]
        const toAdd = next.filter((id) => !attached.has(id))
        const toDel = prev.filter((id) => !st.selected.has(id))
        if (toAdd.length) await adminApi.addFrontCategoryItems(cat.id, toAdd)
        if (toDel.length) await adminApi.removeFrontCategoryItems(cat.id, toDel)
        notify(`「${cat.name}」已更新：+${toAdd.length} / -${toDel.length}`)
        mask.remove()
        await loadFront()
      } catch (e) {
        notify(e.message, true)
        saveBtn.disabled = false
      }
    }

    const mask = modalMask(h('div', { class: 'modal', style: 'width:min(720px,96vw);max-height:88vh;display:flex;flex-direction:column' }, [
      h('h3', null, `给「${cat.name}」选项目上架`),
      h('div', { class: 'tip', style: 'margin-bottom:8px' }, '勾选要出现在这个前台分类里的商品；保存后才会上架到用户端。同步不会自动全上架。'),
      h('div', { class: 'toolbar', style: 'margin-bottom:8px' }, [qEl, srcSel]),
      tip,
      h('div', { class: 'table-wrap', style: 'flex:1;overflow:auto;max-height:50vh;margin-top:8px' }, h('table', null, [
        h('thead', null, h('tr', null, ['', 'ID', '商品', '货源', '售价'].map((x) => h('th', null, x)))),
        listBody,
      ])),
      pagerWrap,
      h('div', { class: 'modal-actions' }, [
        h('button', { type: 'button', class: 'btn', onclick: () => mask.remove() }, '取消'),
        saveBtn,
      ]),
    ]))
    reload()
  }

  async function loadProducts() {
    try {
      state.data = await adminApi.moreProducts({ q: state.q.trim(), fenlei: state.fenlei, page: state.page, page_size: 20 })
      drawProducts()
    } catch (e) { notify(e.message, true) }
  }

  function drawProducts() {
    if (!state.data) return
    body.replaceChildren(...state.data.items.map((it) => {
      const draft = h('input', { class: 'input', type: 'number', step: '0.01', min: '0', value: it.price_yuan > 0 ? String(it.price_yuan) : '', placeholder: it.base_yuan > 0 ? it.base_yuan.toFixed(2) : '0.00', style: 'width:92px;padding:5px 8px' })
      const priceBadge = it.price_override
        ? h('span', { class: 'badge badge-progress' }, '自定义')
        : h('span', { class: 'badge badge-gray' }, '上游价')
      const resetBtn = it.price_override ? h('button', { class: 'btn btn-sm', onclick: () => resetPrice(it) }, '恢复默认') : null
      return h('tr', null, [
        h('td', { class: 'mono' }, it.cid),
        h('td', { class: 'wrap', style: 'max-width:240px' }, it.name),
        h('td', null, fenleiName(it.fenlei)),
        h('td', { class: 'mono' }, yuan(it.base_yuan)),
        h('td', { class: 'mono' }, yuan(it.price_yuan)),
        h('td', null, [draft, ' ', h('button', { class: 'btn btn-sm btn-primary', onclick: () => savePrice(it, draft) }, '保存'), ' ', resetBtn]),
        h('td', null, priceBadge),
      ])
    }))
    if (state.data.items.length === 0) body.replaceChildren(h('tr', null, h('td', { colSpan: 7 }, h('div', { class: 'empty' }, '没有匹配的商品'))))
    const _pg = panel.querySelector('.pager'); if (_pg) _pg.replaceWith(pagerEl(state.page, (p) => { state.page = p; loadProducts() }, state.data.total, 20))
    const _ct = panel.querySelector('[data-count]'); if (_ct) _ct.textContent = `共 ${state.data.total} 项`
  }

  async function savePrice(it, input) {
    const v = parseFloat(input.value)
    if (Number.isNaN(v) || v < 0) { notify('请输入合法价格', true); return }
    if (v > 10000) { notify('价格需在 0~10000 元之间', true); return }
    try {
      await adminApi.setMorePrice(it.cid, v)
      notify(`已更新「${it.name}」售价 ¥${v.toFixed(2)}`)
      await loadProducts()
    } catch (e) { notify(e.message, true) }
  }

  async function resetPrice(it) {
    if (!confirm(`确定把「${it.name}」恢复为上游默认价？`)) return
    try {
      await adminApi.setMorePrice(it.cid, 0)
      notify(`「${it.name}」已恢复上游默认价`)
      await loadProducts()
    } catch (e) { notify(e.message, true) }
  }

  const qInput = h('input', { class: 'input grow', style: 'max-width:240px', placeholder: '搜索商品名' })
  qInput.addEventListener('input', debounce(() => { state.q = qInput.value; state.page = 1; loadProducts() }, 300))

  panel.appendChild(h('div', { class: 'card' }, [
    h('h2', null, '前台分类（用户看到的平台）'),
    h('div', { class: 'tip', style: 'margin-bottom:12px' }, '同步不会自动上架。新建分类时可设「倍速」（售价=进价×倍速），再点「选项目上架」勾选商品。'),
    h('div', { class: 'toolbar', style: 'margin-bottom:10px' }, [
      h('button', { class: 'btn btn-primary btn-sm', onclick: createFront }, '＋ 新建分类'),
    ]),
    h('div', { class: 'table-wrap' }, h('table', null, [
      h('thead', null, h('tr', null, ['分类名', '倍速', '已挂载', '状态', '操作'].map((x) => h('th', null, x)))),
      frontBody,
    ])),
  ]))

  panel.appendChild(h('div', { class: 'card' }, [
    h('h2', null, '同步商品库（改价）'),
    h('div', { class: 'tip', style: 'margin-bottom:12px' }, '这里是上游同步下来的全部商品，只改售价；要出现在前台必须挂到上面的分类里。'),
    h('div', { class: 'toolbar' }, [qInput, fenleiSel, h('span', { class: 'tip', dataset: { count: '' } }, '')]),
    h('div', { class: 'table-wrap' }, h('table', null, [
      h('thead', null, h('tr', null, ['ID', '商品', '货源', '进价', '售价', '改价', '价类型'].map((x) => h('th', null, x)))),
      body,
    ])),
    h('div', { class: 'pager' }),
  ]))

  loadSrcCats()
  loadFront()
  loadProducts()
}

/* ==================== 运动定价 ==================== */

// 我爱运动（syy）按项目定价：默认价 = 上游基准价 × 倍率(SYY_PRICE_MULT)，可按项目覆盖。
// 注意实际成本 = 基准价 × 你的等级费率 ÷ 基准费率，自定义价需覆盖成本。
function renderSyyPrices(panel) {
  const items = []
  const body = h('tbody')

  async function load() {
    try {
      const data = await adminApi.syyProjectsAdmin()
      body.replaceChildren(...(data.items || []).map((it) => {
        const input = h('input', { class: 'input', type: 'number', step: '0.01', min: '0', value: (it.unit_fen / 100).toFixed(2), style: 'width:92px;padding:5px 8px' })
        const save = async () => {
          const v = parseFloat(input.value)
          if (Number.isNaN(v) || v < 0) { notify('请输入合法价格', true); return }
          if (v > 10000) { notify('价格需在 0~10000 元之间', true); return }
          try { await adminApi.syySetPrice(it.project_id, v); notify(`「${it.name}」售价已更新`); load() } catch (e) { notify(e.message, true) }
        }
        const resetBtn = it.source === 'custom' ? h('button', { class: 'btn btn-sm', title: '删除自定义价，回退默认倍率价', onclick: async () => {
          if (!confirm(`确定把「${it.name}」恢复为默认倍率价？`)) return
          try { await adminApi.syySetPrice(it.project_id, 0); notify(`「${it.name}」已恢复默认`); load() } catch (e) { notify(e.message, true) }
        } }, '恢复默认') : null
        return h('tr', null, [
          h('td', { class: 'mono' }, it.project_id),
          h('td', { class: 'wrap', style: 'max-width:220px' }, [
            h('div', { style: 'font-weight:600' }, it.name),
            it.description ? h('div', { style: 'font-size:11.5px;color:#7a8087;margin-top:2px' }, it.description) : null,
          ]),
          h('td', { class: 'mono' }, `¥${(it.base_price || 0).toFixed(2)}`),
          h('td', { class: 'mono', style: 'font-weight:700' }, `¥${(it.unit_fen / 100).toFixed(2)}`),
          h('td', null, h('span', { class: `badge ${it.source === 'custom' ? 'badge-progress' : 'badge-gray'}` }, it.source === 'custom' ? '自定义' : '默认倍率')),
          h('td', null, [input, ' ', h('button', { class: 'btn btn-sm btn-primary', onclick: save }, '保存'), resetBtn ? ' ' : null, resetBtn]),
        ])
      }))
      if (!body.children.length) {
        body.replaceChildren(h('tr', null, h('td', { colSpan: 6 }, h('div', { class: 'empty' }, '上游项目加载失败或暂无项目'))))
      }
    } catch (e) { notify(e.message, true) }
  }

  panel.appendChild(h('div', { class: 'card' }, [
    h('h2', null, '运动定价（元/单位）'),
    h('div', { class: 'tip', style: 'margin-bottom:12px' }, '本站售价按「元/单位」计，未自定义的项目 = 上游基准价 × 倍率(SYY_PRICE_MULT)。注意实际成本 = 基准价 × 你的等级费率 ÷ 基准费率，售价必须覆盖成本。改动即时生效，不影响已下单的订单。'),
    h('div', { class: 'table-wrap' }, h('table', null, [
      h('thead', null, h('tr', null, ['ID', '项目', '上游基准价', '本站售价', '来源', '修改 / 操作'].map((x) => h('th', null, x)))),
      body,
    ])),
  ]))
  load()
}

/* ==================== 病例管理（开关 + 模板定价） ==================== */

// 病例（bingli）：管理端运行开关 + 按模板改价。
// 默认售价 = 上游模板价 × 倍率(BING_PRICE_MULT)；自定义价优先，保存后即时生效。
function renderBingPrices(panel) {
  let settings = null
  let apps = null
  let loadError = ''

  const root = h('div')
  panel.appendChild(root)

  async function load() {
    try {
      settings = await adminApi.bingSettings()
    } catch (e) {
      if (!e.auth) notify(e.message, true)
      return
    }
    apps = null
    loadError = ''
    if (settings.configured) {
      try {
        const data = await adminApi.bingApplicationsAdmin()
        apps = Array.isArray(data.items) ? data.items : []
      } catch (e) {
        loadError = e.message
        notify('病例模板加载失败：' + e.message, true)
      }
    }
    draw()
  }

  function draw() {
    root.replaceChildren()
    root.appendChild(switchCard())
    if (!settings.configured) {
      root.appendChild(h('div', { class: 'card' }, h('div', { class: 'empty' },
        '病例上游未配置：请在服务端 .env 填写 BING_USER_EMAIL / BING_API_KEY 后重启。未配置时开关不会生效。')))
      return
    }
    if (apps === null) {
      root.appendChild(h('div', { class: 'card' }, [
        h('div', { class: 'empty' }, loadError ? `模板加载失败：${loadError}` : '模板加载中…'),
        h('div', { style: 'margin-top:10px' }, h('button', { class: 'btn btn-sm', onclick: load }, '重新加载')),
      ]))
      return
    }
    root.appendChild(priceCard())
  }

  function switchCard() {
    const on = settings.switch_on
    const available = settings.available
    const toggle = async () => {
      if (on && !confirm('关闭后用户端将隐藏病例入口，且无法新建订单；已支付订单仍可查看图片/重试。确定关闭？')) return
      try {
        settings = await adminApi.bingSetEnabled(!on)
        notify(!on ? '病例功能已开启' : '病例功能已关闭')
        await load()
      } catch (e) { notify(e.message, true) }
    }
    return h('div', { class: 'card', style: 'margin-bottom:14px' }, [
      h('h2', null, '病例功能开关'),
      h('div', { class: 'toolbar' }, [
        h('span', { class: `badge ${available ? 'badge-done' : 'badge-gray'}` }, available ? '用户端已开放' : '用户端已关闭'),
        h('button', { class: `btn btn-sm ${on ? 'btn-danger' : 'btn-primary'}`, onclick: toggle }, on ? '关闭病例' : '开启病例'),
        h('span', { class: 'tip' }, '关闭只影响新建/浏览模板；已支付订单的图片与重试不受影响。'),
      ]),
      !settings.configured ? h('div', { class: 'tip', style: 'margin-top:10px;color:#b45309' }, '上游账号/密钥未配置，开关即使开启也不可用。') : null,
    ])
  }

  function priceCard() {
    const body = h('tbody')
    const rows = apps.map((it) => {
      const input = h('input', {
        class: 'input', type: 'number', step: '0.01', min: '0',
        value: (it.price_fen / 100).toFixed(2), style: 'width:96px;padding:5px 8px',
      })
      const save = async () => {
        const v = parseFloat(input.value)
        if (Number.isNaN(v) || v < 0) { notify('请输入合法价格', true); return }
        if (v > 10000) { notify('价格需在 0~10000 元之间', true); return }
        if (v > 0 && v < 0.01) { notify('自定义价格不能低于 0.01 元', true); return }
        try {
          await adminApi.bingSetPrice(it.id, v)
          notify(`「${it.name}」售价已更新`)
          await load()
        } catch (e) { notify(e.message, true) }
      }
      const reset = async () => {
        if (!confirm(`确定把「${it.name}」恢复为默认价（上游价 × ${fmtNum(settings.price_mult)}）？`)) return
        try {
          await adminApi.bingSetPrice(it.id, 0)
          notify(`「${it.name}」已恢复默认价`)
          await load()
        } catch (e) { notify(e.message, true) }
      }
      const badge = it.source === 'custom'
        ? h('span', { class: 'badge badge-progress' }, '自定义')
        : h('span', { class: 'badge badge-gray' }, '默认价')
      return h('tr', null, [
        h('td', { class: 'mono' }, it.id),
        h('td', { class: 'wrap', style: 'max-width:240px' }, [
          h('div', { style: 'font-weight:600' }, it.name || '(未命名模板)'),
          it.description ? h('div', { style: 'font-size:11.5px;color:#7a8087;margin-top:2px' }, it.description) : null,
        ]),
        h('td', { class: 'mono' }, fmtNum(it.upstream_price)),
        h('td', { class: 'mono' }, fmtMoney(it.default_price_fen)),
        h('td', { class: 'mono', style: 'font-weight:700' }, fmtMoney(it.price_fen)),
        h('td', null, badge),
        h('td', null, [
          input, ' ',
          h('button', { class: 'btn btn-sm btn-primary', onclick: save }, '保存'),
          it.source === 'custom' ? ' ' : null,
          it.source === 'custom' ? h('button', { class: 'btn btn-sm', onclick: reset }, '恢复默认') : null,
        ]),
      ])
    })
    body.replaceChildren(...rows)
    if (!rows.length) {
      body.replaceChildren(h('tr', null, h('td', { colSpan: 7 }, h('div', { class: 'empty' }, '上游暂无可用病例模板'))))
    }
    return h('div', { class: 'card' }, [
      h('h2', null, '病例模板售价'),
      h('div', { class: 'tip', style: 'margin-bottom:12px' }, `默认售价 = 上游模板价 × ${fmtNum(settings.price_mult)}；自定义价优先且即时生效。用户端展示与下单扣费使用同一价格，改价不影响已提交订单。`),
      h('div', { class: 'table-wrap' }, h('table', null, [
        h('thead', null, h('tr', null, ['模板ID', '模板', '上游价(积分)', '默认售价', '当前售价', '来源', '改价 / 操作'].map((x) => h('th', null, x)))),
        body,
      ])),
    ])
  }

  load()
}

/* ==================== 公告 ==================== */

const ANN_LEVELS = {
  info: { label: '通知', color: '#1d4ed8', bg: '#eff6ff', border: '#bfdbfe' },
  warning: { label: '提醒', color: '#92400e', bg: '#fffbeb', border: '#fde68a' },
  urgent: { label: '重要', color: '#991b1b', bg: '#fef2f2', border: '#fecaca' },
}

function renderAnnouncements(panel) {
  let data = null
  let page = 1
  const body = h('tbody')

  async function load() {
    try {
      data = await adminApi.announcements({ page, page_size: 20 })
      draw()
    } catch (e) { notify(e.message, true) }
  }

  function draw() {
    if (!data) return
    body.replaceChildren(...data.items.map((item) => {
      const st = ANN_LEVELS[item.level] || ANN_LEVELS.info
      const actions = h('td', null, [
        h('button', { class: 'btn btn-sm', onclick: () => openModal({ mode: 'edit', item }) }, '编辑'),
        ' ',
        h('button', { class: 'btn btn-sm', onclick: async () => {
          try { await adminApi.updateAnnouncement(item.id, { is_pinned: !item.is_pinned }); notify(item.is_pinned ? '已取消置顶' : '已置顶'); load() } catch (e) { notify(e.message, true) }
        } }, item.is_pinned ? '取消置顶' : '置顶'),
        ' ',
        h('button', { class: 'btn btn-sm', onclick: async () => {
          try { await adminApi.updateAnnouncement(item.id, { is_active: !item.is_active }); notify(item.is_active ? '已隐藏' : '已展示'); load() } catch (e) { notify(e.message, true) }
        } }, item.is_active ? '隐藏' : '展示'),
        ' ',
        h('button', { class: 'btn btn-sm btn-danger', onclick: async () => {
          if (!confirm(`确定删除公告「${item.title}」？`)) return
          try { await adminApi.deleteAnnouncement(item.id); notify(`已删除 ${item.title}`); load() } catch (e) { notify(e.message, true) }
        } }, '删除'),
      ])
      return h('tr', null, [
        h('td', { class: 'mono' }, item.id),
        h('td', { class: 'wrap', style: 'max-width:220px;font-weight:600' }, item.title),
        h('td', null, h('span', { class: 'badge', style: `background:${st.bg};color:${st.color};border-color:${st.border}` }, st.label)),
        h('td', null, item.is_pinned ? h('span', { class: 'badge badge-done' }, '置顶') : h('span', { class: 'badge badge-gray' }, '普通')),
        h('td', null, item.is_active ? h('span', { class: 'badge badge-done' }, '展示中') : h('span', { class: 'badge badge-gray' }, '已隐藏')),
        h('td', { class: 'time' }, fmtTime(item.created_at)),
        h('td', { class: 'time' }, fmtTime(item.updated_at)),
        actions,
      ])
    }))
    if (data.items.length === 0) {
      body.replaceChildren(h('tr', null, h('td', { colSpan: 8 }, h('div', { class: 'empty' }, '暂无公告，点击"新建公告"发布第一条'))))
    }
    const _pg = panel.querySelector('.pager'); if (_pg) _pg.replaceWith(pagerEl(page, (p) => { page = p; load() }, data.total, 20))
    const _ct = panel.querySelector('[data-count]'); if (_ct) _ct.textContent = `共 ${data.total} 条`
  }

  function openModal({ mode, item }) {
    const isEdit = mode === 'edit'
    const titleInput = h('input', { class: 'input', maxlength: 100, value: item?.title || '', required: true })
    const contentInput = h('textarea', { class: 'input', rows: 6, value: item?.content || '', required: true, style: 'resize:vertical;line-height:1.7' })
    contentInput.maxLength = 3000
    const levelSel = h('select', { class: 'input' }, ['info', 'warning', 'urgent'].map((lv) => h('option', { value: lv, selected: (item?.level || 'info') === lv || undefined }, lv === 'info' ? '通知（蓝）' : lv === 'warning' ? '提醒（黄）' : '重要（红）')))
    const pinnedCk = h('input', { type: 'checkbox', checked: (!!item?.is_pinned) || undefined })
    const activeCk = h('input', { type: 'checkbox', checked: item ? !!item.is_active : true })
    const errEl = h('div')
    let busy = false

    const submitBtn = h('button', { class: 'btn btn-primary', type: 'submit' }, isEdit ? '保存' : '发布')
    const form = h('form', { class: 'modal', style: 'width:520px', onsubmit: async (e) => {
      e.preventDefault()
      errEl.replaceChildren()
      const t = titleInput.value.trim()
      const c = contentInput.value.trim()
      if (t.length < 1 || t.length > 100) { errEl.appendChild(h('div', { class: 'err' }, '标题需 1-100 字')); return }
      if (c.length < 1 || c.length > 3000) { errEl.appendChild(h('div', { class: 'err' }, '内容需 1-3000 字')); return }
      if (!['info', 'warning', 'urgent'].includes(levelSel.value)) { errEl.appendChild(h('div', { class: 'err' }, '级别不合法')); return }
      busy = true
      submitBtn.disabled = true
      try {
        if (isEdit) {
          await adminApi.updateAnnouncement(item.id, { title: t, content: c, level: levelSel.value, is_pinned: pinnedCk.checked, is_active: activeCk.checked })
          notify('已更新')
        } else {
          await adminApi.createAnnouncement({ title: t, content: c, level: levelSel.value, is_pinned: pinnedCk.checked, is_active: activeCk.checked })
          notify('已发布，用户端立即可见')
        }
        mask.remove()
        load()
      } catch (err) {
        errEl.appendChild(h('div', { class: 'err' }, err.message))
      } finally {
        busy = false
        submitBtn.disabled = false
      }
    } }, [
      h('h3', null, isEdit ? `编辑公告 #${item.id}` : '新建公告'),
      h('div', { class: 'field' }, [h('label', null, '标题（1-100 字）'), titleInput]),
      h('div', { class: 'field' }, [h('label', null, '内容（1-3000 字，纯文本，保留换行，自动防 XSS）'), contentInput]),
      h('div', { class: 'field' }, [h('label', null, '级别'), levelSel]),
      h('div', { style: 'display:flex;gap:16px' }, [
        h('label', { style: 'display:flex;gap:6px;align-items:center;cursor:pointer' }, [pinnedCk, '置顶']),
        h('label', { style: 'display:flex;gap:6px;align-items:center;cursor:pointer' }, [activeCk, '立即展示']),
      ]),
      errEl,
      h('div', { class: 'modal-actions' }, [h('button', { type: 'button', class: 'btn', onclick: () => mask.remove() }, '取消'), submitBtn]),
    ])
    const mask = modalMask(form)
  }

  panel.appendChild(h('div', { class: 'card' }, [
    h('h2', null, '公告管理'),
    h('div', { class: 'tip', style: 'margin-bottom:12px' }, '发布后全站用户立即可见（首页顶部横幅，30s 轮询）。内容按纯文本展示，自动防 XSS；置顶公告优先显示。'),
    h('div', { class: 'toolbar' }, [
      h('span', { class: 'tip', dataset: { count: '' } }, ''),
      h('span', { style: 'flex:1' }),
      h('button', { class: 'btn btn-primary btn-sm', onclick: () => openModal({ mode: 'create' }) }, '＋ 新建公告'),
      h('button', { class: 'btn btn-sm', onclick: load }, '刷新'),
    ]),
    h('div', { class: 'table-wrap' }, h('table', null, [
      h('thead', null, h('tr', null, ['ID', '标题', '级别', '置顶', '状态', '创建时间', '更新时间', '操作'].map((x) => h('th', null, x)))),
      body,
    ])),
    h('div', { class: 'pager' }),
  ]))
  load()
}

/* ==================== 订单汇总 ==================== */

function renderOrders(panel) {
  const state = { filters: { status: 'all', pay_method: 'all', process: 'all', platform: '', q: '' }, page: 1, data: null, openId: null }
  const body = h('tbody')
  const paidEl = h('span', { class: 'tip' })
  const load = async () => {
    try {
      state.data = await adminApi.orders({ ...state.filters, q: state.filters.q.trim(), page: state.page, page_size: 20 })
      draw()
    } catch (e) { notify(e.message, true) }
  }

  function filtersSel(fn) {
    const sel = h('select', {
      value: state.filters[fn],
      onchange: (e) => { state.filters[fn] = e.target.value; state.page = 1; load() },
    })
    return sel
  }

  function draw() {
    if (!state.data) return
    const rows = []
    for (const o of state.data.items) {
      const open = state.openId === o.order_id
      const details = h('ul', { style: 'margin:6px 0 0 18px' }, (o.courses || []).map((c) => {
        const parts = [c.title || '', `（进度 ${c.progress ?? 0}%`]
        if (c.kcid) parts[1] += `，kcid ${c.kcid}`
        if (c.deadline) parts[1] += `，截止 ${c.deadline}`
        parts[1] += '）'
        return h('li', null, parts.join(''))
      }))
      const pwCell = h('td', { class: 'pw', style: 'cursor:pointer;user-select:none', title: '点击显示/隐藏（凭据按需拉取）' })
      pwCell.textContent = '••••••••'
      let pwShown = false
      pwCell.addEventListener('click', async () => {
        if (pwShown) { pwCell.textContent = '••••••••'; pwShown = false; return }
        pwCell.textContent = '…'
        try {
          const cred = await adminApi.orderCredentials(o.order_id)
          pwCell.textContent = cred.password || ''
          pwShown = true
        } catch (e) {
          pwCell.textContent = '••••••••'
          notify('取凭据失败：' + e.message, true)
        }
      })
      const procLabel = (PROCESS_OPTIONS.find(([v]) => v === o.process_status) || PROCESS_OPTIONS[0])[1]
      rows.push(h('tr', { key: o.order_id }, [
        h('td', { class: 'time' }, fmtTime(o.created_at)),
        h('td', { class: 'mono', style: 'cursor:pointer', onclick: () => { state.openId = open ? null : o.order_id; draw() } }, o.order_id.slice(0, 8) + '…'),
        h('td', null, o.platform),
        h('td', null, o.username),
        pwCell,
        h('td', null, h('a', { href: '#', onclick: (e) => { e.preventDefault(); state.openId = open ? null : o.order_id; draw() } }, `${o.course_count} 门`)),
        h('td', null, fmtMoney(o.amount_fen)),
        h('td', null, h('span', { class: `badge ${o.status === 'paid' ? 'badge-done' : 'badge-progress'}` }, o.status === 'paid' ? (PAY_METHOD_LABELS[o.pay_method] || '已付') : '待付')),
        h('td', null, h('button', { class: 'btn btn-sm', onclick: async () => {
          const next = o.process_status === 'pending' ? 'processing' : o.process_status === 'processing' ? 'done' : 'pending'
          if (next === 'pending' && !confirm(`确定把订单 ${o.order_id.slice(0, 8)}… 打回「待处理」？`)) return
          try { await adminApi.setProcessStatus(o.order_id, next); notify('已更新'); load() } catch (e) { notify(e.message, true) }
        } }, procLabel)),
        h('td', null, o.agent_id || '-'),
        h('td', { class: 'wrap' }, o.remark || '-'),
        h('td', null, o.status === 'paid' ? h('button', {
          class: 'btn btn-sm', title: '暂停该订单在上游的全部刷课任务（act=zt）',
          onclick: async () => {
            if (!confirm(`确定暂停订单 ${o.order_id.slice(0, 8)}… 的上游任务？\n（只暂停上游执行，不改本站订单状态，可到上游后台恢复）`)) return
            try {
              const res = await adminApi.pauseOrder(o.order_id)
              if (res.failed > 0) notify(`暂停完成：成功 ${res.success}/${res.total}，失败 ${res.failed}，详情见审计`, true)
              else notify(`已暂停 ${res.success}/${res.total} 个上游任务`)
            } catch (e) { notify(e.message, true) }
          },
        }, '暂停') : null),
      ]))
      if (open) {
        rows.push(h('tr', { key: o.order_id + '-d' }, h('td', { colSpan: 12, class: 'wrap' }, [
          h('b', null, '完整订单号：'), h('span', { class: 'mono' }, o.order_id),
          o.trade_no ? h('span', null, [' · ', h('b', null, '渠道流水：'), h('span', { class: 'mono' }, o.trade_no)]) : null,
          details,
        ])))
      }
    }
    body.replaceChildren(...rows)
    if (state.data.items.length === 0) {
      body.replaceChildren(h('tr', null, h('td', { colSpan: 12 }, h('div', { class: 'empty' }, '没有匹配的订单'))))
    }
    paidEl.textContent = `共 ${state.data.total} 单 · 已支付合计 ${fmtMoney(state.data.paid_sum_fen)}`
    const _pg = panel.querySelector('.pager'); if (_pg) _pg.replaceWith(pagerEl(state.page, (p) => { state.page = p; load() }, state.data.total, 20))
  }

  const statusSel = h('select', { onchange: (e) => { state.filters.status = e.target.value; state.page = 1; load() } },
    [['all', '全部状态'], ['paid', '已支付'], ['pending', '待支付']].map(([v, l]) => h('option', { value: v, selected: state.filters.status === v || undefined }, l)))
  const paySel = h('select', { onchange: (e) => { state.filters.pay_method = e.target.value; state.page = 1; load() } },
    [['all', '全部方式'], ['epay', '在线支付'], ['points', '积分支付']].map(([v, l]) => h('option', { value: v, selected: state.filters.pay_method === v || undefined }, l)))
  const procSel = h('select', { onchange: (e) => { state.filters.process = e.target.value; state.page = 1; load() } },
    [['all', '全部处理'], ['undone', '未完成'], ...PROCESS_OPTIONS].map(([v, l]) => h('option', { value: v, selected: state.filters.process === v || undefined }, l)))
  const qInput = h('input', { class: 'input grow', style: 'max-width:220px', placeholder: '搜索账号 / 订单号 / 备注' })
  qInput.addEventListener('input', debounce(() => { state.filters.q = qInput.value; state.page = 1; load() }, 300))

  panel.appendChild(h('div', { class: 'card' }, [
    h('h2', null, '订单汇总'),
    h('div', { class: 'toolbar' }, [
      statusSel, paySel, procSel, qInput,
      h('a', { class: 'btn btn-sm', href: adminApi.exportOrdersUrl(state.filters), title: '最多导出最近 5000 条；超出时文件末尾会附截断提示行' }, '导出 CSV'),
      paidEl,
    ]),
    h('div', { class: 'table-wrap' }, h('table', null, [
      h('thead', null, h('tr', null, ['时间', '订单号', '平台', '账号', '密码', '门数', '金额', '支付', '处理', '代理', '备注', '操作'].map((x) => h('th', null, x)))),
      body,
    ])),
    h('div', { class: 'pager' }),
  ]))
  load()
}

/* ==================== 用户管理 ==================== */

function genPassword(len = 12) {
  const lower = 'abcdefghjkmnpqrstuvwxyz'
  const upper = 'ABCDEFGHJKMNPQRSTUVWXYZ'
  const digits = '23456789'
  const all = lower + upper + digits
  const buf = new Uint32Array(len * 2)
  crypto.getRandomValues(buf)
  const chars = [lower[buf[1] % lower.length], upper[buf[2] % upper.length], digits[buf[3] % digits.length]]
  for (let i = chars.length; i < len; i++) chars.push(all[buf[i] % all.length])
  for (let i = chars.length - 1; i > 0; i--) {
    const j = buf[len + i] % (i + 1)
    ;[chars[i], chars[j]] = [chars[j], chars[i]]
  }
  return chars.join('')
}

function renderUsers(panel) {
  const state = { q: '', page: 1, data: null }
  const body = h('tbody')

  const load = async () => {
    try {
      state.data = await adminApi.users({ q: state.q.trim(), page: state.page, page_size: 20 })
      draw()
    } catch (e) { notify(e.message, true) }
  }

  function draw() {
    if (!state.data) return
    body.replaceChildren(...state.data.items.map((u) => h('tr', null, [
      h('td', null, u.id),
      h('td', null, u.username),
      h('td', null, u.contact || '-'),
      h('td', { class: 'mono' }, u.points),
      h('td', null, h('span', { class: `badge ${u.status === 'banned' ? 'badge-banned' : 'badge-done'}` }, u.status === 'banned' ? '已封禁' : '正常')),
      h('td', null, u.order_count),
      h('td', null, `¥${((u.paid_amount_fen || 0) / 100).toFixed(2)}`),
      h('td', { class: 'time' }, fmtTime(u.created_at)),
      h('td', null, [
        h('button', { class: 'btn btn-sm', onclick: () => openModal('points', u) }, '积分'),
        ' ',
        h('button', { class: 'btn btn-sm', onclick: () => openModal('password', u) }, '改密'),
        ' ',
        u.status === 'banned'
          ? h('button', { class: 'btn btn-sm btn-ok', onclick: () => run(() => adminApi.unbanUser(u.id), `已解封 ${u.username}`) }, '解封')
          : h('button', { class: 'btn btn-sm btn-danger', onclick: () => confirm(`确定封禁 ${u.username}？`) && run(() => adminApi.banUser(u.id), `已封禁 ${u.username}`) }, '封禁'),
        ' ',
        h('button', {
          class: 'btn btn-sm', title: '删除该用户，订单/流水保留作审计', style: 'color:#c33;border-color:#e6b0b0',
          onclick: () => {
            if (!confirm(`确定删除用户 ${u.username} (ID ${u.id})？\n积分 ${u.points}，订单 ${u.order_count} 条，累计已付 ¥${((u.paid_amount_fen || 0) / 100).toFixed(2)}\n\n删除后该用户名可被重新注册，其订单/流水保留仅作审计，此操作不可恢复。`)) return
            const name = prompt(`二次确认：请输入要删除的用户名 "${u.username}"`)
            if (name === null) return
            if (name !== u.username) { alert('用户名不匹配，已取消删除'); return }
            run(() => adminApi.deleteUser(u.id), `已删除 ${u.username}`)
          },
        }, '删除'),
      ]),
    ])))
    if (state.data.items.length === 0) {
      body.replaceChildren(h('tr', null, h('td', { colSpan: 9 }, h('div', { class: 'empty' }, '没有匹配的用户'))))
    }
    const _pg = panel.querySelector('.pager'); if (_pg) _pg.replaceWith(pagerEl(state.page, (p) => { state.page = p; load() }, state.data.total, 20))
    const _ct = panel.querySelector('[data-count]'); if (_ct) _ct.textContent = `共 ${state.data.total} 人`
  }

  async function run(fn, msg) {
    try { await fn(); notify(msg); load() } catch (e) { notify(e.message, true) }
  }

  function openModal(type, u) {
    const errEl = h('div')
    const submitBtn = h('button', { class: 'btn btn-primary', type: 'submit' }, '确认')
    let body
    if (type === 'points') {
      const amount = h('input', { class: 'input', type: 'number', step: '0.01', placeholder: '如 100、-50 或 0.4', required: true })
      const reason = h('input', { class: 'input', placeholder: '如 收到转账 100 元' })
      body = h('form', { class: 'modal', onsubmit: async (e) => {
        e.preventDefault()
        errEl.replaceChildren()
        const delta = Math.round(parseFloat(amount.value) * 100) / 100
        if (!Number.isFinite(delta) || delta === 0) { errEl.appendChild(h('div', { class: 'err' }, '请输入非 0 数值（支持两位小数）')); return }
        submitBtn.disabled = true
        try {
          const res = await adminApi.adjustPoints(u.id, delta, reason.value.trim())
          mask.remove()
          notify(`积分已调整，当前余额 ${res.balance}`)
          load()
        } catch (err) { errEl.appendChild(h('div', { class: 'err' }, err.message)); submitBtn.disabled = false }
      } }, [
        h('h3', null, `调整积分 — ${u.username}`),
        h('div', { class: 'field' }, [h('label', null, `变动值（正充值 / 负扣减，支持小数）· 当前余额 ${u.points}`), amount]),
        h('div', { class: 'field' }, [h('label', null, '原因（记入流水与审计）'), reason]),
        errEl,
        h('div', { class: 'modal-actions' }, [h('button', { type: 'button', class: 'btn', onclick: () => mask.remove() }, '取消'), submitBtn]),
      ])
    } else {
      const newPw = h('input', { class: 'input', type: 'text', placeholder: '输入或点击右侧自动生成', style: 'font-family:ui-monospace,Consolas,monospace' })
      body = h('form', { class: 'modal', onsubmit: async (e) => {
        e.preventDefault()
        errEl.replaceChildren()
        if (newPw.value.length < 8 || newPw.value.length > 64) { errEl.appendChild(h('div', { class: 'err' }, '密码需 8-64 位')); return }
        submitBtn.disabled = true
        try {
          await adminApi.resetPassword(u.id, newPw.value)
          mask.remove()
          showNewPassword(u.username, newPw.value)
          load()
        } catch (err) { errEl.appendChild(h('div', { class: 'err' }, err.message)); submitBtn.disabled = false }
      } }, [
        h('h3', null, `重置密码 — ${u.username}`),
        h('div', { class: 'field' }, [h('label', null, '新密码（8-64 位，含字母和数字；重置后该用户需重新登录）'),
          h('div', { style: 'display:flex;gap:6px' }, [newPw, h('button', { type: 'button', class: 'btn btn-sm', style: 'white-space:nowrap', onclick: () => { newPw.value = genPassword(12) } }, '随机生成')]),
        ]),
        errEl,
        h('div', { class: 'modal-actions' }, [h('button', { type: 'button', class: 'btn', onclick: () => mask.remove() }, '取消'), submitBtn]),
      ])
    }
    const mask = modalMask(body)
  }

  function showNewPassword(username, pw) {
    let copied = false
    const copyBtn = h('button', { type: 'button', class: 'btn', onclick: () => { navigator.clipboard?.writeText(pw); copied = true; copyBtn.textContent = '已复制 ✓' } }, '复制密码')
    const doneBtn = h('button', { type: 'button', class: 'btn btn-primary', onclick: () => mask.remove() }, '完成')
    const mask = modalMask(h('div', { class: 'modal' }, [
      h('h3', null, `密码已重置 — ${username}`),
      h('div', { style: 'margin:10px 0' }, [
        h('label', { style: 'display:block;font-size:12.5px;color:#7a8087;margin-bottom:4px' }, '新密码（请立即复制并告知用户，关闭后不再显示）'),
        h('div', { class: 'mono', style: 'font-size:18px;font-weight:700;letter-spacing:1px;background:#f7f8f9;border-radius:8px;padding:12px 14px;user-select:all;word-break:break-all' }, pw),
      ]),
      h('div', { class: 'modal-actions' }, [copyBtn, doneBtn]),
    ]))
  }

  const qInput = h('input', { class: 'input grow', placeholder: '搜索用户名 / 联系方式' })
  qInput.addEventListener('input', debounce(() => { state.q = qInput.value; state.page = 1; load() }, 300))

  panel.appendChild(h('div', { class: 'card' }, [
    h('h2', null, '用户管理'),
    h('div', { class: 'toolbar' }, [qInput, h('span', { class: 'tip', dataset: { count: '' } }, '')]),
    h('div', { class: 'table-wrap' }, h('table', null, [
      h('thead', null, h('tr', null, ['ID', '用户名', '联系方式', '积分', '状态', '订单数', '累计已付', '注册时间', '操作'].map((x) => h('th', null, x)))),
      body,
    ])),
    h('div', { class: 'pager' }),
  ]))
  load()
}

/* ==================== 充值码 ==================== */

function renderCodes(panel) {
  const state = { status: 'all', page: 1, data: null }
  const body = h('tbody')

  const load = async () => {
    try {
      state.data = await adminApi.codes({ status: state.status, page: state.page, page_size: 20 })
      draw()
    } catch (e) { notify(e.message, true) }
  }

  function draw() {
    if (!state.data) return
    body.replaceChildren(...state.data.items.map((item) => {
      const [cls, label] = CODE_STATUS[item.status] || CODE_STATUS.unused
      return h('tr', null, [
        h('td', { class: 'mono' }, fmtCode(item.code)),
        h('td', null, item.points),
        h('td', null, h('span', { class: `badge ${cls}` }, label)),
        h('td', null, item.used_by_name || item.used_by || '-'),
        h('td', { class: 'time' }, fmtTime(item.used_at)),
        h('td', { class: 'time' }, fmtTime(item.created_at)),
        h('td', { class: 'wrap' }, item.note || '-'),
        h('td', null, item.status === 'unused' ? h('button', { class: 'btn btn-sm btn-danger', onclick: async () => {
          if (!confirm(`确定作废 ${item.code}？`)) return
          try { await adminApi.disableCode(item.code); notify('已作废'); load() } catch (e) { notify(e.message, true) }
        } }, '作废') : null),
      ])
    }))
    if (state.data.items.length === 0) {
      body.replaceChildren(h('tr', null, h('td', { colSpan: 8 }, h('div', { class: 'empty' }, '没有匹配的充值码'))))
    }
    const _pg = panel.querySelector('.pager'); if (_pg) _pg.replaceWith(pagerEl(state.page, (p) => { state.page = p; load() }, state.data.total, 20))
    const _ct = panel.querySelector('[data-count]'); if (_ct) _ct.textContent = `共 ${state.data.total} 个`
  }

  const statusSel = h('select', { onchange: (e) => { state.status = e.target.value; state.page = 1; load() } },
    [['all', '全部'], ['unused', '未使用'], ['used', '已使用'], ['disabled', '已作废']].map(([v, l]) => h('option', { value: v, selected: state.status === v || undefined }, l)))

  function openGen() {
    const count = h('input', { class: 'input', type: 'number', min: '1', max: '200', value: '10', required: true })
    const points = h('input', { class: 'input', type: 'number', min: '1', placeholder: '如 30', required: true })
    const note = h('input', { class: 'input', placeholder: '如 8 月代理批发' })
    const errEl = h('div')
    const submitBtn = h('button', { class: 'btn btn-primary', type: 'submit' }, '生成')
    const mask = modalMask(h('form', { class: 'modal', onsubmit: async (e) => {
      e.preventDefault()
      errEl.replaceChildren()
      submitBtn.disabled = true
      try {
        const res = await adminApi.generateCodes(parseInt(count.value, 10), parseInt(points.value, 10), note.value.trim())
        mask.remove()
        showGenerated(res.codes, res.points)
        notify(`已生成 ${res.codes.length} 个充值码`)
        load()
      } catch (err) { errEl.appendChild(h('div', { class: 'err' }, err.message)); submitBtn.disabled = false }
    } }, [
      h('h3', null, '批量生成充值码'),
      h('div', { class: 'field' }, [h('label', null, '数量（1-200）'), count]),
      h('div', { class: 'field' }, [h('label', null, '面值（积分/个）'), points]),
      h('div', { class: 'field' }, [h('label', null, '备注（批次说明，可选）'), note]),
      errEl,
      h('div', { class: 'modal-actions' }, [h('button', { type: 'button', class: 'btn', onclick: () => mask.remove() }, '取消'), submitBtn]),
    ]))
  }

  function showGenerated(codes, points) {
    const list = h('div', { class: 'code-list' })
    codes.forEach((c) => list.appendChild(h('div', null, c)))
    let copied = false
    const copyBtn = h('button', { type: 'button', class: 'btn', onclick: () => { navigator.clipboard?.writeText(codes.join('\n')); if (!copied) { copied = true; notify('已复制全部充值码') } } }, '复制全部')
    const closeBtn = h('button', { type: 'button', class: 'btn btn-primary', onclick: () => mask.remove() }, '关闭')
    const mask = modalMask(h('div', { class: 'modal' }, [
      h('h3', null, `新生成的充值码（${points} 积分/个）`),
      list,
      h('div', { class: 'modal-actions' }, [copyBtn, closeBtn]),
    ]))
  }

  panel.appendChild(h('div', { class: 'card' }, [
    h('h2', null, '充值码'),
    h('div', { class: 'toolbar' }, [
      statusSel,
      h('button', { class: 'btn btn-primary btn-sm', onclick: openGen }, '批量生成'),
      h('a', { class: 'btn btn-sm', href: adminApi.exportCodesUrl(state.status), title: '最多导出最近 5000 个；超出时文件末尾会附截断提示行' }, '导出 CSV'),
      h('span', { class: 'tip', dataset: { count: '' } }, ''),
    ]),
    h('div', { class: 'table-wrap' }, h('table', null, [
      h('thead', null, h('tr', null, ['充值码', '面值', '状态', '使用者', '兑换时间', '生成时间', '备注', '操作'].map((x) => h('th', null, x)))),
      body,
    ])),
    h('div', { class: 'pager' }),
  ]))
  load()
}

/* ==================== 流水与审计 ==================== */

/* ==================== 支付与对接（易支付 + 天河课程 API） ==================== */

function renderSyscfg(panel) {
  panel.replaceChildren(h('div', { class: 'tip' }, '对接配置加载中…'))

  async function load() {
    try {
      const data = await adminApi.syscfg()
      draw(data)
    } catch (e) {
      panel.replaceChildren(h('div', { class: 'error' }, '加载失败：' + e.message))
    }
  }

  function field(label, input, tip) {
    return h('label', { style: 'display:flex;flex-direction:column;gap:6px;min-width:220px;flex:1' }, [
      h('span', { style: 'font-size:13px;color:var(--text-muted)' }, label),
      input,
      tip ? h('span', { class: 'tip', style: 'margin:0' }, tip) : null,
    ])
  }

  function textInput(name, value, opts = {}) {
    return h('input', {
      class: 'input',
      name,
      type: opts.type || 'text',
      value: value || '',
      placeholder: opts.placeholder || '',
      autocomplete: 'off',
      style: 'width:100%',
    })
  }

  function draw(data) {
    const siteURL = textInput('site_url', data.site_url, { placeholder: 'https://你的域名' })
    const epayURL = textInput('epay_api_url', data.epay_api_url, { placeholder: 'https://pay.example.com' })
    const epayPID = textInput('epay_pid', data.epay_pid, { placeholder: '商户 ID' })
    const epayKey = textInput('epay_key', '', {
      type: 'password',
      placeholder: data.epay_key_set ? '已配置，留空则不修改' : '易支付商户密钥',
    })
    const epayType = h('select', { class: 'input', name: 'epay_type', style: 'width:100%' }, [
      h('option', { value: 'wxpay', selected: (data.epay_type || 'wxpay') === 'wxpay' || undefined }, '微信 wxpay'),
      h('option', { value: 'alipay', selected: data.epay_type === 'alipay' || undefined }, '支付宝 alipay'),
      h('option', { value: 'qqpay', selected: data.epay_type === 'qqpay' || undefined }, 'QQ钱包 qqpay'),
    ])
    const skyURL = textInput('skyriver_url', data.skyriver_url, { placeholder: 'https://天河域名（不要带 /api.php）' })
    const skyUID = textInput('skyriver_uid', data.skyriver_uid, { placeholder: '对接 uid' })
    const skyKey = textInput('skyriver_key', '', {
      type: 'password',
      placeholder: data.skyriver_key_set ? '已配置，留空则不修改' : '对接 key',
    })
    const coursePrice = textInput('course_price', data.course_price, { placeholder: '3.00' })
    const sxdkURL = textInput('sxdk_upstream_url', data.sxdk_upstream_url, { placeholder: 'https://上级/sxdk/upstream.php' })
    const sxdkAdmin = textInput('sxdk_admin', data.sxdk_admin, { placeholder: '对接 uid' })
    const sxdkToken = textInput('sxdk_token', '', {
      type: 'password',
      placeholder: data.sxdk_token_set ? '已配置，留空则不修改' : '对接 token/key',
    })
    const sxdkPrice = textInput('sxdk_price_yuan', data.sxdk_price_yuan || '1', { placeholder: '1' })
    const regOpen = h('select', { class: 'input', name: 'register_open', style: 'width:100%' }, [
      h('option', { value: '1', selected: data.register_open ? true : undefined }, '开放注册'),
      h('option', { value: '0', selected: !data.register_open ? true : undefined }, '关闭注册'),
    ])
    const regCode = textInput('register_code', data.register_code || '', { placeholder: '用户注册时填写的邀请码' })

    const saveBtn = h('button', { class: 'btn', type: 'button' }, '保存配置')
    saveBtn.onclick = async () => {
      saveBtn.disabled = true
      saveBtn.textContent = '保存中…'
      try {
        const body = {
          site_url: siteURL.value.trim(),
          epay_api_url: epayURL.value.trim(),
          epay_pid: epayPID.value.trim(),
          epay_key: epayKey.value,
          epay_type: epayType.value,
          skyriver_url: skyURL.value.trim(),
          skyriver_uid: skyUID.value.trim(),
          skyriver_key: skyKey.value,
          course_price: coursePrice.value.trim(),
          sxdk_upstream_url: sxdkURL.value.trim(),
          sxdk_admin: sxdkAdmin.value.trim(),
          sxdk_token: sxdkToken.value,
          sxdk_price_yuan: sxdkPrice.value.trim(),
          register_open: regOpen.value === '1',
          register_code: regCode.value.trim(),
        }
        const next = await adminApi.saveSyscfg(body)
        notify('已保存，立即生效（无需重启）')
        epayKey.value = ''
        skyKey.value = ''
        sxdkToken.value = ''
        draw(next)
      } catch (e) {
        notify(e.message, true)
        saveBtn.disabled = false
        saveBtn.textContent = '保存配置'
      }
    }

    panel.replaceChildren(h('div', null, [
      h('div', { class: 'card', style: 'padding:18px;margin-bottom:14px' }, [
        h('div', { style: 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px' }, [
          h('h2', { style: 'font-size:16px;margin:0' }, '用户注册 / 邀请码'),
          h('span', { class: 'badge ' + (data.register_open ? 'badge-done' : 'badge-banned') },
            data.register_open ? '注册开放中' : '注册已关闭'),
        ]),
        h('div', { class: 'tip', style: 'margin-bottom:12px' },
          '关闭后前台无法注册新账号（已有用户仍可登录）。开放时必须设置邀请码，用户注册时填写一致才能通过。'),
        h('div', { style: 'display:flex;flex-wrap:wrap;gap:12px' }, [
          field('注册开关', regOpen),
          field('邀请码', regCode, '发给用户的注册口令；改完立刻生效'),
        ]),
      ]),
      h('div', { class: 'card', style: 'padding:18px;margin-bottom:14px' }, [
        h('div', { style: 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px' }, [
          h('h2', { style: 'font-size:16px;margin:0' }, '易支付（在线充值/付款）'),
          h('span', { class: 'badge ' + (data.pay_enabled ? 'badge-done' : 'badge-banned') },
            data.pay_enabled ? '已启用' : '未启用'),
        ]),
        h('div', { class: 'tip', style: 'margin-bottom:12px' },
          '三项都填齐才会开启在线支付。站点地址用于回调；密钥留空表示不改。'),
        h('div', { style: 'display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px' }, [
          field('站点地址 SITE_URL', siteURL, '回调/跳转用，填公网 https 域名'),
          field('易支付接口地址', epayURL),
        ]),
        h('div', { style: 'display:flex;flex-wrap:wrap;gap:12px' }, [
          field('商户 PID', epayPID),
          field('商户密钥 KEY', epayKey),
          field('默认支付方式', epayType),
        ]),
      ]),
      h('div', { class: 'card', style: 'padding:18px;margin-bottom:14px' }, [
        h('div', { style: 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px' }, [
          h('h2', { style: 'font-size:16px;margin:0' }, '货源对接（天河/丸子同类）'),
          h('span', { class: 'badge ' + (data.skyriver_ok ? 'badge-done' : 'badge-banned') },
            data.skyriver_ok ? '已对接' : '未对接'),
          (() => {
            const balTip = h('span', { class: 'tip', style: 'margin:0' }, '')
            const balBtn = h('button', { class: 'btn btn-sm', type: 'button' }, '查询余额')
            balBtn.onclick = async () => {
              balBtn.disabled = true
              balTip.textContent = '查询中…'
              try {
                const res = await adminApi.skyriverBalance()
                balTip.textContent = `上游余额：${res.balance}`
                notify(`余额 ${res.balance}`)
              } catch (e) {
                balTip.textContent = e.message || '查询失败'
                notify(e.message, true)
              } finally { balBtn.disabled = false }
            }
            return h('span', { style: 'display:inline-flex;align-items:center;gap:8px' }, [balBtn, balTip])
          })(),
        ]),
        h('div', { class: 'tip', style: 'margin-bottom:12px' },
          '查课/下单/同步走 api.php。地址只填根域名（如 https://xuexiba.icu），不要带 /api.php。密钥留空表示不改。'),
        h('div', { style: 'display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px' }, [
          field('对接地址', skyURL),
          field('对接 UID', skyUID),
          field('对接 KEY', skyKey),
        ]),
        h('div', { style: 'display:flex;flex-wrap:wrap;gap:12px' }, [
          field('主流平台默认单价（元/门）', coursePrice, '智慧树/学习通等「平台价格」页未单独改价时用这个'),
        ]),
      ]),
      h('div', { class: 'card', style: 'padding:18px;margin-bottom:14px' }, [
        h('div', { style: 'display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px' }, [
          h('h2', { style: 'font-size:16px;margin:0' }, '实习打卡对接'),
          h('span', { class: 'badge ' + (data.sxdk_ok ? 'badge-done' : 'badge-banned') },
            data.sxdk_ok ? '已对接' : '未对接'),
        ]),
        h('div', { class: 'tip', style: 'margin-bottom:12px' },
          '对接天河/套娃上级 upstream。三项齐了用户端才能正常查账号、下单；密钥留空表示不改。'),
        h('div', { style: 'display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px' }, [
          field('上游地址 SXDK_UPSTREAM_URL', sxdkURL, '一般填 …/sxdk/upstream.php，末尾不要 /'),
          field('对接 UID', sxdkAdmin),
          field('对接 Token', sxdkToken),
        ]),
        h('div', { style: 'display:flex;flex-wrap:wrap;gap:12px' }, [
          field('售价（积分/天）', sxdkPrice),
        ]),
      ]),
      h('div', { style: 'display:flex;align-items:center;gap:12px;flex-wrap:wrap' }, [
        saveBtn,
        h('span', { class: 'tip' }, data.source === 'db' ? '当前以数据库配置为准（可覆盖环境变量）' : '尚未保存过，当前来自环境变量/.env'),
      ]),
    ]))
  }

  load()
}

function renderLogs(panel) {
  const state = { tab: logsActiveTab, p1: 1, p2: 1, p3: 1, p4: 1, d: {} }
  const theadRow = h('tr')
  const body = h('tbody')
  const pagerBox = h('div', { class: 'pager', id: 'logs-pager' })
  const empty = (n) => h('tr', null, h('td', { colSpan: n }, h('div', { class: 'empty' }, '暂无记录')))

  const HEADS = {
    points: ['时间', '用户', '变动', '余额', '说明', '关联订单'],
    banned: ['IP', '封禁时间', '原因', '操作'],
    audit: ['时间', '操作', '详情'],
    login: ['时间', '账号', 'IP', '结果'],
  }

  async function loadData() {
    const t = state.tab
    theadRow.replaceChildren(...HEADS[t].map((x) => h('th', null, x)))
    body.replaceChildren(h('tr', null, h('td', { colSpan: HEADS[t].length }, h('div', { class: 'empty' }, '加载中…'))))
    try {
      if (t === 'points') { state.d.points = await adminApi.pointLogs({ page: state.p1, page_size: 50 }); draw('points') }
      else if (t === 'banned') { state.d.banned = await adminApi.bannedIPs({ page: state.p2, page_size: 50 }); draw('banned') }
      else if (t === 'audit') { state.d.audit = await adminApi.auditLogs({ page: state.p3, page_size: 50 }); draw('audit') }
      else { state.d.login = await adminApi.loginLogs({ page: state.p4, page_size: 50 }); draw('login') }
    } catch (e) {
      // 所有 tab 出错都要可见：不仅提示，还替换掉「加载中…」骨架，避免表格永远停在加载态
      notify((e && e.message) || '加载失败', true)
      body.replaceChildren(h('tr', null, h('td', { colSpan: HEADS[t].length }, h('div', { class: 'empty' }, '加载失败，请刷新重试'))))
    }
  }

  function draw(t) {
    const data = state.d[t]
    if (!data) return
    let rows, page, setPage, pageSize
    if (t === 'points') {
      pageSize = data.page_size
      rows = data.items.map((item) => h('tr', null, [
        h('td', { class: 'time' }, fmtTime(item.created_at)),
        h('td', null, item.username),
        h('td', { class: item.delta >= 0 ? '' : 'pw' }, item.delta >= 0 ? `+${item.delta}` : item.delta),
        h('td', { class: 'mono' }, item.balance_after),
        h('td', { class: 'wrap' }, item.reason),
        h('td', { class: 'mono' }, item.order_id ? item.order_id.slice(0, 8) + '…' : '-'),
      ]))
      page = state.p1; setPage = (p) => { state.p1 = p; loadData() }
    } else if (t === 'banned') {
      pageSize = data.page_size
      rows = data.items.map((item) => h('tr', null, [
        h('td', { class: 'mono' }, item.ip),
        h('td', { class: 'time' }, fmtTime(item.banned_at)),
        h('td', { class: 'wrap' }, item.reason || '-'),
        h('td', null, h('button', { class: 'btn btn-sm btn-ok', onclick: async () => {
          if (!confirm(`确定解封 ${item.ip}？`)) return
          try { await adminApi.unbanIP(item.ip); notify(`已解封 ${item.ip}`); loadData() } catch (e) { notify(e.message, true) }
        } }, '解封')),
      ]))
      page = state.p2; setPage = (p) => { state.p2 = p; loadData() }
    } else if (t === 'audit') {
      pageSize = data.page_size
      rows = data.items.map((item) => h('tr', null, [
        h('td', { class: 'time' }, fmtTime(item.created_at)),
        h('td', null, AUDIT_ACTIONS[item.action] || item.action),
        h('td', { class: 'wrap mono', style: 'font-size:12px' }, item.detail),
      ]))
      page = state.p3; setPage = (p) => { state.p3 = p; loadData() }
    } else {
      pageSize = data.page_size
      rows = data.items.map((item) => h('tr', null, [
        h('td', { class: 'time' }, fmtTime(item.created_at)),
        h('td', null, item.username),
        h('td', null, item.ip),
        h('td', null, item.success ? h('span', { class: 'badge badge-done' }, '成功') : h('span', { class: 'badge badge-banned' }, '失败')),
      ]))
      page = state.p4; setPage = (p) => { state.p4 = p; loadData() }
    }
    body.replaceChildren(rows.length ? rows : empty(HEADS[t].length))
    pagerBox.replaceChildren(pagerEl(page, setPage, data.total, pageSize))
  }

  const tabsBar = h('div', { class: 'toolbar' })
  const bannedTip = h('div', { class: 'tip', style: 'display:none;margin-bottom:8px' }, '查课频率异常的 IP 会被系统永久封禁；确认误封（如同办宽带多人共用出口）可在此解封。封禁/解封均记入审计日志。')
  if (logsActiveTab === 'banned') bannedTip.style.display = '' // 重进时若停在「IP封禁」子页，展开对应提示
  ;[['points', '积分流水'], ['banned', 'IP 封禁'], ['audit', '审计日志'], ['login', '登录日志']].forEach(([k, l]) => {
    tabsBar.appendChild(h('button', { class: 'nav-tab' + (state.tab === k ? ' active' : ''), onclick: () => {
      state.tab = logsActiveTab = k
      bannedTip.style.display = k === 'banned' ? '' : 'none'
      document.querySelectorAll('#admin-panel .logs-tab').forEach((b) => b.classList.toggle('active', b.dataset.logTab === k))
      loadData()
    }, dataset: { logTab: k } }, l))
  })

  panel.appendChild(h('div', { class: 'card' }, [
    tabsBar,
    bannedTip,
    h('div', { class: 'table-wrap' }, h('table', null, [h('thead', null, theadRow), body])),
    pagerBox,
  ]))

  // 给 tab 按钮统一标 class 便于高亮管理
  tabsBar.querySelectorAll('button').forEach((b) => b.classList.add('logs-tab'))
  loadData()
}

/* ==================== 自动签到管理（开关 + 定价 + 订单） ==================== */

function renderQd(panel) {
  panel.replaceChildren(h('div', { class: 'tip' }, '自动签到设置加载中…'))
  let settings = null

  async function load() {
    try {
      settings = await adminApi.qdSettings()
      draw()
    } catch (e) {
      panel.replaceChildren(h('div', { class: 'error' }, '加载失败：' + e.message))
    }
  }

  function draw() {
    const panel2 = h('div')
    panel2.appendChild(h('div', { class: 'card', style: 'padding:18px;margin-bottom:14px' }, [
      h('h2', { style: 'font-size:16px;margin-bottom:10px' }, '学习通自动签到'),
      !settings.configured
        ? h('div', { class: 'tip', style: 'color:var(--danger)' },
            '引擎未配置：请在服务端 .env 填写 QD_ENGINE_URL / QD_UID_WEEK / QD_KEY_WEEK（月卡另配 QD_UID_MONTH / QD_KEY_MONTH）后重启。')
        : h('div', { class: 'tip', style: 'color:var(--text-muted)' },
            `引擎：${settings.engine_url || '-'}　批发余额：${settings.balance ? settings.balance + ' 元' : (settings.balance_week || settings.balance_month ? `周 ${settings.balance_week || '-'} / 月 ${settings.balance_month || '-'} 元` : '查询失败')}`),
      h('div', { style: 'display:flex;align-items:center;gap:14px;margin-top:12px;flex-wrap:wrap' }, [
        h('button', { class: 'btn btn-sm', onclick: async (e) => {
          const on = settings.switch_on
          if (on && !confirm('关闭后用户端将隐藏自动签到入口，且无法新建订单；已有订单仍可查看状态/重新上号。确定关闭？')) return
          try {
            settings = await adminApi.qdSetEnabled(!on)
            notify(!on ? '自动签到功能已开启' : '自动签到功能已关闭')
            draw()
          } catch (err) { notify(err.message, true) }
        } }, settings.switch_on ? '关闭功能' : '开启功能'),
        h('span', { class: 'badge ' + (settings.available ? 'badge-done' : 'badge-banned') },
          settings.available ? '用户端开放中' : '用户端已关闭'),
      ]),
      h('div', { style: 'display:flex;gap:14px;margin-top:14px;flex-wrap:wrap;align-items:flex-end' }, [
        qdPriceInput('周卡（积分）', settings.price_week, 'week'),
        qdPriceInput('月卡（积分）', settings.price_month, 'month'),
      ]),
    ]))

    const ordersBox = h('div', { class: 'tip' }, '订单加载中…')
    panel2.appendChild(ordersBox)
    ;(async () => {
      try {
        const d = await adminApi.qdOrdersAdmin()
        const items = Array.isArray(d.items) ? d.items : []
        if (!items.length) {
          ordersBox.replaceChildren(h('div', { class: 'tip', style: 'color:var(--text-muted)' }, '暂无自动签到订单'))
          return
        }
        ordersBox.replaceChildren(h('div', { class: 'card', style: 'padding:0;overflow:hidden' },
          h('div', { class: 'table-wrap' }, h('table', null, [
            h('thead', null, h('tr', null, ['时间', '用户ID', '手机号', '卡型', '状态', '引擎状态', '服务到期', '最近动态'].map((c) => h('th', null, c)))),
            h('tbody', null, items.map((o) => h('tr', null, [
              h('td', null, new Date(o.created_at * 1000).toLocaleString('zh-CN', { hour12: false })),
              h('td', null, String(o.user_id || '')),
              h('td', null, o.phone),
              h('td', null, o.plan_name),
              h('td', null, qdAdminBadge(o.status)),
              h('td', null, o.engine_status || '-'),
              h('td', null, o.end_at ? new Date(o.end_at * 1000).toLocaleString('zh-CN', { hour12: false }) : '-'),
              h('td', { style: 'max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' }, o.last_msg || '-'),
            ]))),
          ]))))
      } catch (e) {
        ordersBox.replaceChildren(h('div', { class: 'error' }, '订单加载失败：' + e.message))
      }
    })()

    panel.replaceChildren(panel2)
  }

  function qdPriceInput(label, value, plan) {
    const input = h('input', { class: 'input', type: 'number', step: '0.01', min: '0', value: value, style: 'width:130px' })
    return h('div', { class: 'field' }, [
      h('label', null, label),
      h('div', { style: 'display:flex;gap:8px' }, [
        input,
        h('button', { class: 'btn btn-sm', onclick: async () => {
          const v = parseFloat(input.value)
          if (isNaN(v) || v < 0) { notify('价格不合法', true); return }
          try {
            settings = await adminApi.qdSetPrice(plan, v)
            notify('已保存：' + label + ' ' + v + ' 积分')
            draw()
          } catch (err) { notify(err.message, true) }
        } }, '保存'),
      ]),
    ])
  }

  load()
}

function qdAdminBadge(status) {
  const map = {
    processing: ['badge-progress', '开通中'],
    pending_on: ['badge-progress', '待上号'],
    active: ['badge-done', '监控中'],
    done: ['badge-done', '已结束'],
    error: ['badge-banned', '已失败'],
  }
  const [cls, label] = map[status] || ['badge-gray', status]
  return h('span', { class: `badge ${cls}` }, label)
}

/* ==================== 启动 ==================== */

async function bootAdmin() {
  initAdminEvents()
  adminApi.me()
    .then((me) => { adminState.user = me; applyAdminView() })
    .catch(() => { adminState.user = null; applyAdminView() })
}

document.addEventListener('DOMContentLoaded', bootAdmin)