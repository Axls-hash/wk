// qd.js —— 学习通自动签到工作台（qd_auto.php 同款交互，数据源 /api/qd/*）
'use strict'
const $ = (s) => document.querySelector(s)
const app = $('#app')
let accounts = []      // 订单列表（服务端）
let plans = []         // 卡型价格
let pkg = 'week'
let pollTimer = null
let curLogOrderId = '' // 当前打开日志弹窗的订单
let curPushOrderId = ''
let curFaceOrderId = ''

/* ---------- toast（qd_auto 同款） ---------- */
let toastTimer = null
function toast(msg, bad) {
  const t = $('#toast')
  t.textContent = msg
  t.className = 'toast show' + (bad ? ' bad' : ' ok')
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { t.className = 'toast' }, 2600)
}

/* ---------- 登录守卫 ---------- */
async function ensureLogin() {
  try {
    await api.qdOrders()
    return true
  } catch (e) {
    if (e.auth) { drawLogin(); return false }
    throw e
  }
}

function drawLogin() {
  document.title = '登录 · 海鸥-学习通全自动签到'
  const u = h('input', { type: 'text', placeholder: '用户名', autocomplete: 'username' })
  const p = h('input', { type: 'password', placeholder: '密码', autocomplete: 'current-password' })
  const btn = h('button', { class: 'btn btn-go', type: 'submit', style: 'width:100%' }, '登 录')
  const err = h('div', { class: 'hint', style: 'color:var(--bad);margin-top:10px;min-height:16px' })
  app.replaceChildren(
    h('div', { class: 'topbar' }, [
      h('div', { class: 'tt' }, [h('h1', null, '学习通全自动签到'), h('div', { class: 'sub' }, '登录后管理你的签到账号')]),
    ]),
    h('div', { class: 'card', style: 'padding:22px' }, h('form', { onsubmit: async (e) => {
      e.preventDefault()
      btn.disabled = true
      btn.innerHTML = '<span class="spin"></span> 登录中'
      err.textContent = ''
      try {
        await api.agentLogin({ username: u.value.trim(), password: p.value })
        await boot()
      } catch (ex) {
        err.textContent = ex.message || '登录失败'
        btn.disabled = false
        btn.textContent = '登 录'
      }
    } }, [
      h('div', { class: 'field' }, [h('label', null, '用户名'), u]),
      h('div', { class: 'field' }, [h('label', null, '密码'), p]),
      btn, err,
      h('div', { class: 'hint', style: 'margin-top:12px' }, '没有账号？去首页注册代理，或登录后再回到本页。'),
      h('div', { class: 'hint' }, [h('a', { href: '/', style: 'color:var(--acc)' }, '← 返回首页')]),
    ])),
  )
}

/* ---------- 弹窗开关（qd_auto 同款） ---------- */
function openModal(maskId, modalId) {
  $('#' + maskId).classList.add('show')
  $('#' + modalId).classList.add('show')
}
function closeModal(maskId, modalId) {
  $('#' + maskId).classList.remove('show')
  $('#' + modalId).classList.remove('show')
}

/* ---------- 卡型价格 ---------- */
function loadPrices() {
  api.qdPlans().then(function (rs) {
    plans = Array.isArray(rs.plans) ? rs.plans : []
    const w = plans.find(function (p) { return p.key === 'week' })
    const m = plans.find(function (p) { return p.key === 'month' })
    if (w) $('#pkgWeek').textContent = '7 天 · ' + fmtNum(w.price) + ' 积分'
    if (m) $('#pkgMonth').textContent = '30 天 · ' + fmtNum(m.price) + ' 积分'
  }).catch(function () {})
}

