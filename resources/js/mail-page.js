/*
 * ⭐ মেইলের পাতা — মালিকের অনুমোদিত নমুনা v3 (২৬ নভেম্বর ২০২৬)। Alpine নয়, একটা সাধারণ মডিউল; শব্দ `data-words`, ঠিকানা `data-routes`।
 * ⓘ মেইলবক্স → ফোল্ডার → তালিকা → পড়া, সব JSON-এ ([[MailController]]); একটা মেইলবক্স ধীর হলে বাকিগুলো আটকায় না।
 * ⛔ মেইলের লেখা iframe-এ, `sandbox` (স্ক্রিপ্ট নয়); বাইরের ছবি প্রথমে বন্ধ — "ছবি দেখান" চাপলে।
 * ⛔ ইংরেজি অঙ্ক (মালিক, ১৬ নভেম্বর) — সময় হাতে সাজানো।
 */
import '../css/mail.css'

const root = document.querySelector('[data-mailx]')

if (root) {
    const W = parse(root.dataset.words, {})
    const R = parse(root.dataset.routes, {})
    const CSRF = root.dataset.csrf || ''
    const ZERO = '00000000-0000-0000-0000-000000000000'
    const $ = (s, el = root) => el.querySelector(s)
    const state = { accounts: [], folders: {}, current: null, folder: 'INBOX', folderRole: 'inbox', page: 1, q: '', rows: [], open: null, requests: [] }

    const url = (key, id, params) => {
        const u = new URL((R[key] || '').replace(ZERO, id || ZERO), location.origin)
        Object.entries(params || {}).forEach(([k, v]) => u.searchParams.set(k, v))
        return u.toString()
    }
    const get = async (u) => {
        const r = await fetch(u, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        const j = await r.json().catch(() => ({}))
        if (! r.ok) throw new Error(j.message || (W.msg && W.msg.server_error) || 'error')
        return j
    }
    const post = async (u, body) => {
        const isForm = body instanceof FormData
        const r = await fetch(u, { method: 'POST', credentials: 'same-origin', headers: Object.assign({ Accept: 'application/json', 'X-CSRF-TOKEN': CSRF }, isForm ? {} : { 'Content-Type': 'application/json' }), body: isForm ? body : JSON.stringify(body) })
        const j = await r.json().catch(() => ({}))
        if (! r.ok) throw new Error(j.message || firstError(j) || 'error')
        return j
    }

    // ── মেইলবক্স ও ফোল্ডার ───────────────────────────────────────────
    async function loadAccounts () {
        const pane = $('[data-folders]')
        try {
            const j = await get(R.accounts)
            state.accounts = j.accounts || []
            state.requests = j.requests || []
        } catch (e) {
            pane.innerHTML = '<p class="mx-err">' + esc(e.message) + '</p>'
            return
        }

        if (! state.current) {
            const first = state.accounts.find(a => a.usable)
            state.current = first ? first.id : null
        }

        renderFolders()
        await Promise.all(state.accounts.filter(a => a.usable).map(a => loadFolders(a.id)))
        renderFolders()
        fillFrom()

        if (state.current) loadList()
        else {
            $('[data-rows]').innerHTML = '<p class="mx-empty">' + esc(W.msg.no_accounts) + '</p>'
        }
    }

    async function loadFolders (id) {
        try {
            state.folders[id] = (await get(url('folders', id))).folders || []
        } catch (e) {
            state.folders[id] = { error: e.message }
        }
    }

    function renderFolders () {
        const pane = $('[data-folders]')
        const groups = [['own', W.my_mail], ['shared', W.team_boxes], ['personal', W.personal]]
        let html = ''

        groups.forEach(([kind, title]) => {
            const list = state.accounts.filter(a => a.kind === kind)
            if (! list.length) return
            html += '<div class="mx-cap">' + esc(title) + '</div>'
            list.forEach(a => {
                const pill = a.status === 'pending' ? '<span class="mx-pend">' + esc(W.pending) + '</span>'
                    : (a.kind === 'shared' ? '<span class="mx-share">' + esc((W.people || ':count').replace(':count', a.people)) + '</span>' : '')
                html += '<div class="mx-acct"><span class="mx-dot mx-dot-' + a.kind + '"></span><div><b>' + esc(a.title) + '</b><small>' + esc(a.email) + '</small></div>' + pill + '</div>'
                const fs = state.folders[a.id]
                if (! a.usable) return
                if (fs && fs.error) { html += '<p class="mx-err">' + esc(fs.error) + '</p>'; return }
                ;(Array.isArray(fs) ? fs : []).forEach(f => {
                    const on = state.current === a.id && state.folder === f.path
                    const label = (W.folders && W.folders[f.role]) || f.name
                    html += '<button type="button" class="mx-fl' + (on ? ' on' : '') + '" data-open-folder="' + esc(a.id) + '" data-path="' + esc(f.path) + '" data-role="' + esc(f.role) + '">'
                        + icon(f.role) + '<span>' + esc(label) + '</span>' + (f.unread ? '<i class="num">' + f.unread + '</i>' : '') + '</button>'
                })
            })
        })

        if (state.requests.length) {
            html += '<div class="mx-cap">' + esc(W.requests) + '</div>'
            state.requests.forEach(q => {
                html += '<div class="mx-req"><b>' + esc(q.who) + '</b><small>' + esc(q.email) + ' · ' + esc((W.expires || ':date').replace(':date', q.expires || '')) + '</small><p>' + esc(q.reason) + '</p>'
                    + '<div><button type="button" data-decide="' + esc(q.id) + '" data-yes="1" class="mx-ok">' + esc(W.approve) + '</button><button type="button" data-decide="' + esc(q.id) + '" data-yes="0">' + esc(W.decline) + '</button></div></div>'
            })
        }

        html += '<button type="button" class="mx-addbox" data-act="add">＋ ' + esc(W.add_box) + '</button>'
        pane.innerHTML = html
    }

    // ── তালিকা ───────────────────────────────────────────────────────
    async function loadList () {
        const rows = $('[data-rows]')
        const acct = state.accounts.find(a => a.id === state.current)
        const fs = Array.isArray(state.folders[state.current]) ? state.folders[state.current] : []
        const f = fs.find(x => x.path === state.folder)
        $('[data-folder-name]').textContent = (acct ? acct.title + ' · ' : '') + ((f && W.folders[f.role]) || (f && f.name) || state.folder)
        rows.innerHTML = '<p class="mx-empty">…</p>'

        try {
            const j = await get(url('list', state.current, { folder: state.folder, page: state.page, q: state.q }))
            state.rows = j.items || []
            renderRows(j)
        } catch (e) {
            rows.innerHTML = '<p class="mx-err">' + esc(e.message) + '</p>'
        }
    }

    function renderRows (j) {
        const rows = $('[data-rows]')
        if (! state.rows.length) {
            rows.innerHTML = '<p class="mx-empty">' + esc(W.msg.empty_box) + '</p>'
            $('[data-pager]').innerHTML = ''
            return
        }

        let html = ''
        let day = ''
        state.rows.forEach(m => {
            const d = dayOf(m.date)
            if (d !== day) { day = d; html += '<div class="mx-day">' + esc(d) + '</div>' }
            html += '<button type="button" class="mx-it' + (m.seen ? '' : ' un') + (state.open && state.open.uid === m.uid ? ' sel' : '') + '" data-uid="' + m.uid + '">'
                + '<span class="mx-av" style="background:' + colour(m.fromEmail || m.fromName) + '">' + esc(initials(m.fromName)) + '</span>'
                + '<span class="mx-mid"><b>' + esc(m.fromName || m.fromEmail) + '</b><span class="mx-s">' + esc(m.subject || '—') + '</span></span>'
                + '<span class="mx-meta num">' + esc(timeOf(m.date)) + '<span>' + (m.attach ? '📎' : '') + (m.flagged ? '<em class="mx-flag">⚑</em>' : '') + '</span></span></button>'
        })
        rows.innerHTML = html

        const per = j.per || 40
        const from = (j.page - 1) * per + 1
        const to = Math.min(j.total, j.page * per)
        $('[data-pager]').innerHTML = '<span class="num">' + esc((W.of || ':from–:to / :total').replace(':from', from).replace(':to', to).replace(':total', j.total)) + '</span>'
            + '<button type="button" data-page="-1"' + (j.page > 1 ? '' : ' disabled') + '>‹</button><button type="button" data-page="1"' + (to < j.total ? '' : ' disabled') + '>›</button>'
    }

    // ── পড়া ─────────────────────────────────────────────────────────
    async function openMail (uid, images) {
        const reader = $('[data-reader]')
        reader.innerHTML = '<div class="mx-empty">…</div>'
        try {
            const m = await get(url('read', state.current, { folder: state.folder, uid, images: images ? 1 : 0 }))
            state.open = m
            const row = state.rows.find(r => r.uid === uid)
            if (row) row.seen = true
            renderRows({ page: state.page, per: 40, total: state.rows.length })
            renderReader(m, images)
            loadParty((m.from[0] || {}).email)
        } catch (e) {
            reader.innerHTML = '<p class="mx-err">' + esc(e.message) + '</p>'
        }
    }

    function renderReader (m, images) {
        const reader = $('[data-reader]')
        const from = m.from[0] || { name: '', email: '' }
        const blocked = ! images && /data-blocked-src=/.test(m.body)
        const files = (m.files || []).filter(f => ! f.inline).map(f => '<a class="mx-file" href="' + esc(url('file', state.current, { folder: state.folder, uid: m.uid, part: f.part })) + '">'
            + '<span class="mx-ft">' + esc((f.name.split('.').pop() || '').toUpperCase().slice(0, 4)) + '</span><span>' + esc(f.name) + '<small class="num">' + size(f.size) + '</small></span></a>').join('')

        reader.innerHTML = '<div class="mx-tool">'
            + btn('reply', W.reply) + btn('reply-all', W.reply_all) + btn('forward', W.forward) + '<span class="mx-sp"></span>'
            + (state.folderRole !== 'archive' ? btn('archive', W.archive) : '') + btn('delete', W.delete) + btn('flag', m.flagged ? W.unflag : W.flag) + btn('unread', W.mark_unread) + '</div>'
            + '<div class="mx-rbody"><h2>' + esc(m.subject || '—') + '</h2>'
            + (m.external ? '<div class="mx-ext">⚠ ' + esc(W.msg.external) + '</div>' : '')
            + '<div class="mx-mh"><span class="mx-av" style="background:' + colour(from.email) + '">' + esc(initials(from.name || from.email)) + '</span><div><b>' + esc(from.name || from.email)
            + '</b> <span class="mx-mail">&lt;' + esc(from.email) + '&gt;</span><div class="mx-to num">' + esc(W.to) + ': ' + esc(m.to.map(a => a.email).join(', '))
            + (m.cc.length ? ' · Cc: ' + esc(m.cc.map(a => a.email).join(', ')) : '') + '</div></div><span class="mx-when num">' + esc(fullTime(m.date)) + '</span></div>'
            + (blocked ? '<div class="mx-img">' + esc(W.msg.images_blocked) + ' <button type="button" data-act="images">' + esc(W.msg.show_images) + '</button></div>' : '')
            + '<iframe class="mx-frame" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" title="mail"></iframe>'
            + (files ? '<div class="mx-files-list">' + files + '</div>' : '') + '</div>'
            + '<div class="mx-quick"><textarea rows="2" data-quick placeholder="' + esc((W.quick_reply || ':name').replace(':name', from.name || from.email)) + '"></textarea><button type="button" class="mx-send" data-act="quick-send">' + esc(W.send) + '</button></div>'

        const frame = $('.mx-frame', reader)
        frame.srcdoc = '<!doctype html><meta charset="utf-8"><base target="_blank"><style>body{font:15px/1.7 "Hind Siliguri",Inter,Arial,sans-serif;color:#24324a;margin:0;padding:4px 2px;word-wrap:break-word}img{max-width:100%;height:auto}a{color:#1d4ed8}blockquote{border-left:3px solid #e3e8f0;margin:8px 0;padding-left:10px;color:#64748b}</style>' + m.body
        frame.addEventListener('load', () => { try { frame.style.height = (frame.contentDocument.documentElement.scrollHeight + 16) + 'px' } catch (e) { frame.style.height = '480px' } })
    }

    async function loadParty (email) {
        const ctx = $('[data-ctx]')
        const lock = '<p class="mx-lock">🔒 ' + esc(W.msg.private_note) + '</p>'
        if (! email) { ctx.innerHTML = lock; return }
        try {
            const j = await get(url('party', '', { email }))
            const p = j.party
            if (! p) { ctx.innerHTML = lock; return }
            const bills = (p.bills || []).map(b => '<div class="mx-bl"><span class="num">' + esc(b.no) + ' · ' + esc(b.date || '') + '</span><b class="num">৳' + money(b.due) + '</b></div>').join('')
            ctx.innerHTML = '<div class="mx-card"><b class="mx-cn">' + esc(p.name) + '</b><small>' + esc([p.point, p.phone].filter(Boolean).join(' · ')) + '</small>'
                + (p.due !== null ? '<div class="mx-kv"><span>' + esc(W.msg.due_word || 'বকেয়া') + '</span><b class="num">৳' + money(p.due) + '</b></div>' : '')
                + (p.limit !== null && Number(p.limit) > 0 ? '<div class="mx-kv"><span>' + esc(W.msg.limit_word || 'বাকির সীমা') + '</span><b class="num">৳' + money(p.limit) + '</b></div>' : '')
                + '<div class="mx-acts">' + (p.links.receipt ? '<a class="mx-ok" href="' + esc(p.links.receipt) + '" target="abos">💰 ' + esc(W.msg.take_money || 'টাকা জমা নিন') + '</a>' : '')
                + (p.links.page ? '<a href="' + esc(p.links.page) + '" target="abos">' + esc(W.msg.open_party || 'পাতা দেখুন') + '</a>' : '')
                + (p.links.bill ? '<a href="' + esc(p.links.bill) + '" target="abos">' + esc(W.msg.new_bill || 'নতুন বিল') + '</a>' : '') + '</div></div>'
                + (bills ? '<div class="mx-card"><b>' + esc(W.msg.open_bills || 'খোলা বিল') + '</b>' + bills + '</div>' : '') + lock
        } catch (e) {
            ctx.innerHTML = lock
        }
    }

    // ── নতুন মেইল / উত্তর ────────────────────────────────────────────
    function fillFrom () {
        const sel = $('[data-from]')
        sel.innerHTML = state.accounts.filter(a => a.usable).map(a => '<option value="' + esc(a.id) + '">' + esc(a.title + ' <' + a.email + '>') + '</option>').join('')
    }

    function compose (mode) {
        const ov = $('[data-compose]')
        const form = $('[data-compose-form]')
        form.reset()
        $('[data-editor]').innerHTML = ''
        $('[data-file-names]').textContent = ''
        $('[data-compose-msg]').textContent = ''
        $('[data-from]').value = state.current || ''
        const m = state.open

        if (m && mode) {
            const from = m.from[0] || { email: '' }
            const re = /^(re|fw|fwd):/i
            if (mode === 'reply' || mode === 'reply-all') {
                form.to.value = ((m.replyTo[0] || from).email || '')
                if (mode === 'reply-all') form.cc.value = m.to.concat(m.cc).map(a => a.email).filter(e => e && ! state.accounts.some(a => a.email === e)).join(', ')
                form.subject.value = re.test(m.subject) ? m.subject : 'Re: ' + m.subject
                form.inReplyTo.value = m.messageId || ''
            } else {
                form.subject.value = re.test(m.subject) ? m.subject : 'Fwd: ' + m.subject
            }
            $('[data-editor]').innerHTML = '<p><br></p><blockquote>' + esc(fullTime(m.date)) + ' · ' + esc(from.name || from.email) + '</blockquote>'
        }

        ov.hidden = false
        ;(form.to.value ? $('[data-editor]') : form.to).focus()
    }

    async function send (form, html, accountId) {
        const fd = new FormData()
        addrs(form.to.value).forEach(a => fd.append('to[]', a))
        addrs(form.cc ? form.cc.value : '').forEach(a => fd.append('cc[]', a))
        fd.append('subject', form.subject.value)
        fd.append('html', html)
        if (form.inReplyTo && form.inReplyTo.value) fd.append('inReplyTo', form.inReplyTo.value)
        const files = form.querySelector('[data-files]')
        if (files) Array.from(files.files).forEach(f => fd.append('files[]', f))
        return post(url('send', accountId), fd)
    }

    // ── মেইলবক্স যোগ ────────────────────────────────────────────────
    function setAdd (key, value) {
        const form = $('[data-add-form]')
        form[key].value = value
        root.querySelectorAll(key === 'provider' ? '[data-provider]' : '[data-kind-v]').forEach(b => b.classList.toggle('on', (b.dataset.provider || b.dataset.kindV) === value))
        const kind = form.kind.value
        const prov = form.provider.value
        root.querySelectorAll('[data-only]').forEach(el => { el.hidden = ! ((el.dataset.only === 'personal' && kind === 'personal') || (el.dataset.only === 'imap' && prov === 'imap')) })
        $('[data-add-btn]').textContent = kind === 'personal' ? W.add.ask : W.add.save
    }

    // ── ঘটনা ────────────────────────────────────────────────────────
    root.addEventListener('click', async (ev) => {
        const t = ev.target.closest('button, a')
        if (! t || ! root.contains(t)) return

        if (t.dataset.openFolder) {
            state.current = t.dataset.openFolder
            state.folder = t.dataset.path
            state.folderRole = t.dataset.role || ''
            state.page = 1
            state.open = null
            renderFolders()
            loadList()
            return
        }
        if (t.dataset.uid) { openMail(Number(t.dataset.uid), false); return }
        if (t.dataset.page) { state.page += Number(t.dataset.page); loadList(); return }
        if (t.dataset.provider) { setAdd('provider', t.dataset.provider); return }
        if (t.dataset.kindV) { setAdd('kind', t.dataset.kindV); return }
        if (t.dataset.decide) {
            try { await post(url('decide', t.dataset.decide), { yes: t.dataset.yes === '1' }); loadAccounts() } catch (e) { alertIn($('[data-folders]'), e.message) }
            return
        }

        const act = t.dataset.act
        const m = state.open
        if (! act) return

        if (act === 'compose') compose(null)
        else if (act === 'close-compose') $('[data-compose]').hidden = true
        else if (act === 'add') $('[data-add]').hidden = false
        else if (act === 'close-add') $('[data-add]').hidden = true
        else if (act === 'refresh') { loadAccounts() }
        else if (act === 'images' && m) openMail(m.uid, true)
        else if ((act === 'reply' || act === 'reply-all' || act === 'forward') && m) compose(act)
        else if (act === 'flag' && m) {
            await post(url('flag', state.current), { folder: state.folder, uid: m.uid, flag: 'flagged', on: ! m.flagged }).catch(() => null)
            m.flagged = ! m.flagged
            const row = state.rows.find(r => r.uid === m.uid)
            if (row) row.flagged = m.flagged
            renderReader(m, false)
            loadList()
        } else if (act === 'unread' && m) {
            await post(url('flag', state.current), { folder: state.folder, uid: m.uid, flag: 'seen', on: false }).catch(() => null)
            state.open = null
            $('[data-reader]').innerHTML = '<div class="mx-empty">' + esc(W.msg.pick_mail) + '</div>'
            loadList()
        } else if ((act === 'archive' || act === 'delete') && m) {
            const fs = Array.isArray(state.folders[state.current]) ? state.folders[state.current] : []
            const target = fs.find(f => f.role === (act === 'archive' ? 'archive' : 'trash'))
            if (! target) return
            try {
                await post(url('move', state.current), { folder: state.folder, uid: m.uid, to: target.path })
                state.open = null
                $('[data-reader]').innerHTML = '<div class="mx-empty">' + esc(W.msg.pick_mail) + '</div>'
                loadList()
            } catch (e) { alertIn($('[data-reader]'), e.message) }
        } else if (act === 'quick-send' && m) {
            const box = $('[data-quick]')
            const text = box.value.trim()
            if (! text) return
            const from = m.from[0] || { email: '' }
            const fake = { to: { value: (m.replyTo[0] || from).email }, cc: { value: '' }, subject: { value: /^re:/i.test(m.subject) ? m.subject : 'Re: ' + m.subject }, inReplyTo: { value: m.messageId || '' }, querySelector: () => null }
            t.disabled = true
            try {
                await send(fake, '<p>' + esc(text).replace(/\n/g, '<br>') + '</p>', state.current)
                box.value = ''
                alertIn($('.mx-quick'), W.msg.sent, true)
            } catch (e) { alertIn($('.mx-quick'), e.message) }
            t.disabled = false
        }
    })

    $('[data-compose-form]').addEventListener('submit', async (ev) => {
        ev.preventDefault()
        const form = ev.currentTarget
        const msg = $('[data-compose-msg]')
        const btnEl = form.querySelector('.mx-send')
        btnEl.disabled = true
        msg.textContent = '…'
        try {
            await send(form, $('[data-editor]').innerHTML, $('[data-from]').value)
            msg.textContent = W.msg.sent
            setTimeout(() => { $('[data-compose]').hidden = true }, 600)
        } catch (e) { msg.textContent = e.message }
        btnEl.disabled = false
    })

    $('[data-files]').addEventListener('change', (ev) => {
        $('[data-file-names]').textContent = Array.from(ev.target.files).map(f => f.name).join(', ')
    })

    $('[data-add-form]').addEventListener('submit', async (ev) => {
        ev.preventDefault()
        const form = ev.currentTarget
        const msg = $('[data-add-msg]')
        msg.textContent = '…'
        const data = Object.fromEntries(new FormData(form).entries())
        if (data.kind !== 'personal') { delete data.reason; delete data.months }
        if (data.provider !== 'imap') { delete data.imap_host; delete data.smtp_host }
        try {
            const j = await post(R.add, data)
            msg.textContent = j.message
            form.reset()
            setTimeout(() => { $('[data-add]').hidden = true; loadAccounts() }, 700)
        } catch (e) { msg.textContent = e.message }
    })

    $('[data-q]').addEventListener('keydown', (ev) => {
        if (ev.key !== 'Enter') return
        state.q = ev.target.value.trim()
        state.page = 1
        loadList()
    })

    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') { $('[data-compose]').hidden = true; $('[data-add]').hidden = true }
        if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey) && ! $('[data-compose]').hidden) $('[data-compose-form]').requestSubmit()
    })

    setAdd('kind', 'own')
    loadAccounts()
    // ⓘ নতুন মেইল — প্রতি ২ মিনিটে চলতি তালিকা, পাতা লুকানো থাকলে নয়
    setInterval(() => { if (! document.hidden && state.current && ! state.open) loadList() }, 120000)
}

