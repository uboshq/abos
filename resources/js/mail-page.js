/*
 * ⭐ মেইলের পাতা — মালিকের অনুমোদিত নমুনা v4 (২৬ নভেম্বর ২০২৬)। Alpine নয়, সাধারণ মডিউল; শব্দ `data-words`, ঠিকানা `data-routes`।
 * ⓘ অ্যাকাউন্ট → ফোল্ডার → তালিকা → পড়া, সব JSON-এ ([[MailController]]); একটা মেইলবক্স ধীর হলে বাকিগুলো আটকায় না।
 * ⛔ মেইলের লেখা iframe-এ, `sandbox` (স্ক্রিপ্ট নয়); বাইরের ছবি প্রথমে বন্ধ — "ছবি দেখান" বা "এই প্রেরকের ছবি সবসময়" চাপলে।
 * ⛔ ইংরেজি অঙ্ক (মালিক, ১৬ নভেম্বর) — সময় আর টাকা হাতে সাজানো।
 */
import '../css/mail.css'

const root = document.querySelector('[data-mailx]')

if (root) {
    const W = parse(root.dataset.words, {})
    const M = W.msg || {}
    const R = parse(root.dataset.routes, {})
    const PRE = parse(root.dataset.prefill, {})
    const CSRF = root.dataset.csrf || ''
    const ZERO = '00000000-0000-0000-0000-000000000000'
    const PER = 50
    const $ = (s, el = root) => el.querySelector(s)
    const $$ = (s, el = root) => Array.from(el.querySelectorAll(s))
    const state = {
        accounts: [], requests: [], folders: {}, view: { acc: null, path: 'INBOX', role: 'inbox', virtual: '' },
        chip: 'all', q: '', page: 1, total: 0, rows: [], parties: {}, picked: new Set(), open: null, openAcc: null, openParty: null,
        compose: { papers: [], data: null, replying: false, tplSubject: '' }, prefilled: false,
    }

    const url = (key, id, params) => {
        const u = new URL((R[key] || '').replace(ZERO, id || ZERO), location.origin)
        Object.entries(params || {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') u.searchParams.set(k, v) })
        return u.toString()
    }
    const get = async (u) => {
        const r = await fetch(u, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        const j = await r.json().catch(() => ({}))
        if (! r.ok) throw new Error(j.message || M.server_error || 'error')
        return j
    }
    const post = async (u, body) => {
        const isForm = body instanceof FormData
        const r = await fetch(u, { method: 'POST', credentials: 'same-origin',
            headers: Object.assign({ Accept: 'application/json', 'X-CSRF-TOKEN': CSRF }, isForm ? {} : { 'Content-Type': 'application/json' }),
            body: isForm ? body : JSON.stringify(body) })
        const j = await r.json().catch(() => ({}))
        if (! r.ok) throw new Error(firstError(j) || j.message || 'error')
        return j
    }
    const acc = (id) => state.accounts.find(a => a.id === id)
    const usable = () => state.accounts.filter(a => a.usable)

    // ── অ্যাকাউন্ট ও ফোল্ডার ─────────────────────────────────────────
    async function loadAccounts () {
        try {
            const j = await get(R.accounts)
            state.accounts = j.accounts || []
            state.requests = j.requests || []
        } catch (e) {
            $('[data-accounts]').innerHTML = '<p class="mx-err">' + esc(e.message) + '</p>'
            return
        }

        if (! state.view.acc || (state.view.acc !== 'all' && ! acc(state.view.acc))) {
            state.view.acc = usable().length > 1 ? 'all' : (usable()[0] ? usable()[0].id : null)
        }

        renderAccounts()
        renderRequests()
        await Promise.all(usable().map(a => loadFolders(a.id)))
        renderAccounts()
        renderFolders()
        renderChips()

        if (state.view.acc) loadList()
        else $('[data-rows]').innerHTML = '<p class="mx-empty">' + esc(M.no_accounts) + '</p>'

        if (PRE.compose && ! state.prefilled) { state.prefilled = true; compose('new', PRE) }
    }

    async function loadFolders (id) {
        try { state.folders[id] = (await get(url('folders', id))).folders || [] } catch (e) { state.folders[id] = { error: e.message } }
    }

    const inboxOf = (id) => (Array.isArray(state.folders[id]) ? state.folders[id] : []).find(f => f.role === 'inbox')
    const unreadOf = (id) => (inboxOf(id) || {}).unread || 0

    function renderAccounts () {
        const box = (a) => {
            const pill = a.status === 'pending' ? '<em class="mx-pill t-amber" style="font-size:10.5px">' + esc(W.pending) + '</em>'
                : (a.usable && unreadOf(a.id) ? '<em class="num">' + unreadOf(a.id) + '</em>' : '<em></em>')
            return '<button type="button" class="mx-acc' + (state.view.acc === a.id ? ' on' : '') + '" data-acc="' + esc(a.id) + '"' + (a.usable ? '' : ' disabled') + '>'
                + '<i style="background:' + esc(a.colour) + '"></i><div><b>' + esc(a.title) + '</b><small>' + esc(a.email) + '</small></div>' + pill + '</button>'
        }
        const office = state.accounts.filter(a => a.kind !== 'personal')
        const personal = state.accounts.filter(a => a.kind === 'personal')
        const total = usable().reduce((n, a) => n + unreadOf(a.id), 0)
        let html = ''

        if (usable().length > 1) {
            html += '<button type="button" class="mx-acc' + (state.view.acc === 'all' ? ' on' : '') + '" data-acc="all"><i style="background:conic-gradient(#00ab3f 0 33%,#1a3fd0 0 66%,#f5121d 0)"></i>'
                + '<div><b>' + esc(W.all_inbox) + '</b><small>' + esc(tr(W.all_inbox_sub, { count: usable().length })) + '</small></div>' + (total ? '<em class="num">' + total + '</em>' : '<em></em>') + '</button>'
        }
        if (office.length) html += '<div class="mx-sect">' + esc(W.my_mail) + '</div>' + office.map(box).join('')
        if (personal.length) html += '<div class="mx-sect">' + esc(W.personal) + '</div>' + personal.map(box).join('')
        $('[data-accounts]').innerHTML = html
    }

    function renderRequests () {
        if (! state.requests.length) { $('[data-requests]').innerHTML = ''; return }
        $('[data-requests]').innerHTML = '<div class="mx-sect">' + esc(W.requests) + '</div>' + state.requests.map(q => '<div class="mx-req"><b>' + esc(q.who) + '</b> · ' + esc(q.email)
            + '<br><span class="muted">' + esc(q.expires ? tr(W.expires, { date: q.expires }) : '') + ' · "' + esc(q.reason) + '"</span>'
            + '<div><button type="button" class="mx-btn g" data-decide="' + esc(q.id) + '" data-yes="1">' + esc(W.approve) + '</button>'
            + '<button type="button" class="mx-btn" data-decide="' + esc(q.id) + '" data-yes="0">' + esc(W.decline) + '</button></div></div>').join('')
    }

    function renderFolders () {
        const pane = $('[data-folders]')
        const v = state.view

        if (v.acc === 'all' || ! v.acc) {
            const total = usable().reduce((n, a) => n + unreadOf(a.id), 0)
            pane.innerHTML = folderBtn({ path: 'INBOX', role: 'inbox' }, 'inbox', W.folders.inbox, total, ! v.virtual)
                + folderBtn({ path: 'INBOX', role: 'inbox' }, 'starred', W.folders.starred, 0, v.virtual === 'starred')
                + folderBtn({ path: 'INBOX', role: 'inbox' }, 'later', W.folders.later, 0, v.virtual === 'later')
            return
        }

        const fs = state.folders[v.acc]
        if (fs && fs.error) { pane.innerHTML = '<p class="mx-err">' + esc(fs.error) + '</p>'; return }
        const list = Array.isArray(fs) ? fs : []
        const byRole = (r) => list.find(f => f.role === r)
        const inbox = byRole('inbox') || { path: 'INBOX', role: 'inbox', unread: 0 }
        let html = folderBtn(inbox, 'inbox', W.folders.inbox, inbox.unread, v.path === inbox.path && ! v.virtual)
            + folderBtn(inbox, 'starred', W.folders.starred, 0, v.virtual === 'starred')
            + folderBtn(inbox, 'later', W.folders.later, 0, v.virtual === 'later')

        ;['sent', 'drafts', 'archive', 'junk', 'trash'].forEach(r => {
            const f = byRole(r)
            if (f) html += folderBtn(f, r, W.folders[r], r === 'junk' || r === 'drafts' ? f.total : 0, v.path === f.path && ! v.virtual)
        })
        list.filter(f => ! f.role).forEach(f => { html += folderBtn(f, '', f.name, f.unread, v.path === f.path && ! v.virtual) })
        pane.innerHTML = html
    }

    function folderBtn (f, kind, label, count, on) {
        const ic = { inbox: '📥', starred: '⭐', later: '🕒', sent: '📤', drafts: '📝', archive: '🗄️', junk: '⚠️', trash: '🗑️' }[kind] || '📁'
        return '<button type="button" class="mx-fold' + (on ? ' on' : '') + '" data-path="' + esc(f.path) + '" data-role="' + esc(f.role || '') + '" data-virtual="'
            + (kind === 'starred' || kind === 'later' ? kind : '') + '"><span>' + ic + ' ' + esc(label) + '</span><span class="num">' + (count ? count : '') + '</span></button>'
    }

    function renderChips () {
        const unread = state.view.acc === 'all' ? usable().reduce((n, a) => n + unreadOf(a.id), 0) : unreadOf(state.view.acc)
        const chips = [['all', W.chips.all], ['unread', W.chips.unread + (unread ? ' ' + unread : '')], ['attach', '📎 ' + W.chips.attach],
            ['customer', '🤝 ' + W.chips.customer], ['supplier', '🏭 ' + W.chips.supplier]]
        $('[data-chips]').innerHTML = chips.map(([k, l]) => '<button type="button" class="mx-chip' + (state.chip === k ? ' on' : '') + '" data-chip="' + k + '">' + esc(l) + '</button>').join('')
    }

    // ── তালিকা ──────────────────────────────────────────────────────
    function query () {
        const parts = [state.q]
        if (state.chip === 'unread') parts.push('is:unread')
        if (state.view.virtual === 'starred') parts.push('is:flagged')
        if (state.view.virtual === 'later') parts.push('is:later')
        return parts.filter(Boolean).join(' ')
    }

    async function loadList () {
        const rows = $('[data-rows]')
        state.picked.clear()
        syncBulk()
        rows.innerHTML = '<p class="mx-empty">…</p>'

        try {
            if (state.view.acc === 'all') {
                const parts = await Promise.all(usable().map(a => get(url('list', a.id, { folder: (inboxOf(a.id) || {}).path || 'INBOX', page: 1, per: PER, q: query() }))
                    .then(j => ({ a, j })).catch(() => ({ a, j: { items: [], total: 0 } }))))
                state.rows = parts.flatMap(({ a, j }) => (j.items || []).map(m => Object.assign(m, { acc: a.id, folder: (inboxOf(a.id) || {}).path || 'INBOX' })))
                    .sort((x, y) => String(y.date || '').localeCompare(String(x.date || ''))).slice(0, PER)
                state.total = parts.reduce((n, p) => n + (p.j.total || 0), 0)
            } else {
                const j = await get(url('list', state.view.acc, { folder: state.view.path, page: state.page, per: PER, q: query() }))
                state.rows = (j.items || []).map(m => Object.assign(m, { acc: state.view.acc, folder: state.view.path }))
                state.total = j.total || 0
            }
        } catch (e) {
            rows.innerHTML = '<p class="mx-err">' + esc(e.message) + '</p>'
            return
        }

        renderRows()
        loadParties()
    }

    async function loadParties () {
        const emails = Array.from(new Set(state.rows.map(m => String(m.fromEmail || '').toLowerCase()).filter(e => e && ! (e in state.parties))))
        if (! emails.length) return
        try {
            const j = await post(R.parties, { emails })
            emails.forEach(e => { state.parties[e] = (j.parties || {})[e] || null })
            renderRows()
        } catch (e) { /* চিহ্ন না এলে তালিকা তবু চলে */ }
    }

    function visibleRows () {
        return state.rows.filter(m => {
            const p = state.parties[String(m.fromEmail || '').toLowerCase()]
            if (state.chip === 'attach') return m.attach
            if (state.chip === 'customer') return p && p.kind === 'customer'
            if (state.chip === 'supplier') return p && p.kind === 'supplier'
            return true
        })
    }

    function renderRows () {
        const rows = $('[data-rows]')
        const list = visibleRows()

        if (! list.length) {
            rows.innerHTML = '<p class="mx-empty">' + esc(M.empty_box) + '</p>'
            $('[data-pager]').textContent = ''
            return
        }

        let html = ''
        let group = ''
        list.forEach(m => {
            const g = dayGroup(m.date)
            if (g !== group) { group = g; html += '<div class="mx-day">' + esc(g) + '</div>' }
            const key = m.acc + ':' + m.uid
            const a = acc(m.acc) || {}
            const p = state.parties[String(m.fromEmail || '').toLowerCase()]
            const sel = state.open && state.openAcc === m.acc && state.open.uid === m.uid && state.open.folder === m.folder
            let tags = ''
            if (p && p.kind === 'customer') {
                tags += '<span class="mx-pill t-green">' + esc(W.tags.customer) + (p.point ? ' · ' + esc(p.point) : '') + '</span>'
                if (Number(p.due) > 0) tags += '<span class="mx-pill t-red num">' + esc(W.tags.due) + ' ' + money(p.due) + '</span>'
            } else if (p && p.kind === 'supplier') {
                tags += '<span class="mx-pill t-amber">' + esc(W.tags.supplier) + '</span>'
            }
            if (m.attach) tags += '<span class="mx-pill t-blue">📎</span>'
            if (m.flagged) tags += '<span class="mx-pill t-amber">⭐</span>'
            if (state.view.acc === 'all') tags += '<span class="acc">' + esc(a.title || '') + '</span>'

            html += '<div class="mx-m' + (m.seen ? '' : ' un') + (sel ? ' sel' : '') + '" data-key="' + esc(key) + '" role="button" tabindex="0">'
                + '<i class="c" style="background:' + esc(a.colour || '#00ab3f') + '"></i>'
                + '<input type="checkbox" class="ck" data-pick="' + esc(key) + '"' + (state.picked.has(key) ? ' checked' : '') + ' aria-label="✓">'
                + '<b>' + esc(m.fromName || m.fromEmail || '—') + '</b><span class="time num">' + esc(timeOf(m.date)) + '</span>'
                + '<span class="s">' + esc(m.subject || '—') + '</span>'
                + (tags ? '<div class="x">' + tags + '</div>' : '') + '</div>'
        })
        rows.innerHTML = html

        const from = (state.page - 1) * PER + 1
        const to = Math.min(state.total, from + state.rows.length - 1)
        $('[data-pager]').innerHTML = '<span>' + esc(tr(W.of, { from, to, total: state.total })) + '</span>'
            + (state.view.acc !== 'all' ? '<button type="button" class="mx-btn" style="height:24px;padding:0 8px" data-page="-1"' + (state.page > 1 ? '' : ' disabled') + '>‹</button>'
            + '<button type="button" class="mx-btn" style="height:24px;padding:0 8px" data-page="1"' + (to < state.total ? '' : ' disabled') + '>›</button>' : '')
    }

    function syncBulk () {
        const n = state.picked.size
        $$('[data-bulk]').forEach(b => { b.disabled = n === 0 || (state.view.acc === 'all' && b.dataset.bulk === 'move') })
        root.classList.toggle('mx-picking', n > 0)
        const all = $('[data-pick-all]')
        if (all) all.checked = n > 0 && n === visibleRows().length
    }

    async function bulk (what) {
        const keys = Array.from(state.picked)
        if (! keys.length) return
        let to = ''

        if (what === 'move') {
            const fs = Array.isArray(state.folders[state.view.acc]) ? state.folders[state.view.acc] : []
            const names = fs.filter(f => f.path !== state.view.path).map(f => (W.folders[f.role] || f.name)).join(', ')
            const pick = window.prompt(W.move + ': ' + names)
            if (! pick) return
            const f = fs.find(x => (W.folders[x.role] || x.name).toLowerCase() === pick.trim().toLowerCase() || x.path.toLowerCase() === pick.trim().toLowerCase())
            if (! f) return
            to = f.path
        }

        // ⓘ অ্যাকাউন্ট ধরে ভাগ — "সব ইনবক্স" থেকে বাছলে প্রতিটা মেইলবক্সে আলাদা ডাক
        const byAcc = {}
        keys.forEach(k => { const i = k.lastIndexOf(':'); const a = k.slice(0, i); (byAcc[a] = byAcc[a] || []).push(Number(k.slice(i + 1))) })
        const errors = []

        for (const [a, uids] of Object.entries(byAcc)) {
            const folder = (state.rows.find(m => m.acc === a) || {}).folder || state.view.path
            const subjects = uids.map(u => (state.rows.find(m => m.acc === a && m.uid === u) || {}).subject || '')
            try {
                const j = await post(url('bulk', a), { folder, uids, do: what, to, subjects })
                if (j.missing) errors.push(M.no_archive)
            } catch (e) { errors.push(e.message) }
        }

        if (errors.length) toast(errors[0], true)
        if (['archive', 'delete', 'move'].includes(what) && state.open && keys.includes(state.openAcc + ':' + state.open.uid)) closeReader()
        await refreshCounts()
        loadList()
    }

    async function refreshCounts () {
        await Promise.all(usable().map(a => loadFolders(a.id)))
        renderAccounts()
        renderFolders()
        renderChips()
    }

    // ── পড়া ────────────────────────────────────────────────────────
    async function openMail (key, images) {
        const i = key.lastIndexOf(':')
        const a = key.slice(0, i)
        const uid = Number(key.slice(i + 1))
        const row = state.rows.find(m => m.acc === a && m.uid === uid)
        const folder = row ? row.folder : state.view.path
        const reader = $('[data-reader]')
        reader.innerHTML = '<div class="mx-empty">…</div>'

        try {
            const m = await get(url('read', a, { folder, uid, images: images ? 1 : 0 }))
            m.folder = folder
            state.open = m
            state.openAcc = a
            if (row && ! row.seen) { row.seen = true; const f = inboxOf(a); if (f && f.unread) f.unread--; renderAccounts(); renderFolders(); renderChips() }
            if (! state.compose.data) await loadComposeData({}, true)
            renderRows()
            renderReader(m)
            loadParty((m.from[0] || {}).email)
        } catch (e) {
            reader.innerHTML = '<p class="mx-err">' + esc(e.message) + '</p>'
        }
    }

    function closeReader () {
        state.open = null
        state.openAcc = null
        $('[data-reader]').innerHTML = '<div class="mx-empty">' + esc(M.pick_mail) + '</div>'
    }

    function renderReader (m) {
        const reader = $('[data-reader]')
        const from = m.from[0] || { name: '', email: '' }
        const a = acc(state.openAcc) || {}
        const blocked = /data-blocked-src=/.test(m.body)
        const norm = (s) => String(s || '').replace(/^((re|fw|fwd)\s*:\s*)+/i, '').trim().toLowerCase()
        const thread = state.rows.filter(r => norm(r.subject) === norm(m.subject)).length
        const files = (m.files || []).filter(f => ! f.inline).map(f => '<a class="mx-file" href="' + esc(url('file', state.openAcc, { folder: m.folder, uid: m.uid, part: f.part })) + '">'
            + '<i>' + esc((String(f.name).split('.').pop() || '').toUpperCase().slice(0, 4)) + '</i><div><b>' + esc(f.name) + '</b><div class="muted num">' + size(f.size) + '</div></div></a>').join('')
        const replies = ((state.compose.data && state.compose.data.templates) || []).filter(t => t.category === 'reply').slice(0, 4)

        reader.innerHTML = '<div class="mx-tool">'
            + tbtn('reply', '↩ ' + W.reply) + tbtn('reply-all', '↩↩ ' + W.reply_all) + tbtn('forward', '↪ ' + W.forward)
            + tbtn('archive', '🗄️ ' + W.archive) + tbtn('flag', m.flagged ? '⭐' : '☆', m.flagged ? W.unstar : W.star) + tbtn('delete', '🗑️', W.delete)
            + tbtn('more', '⋯ ' + W.more) + '<span class="sp"></span><span data-keep></span></div>'
            + '<div class="mx-scroll">'
            + '<h1>' + esc(m.subject || '—') + (thread > 1 ? ' <span class="mx-pill t-blue" style="vertical-align:middle">' + esc(tr(W.thread, { count: thread })) + '</span>' : '') + '</h1>'
            + '<div class="mx-from"><div class="mx-fav" style="background:' + colour(from.email) + '">' + esc(initials(from.name || from.email)) + '</div><div><b>' + esc(from.name || from.email)
            + '</b> <span class="muted">&lt;' + esc(from.email) + '&gt;</span><div class="muted num">' + esc(W.to_label) + ': ' + esc(m.to.map(x => x.email).join(', '))
            + (m.cc.length ? ' · ' + esc(W.cc_label) + ': ' + esc(m.cc.map(x => x.email).join(', ')) : '') + ' · ' + esc(fullTime(m.date)) + '</div></div></div>'
            + (m.external ? '<div class="mx-warn t-amber">⚠️ <span><b>' + esc(W.external_title) + '</b> — ' + esc(M.external) + '</span></div>' : '')
            + (blocked ? '<div class="mx-warn" style="background:var(--mx-soft);color:var(--mx-muted)">🖼️ <span>' + esc(M.images_blocked) + '</span>'
                + '<button type="button" data-act="images">' + esc(M.show_images) + '</button><span>·</span><button type="button" data-act="trust">' + esc(W.always_images) + '</button></div>' : '')
            + '<iframe class="mx-frame" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" title="mail"></iframe>'
            + (files ? '<div class="mx-att">' + files + '</div>' : '') + '</div>'
            + (a.canSend ? '<div style="display:flex;flex-direction:column;gap:8px"><div class="mx-smart">' + replies.map(t => '<button type="button" data-smart="' + esc(t.id) + '">' + esc(t.name) + '</button>').join('') + '</div>'
                + '<div class="mx-qr"><textarea rows="1" data-quick placeholder="↩ ' + esc(tr(W.quick_reply, { name: from.name || from.email })) + '"></textarea>'
                + '<span class="muted" style="font-size:12px">' + esc(W.ctrl_enter) + '</span><button type="button" class="mx-btn p" data-act="quick-send">' + esc(W.send) + ' ➤</button></div></div>' : '')

        const frame = $('.mx-frame', reader)
        frame.srcdoc = '<!doctype html><meta charset="utf-8"><base target="_blank"><style>body{font:14px/1.75 "Nirmala UI","Hind Siliguri",Inter,Arial,sans-serif;color:#26304d;margin:0;padding:2px;word-wrap:break-word;max-width:820px}img{max-width:100%;height:auto}a{color:#1a3fd0}blockquote{border-left:3px solid #e3e8f0;margin:8px 0;padding-left:10px;color:#64748b}</style>' + m.body
        frame.addEventListener('load', () => { try { frame.style.height = (frame.contentDocument.documentElement.scrollHeight + 16) + 'px' } catch (e) { frame.style.height = '480px' } })
        renderKeep()
    }

    function renderKeep () {
        const slot = $('[data-keep]')
        if (! slot || ! state.open) return
        const p = state.openParty
        if (state.open.linked) slot.innerHTML = '<span class="mx-pill t-green">✓ ' + esc(W.kept) + '</span>'
        else if (p && p.ref && state.open.messageId) slot.innerHTML = '<button type="button" class="mx-btn g" data-act="keep">＋ ' + esc(W.keep_party) + '</button>'
        else slot.innerHTML = ''
    }

    async function loadParty (email) {
        const ctx = $('[data-ctx]')
        const lock = '<p class="muted" style="font-size:11.5px;line-height:1.6;margin:0">🔒 ' + esc(M.private_note) + '</p>'
        state.openParty = null
        if (! email) { ctx.innerHTML = lock; return }
        try {
            const p = (await get(url('party', '', { email }))).party
            state.openParty = p
            renderKeep()
            if (! p) { ctx.innerHTML = lock; return }
            const C = W.card
            const kv = (k, v, col) => v === null || v === undefined || v === '' ? '' : '<div class="mx-kv"><span>' + esc(k) + '</span><b class="num" style="color:' + col + '">' + v + '</b></div>'
            const bills = (p.bills || []).map(b => '<a class="mx-bl"' + (b.url ? ' href="' + esc(b.url) + '" target="abos"' : '') + '><span class="num">' + esc(b.no) + ' · ' + esc(b.date || '') + '</span><b class="num" style="color:var(--mx-red)">' + money(b.due) + '</b></a>').join('')
            ctx.innerHTML = '<div class="mx-cc"><b style="font-size:15px;display:block">' + esc(p.name) + '</b><div class="muted" style="font-size:12px;margin:2px 0 8px">'
                + esc([p.kind === 'supplier' ? C.supplier : W.tags.customer, p.point, p.phone].filter(Boolean).join(' · ')) + '</div>'
                + (p.due !== null ? kv(C.due, money(p.due), 'var(--mx-red)') : '')
                + (p.limit !== null && Number(p.limit) > 0 ? kv(C.limit, money(p.limit), 'inherit') : '')
                + (p.left !== null && p.left !== undefined ? kv(C.left, money(p.left), Number(p.left) >= 0 ? 'var(--mx-green-ink)' : 'var(--mx-red)') : '')
                + (p.points ? kv(C.points, '⭐ ' + esc(p.points), 'var(--mx-amber)') : '')
                + (p.last ? kv(C.last, esc(p.last), 'var(--mx-brand)') : '')
                + (p.links.receipt ? '<a class="mx-btn g" style="width:100%;justify-content:center;margin-top:10px;height:36px" href="' + esc(p.links.receipt) + '" target="abos">💰 ' + esc(C.take_money) + '</a>' : '')
                + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px">'
                + (p.links.page ? '<a class="mx-btn" style="justify-content:center" href="' + esc(p.links.page) + '" target="abos">' + esc(C.ledger) + '</a>' : '')
                + (p.links.bill ? '<a class="mx-btn" style="justify-content:center" href="' + esc(p.links.bill) + '" target="abos">' + esc(C.new_bill) + '</a>' : '') + '</div></div>'
                + (bills ? '<div class="mx-cc"><b>' + esc(C.open_bills) + '</b>' + bills + '<div class="muted" style="font-size:11.5px;margin-top:6px">' + esc(C.bills_hint) + '</div></div>' : '') + lock
        } catch (e) { ctx.innerHTML = lock }
    }

    function menuFor (anchor) {
        closeMenu()
        const m = state.open
        if (! m) return
        const fs = Array.isArray(state.folders[state.openAcc]) ? state.folders[state.openAcc] : []
        const menu = document.createElement('div')
        menu.className = 'mx-menu'
        menu.dataset.menu = '1'
        menu.innerHTML = '<button type="button" data-act="unread">✉️ ' + esc(W.mark_unread) + '</button>'
            + '<button type="button" data-act="later">🕒 ' + esc(W.later) + '</button>'
            + '<button type="button" data-act="trust">🖼️ ' + esc(W.always_images) + '</button>'
            + '<div class="sep"></div>' + fs.filter(f => f.path !== m.folder).map(f => '<button type="button" data-move-to="' + esc(f.path) + '">📁 ' + esc(W.move) + ' → ' + esc(W.folders[f.role] || f.name) + '</button>').join('')
        const r = anchor.getBoundingClientRect()
        const host = $('[data-reader]').getBoundingClientRect()
        menu.style.left = Math.max(8, r.left - host.left) + 'px'
        menu.style.top = (r.bottom - host.top + 4) + 'px'
        $('[data-reader]').appendChild(menu)
    }
    function closeMenu () { $$('[data-menu]').forEach(x => x.remove()) }

    async function moveOpen (to, action) {
        const m = state.open
        if (! m || ! to) return
        try {
            await post(url('move', state.openAcc), { folder: m.folder, uid: m.uid, to, subject: m.subject, action })
            closeReader()
            await refreshCounts()
            loadList()
        } catch (e) { toast(e.message, true) }
    }
    const roleFolder = (a, role) => ((Array.isArray(state.folders[a]) ? state.folders[a] : []).find(f => f.role === role) || {}).path

    // ── নতুন মেইল / উত্তর ────────────────────────────────────────────
    async function compose (mode, opts) {
        opts = opts || {}
        const form = $('[data-compose]')
        const m = mode === 'new' ? null : state.open
        const sendable = usable().filter(a => a.canSend)
        const fromId = m && acc(state.openAcc) && acc(state.openAcc).canSend ? state.openAcc
            : (state.view.acc && state.view.acc !== 'all' && acc(state.view.acc) && acc(state.view.acc).canSend ? state.view.acc : (sendable[0] || {}).id)

        form.reset()
        $('[data-editor]').innerHTML = ''
        $('[data-file-names]').textContent = ''
        $('[data-compose-msg]').textContent = ''
        $$('[data-cc-row]').forEach(r => { r.hidden = true })
        $('[data-from]').innerHTML = sendable.map(a => '<option value="' + esc(a.id) + '">' + esc(a.email) + '</option>').join('')
        $('[data-from]').value = fromId || ''
        state.compose.papers = []
        renderPaperLine()
        state.compose.replying = mode !== 'new'
        state.compose.tplSubject = ''
        $('[data-sig]').value = mode === 'new' ? 'new' : 'reply'
        $('[data-compose-title]').textContent = mode === 'new' ? W.compose.title : (mode === 'forward' ? W.forward : W.reply)
        $('[data-compose-body]').hidden = false

        if (m) {
            const from = m.from[0] || { email: '' }
            const re = /^(re|fw|fwd)\s*:/i
            if (mode === 'reply' || mode === 'reply-all') {
                form.to.value = (m.replyTo[0] || from).email || ''
                if (mode === 'reply-all') {
                    const mine = state.accounts.map(a => a.email.toLowerCase())
                    form.cc.value = m.to.concat(m.cc).map(x => x.email).filter(e => e && ! mine.includes(e.toLowerCase())).join(', ')
                    if (form.cc.value) $$('[data-cc-row]').forEach(r => { r.hidden = false })
                }
                form.subject.value = re.test(m.subject) ? m.subject : 'Re: ' + m.subject
                form.inReplyTo.value = m.messageId || ''
            } else {
                form.subject.value = re.test(m.subject) ? m.subject : 'Fwd: ' + m.subject
            }
            $('[data-editor]').innerHTML = '<p><br></p><blockquote>' + esc(fullTime(m.date)) + ' · ' + esc(from.name || from.email) + ':<br>'
                + esc(stripHtml(m.body).slice(0, 4000)).replace(/\n/g, '<br>') + '</blockquote>'
        }

        if (opts.to) form.to.value = opts.to
        if (opts.subject) form.subject.value = opts.subject
        form.hidden = false

        await loadComposeData(opts)

        // ⓘ টেমপ্লেটের পাতা থেকে "এটা দিয়ে মেইল লিখুন"
        if (opts.template) {
            const sel = $('[data-template]')
            sel.value = opts.template
            sel.dispatchEvent(new Event('change', { bubbles: true }))
        }
        ;(form.to.value ? $('[data-editor]') : form.to).focus()
    }

    async function loadComposeData (opts, quiet) {
        const form = $('[data-compose]')
        try {
            const d = await get(url('compose', '', { to: form.to.value, from: $('[data-from]').value, paper: opts && opts.paper, id: opts && opts.id, no: opts && opts.no, party: opts && opts.party }))
            state.compose.data = d
            const keep = $('[data-template]').value
            $('[data-template]').innerHTML = '<option value="">' + esc(W.compose.no_template) + '</option>'
                + d.templates.filter(t => t.category !== 'reply').map(t => '<option value="' + esc(t.id) + '">' + esc(t.name) + '</option>').join('')
            $('[data-template]').value = keep
            $('[data-paper-chips]').innerHTML = d.papers.map(p => '<button type="button" class="mx-chip" data-paper="' + esc(p.kind) + '">' + esc(paperIcon(p.kind) + ' ' + p.label) + '</button>').join('')
            if (d.paper) addPaper(d.paper)
            renderPaperLine()
            showSignature()
        } catch (e) { if (! quiet) $('[data-compose-msg]').textContent = e.message }
    }

    function addPaper (p) {
        if (state.compose.papers.some(x => x.kind === p.kind && x.id === p.id)) return
        state.compose.papers.push(p)
        renderPaperLine()
    }

    function renderPaperLine () {
        const line = $('[data-paper-line]')
        const list = state.compose.papers
        line.hidden = ! list.length
        line.innerHTML = list.length ? '📎 <span>' + esc(tr(W.compose.paper_auto, { files: list.map(p => (p.no || p.label) + '.pdf').join(', ') })) + '</span>'
            + list.map((p, i) => '<button type="button" class="mx-chip" data-unpaper="' + i + '" style="margin-left:4px">✕ ' + esc(p.no || p.label) + '</button>').join('') : ''
        $$('[data-paper]').forEach(b => b.classList.toggle('on', list.some(p => p.kind === b.dataset.paper)))
    }

    function showSignature () {
        const d = state.compose.data
        const which = $('[data-sig]').value
        const view = $('[data-sig-view]')
        view.hidden = which === 'none' || ! d
        if (d && which !== 'none') view.innerHTML = d.signature[which] || ''
    }

    async function send (form, html, accountId, opts) {
        const fd = new FormData()
        addrs(form.to.value).forEach(a => fd.append('to[]', a))
        addrs(form.cc ? form.cc.value : '').forEach(a => fd.append('cc[]', a))
        addrs(form.bcc ? form.bcc.value : '').forEach(a => fd.append('bcc[]', a))
        fd.append('subject', form.subject.value)
        fd.append('html', html)
        fd.append('signature', (opts && opts.signature) || 'new')
        if (opts && opts.template) fd.append('template', opts.template)
        if (form.inReplyTo && form.inReplyTo.value) fd.append('inReplyTo', form.inReplyTo.value)
        ;((opts && opts.papers) || []).forEach((p, i) => {
            fd.append('papers[' + i + '][kind]', p.kind)
            fd.append('papers[' + i + '][id]', p.id)
            if (p.party) fd.append('papers[' + i + '][party]', p.party)
        })
        const files = form.querySelector && form.querySelector('[data-files]')
        if (files) Array.from(files.files).forEach(f => fd.append('files[]', f))
        return post(url('send', accountId), fd)
    }

    // ── অ্যাকাউন্ট জোড়া ────────────────────────────────────────────
    function setProvider (p) {
        const form = $('[data-connect]')
        form.provider.value = p
        $$('[data-provider]').forEach(b => b.classList.toggle('on', b.dataset.provider === p))
        const name = { company: W.add.company, gmail: W.add.gmail, outlook: W.add.outlook, imap: W.add.imap }[p]
        $('[data-connect-title]').textContent = '✉️ ' + tr(W.add.steps_title, { name })
        $('[data-connect-steps]').innerHTML = p === 'gmail' || p === 'outlook'
            ? [tr(W.add.step1, { name }), W.add.step2, W.add.step3].map((s, i) => '<div class="st"><span class="n num">' + (i + 1) + '</span><div>' + esc(s) + '</div></div>').join('')
            : ''
        setKind(p === 'company' ? 'own' : 'personal')
    }

    function setKind (k) {
        const form = $('[data-connect]')
        form.kind.value = k
        $$('[name="kind_pick"]').forEach(r => { r.checked = r.value === k; r.parentElement.classList.toggle('on', r.value === k) })
        $$('[data-only]', form).forEach(el => {
            el.hidden = ! ((el.dataset.only === 'personal' && k === 'personal') || (el.dataset.only === 'imap' && form.provider.value === 'imap'))
        })
        $('[data-connect-btn]').textContent = k === 'personal' ? W.add.ask : W.add.save
    }

    // ── ঘটনা ────────────────────────────────────────────────────────
    root.addEventListener('click', async (ev) => {
        if (! ev.target.closest('[data-menu]') && ! ev.target.closest('[data-act="more"]')) closeMenu()
        const t = ev.target.closest('button, a, [data-key], input[data-pick]')
        if (! t || ! root.contains(t)) return

        if (t.matches('input[data-pick]')) {
            ev.stopPropagation()
            t.checked ? state.picked.add(t.dataset.pick) : state.picked.delete(t.dataset.pick)
            syncBulk()
            return
        }
        if (t.dataset.key) { openMail(t.dataset.key, false); return }
        if (t.dataset.acc) {
            state.view = { acc: t.dataset.acc, path: 'INBOX', role: 'inbox', virtual: '' }
            const inbox = t.dataset.acc !== 'all' && inboxOf(t.dataset.acc)
            if (inbox) state.view.path = inbox.path
            state.page = 1
            closeReader()
            renderAccounts(); renderFolders(); renderChips(); loadList()
            return
        }
        if (t.classList.contains('mx-fold')) {
            state.view = Object.assign({}, state.view, { path: t.dataset.path, role: t.dataset.role, virtual: t.dataset.virtual || '' })
            state.page = 1
            closeReader()
            renderFolders(); loadList()
            return
        }
        if (t.dataset.chip) { state.chip = t.dataset.chip; state.page = 1; renderChips(); loadList(); return }
        if (t.dataset.page) { state.page = Math.max(1, state.page + Number(t.dataset.page)); loadList(); return }
        if (t.dataset.bulk) { bulk(t.dataset.bulk); return }
        if (t.dataset.decide) {
            try { await post(url('decide', t.dataset.decide), { yes: t.dataset.yes === '1' }); loadAccounts() } catch (e) { toast(e.message, true) }
            return
        }
        if (t.dataset.provider) { setProvider(t.dataset.provider); return }
        if (t.dataset.colour) { $('[data-connect]').colour.value = t.dataset.colour; $$('[data-colour]').forEach(b => b.classList.toggle('on', b === t)); return }
        if (t.dataset.paper) { pickPaper(t.dataset.paper); return }
        if (t.dataset.unpaper !== undefined) { state.compose.papers.splice(Number(t.dataset.unpaper), 1); renderPaperLine(); return }
        if (t.dataset.fmt) { ev.preventDefault(); fmt(t.dataset.fmt); return }
        if (t.dataset.smart) {
            const tpl = ((state.compose.data && state.compose.data.templates) || []).find(x => x.id === t.dataset.smart)
            const box = $('[data-quick]')
            if (tpl && box) { box.value = stripHtml(tpl.html).trim(); box.focus() }
            return
        }
        if (t.dataset.moveTo) { closeMenu(); moveOpen(t.dataset.moveTo, 'mail_moved'); return }

        const act = t.dataset.act
        const m = state.open
        if (! act) return

        if (act === 'compose') compose('new')
        else if (act === 'compose-close') $('[data-compose]').hidden = true
        else if (act === 'compose-wide') $('[data-compose]').classList.toggle('wide')
        else if (act === 'compose-small') { const b = $('[data-compose-body]'); b.hidden = ! b.hidden }
        else if (act === 'show-cc') $$('[data-cc-row]').forEach(r => { r.hidden = ! r.hidden })
        else if (act === 'connect') { $('[data-connect]').hidden = false; setProvider('company') }
        else if (act === 'connect-close') $('[data-connect]').hidden = true
        else if (act === 'refresh') { await refreshCounts(); loadList() }
        else if (act === 'more' && m) menuFor(t)
        else if (act === 'images' && m) openMail(state.openAcc + ':' + m.uid, true)
        else if (act === 'trust' && m) {
            closeMenu()
            try { await post(R.trust, { email: (m.from[0] || {}).email, on: true }); openMail(state.openAcc + ':' + m.uid, true) } catch (e) { toast(e.message, true) }
        } else if ((act === 'reply' || act === 'reply-all' || act === 'forward') && m) compose(act)
        else if (act === 'flag' && m) {
            try {
                await post(url('flag', state.openAcc), { folder: m.folder, uid: m.uid, flag: 'flagged', on: ! m.flagged })
                m.flagged = ! m.flagged
                const row = state.rows.find(r => r.acc === state.openAcc && r.uid === m.uid)
                if (row) row.flagged = m.flagged
                renderReader(m); renderRows()
            } catch (e) { toast(e.message, true) }
        } else if (act === 'unread' && m) {
            closeMenu()
            await post(url('flag', state.openAcc), { folder: m.folder, uid: m.uid, flag: 'seen', on: false }).catch(() => null)
            closeReader(); await refreshCounts(); loadList()
        } else if (act === 'later' && m) {
            closeMenu()
            try { await post(url('flag', state.openAcc), { folder: m.folder, uid: m.uid, flag: 'later', on: true }); toast(W.folders.later + ' ✓') } catch (e) { toast(e.message, true) }
        } else if (act === 'archive' && m) {
            const to = roleFolder(state.openAcc, 'archive')
            to ? moveOpen(to, 'mail_archived') : toast(M.no_archive, true)
        } else if (act === 'delete' && m) moveOpen(roleFolder(state.openAcc, 'trash'), 'mail_deleted')
        else if (act === 'keep' && m && state.openParty && state.openParty.ref) {
            try {
                await post(url('link', state.openAcc), { messageId: m.messageId, subject: m.subject, from: (m.from[0] || {}).email, date: m.date, type: state.openParty.ref.type, id: state.openParty.ref.id })
                m.linked = true; renderKeep(); toast(M.linked)
            } catch (e) { toast(e.message, true) }
        } else if (act === 'quick-send' && m) quickSend(t)
    })

    root.addEventListener('keydown', (ev) => {
        const row = ev.target.closest && ev.target.closest('[data-key]')
        if (row && (ev.key === 'Enter' || ev.key === ' ') && ! ev.target.matches('input')) { ev.preventDefault(); openMail(row.dataset.key, false) }
        if (ev.target.matches && ev.target.matches('[data-quick]') && ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); quickSend($('[data-act="quick-send"]')) }
    })

    async function quickSend (btn) {
        const m = state.open
        const box = $('[data-quick]')
        const text = box ? box.value.trim() : ''
        if (! m || ! text) return
        const from = m.from[0] || { email: '' }
        const fake = { to: { value: (m.replyTo[0] || from).email }, cc: { value: '' }, bcc: { value: '' },
            subject: { value: /^re\s*:/i.test(m.subject) ? m.subject : 'Re: ' + m.subject }, inReplyTo: { value: m.messageId || '' } }
        if (btn) btn.disabled = true
        try {
            await send(fake, '<p>' + esc(text).replace(/\n/g, '<br>') + '</p>', state.openAcc, { signature: 'reply' })
            box.value = ''
            toast(M.sent)
        } catch (e) { toast(e.message, true) }
        if (btn) btn.disabled = false
    }

    async function pickPaper (kind) {
        const form = $('[data-compose]')
        if (kind === 'statement') {
            try {
                const p = (await get(url('party', '', { email: addrs(form.to.value)[0] || '' }))).party
                if (! p || ! p.ref) { toast(M.paper_unknown, true); return }
                addPaper({ kind, id: p.ref.id, party: p.ref.type, no: '', label: W.papers.statement + ' · ' + p.name })
            } catch (e) { toast(e.message, true) }
            return
        }
        const no = window.prompt(W.papers[kind] + ' — ' + W.compose.paper_no)
        if (! no) return
        try {
            const d = await get(url('compose', '', { to: form.to.value, paper: kind, no: no.trim() }))
            if (d.paper) addPaper(d.paper)
        } catch (e) { toast(e.message, true) }
    }

    function fmt (cmd) {
        $('[data-editor]').focus()
        if (cmd === 'createLink') {
            const href = window.prompt(W.compose.link_ask, 'https://')
            if (href && /^https?:\/\//i.test(href)) document.execCommand('createLink', false, href)
            return
        }
        document.execCommand(cmd, false, null)
    }

    $('[data-compose]').addEventListener('submit', async (ev) => {
        ev.preventDefault()
        const form = ev.currentTarget
        const msg = $('[data-compose-msg]')
        const btn = $('[data-send]')
        btn.disabled = true
        msg.textContent = W.compose.sending
        try {
            await send(form, $('[data-editor]').innerHTML || '<p></p>', $('[data-from]').value, {
                signature: $('[data-sig]').value, template: $('[data-template]').value, papers: state.compose.papers,
            })
            msg.textContent = M.sent
            setTimeout(() => { form.hidden = true }, 700)
        } catch (e) { msg.textContent = e.message }
        btn.disabled = false
    })

    $('[data-compose]').addEventListener('change', (ev) => {
        if (ev.target.matches('[data-files]')) $('[data-file-names]').textContent = Array.from(ev.target.files).map(f => f.name).join(', ')
        if (ev.target.matches('[data-sig]')) showSignature()
        if (ev.target.matches('[data-from]')) loadComposeData({})
        if (ev.target.matches('[data-template]')) {
            const tpl = ((state.compose.data && state.compose.data.templates) || []).find(x => x.id === ev.target.value)
            const form = $('[data-compose]')
            if (! tpl) return
            if (! form.subject.value || form.subject.value === state.compose.tplSubject) form.subject.value = tpl.subject
            state.compose.tplSubject = tpl.subject
            $('[data-editor]').innerHTML = tpl.html
        }
    })
    $('[data-compose]').addEventListener('focusout', (ev) => {
        // ⓘ প্রাপক বদলালে টেমপ্লেটের ঘর নতুন প্রাপকের তথ্যে
        if (ev.target.matches('[data-to]') && ev.target.value) loadComposeData({})
    })

    $('[data-connect]').addEventListener('change', (ev) => { if (ev.target.name === 'kind_pick') setKind(ev.target.value) })
    $('[data-connect]').addEventListener('submit', async (ev) => {
        ev.preventDefault()
        const form = ev.currentTarget
        const msg = $('[data-connect-msg]')
        msg.textContent = '…'
        const data = Object.fromEntries(new FormData(form).entries())
        delete data.kind_pick
        if (data.kind !== 'personal') { delete data.reason; delete data.months }
        if (data.provider !== 'imap') { delete data.imap_host; delete data.smtp_host }
        try {
            const j = await post(R.add, data)
            msg.textContent = j.message
            form.reset()
            setTimeout(() => { form.hidden = true; loadAccounts() }, 800)
        } catch (e) { msg.textContent = e.message }
    })

    $('[data-q]').addEventListener('keydown', (ev) => {
        if (ev.key !== 'Enter') return
        state.q = ev.target.value.trim()
        state.page = 1
        loadList()
    })
    $('[data-pick-all]').addEventListener('change', (ev) => {
        state.picked.clear()
        if (ev.target.checked) visibleRows().forEach(m => state.picked.add(m.acc + ':' + m.uid))
        renderRows()
        syncBulk()
    })

    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') { $('[data-compose]').hidden = true; $('[data-connect]').hidden = true; closeMenu() }
        if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey) && ! $('[data-compose]').hidden && $('[data-compose]').contains(document.activeElement)) $('[data-compose]').requestSubmit()
    })

    loadAccounts()
    // ⓘ নতুন মেইল — প্রতি 2 মিনিটে, পাতা লুকানো বা কিছু বাছা থাকলে নয়
    setInterval(() => { if (! document.hidden && state.view.acc && ! state.picked.size) { refreshCounts(); if (! state.open) loadList() } }, 120000)

    function toast (text, bad) {
        const p = document.createElement('div')
        p.textContent = text
        p.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:80;padding:9px 16px;border-radius:10px;font-weight:600;box-shadow:0 8px 24px rgb(0 0 0 / .2);'
            + (bad ? 'background:#fde7e8;color:#c40d16' : 'background:#e3f7ea;color:#0a7a33')
        root.appendChild(p)
        setTimeout(() => p.remove(), 3500)
    }
    function dayGroup (iso) {
        if (! iso) return W.earlier
        const d = new Date(iso)
        const t = new Date()
        const y = new Date(); y.setDate(t.getDate() - 1)
        const week = new Date(); week.setDate(t.getDate() - 7)
        if (d.toDateString() === t.toDateString()) return W.today
        if (d.toDateString() === y.toDateString()) return W.yesterday
        if (d > week) return W.this_week
        return W.earlier
    }
    function tbtn (act, label, title) { return '<button type="button" class="mx-btn" data-act="' + act + '"' + (title ? ' title="' + esc(title) + '"' : '') + '>' + esc(label) + '</button>' }
    function paperIcon (k) { return { invoice: '🧾', challan: '🚚', order: '📋', receipt: '💳', voucher: '💳', purchase_order: '🏭', statement: '📒' }[k] || '📄' }
}