/* ---------- 上号 ---------- */
function pickPkg(el) {
  document.querySelectorAll('#pkgRow .pkg').forEach(function (p) { p.classList.remove('active') })
  el.classList.add('active')
  pkg = el.dataset.pkg || 'week'
}
function openAdd(phone, pass) {
  $('#phone').value = phone || ''
  $('#pass').value = pass || ''
  $('#btnAdd').disabled = false
  $('#btnAdd').textContent = '登录上号（扣费开通）'
  openModal('addMask', 'addModal')
  setTimeout(function () { $('#phone').focus() }, 120)
}
function closeAdd() { closeModal('addMask', 'addModal') }
function togglePw() {
  const p = $('#pass')
  p.type = p.type === 'password' ? 'text' : 'password'
  $('#eye').textContent = p.type === 'password' ? '👁' : '🙈'
}
function byPhone(phone) {
  return accounts.find(function (a) { return a.phone === phone })
}
function doAdd() {
  const phone = $('#phone').value.trim()
  const pass = $('#pass').value
  if (!phone) { toast('请输入手机号和密码', 'bad'); return }
  const btn = $('#btnAdd')
  btn.disabled = true
  btn.innerHTML = '<span class="spin"></span> 登录上号中'
  const done = function (fail) {
    btn.disabled = false
    btn.textContent = '登录上号（扣费开通）'
    if (fail) toast(fail, true)
  }
  const finishLogin = function () {
    toast('登录成功，自动监控已启动', 'ok')
    closeAdd()
    refresh()
  }
  // 已有进行中订单的号码 = 重复上号：走免费重新上号（服务期内不重复扣费）
  const exist = byPhone(phone)
  if (exist && (exist.status === 'active' || exist.status === 'pending_on')) {
    api.qdRelogin(exist.order_id, pass).then(function (v) {
      if (v.status === 'active') { finishLogin() } else { done(v.last_msg || '状态异常') }
    }).catch(function (e) { done(e.message) })
    return
  }
  if (!pass) { done('请输入手机号和密码'); return }
  api.qdCreateOrder({ phone: phone, pass: pass, plan: pkg, expected_points: (plans.find(function (p) { return p.key === pkg }) || {}).price })
    .then(function (v) {
      if (v.status === 'active' || v.status === 'pending_on') { finishLogin() } else { done(v.last_msg || '状态异常') }
    })
    .catch(function (e) {
      // 竞态兜底：下单瞬间另一端已开通 → 转免费重登
      if (e.status === 400 && /已有进行中/.test(e.message)) {
        api.qdOrders().then(function (d) {
          accounts = Array.isArray(d.items) ? d.items : []
          const ex = byPhone(phone)
          if (ex) {
            api.qdRelogin(ex.order_id, pass).then(function (v) {
              if (v.status === 'active') { finishLogin() } else { done(v.last_msg || '状态异常') }
            }).catch(function (e2) { done(e2.message) })
            return
          }
          done(e.message)
        })
        return
      }
      done(e.message)
    })
}

/* ---------- 状态轮询 ---------- */
function refresh() {
  api.qdOrders().then(function (d) {
    accounts = Array.isArray(d.items) ? d.items : []
    render()
    if (curLogOrderId) {
      api.qdOrder(curLogOrderId).then(function (o) {
        renderLogBox(o)
      }).catch(function () {})
    }
  }).catch(function () {})
}

/* ---------- 渲染（qd_auto 同款） ---------- */
function initials(phone) {
  if (!phone) return '?'
  return phone.slice(-4)
}
function statusView(o) {
  if (o.status === 'error') return { cls: 'err', label: '开通失败', avt: 'err' }
  if (o.status === 'processing') return { cls: 'off', label: '开通中', avt: 'idle' }
  if (o.status === 'done') return { cls: 'off', label: '已结束', avt: 'idle' }
  if (o.status === 'pending_on') return { cls: 'wrn', label: '待上号', avt: 'idle' }
  if (o.status === 'active') {
    if (o.engine_status === 'failed') return { cls: 'err', label: '登录失败', avt: 'err' }
    if (o.engine_status === 'stopped') return { cls: 'wrn', label: '已停止', avt: 'idle' }
    if (o.engine_status === 'logged_in') return { cls: 'off', label: '待启动', avt: 'idle' }
    if (o.engine_status === 'running') return { cls: 'on', label: '监控中', avt: 'run' }
  }
  return { cls: 'off', label: '同步中…', avt: 'idle' }
}
function render() {
  const list = $('#acctList')
  let run = 0, errCount = 0, signs = 0
  accounts.forEach(function (o) {
    const s = statusView(o)
    if (s.cls === 'on') run++
    if (o.status === 'pending_on' || (o.status === 'active' && o.engine_status === 'failed')) errCount++
    signs += o.sign_ok || 0
  })
  const chips = $('#chips')
  if (accounts.length) {
    chips.innerHTML =
      '<span class="chip">账号 <b>' + accounts.length + '</b></span>' +
      '<span class="chip on">监控中 <b>' + run + '</b></span>' +
      (errCount ? '<span class="chip bad">待处理 <b>' + errCount + '</b></span>' : '') +
      '<span class="chip">累计签到 <b>' + signs + '</b> 次</span>'
    chips.style.display = ''
  } else { chips.style.display = 'none' }

  if (!accounts.length) {
    list.innerHTML = '<div class="empty"><div class="big">＋</div>' +
      '<h3>还没有上号的账号</h3>' +
      '<p>点击右上角「上号」，登录学习通账号后<br>本站自动监控该账号的签到活动，支持同时管理多个账号</p>' +
      '<button class="btn btn-go" onclick="openAdd()">＋ 上号</button></div>'
    return
  }
  list.innerHTML = accounts.map(function (o) {
    const s = statusView(o)
    const meta =
      '<span>' + esc(o.name || '—') + (o.uid ? ' · UID ' + esc(o.uid) : '') + '</span>' +
      (o.sign_ok ? '<span>已签 <b class="ok-c">' + o.sign_ok + '</b> 次' +
        (o.sign_fail ? ' · <b class="bad-c">失败 ' + o.sign_fail + '</b>' : '') +
        '</span>' : '') +
      (o.end_at ? '<span>服务至 ' + esc(fmtTime(o.end_at)) + '</span>' : '')
    const last = o.last_msg ? '<span class="last">' + esc(o.last_msg) + '</span>' : ''
    const acts = (o.status === 'active' || o.status === 'pending_on')
      ? '<button class="btn btn-sm" onclick="openLog(\'' + o.order_id + '\')">日志</button>' +
        '<button class="btn btn-sm" onclick="openPush(\'' + o.order_id + '\')">推送</button>' +
        '<button class="btn btn-sm" onclick="openFace(\'' + o.order_id + '\')">人脸</button>' +
        (o.status === 'pending_on' ? '<button class="btn btn-sm primary" onclick="openAdd(\'' + esc(o.phone) + '\')">重新上号</button>' : '')
      : '<button class="btn btn-sm" onclick="openLog(\'' + o.order_id + '\')">日志</button>'
    const avtTxt = o.status === 'pending_on' ? '!' : initials(o.phone)
    return '<div class="acct' + (s.cls === 'on' ? '' : ' off') + '">' +
      '<div class="avt ' + s.avt + '">' + esc(avtTxt) + '</div>' +
      '<div class="info">' +
        '<div class="ph">' + esc(o.phone || '同步中…') +
          (o.plan_name ? '<span class="pkg-tag">' + esc(o.plan_name) + '</span>' : '') +
          '<span class="status-pill ' + s.cls + '"><span class="dot"></span>' + s.label + '</span>' +
        '</div>' +
        '<div class="meta">' + meta + last + '</div>' +
      '</div>' +
      '<div class="acts">' + acts + '</div>' +
    '</div>'
  }).join('')
}