// ── ছোট সাহায্যকারী ───────────────────────────────────────────────
function parse (s, f) { try { return JSON.parse(s || '') } catch (e) { return f } }
function esc (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])) }
function firstError (j) { const e = j && j.errors; if (! e) return ''; const k = Object.keys(e)[0]; return k ? e[k][0] : '' }
function addrs (s) { return String(s || '').split(/[,;\s]+/).map(x => x.trim()).filter(x => /@/.test(x)) }
function pad (n) { return (n < 10 ? '0' : '') + n }
function dayOf (iso) {
    if (! iso) return ''
    const d = new Date(iso)
    const t = new Date()
    const y = new Date(); y.setDate(t.getDate() - 1)
    const same = (a, b) => a.toDateString() === b.toDateString()
    const root = document.querySelector('[data-mailx]')
    const W = parse(root && root.dataset.words, {})
    if (same(d, t)) return W.today || 'Today'
    if (same(d, y)) return W.yesterday || 'Yesterday'
    return pad(d.getDate()) + '-' + pad(d.getMonth() + 1) + '-' + d.getFullYear()
}
function timeOf (iso) {
    if (! iso) return ''
    const d = new Date(iso)
    return d.toDateString() === new Date().toDateString() ? pad(d.getHours()) + ':' + pad(d.getMinutes()) : pad(d.getDate()) + '-' + pad(d.getMonth() + 1)
}
function fullTime (iso) { if (! iso) return ''; const d = new Date(iso); return pad(d.getDate()) + '-' + pad(d.getMonth() + 1) + '-' + d.getFullYear() + ' · ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) }
function initials (s) { const p = String(s || '?').replace(/[<>"]/g, '').trim().split(/\s+/); return ((p[0] || '?')[0] + ((p[1] || '')[0] || '')).toUpperCase() }
function colour (s) { const c = ['#0b8a9a', '#b45309', '#4338ca', '#15803d', '#be185d', '#334155', '#1d4ed8', '#7c3aed']; let h = 0; for (const ch of String(s || '')) h = (h * 31 + ch.charCodeAt(0)) >>> 0; return c[h % c.length] }
function size (b) { b = Number(b || 0); return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB' }
function money (v) { const n = Number(v || 0); return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function btn (act, label) { return '<button type="button" class="mx-tb" data-act="' + act + '">' + esc(label) + '</button>' }
function icon (role) { return '<span class="mx-ic" aria-hidden="true">' + ({ inbox: '📥', sent: '📤', drafts: '📝', trash: '🗑', junk: '⚠', archive: '🗄' }[role] || '📁') + '</span>' }
function alertIn (el, text, ok) {
    if (! el) return
    const p = document.createElement('p')
    p.className = ok ? 'mx-okmsg' : 'mx-err'
    p.textContent = text
    el.appendChild(p)
    setTimeout(() => p.remove(), 4000)
}