// ── ছোট সাহায্যকারী ───────────────────────────────────────────────
function parse (s, f) { try { return JSON.parse(s || '') } catch (e) { return f } }
function esc (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])) }
function tr (s, vars) { return String(s || '').replace(/:([a-z]+)/g, (m, k) => (vars && k in vars ? vars[k] : m)) }
function firstError (j) { const e = j && j.errors; if (! e) return ''; const k = Object.keys(e)[0]; return k ? e[k][0] : '' }
function addrs (s) { return String(s || '').split(/[,;\s]+/).map(x => x.trim()).filter(x => /@/.test(x)) }
function pad (n) { return (n < 10 ? '0' : '') + n }
function timeOf (iso) {
    if (! iso) return ''
    const d = new Date(iso)
    const t = new Date()
    const y = new Date(); y.setDate(t.getDate() - 1)
    if (d.toDateString() === t.toDateString() || d.toDateString() === y.toDateString()) return pad(d.getHours()) + ':' + pad(d.getMinutes())
    return pad(d.getDate()) + '-' + pad(d.getMonth() + 1)
}
function fullTime (iso) { if (! iso) return ''; const d = new Date(iso); return pad(d.getDate()) + '-' + pad(d.getMonth() + 1) + '-' + d.getFullYear() + ' · ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) }
function initials (s) { const p = String(s || '?').replace(/[<>"]/g, '').trim().split(/\s+/); return ((p[0] || '?')[0] + ((p[1] || '')[0] || '')).toUpperCase() }
function colour (s) { const c = ['#0b8a9a', '#b45309', '#4338ca', '#15803d', '#be185d', '#334155', '#1d4ed8', '#7c3aed']; let h = 0; for (const ch of String(s || '')) h = (h * 31 + ch.charCodeAt(0)) >>> 0; return c[h % c.length] }
function size (b) { b = Number(b || 0); return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB' }
function money (v) {
    const n = Math.round(Number(v || 0) * 100) / 100
    const neg = n < 0
    const [i, d] = Math.abs(n).toFixed(2).split('.')
    const last3 = i.slice(-3)
    const rest = i.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',')
    return (neg ? '-' : '') + '৳' + (rest ? rest + ',' : '') + last3 + (d !== '00' ? '.' + d : '')
}
function stripHtml (html) {
    // ⓘ DOMParser — আলাদা নথিতে পড়া, তাই মেইলের ছবি বা কিছুই টানা হয় না
    const doc = new DOMParser().parseFromString(String(html || '').replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>/gi, '\n'), 'text/html')
    return (doc.body.textContent || '').replace(/\n{3,}/g, '\n\n')
}