/* ---------- 日志弹窗 ---------- */
function openLog(orderId) {
  curLogOrderId = orderId
  const a = accounts.find(function (x) { return x.order_id === orderId }) || {}
  $('#logTitle').textContent = '实时日志 · ' + (a.phone || '')
  $('#logSub').textContent = '每 5 秒自动刷新 · 关闭窗口不影响监控'
  $('#logTerm').innerHTML = '<div style="text-align:center;color:#96a0b2;padding:20px 0">加载中…</div>'
  openModal('logMask', 'logModal')
  api.qdOrder(orderId).then(renderLogBox).catch(function (e) {
    $('#logTerm').innerHTML = '<div style="text-align:center;color:var(--bad);padding:20px 0">' + esc(e.message) + '</div>'
  })
}
function renderLogBox(o) {
  const logs = Array.isArray(o.logs) ? o.logs : []
  const term = $('#logTerm')
  if (!logs.length) {
    term.innerHTML = '<div style="text-align:center;color:#96a0b2;padding:20px 0">暂无日志 · 监控运行中，等待签到活动…</div>'
  } else {
    const cls = { info: '', success: 'ok', error: 'bad', warn: 'warn', sign: 'sign' }
    term.innerHTML = logs.slice(-80).reverse().map(function (l) {
      return '<div class="log-line ' + (cls[l.level] || '') + '"><span class="t">' + esc(l.time) +
        '</span><span class="lv"></span><span class="m">' + esc(l.msg) + '</span></div>'
    }).join('')
  }
  const records = Array.isArray(o.records) ? o.records : []
  $('#recTable tbody').innerHTML = records.slice().reverse().map(function (r) {
    return '<tr><td style="white-space:nowrap">' + esc(r.time) + '</td><td>' + esc(r.title) +
      '</td><td>' + esc(r.course) + '</td></tr>'
  }).join('') || '<tr><td colspan="3" style="text-align:center;color:#98a1b2">暂无签到记录</td></tr>'
}
function closeLog() { curLogOrderId = ''; closeModal('logMask', 'logModal') }

/* ---------- 推送 ---------- */
function openPush(orderId) {
  curPushOrderId = orderId
  const a = accounts.find(function (x) { return x.order_id === orderId }) || {}
  $('#pushSub').textContent = '账号 ' + (a.phone || '') + ' · 每次签到成功自动推送到微信；留空不推送'
  $('#pushUrl').value = a.push_url || '' // 已保存的链接回填，可直接改
  openModal('pushMask', 'pushModal')
  setTimeout(function () { $('#pushUrl').focus() }, 120)
}
function closePush() { closeModal('pushMask', 'pushModal') }
function savePush() {
  const btn = $('#btnPush')
  btn.disabled = true
  api.qdPush(curPushOrderId, $('#pushUrl').value.trim()).then(function () {
    btn.disabled = false
    toast('推送链接已保存', 'ok')
    closePush()
  }).catch(function (e) {
    btn.disabled = false
    toast(e.message || '保存失败', true)
  })
}

/* ---------- 人脸 ---------- */
function openFace(orderId) {
  curFaceOrderId = orderId
  const a = accounts.find(function (x) { return x.order_id === orderId }) || {}
  $('#faceSub').textContent = '账号 ' + (a.phone || '') + ' · 上传后该账号签到自动使用'
  $('#facePrev').style.display = 'none'
  $('#facePh').style.display = ''
  $('#facePhT').style.display = ''
  $('#faceFile').value = ''
  openModal('faceMask', 'faceModal')
}
function closeFace() { closeModal('faceMask', 'faceModal') }
function facePicked(inp) {
  const f = inp.files[0]
  const prev = $('#facePrev')
  const ph = $('#facePh'), pt = $('#facePhT')
  if (!f) { prev.style.display = 'none'; ph.style.display = ''; pt.style.display = ''; return }
  const rd = new FileReader()
  rd.onload = function (e) { prev.src = e.target.result; prev.style.display = ''; ph.style.display = 'none'; pt.style.display = 'none' }
  rd.readAsDataURL(f)
}
function uploadFace() {
  const f = $('#faceFile').files[0]
  if (!f) { toast('请先选择照片', 'bad'); return }
  if (f.size > 10 * 1024 * 1024) { toast('文件不能超过 10MB', 'bad'); return }
  api.qdPhoto(curFaceOrderId, f).then(function (rs) {
    toast(rs.message || '人脸照片已保存，该账号签到自动使用', false)
    closeFace()
  }).catch(function (e) { toast(e.message || '上传失败', true) })
}

/* ---------- 事件绑定（CSP 禁内联 handler，统一 addEventListener） ---------- */
function bindEvents() {
  $('#btnRead').addEventListener('click', openRead)
  $('#btnTopAdd').addEventListener('click', () => openAdd())
  $('#addMask').addEventListener('click', closeAdd)
  $('#addModal .m-close').addEventListener('click', closeAdd)
  $('#phone').addEventListener('keydown', (e) => { if (e.key === 'Enter') doAdd() })
  $('#pass').addEventListener('keydown', (e) => { if (e.key === 'Enter') doAdd() })
  $('#eye').addEventListener('click', togglePw)
  $('#pkgWeekCard').addEventListener('click', function () { pickPkg(this) })
  $('#pkgMonthCard').addEventListener('click', function () { pickPkg(this) })
  $('#btnAdd').addEventListener('click', doAdd)
  $('#readMask').addEventListener('click', closeRead)
  $('#readModal .m-close').addEventListener('click', closeRead)
  $('#logMask').addEventListener('click', closeLog)
  $('#logModal .m-close').addEventListener('click', closeLog)
  $('#pushMask').addEventListener('click', closePush)
  $('#pushModal .m-close').addEventListener('click', closePush)
  $('#pushUrl').addEventListener('keydown', (e) => { if (e.key === 'Enter') savePush() })
  $('#btnPush').addEventListener('click', savePush)
  $('#faceMask').addEventListener('click', closeFace)
  $('#faceModal .m-close').addEventListener('click', closeFace)
  $('#faceBox').addEventListener('click', () => $('#faceFile').click())
  $('#faceFile').addEventListener('change', function () { facePicked(this) })
  $('#btnFaceUpload').addEventListener('click', uploadFace)
}
function openRead() { openModal('readMask', 'readModal') }
function closeRead() { closeModal('readMask', 'readModal') }

/* ---------- 入口 ---------- */
async function boot() {
  document.title = '海鸥-学习通全自动签到'
  bindEvents()
  await refresh()
  loadPrices()
  if (!pollTimer) pollTimer = setInterval(refresh, 5000)
}
;(async () => {
  try {
    await ensureLogin()
    await boot()
  } catch (e) {
    app.replaceChildren(h('div', { class: 'card', style: 'text-align:center;color:var(--bad);font-size:13px;padding:30px 0' },
      '加载失败：' + e.message))
  }
})()
window.addEventListener('beforeunload', () => { if (pollTimer) clearInterval(pollTimer) })
