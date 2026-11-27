/*
 * The web messages page — owner-approved sample M1UddRxK v7 (tabs ক and খ).
 *
 * ⭐ One plain ES module, no Alpine: the list, the chat, the ⌄ message menu (reply, forward, copy, delete, info),
 * the emoji picker, voice messages (MediaRecorder) and "বলে লিখুন" (Web Speech dictation).
 * ⓘ Every word comes from the root's `data-words` (messaging::web), every address from `data-routes` — the
 * SAME JSON doors as the phone (/messages/x/…, contract §2). Files open by `attachment.web_url` (session auth).
 * ⓘ Realtime without websockets (contract §6): the open chat polls `messages?after=` every 3 s (5 s after 30 s
 * of quiet), the list every 10 s; nothing polls while the tab is hidden.
 * ⓘ The call buttons only announce `messenger:call` {person_id, video}; the call screen listens elsewhere.
 * ⛔ English digits everywhere (owner, 16 Nov) — times, sizes and counts are formatted here by hand.
 */
import '../css/messenger.css'

/* ⓘ the page starts at the END of this file — the module's constants (pad, GRADS, EMOJI…) must exist first */

import { callHistory, takeBefore } from './messenger-calls.js'

function parse(s, fallback) {
    try {
        return JSON.parse(s || '')
    } catch {
        return fallback
    }
}

export function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c])
}

/* ⓘ per-viewer conveniences only (recent emoji, dictation language) — the page works without storage */
function store(key, value) {
    try {
        if (value === undefined) return window.localStorage.getItem(key)
        window.localStorage.setItem(key, value)
    } catch {
        return null
    }
    return null
}

const pad = (n) => String(n).padStart(2, '0')

const GRADS = [
    'linear-gradient(135deg,#f59e0b,#ea580c)',
    'linear-gradient(135deg,#6366f1,#4338ca)',
    'linear-gradient(135deg,#14b8a6,#0f766e)',
    'linear-gradient(135deg,#ec4899,#be185d)',
    'linear-gradient(135deg,#84cc16,#4d7c0f)',
    'linear-gradient(135deg,#06b6d4,#0e7490)',
    'linear-gradient(135deg,#3b82f6,#1e3a8a)',
    'linear-gradient(135deg,#22c55e,#166534)',
    'linear-gradient(135deg,#fb923c,#9a3412)',
]
const NAME_COLOURS = ['#5b61d6', '#d63384', '#0f9d8a', '#c2410c', '#7c3aed', '#0e7490', '#b45309', '#15803d']

export function hash(s) {
    let h = 0
    for (const ch of String(s ?? '')) h = (h * 31 + ch.codePointAt(0)) >>> 0
    return h
}

const segmenter = typeof Intl !== 'undefined' && Intl.Segmenter ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : null

export function initial(name) {
    const s = String(name || '').trim()
    const first = segmenter ? segmenter.segment(s)[Symbol.iterator]().next().value?.segment : Array.from(s)[0]
    return first ? first.toUpperCase() : '?'
}

/* ── emoji: real characters (the sample drew faces only because an artifact cannot rely on emoji fonts) ── */
const EMOJI = {
    smileys: '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😙 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🥵 🥶 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠 🤬',
    people: '👋 🤚 ✋ 🖖 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ 👍 👎 ✊ 👊 🤛 🤜 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💪 👀 🧠 👶 🧒 👦 👧 🧑 👨 👩 🧓 👴 👵 🙋 🙇 🤦 🤷 👮 👷 💂 🧑‍💼 👨‍💼 👩‍💼 🧑‍💻 🧑‍🌾 🧑‍🍳 🧑‍🔧 🧑‍🏭 🚶 🏃 👪',
    nature: '🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🐔 🐧 🐦 🐤 🦆 🦅 🦉 🐴 🐝 🦋 🐌 🐞 🐢 🐍 🐟 🐠 🐬 🐳 🐘 🐄 🐐 🐓 🌵 🌲 🌳 🌴 🌱 🌿 🍀 🍁 🍂 🌾 🌷 🌹 🌺 🌸 🌼 🌻 🌞 🌙 ⭐ 🌟 ⚡ 🔥 🌈 ☀️ ⛅ ☁️ 🌧️ ⛈️ ❄️ 💧 🌊',
    food: '🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🍈 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🍆 🥑 🥦 🥬 🥒 🌶️ 🌽 🥕 🧄 🧅 🥔 🍠 🥐 🍞 🥖 🧀 🥚 🍳 🥞 🥓 🍗 🍖 🌭 🍔 🍟 🍕 🥪 🌮 🥗 🍝 🍜 🍲 🍛 🍣 🍱 🍚 🍙 🍘 🍥 🍤 🍦 🍧 🍨 🍩 🍪 🎂 🍰 🧁 🍫 🍬 🍭 🍯 🥛 ☕ 🍵 🧃 🥤 🧊',
    activity: '⚽ 🏀 🏈 ⚾ 🎾 🏐 🏉 🎱 🏓 🏸 🏏 🏑 🥅 ⛳ 🏹 🎣 🥊 🥋 🎽 ⛸️ 🏆 🥇 🥈 🥉 🏅 🎖️ 🎗️ 🎫 🎟️ 🎪 🎭 🎨 🎬 🎤 🎧 🎼 🎹 🥁 🎷 🎺 🎸 🎻 🎲 🎯 🎳 🎮 🧩 🎉 🎊 🎈 🎁',
    travel: '🚗 🚕 🚙 🚌 🚎 🏎️ 🚓 🚑 🚒 🚐 🛻 🚚 🚛 🚜 🛵 🏍️ 🚲 🛺 🚨 🚔 🚍 🚘 🚖 🚡 🚠 🚃 🚋 🚝 🚄 🚅 🚈 🚂 🚆 🚇 🚊 🚉 ✈️ 🛫 🛬 🚁 🚀 ⛵ 🚤 🛥️ 🚢 ⚓ ⛽ 🚧 🚦 🗺️ 🏠 🏡 🏢 🏬 🏭 🏦 🏥 🏫 🕌 🛕 ⛪ 🏛️ 🌉 🌃',
    objects: '⌚ 📱 💻 ⌨️ 🖥️ 🖨️ 🖱️ 💽 💾 💿 📷 📹 🎥 📞 ☎️ 📟 📠 📺 📻 🎙️ ⏰ ⏳ 🔋 🔌 💡 🔦 🕯️ 🧯 🛢️ 💸 💵 💴 💶 💷 💰 💳 🧾 💎 ⚖️ 🔧 🔨 🛠️ ⚙️ 🧰 🔩 🧱 🔑 🗝️ 🔒 🔓 📦 📫 📮 📝 📁 📂 📅 📆 📇 📈 📉 📊 📋 📌 📍 📎 🖇️ 📏 📐 ✂️ 🗑️ 🖊️ ✏️ 📚 📖 🔖 🏷️ 🧮',
    symbols: '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 ✅ ☑️ ✔️ ❌ ❎ ➕ ➖ ➗ ✖️ ❓ ❔ ❗ ❕ ‼️ ⁉️ 💯 🔴 🟠 🟡 🟢 🔵 🟣 ⚫ ⚪ 🟥 🟧 🟨 🟩 🟦 🟪 ⬛ ⬜ 🔶 🔷 🔸 🔹 ⭕ 🚫 ⛔ ⚠️ ♻️ 🔔 🔕 📣 💬 💭 🗯️ ♾️ 🆗 🆕 🆓 🔝 🔜 ⏩ ⏪ ▶️ ⏸️ ⏹️ 🔁 🔀',
    flags: '🇧🇩 🇮🇳 🇵🇰 🇳🇵 🇱🇰 🇧🇹 🇲🇻 🇨🇳 🇯🇵 🇰🇷 🇸🇬 🇲🇾 🇹🇭 🇮🇩 🇵🇭 🇻🇳 🇦🇪 🇸🇦 🇶🇦 🇰🇼 🇴🇲 🇧🇭 🇹🇷 🇬🇧 🇺🇸 🇨🇦 🇦🇺 🇳🇿 🇩🇪 🇫🇷 🇮🇹 🇪🇸 🇳🇱 🇸🇪 🇨🇭 🇷🇺 🇧🇷 🇦🇷 🇿🇦 🇪🇬 🏁 🚩 🏳️ 🏴',
}
/* the picker's search box — a few everyday words, Bengali and English */
const EMOJI_WORDS = {
    'smile হাসি happy খুশি': '😀 😃 😄 😁 😊 🙂 😇 🥳',
    'laugh হাহা lol মজা': '😂 🤣 😆 😅',
    'love ভালোবাসা heart হৃদয়': '❤️ 😍 🥰 😘 💕 💖',
    'sad দুঃখ কান্না cry': '😢 😭 😞 😔 💔',
    'angry রাগ': '😠 😡 🤬 😤',
    'ok ঠিক yes হ্যাঁ done': '👌 👍 ✅ 🆗 ✔️',
    'no না wrong ভুল': '👎 ❌ 🚫 ⛔',
    'thanks ধন্যবাদ please দয়া': '🙏 🤝',
    'clap তালি bravo শাবাশ': '👏 🙌 💯',
    'money টাকা cash নগদ': '💰 💵 💸 💳 🧾',
    'truck গাড়ি delivery ডেলিভারি car': '🚚 🚛 🚗 🛻 📦',
    'fire আগুন hot': '🔥',
    'party উৎসব celebrate': '🎉 🎊 🥳 🎂 🎁',
    'tea চা coffee কফি': '☕ 🍵',
    'phone ফোন call কল': '📱 📞 ☎️',
    'paper কাগজ document note': '🧾 📝 📋 📎',
    'time সময় clock ঘড়ি': '⏰ ⌚ ⏳',
    'bangladesh বাংলাদেশ flag পতাকা': '🇧🇩',
    'think ভাবছি': '🤔 🧐',
    'sleep ঘুম': '😴',
    'sun রোদ rain বৃষ্টি': '☀️ 🌧️ ⛅ 🌈',
}

export function start(root) {
    if (!root || root.dataset.msgrStarted === '1') return
    root.dataset.msgrStarted = '1'
    const W = parse(root.dataset.words, {})
    const R = parse(root.dataset.routes, {})
    const ME = parse(root.dataset.me, { id: '', name: '' })
    const LOCALE = root.dataset.locale || 'bn'
    const CSRF = root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''

    const $ = (sel, scope = root) => scope.querySelector(sel)
    const $$ = (sel, scope = root) => Array.from(scope.querySelectorAll(sel))
    const byMid = (id) => $(`[data-mid="${CSS.escape(String(id))}"]`, el.msgs)

    function w(key, params = {}) {
        let v = key.split('.').reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), W)
        if (typeof v !== 'string') v = key
        for (const [k, val] of Object.entries(params)) v = v.split(':' + k).join(String(val))
        return v
    }

    const ic = (id, cls = 'i') => `<svg class="${cls}" aria-hidden="true"><use href="#mg-${id}"/></svg>`

    /* ── elements ── */
    const el = {
        rows: $('[data-rows]'),
        listQ: $('[data-list-q]'),
        unreadTotal: $('[data-unread-total]'),
        empty: $('[data-empty]'),
        head: $('[data-head]'),
        headAv: $('[data-head-av]'),
        headTitle: $('[data-head-title]'),
        headSub: $('[data-head-sub]'),
        kebab: $('[data-kebab]'),
        cwrap: $('[data-cwrap]'),
        scroll: $('[data-scroll]'),
        msgs: $('[data-msgs]'),
        emo: $('[data-emo]'),
        emoGrid: $('[data-emo-grid]'),
        emoLabel: $('[data-emo-label]'),
        emoQ: $('[data-emo-q]'),
        replybar: $('[data-replybar]'),
        replyQuote: $('[data-reply-quote]'),
        stage: $('[data-stage]'),
        comp: $('[data-comp]'),
        atray: $('[data-atray]'),
        input: $('[data-input]'),
        rec: $('[data-rec]'),
        recTime: $('[data-rec-time]'),
        recLevel: $('[data-rec-level]'),
        dict: $('[data-act="dictate"]'),
        dictMenu: $('[data-dict-menu]'),
        send: $('[data-act="send"]'),
        voiceBtn: $('[data-act="voice"]'),
        info: $('[data-info]'),
        infoTitle: $('[data-info-title]'),
        infoBody: $('[data-info-body]'),
        dialogHost: $('[data-dialog-host]'),
        toast: $('[data-toast]'),
    }

    /* ── state ── */
    const S = {
        filter: 'all',
        q: '',
        threads: [],
        unreadTotal: 0,
        listSeq: 0,
        cur: null,
        members: [],
        openSeq: 0,
        msgs: new Map(),
        order: [],
        lastId: 0,
        firstId: 0,
        hasMore: false,
        loadingOlder: false,
        readUpTo: 0,
        reply: null,
        staged: [],
        typing: [],
        peerOnline: false,
        quietSince: Date.now(),
        pollTimer: 0,
        listTimer: 0,
        lastTypingSent: 0,
        audio: new Map(),
        menuFor: null,
        infoMode: null,
        shared: [],
    }

    /* ── calls (messenger-calls.js) — set before the page starts ── */
    let calls = null

    /* ── formatting (English digits) ── */
    function dateOf(iso) {
        const d = iso ? new Date(iso) : null
        return d && !Number.isNaN(d.getTime()) ? d : null
    }
    const hm = (d) => (d ? pad(d.getHours()) + ':' + pad(d.getMinutes()) : '')
    const dayKey = (d) => (d ? d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate() : '')
    function daysAgo(d) {
        const a = new Date(d.getFullYear(), d.getMonth(), d.getDate())
        const now = new Date()
        const b = new Date(now.getFullYear(), now.getMonth(), now.getDate())
        return Math.round((b - a) / 86400000)
    }
    const dmy = (d) => pad(d.getDate()) + '-' + pad(d.getMonth() + 1) + '-' + d.getFullYear()
    function weekday(d) {
        const names = Array.isArray(W.weekdays) ? W.weekdays : []
        return names[d.getDay()] || dmy(d)
    }
    function listTime(iso) {
        const d = dateOf(iso)
        if (!d) return ''
        const ago = daysAgo(d)
        if (ago <= 0) return hm(d)
        if (ago === 1) return w('yesterday')
        if (ago < 7) return weekday(d)
        return dmy(d)
    }
    function dayLabel(d) {
        const ago = daysAgo(d)
        if (ago <= 0) return w('today')
        if (ago === 1) return w('yesterday')
        if (ago < 7) return weekday(d)
        return dmy(d)
    }
    function size(bytes) {
        const b = Number(bytes) || 0
        if (b < 1024) return b + ' B'
        if (b < 1024 * 1024) return Math.round(b / 1024) + ' KB'
        return (b / 1024 / 1024).toFixed(1) + ' MB'
    }
    function dur(ms) {
        const s = Math.max(0, Math.round((Number(ms) || 0) / 1000))
        return Math.floor(s / 60) + ':' + pad(s % 60)
    }
    const extOf = (name) => (String(name || '').match(/\.([a-z0-9]{1,5})$/i)?.[1] || '').toLowerCase()

    /* ── avatars ── */
    function avatar(person, px, online = false, extra = '') {
        const p = person || {}
        const bg = p.colour || GRADS[hash(p.id || p.name) % GRADS.length]
        const inner = p.photo_url ? `<img src="${esc(p.photo_url)}" alt="" loading="lazy">` : esc(initial(p.name))
        const style = `background:${esc(bg)};width:${px}px;height:${px}px;font-size:${Math.round(px * 0.38)}px`
        return `<span class="av" style="${style}">${inner}${online ? '<i class="on-dot"></i>' : ''}${extra}</span>`
    }
    function threadAvatar(t, px, online) {
        if (t.kind === 'group') {
            const bg = t.colour || GRADS[(hash(t.id) + 6) % GRADS.length]
            return `<span class="av" style="background:${esc(bg)};width:${px}px;height:${px}px">${ic('users', '')}</span>`
        }
        return avatar(t.person || { id: t.id, name: t.title }, px, online ?? !!t.person?.online)
    }
    const nameColour = (id) => NAME_COLOURS[hash(id) % NAME_COLOURS.length]

    /* ── doors ── */
    const url = (name, thread) => String(R[name] || '').replace('__T__', encodeURIComponent(thread ?? ''))
    const msgUrl = (name, id) => String(R[name] || '').replace('987654321', encodeURIComponent(id))
    function withQuery(u, params) {
        const q = new URLSearchParams()
        for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') q.set(k, String(v))
        const s = q.toString()
        return s ? u + (u.includes('?') ? '&' : '?') + s : u
    }
    async function api(method, u, body) {
        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        if (method !== 'GET') headers['X-CSRF-TOKEN'] = CSRF
        let payload
        if (body instanceof FormData) payload = body
        else if (body !== undefined) {
            headers['Content-Type'] = 'application/json'
            payload = JSON.stringify(body)
        }
        const res = await fetch(u, { method, headers, body: payload, credentials: 'same-origin' })
        if (res.status === 204) return null
        let data = null
        try {
            data = await res.json()
        } catch {
            data = null
        }
        if (!res.ok) {
            // ⓘ a 403 is {error, message} (contract §0); a 422 names the field
            let message = (data && data.message) || w('error')
            if (res.status === 422 && data && data.errors) message = Object.values(data.errors).flat()[0] || message
            const err = new Error(message)
            err.status = res.status
            throw err
        }
        return data
    }

    /* ── toast ── */
    let toastTimer = 0
    function toast(text) {
        el.toast.textContent = text
        el.toast.hidden = false
        clearTimeout(toastTimer)
        toastTimer = setTimeout(() => {
            el.toast.hidden = true
        }, 3200)
    }

    /* ── the two panes fill the work area down to the status bar ── */
    function fit() {
        const top = root.getBoundingClientRect().top + window.scrollY
        root.style.setProperty('--msgr-top', Math.max(0, Math.round(top)) + 'px')
    }
    fit()
    window.addEventListener('resize', fit)

    /* ════════════════ the list ════════════════ */
    function tick(state, cls = 'tkm') {
        if (state === 'pending') return `<svg class="${cls} pend" aria-hidden="true"><use href="#mg-clock"/></svg>`
        if (!state || state === 'failed') return ''
        return `<svg class="${cls}${state === 'read' ? ' rd' : ''}" aria-hidden="true"><use href="#mg-${state === 'sent' ? 'tk1' : 'tk2'}"/></svg>`
    }
    function kindLine(m) {
        const a = m.attachment || {}
        switch (m.kind) {
            case 'image':
                return ic('image') + `<span>${esc(m.text || w('photo'))}</span>`
            case 'file':
                return ic('doc') + `<span>${esc(a.name || w('file'))}</span>`
            case 'voice':
                return ic('micf') + `<span>${esc(w('voice_message'))} · ${dur(a.duration_ms)}</span>`
            case 'paper':
                return `<span class="ppr">${ic('paper')}${esc(m.paper?.no || '')}</span><span>· ${esc(m.paper?.title || '')}${m.text ? ' · ' + esc(m.text) : ''}</span>`
            case 'location':
                return ic('pin') + `<span>${esc(w('location'))}</span>`
            case 'deleted':
                return ic('ban') + `<span>${esc(w('deleted'))}</span>`
            default:
                return `<span>${esc(m.text)}</span>`
        }
    }
    function lastLine(t) {
        const m = t.last
        if (!m) return ''
        if (m.kind === 'system' || m.kind === 'deleted') return kindLine(m)
        let who = ''
        if (m.mine) who = w('said', { name: w('you') })
        else if (t.kind === 'group' && m.sender) who = w('said', { name: m.sender.name })
        const tk = m.mine ? tick(m.state) : ''
        if (m.kind === 'text') return tk + `<span>${esc(who + (m.text || ''))}</span>`
        return tk + (who ? `<span>${esc(who)}</span>` : '') + kindLine(m)
    }
    function rowHtml(t) {
        const sel = S.cur && S.cur.id === t.id
        const un = sel ? 0 : Number(t.unread) || 0
        const badge = un ? `<span class="badge" aria-label="${esc(w('unread_badge', { count: un }))}">${un}</span>` : ''
        const muted = t.muted ? ic('belloff', 'mutei') : ''
        const cl = calls?.lastFor(t) ?? null
        const missed = cl && calls.isMissed(cl) ? ' miss' : ''
        return `<button type="button" class="crow${un ? ' un' : ''}${sel ? ' sel' : ''}${missed}" role="listitem" data-tid="${esc(t.id)}">${threadAvatar(t, 49)}<div class="tx"><div class="l1"><b>${esc(t.title)}</b><time>${esc(listTime(cl ? cl.at : t.last_at || t.last?.at))}</time></div><small>${esc(t.subtitle)}</small><div class="l3"><span class="last">${cl ? calls.lastHtml(cl) : lastLine(t)}</span>${muted}${badge}</div></div></button>`
    }
    function renderList() {
        el.unreadTotal.textContent = S.unreadTotal ? String(S.unreadTotal) : ''
        if (calls?.on) return // ⓘ the rows are the call list now
        const focused = document.activeElement?.closest?.('.crow')?.dataset.tid
        const threads = calls ? calls.sorted(S.threads) : S.threads
        el.rows.innerHTML = threads.length ? threads.map(rowHtml).join('') : `<p class="none">${esc(w('empty_list'))}</p>`
        if (focused) $(`.crow[data-tid="${CSS.escape(focused)}"]`)?.focus()
    }
    async function loadList() {
        const seq = ++S.listSeq
        try {
            const d = await api('GET', withQuery(R.threads, { filter: S.filter, q: S.q }))
            if (seq !== S.listSeq) return
            S.threads = d.data || []
            S.unreadTotal = Number(d.unread_total) || 0
            if (S.cur) {
                const fresh = S.threads.find((t) => t.id === S.cur.id)
                if (fresh) {
                    S.cur = { ...S.cur, ...fresh, members: S.cur.members }
                    renderHead()
                }
            }
            renderList()
        } catch {
            /* a missed tick — the next one tries again */
        }
    }
    function scheduleList() {
        clearTimeout(S.listTimer)
        S.listTimer = setTimeout(async () => {
            if (!document.hidden) {
                await loadList()
                await calls?.refresh()
                await calls?.pollChat()
            }
            scheduleList()
        }, 10000)
    }

    el.rows.addEventListener('click', (e) => {
        const row = e.target.closest('.crow[data-tid]')
        if (row) openThread(row.dataset.tid)
    })
    $$('.mchips [data-filter]').forEach((b) =>
        b.addEventListener('click', () => {
            S.filter = b.dataset.filter
            $$('.mchips [data-filter]').forEach((o) => {
                o.classList.toggle('on', o === b)
                o.setAttribute('aria-selected', o === b ? 'true' : 'false')
            })
            loadList()
        }),
    )
    let listQTimer = 0
    el.listQ.addEventListener('input', () => {
        clearTimeout(listQTimer)
        listQTimer = setTimeout(() => {
            if (calls?.on) return calls.search(el.listQ.value.trim())
            S.q = el.listQ.value.trim()
            loadList()
            return undefined
        }, 300)
    })

    /* ════════════════ the chat header ════════════════ */
    function renderHead() {
        const t = S.cur
        if (!t) return
        const group = t.kind === 'group'
        const online = S.peerOnline || !!t.person?.online
        el.headAv.outerHTML = threadAvatar(t, 42, online).replace('class="av"', 'class="av" data-head-av')
        el.headAv = $('[data-head-av]')
        el.headTitle.textContent = t.title || ''
        let sub
        const typing = S.typing.length > 0
        if (typing) {
            sub = S.typing.length === 1 ? w('typing_one', { name: group ? S.typing[0].name : t.title }) : w('typing_many', { count: S.typing.length })
        } else if (group) {
            sub = w('group_head', { count: t.member_count || S.members.length, online: t.online_count || 0 })
        } else {
            sub = online ? w('online') : t.subtitle || ''
        }
        el.headSub.textContent = sub
        el.headSub.classList.toggle('typ', typing)
        $('[data-act="info"]').setAttribute('aria-label', group ? w('group_info') : w('contact_info'))
        $$('[data-group-only]').forEach((x) => {
            x.hidden = !group
        })
        // ⓘ no call buttons at all when LiveKit is not set up (calls_off) — the view leaves them out
        $('[data-act="call-video"]')?.setAttribute('aria-label', group ? w('group_video') : w('video_call'))
        $('[data-act="call-voice"]')?.setAttribute('aria-label', group ? w('group_call') : w('voice_call'))
        $('[data-mute-label]').textContent = t.muted ? w('menu_unmute') : w('menu_mute')
        $('[data-mute-icon]').setAttribute('href', t.muted ? '#mg-bell' : '#mg-belloff')
    }

    function showChat(on) {
        el.empty.hidden = on
        el.head.hidden = !on
        el.cwrap.hidden = !on
        el.comp.hidden = !on
        root.classList.toggle('chat-open', on)
        if (!on) {
            closeEmoji()
            clearReply()
            clearStage()
            closeInfo()
        }
    }

    /* ════════════════ opening a thread ════════════════ */
    async function openThread(tid) {
        if (!tid) return
        if (S.cur && S.cur.id === tid) {
            root.classList.add('chat-open')
            return
        }
        if (REC.on) stopRecording(false)
        if (DICT.rec) stopDictation()
        const seq = ++S.openSeq
        stopPoll()
        stopAllAudio()
        S.cur = S.threads.find((t) => t.id === tid) || { id: tid, kind: 'direct', title: '' }
        Object.assign(S, { members: [], msgs: new Map(), order: [], lastId: 0, firstId: 0, hasMore: false, readUpTo: 0, typing: [], menuFor: null })
        S.peerOnline = !!S.cur.person?.online
        S.quietSince = Date.now()
        clearReply()
        clearStage()
        closeEmoji()
        closeInfo()
        el.msgs.innerHTML = `<span class="more">${esc(w('loading'))}</span>`
        el.input.value = ''
        autosize()
        updateSend()
        showChat(true)
        renderHead()
        renderList()
        try {
            window.history.replaceState(null, '', withQuery(R.page, { t: tid }))
        } catch {
            /* not important */
        }
        try {
            const d = await api('GET', url('thread', tid))
            if (seq !== S.openSeq) return
            S.cur = { ...S.cur, ...d.data }
            S.members = d.data.members || []
            renderHead()
            const m = await api('GET', url('messages', tid))
            if (seq !== S.openSeq) return
            for (const msg of m.data || []) putMsg(msg)
            S.hasMore = !!m.meta?.has_more
            applyMeta(m.meta)
            await calls?.openChat(S.cur)
            if (seq !== S.openSeq) return
            renderAll()
            toBottom()
            markRead()
            schedulePoll()
            if (window.matchMedia('(min-width: 900px)').matches) el.input.focus()
        } catch (e) {
            if (seq !== S.openSeq) return
            toast(e.message)
            S.cur = null
            showChat(false)
            renderList()
        }
    }

    function closeChat() {
        if (REC.on) stopRecording(false)
        if (DICT.rec) stopDictation()
        S.openSeq++
        stopPoll()
        stopAllAudio()
        S.cur = null
        showChat(false)
        renderList()
        try {
            window.history.replaceState(null, '', R.page)
        } catch {
            /* not important */
        }
    }

    /* ════════════════ messages: the store ════════════════ */
    const isReal = (id) => /^\d+$/.test(String(id))
    function putMsg(m) {
        const id = String(m.id)
        if (m.client_id && isReal(id)) {
            const tmp = 'c:' + m.client_id
            if (S.msgs.has(tmp)) {
                S.msgs.delete(tmp)
                S.order = S.order.filter((x) => x !== tmp)
                byMid(tmp)?.remove()
            }
        }
        const had = S.msgs.has(id)
        S.msgs.set(id, m)
        if (had) return false
        if (isReal(id)) {
            // real ids stay ascending and before the bubbles still on their way
            const firstPending = S.order.findIndex((x) => !isReal(x))
            let at = firstPending < 0 ? S.order.length : firstPending
            while (at > 0 && Number(S.order[at - 1]) > Number(id)) at--
            S.order.splice(at, 0, id)
            S.lastId = Math.max(S.lastId, Number(id))
            S.firstId = S.firstId ? Math.min(S.firstId, Number(id)) : Number(id)
        } else {
            S.order.push(id)
        }
        return true
    }

    /* ════════════════ messages: drawing ════════════════ */
    function sameRun(a, b) {
        if (!a || !b) return false
        if (a.kind === 'system' || b.kind === 'system') return false
        if (dayKey(dateOf(a.at)) !== dayKey(dateOf(b.at))) return false
        if (a.mine !== b.mine) return false
        return a.mine || String(a.sender?.id) === String(b.sender?.id)
    }
    function metaHtml(m, blk = false) {
        let pre = ''
        if (m.mine && S.cur?.kind === 'group' && Number(m.read_count) > 0 && m.kind !== 'deleted') pre = esc(w('read_by', { count: m.read_count }))
        const t = m.mine && m.kind !== 'deleted' ? tick(m.failed ? 'failed' : m.state, '') : ''
        return `<span class="mt${blk ? ' blk' : ''}">${pre}${esc(hm(dateOf(m.at)))}${t}</span>`
    }
    function linkify(escaped) {
        return escaped.replace(/\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)'"]/g, (u) => `<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`)
    }
    function previewOf(kind, text, paperNo) {
        switch (kind) {
            case 'image':
                return [ic('image', ''), text || w('photo')]
            case 'file':
                return [ic('doc', ''), text || w('file')]
            case 'voice':
                return [ic('micf', ''), w('voice_message')]
            case 'paper':
                return [ic('paper', ''), [paperNo, text].filter(Boolean).join(' · ')]
            case 'location':
                return [ic('pin', ''), w('location')]
            case 'deleted':
                return [ic('ban', ''), w('deleted')]
            default:
                return ['', text || '']
        }
    }
    function quoteHtml(r, tag = 'button') {
        if (!r) return ''
        const name = r.mine ? w('you') : r.sender?.name || ''
        const colour = r.mine ? '#5b61d6' : nameColour(r.sender?.id || name)
        const [icon, text] = previewOf(r.kind, r.text)
        const attrs = tag === 'button' ? ` type="button" data-act="jump" data-to="${esc(r.id)}"` : ''
        return `<${tag} class="quote"${attrs} style="border-left-color:${colour}"><b style="color:${colour}">${esc(name)}</b><span>${icon}${esc(text)}</span></${tag}>`
    }
    const paperIsMoney = (p) => /receipt|deposit|collection|payment|rcv/i.test(String(p?.type || ''))
    function paperCard(p) {
        // ⛔ no grey lock or notice line on the card (owner); "খুলুন" decides at the click
        const money = paperIsMoney(p)
        const sub = p.can_open ? [p.amount, p.party].filter(Boolean) : []
        return `<div class="paper"><div class="pr"><span class="pic${money ? ' g' : ''}">${ic(money ? 'deposit' : 'paper', '')}</span><div class="ptx"><b>${esc(p.title)} ${esc(p.no)}</b>${sub.length ? `<small>${sub.map(esc).join(' · ')}</small>` : ''}</div></div>${p.status ? `<span class="st">${esc(p.status)}</span>` : ''}<button type="button" class="open" data-act="paper-open">${esc(w('open'))}</button></div>`
    }
    function bars(m, n = 34) {
        let src = Array.isArray(m.attachment?.waveform) ? m.attachment.waveform.map(Number).filter((x) => !Number.isNaN(x)) : []
        if (!src.length) {
            let h = hash(m.id) || 7
            src = Array.from({ length: n }, () => {
                h = (h * 1103515245 + 12345) >>> 0
                return 0.2 + ((h >>> 8) % 100) / 125
            })
        }
        return Array.from({ length: n }, (_, i) => {
            const v = src[Math.min(src.length - 1, Math.floor((i * src.length) / n))]
            return Math.round(5 + Math.max(0, Math.min(1, v)) * 23)
        })
    }
    function voiceHtml(m) {
        const a = m.attachment || {}
        const st = S.audio.get(String(m.id))
        const frac = st ? st.frac : 0
        const playing = !!(st && st.playing)
        const hs = bars(m)
        const who = m.mine ? ME : m.sender
        const badge = `<span class="mb">${ic('micf', '')}</span>`
        const shown = st && (playing || frac > 0) ? dur(st.pos * 1000) : dur(a.duration_ms)
        const wf = hs.map((h, i) => `<i class="${(i + 0.5) / hs.length <= frac ? 'pd' : ''}" style="height:${h}px"></i>`).join('')
        return `<div class="voice">${avatar(who, 46, false, badge)}<button type="button" class="pl" data-act="v-play" aria-label="${esc(playing ? w('pause') : w('play'))}">${ic(playing ? 'pause' : 'play', '')}</button><span class="wf" data-act="v-seek">${wf}<b class="knob" style="left:${(frac * 100).toFixed(1)}%"></b></span></div><div class="vfoot"><span class="dur">${shown}</span>${metaHtml(m)}</div>`
    }
    function fileIcon(name) {
        const ext = extOf(name)
        const cls = ['xls', 'xlsx', 'csv'].includes(ext) ? ' x' : ['doc', 'docx'].includes(ext) ? ' w' : ext === 'pdf' ? '' : ' o'
        return `<span class="fic${cls}">${esc(ext || '?')}</span>`
    }
    function caption(m) {
        return m.text ? `<div class="cap2"><span class="txt">${linkify(esc(m.text))}</span>${metaHtml(m)}</div>` : metaHtml(m, true)
    }
    function bodyHtml(m) {
        const a = m.attachment || {}
        switch (m.kind) {
            case 'deleted':
                return `<span class="tomb">${ic('ban', '')}${esc(w('deleted'))}</span>${metaHtml(m)}`
            case 'image': {
                const src = a.local || (a.thumb_url ? withQuery(a.web_url, { thumb: 1 }) : a.web_url)
                const ratio = a.width && a.height ? ` style="aspect-ratio:${Number(a.width)}/${Number(a.height)}"` : ''
                const img = `<img src="${esc(src)}" alt="${esc(a.name || w('photo'))}" loading="lazy"${ratio}>`
                return (a.web_url ? `<a class="photo" href="${esc(a.web_url)}" target="_blank" rel="noopener">${img}</a>` : `<span class="photo">${img}</span>`) + caption(m)
            }
            case 'file': {
                const inner = `${fileIcon(a.name)}<div class="ftx"><b>${esc(a.name)}</b><small>${esc(extOf(a.name).toUpperCase())} · ${size(a.size)}</small></div><span class="dl">${ic('download', '')}</span>`
                return (a.web_url ? `<a class="file" href="${esc(a.web_url)}" download="${esc(a.name)}" title="${esc(w('download'))}">${inner}</a>` : `<span class="file">${inner}</span>`) + caption(m)
            }
            case 'voice':
                return voiceHtml(m)
            case 'paper':
                return paperCard(m.paper || {}) + caption(m)
            case 'location': {
                const href = `https://www.google.com/maps?q=${encodeURIComponent(m.lat)},${encodeURIComponent(m.lng)}`
                return `<a class="loc" href="${esc(href)}" target="_blank" rel="noopener noreferrer"><span class="pic">${ic('pin', '')}</span><span><b>${esc(w('location'))}</b><small>${esc(w('open_map'))}</small></span></a>${metaHtml(m, true)}`
            }
            default:
                return `<span class="txt">${linkify(esc(m.text))}</span>${metaHtml(m)}`
        }
    }
    function msgHtml(m, first) {
        const id = esc(m.id)
        if (m.kind === 'system') return `<div class="sysl" data-mid="${id}">${esc(m.text)}</div>`
        const side = m.mine ? 'me' : 'th'
        const withAv = S.cur?.kind === 'group' && !m.mine
        const kindCls = { paper: ' pp', file: ' fl', image: ' im' }[m.kind] || ''
        let lead = ''
        if (withAv) lead = first ? avatar(m.sender, 30) : '<span class="avsp"></span>'
        const name = withAv && first && m.sender ? `<span class="sn" style="color:${nameColour(m.sender.id)}">${esc(m.sender.name)}</span>` : ''
        const chev = isReal(m.id) ? `<button type="button" class="chev" data-act="menu" aria-label="${esc(w('msg_menu'))}" aria-haspopup="menu">${ic('chev', '')}</button>` : ''
        const fwd = m.forwarded && m.kind !== 'deleted' ? `<span class="fwd">${ic('fwd', '')}${esc(w('forwarded'))}</span>` : ''
        const quote = m.kind !== 'deleted' ? quoteHtml(m.reply_to) : ''
        const fail = m.failed ? `<span class="failtx">${esc(w('failed'))}</span>` : ''
        return `<div class="mr ${side}${first ? ' tl' : ''}${withAv ? ' wa2' : ''}" data-mid="${id}">${lead}<div class="bub${first ? ' tail' : ''}${kindCls}${m.failed ? ' fail' : ''}">${chev}${name}${fwd}${quote}${bodyHtml(m)}${fail}</div></div>`
    }
    function renderAll() {
        let html = S.hasMore ? `<span class="more" data-more${S.loadingOlder ? '' : ' hidden'}>${esc(w('loading'))}</span>` : ''
        let prev = null
        let day = ''
        const daySep = (at) => {
            const d = dateOf(at)
            if (!d || dayKey(d) === day) return ''
            day = dayKey(d)
            return `<span class="dsep">${esc(dayLabel(d))}</span>`
        }
        const queue = calls ? calls.drawn() : []
        const putCalls = (until) => {
            for (const c of takeBefore(queue, until)) {
                html += daySep(c.at) + calls.bubbleHtml(c)
                prev = null
            }
        }
        for (const id of S.order) {
            const m = S.msgs.get(id)
            if (!m) continue
            putCalls(dateOf(m.at)?.getTime() ?? 0)
            html += daySep(m.at)
            html += msgHtml(m, !sameRun(prev, m))
            prev = m
        }
        putCalls(Infinity)
        el.msgs.innerHTML = html
    }
    function appendMsg(m) {
        const id = String(m.id)
        const i = S.order.indexOf(id)
        if (i !== S.order.length - 1) return renderAll()
        const prev = i > 0 ? S.msgs.get(S.order[i - 1]) : null
        const d = dateOf(m.at)
        let html = ''
        if (d && dayKey(d) !== dayKey(dateOf(prev?.at))) html += `<span class="dsep">${esc(dayLabel(d))}</span>`
        // ⓘ a call drawn last breaks the run
        html += msgHtml(m, !!el.msgs.lastElementChild?.dataset.call || !sameRun(prev, m))
        // nosemgrep: javascript.browser.security.insecure-document-method.insecure-document-method, react-unsanitized-method -- every value is esc()-ed; tick()/ic() take fixed names (Semgrep sweep 28 Nov 2026, read line by line)
        el.msgs.insertAdjacentHTML('beforeend', html)
        return undefined
    }
    function redrawMsg(id) {
        const node = byMid(id)
        const m = S.msgs.get(String(id))
        if (!node || !m) return
        const first = node.classList.contains('tl')
        node.outerHTML = msgHtml(m, first)
    }
    const nearBottom = () => el.scroll.scrollHeight - el.scroll.scrollTop - el.scroll.clientHeight < 120
    function toBottom() {
        el.scroll.scrollTop = el.scroll.scrollHeight
    }

    /* ── older pages, when scrolled to the top ── */
    el.scroll.addEventListener('scroll', () => {
        if (el.scroll.scrollTop < 80 && S.hasMore && !S.loadingOlder) loadOlder()
    })
    async function loadOlder() {
        if (!S.cur || !S.firstId) return false
        const seq = S.openSeq
        S.loadingOlder = true
        $('[data-more]', el.msgs)?.removeAttribute('hidden')
        try {
            const d = await api('GET', withQuery(url('messages', S.cur.id), { before: S.firstId }))
            if (seq !== S.openSeq) return false
            const before = el.scroll.scrollHeight
            const top = el.scroll.scrollTop
            for (const m of d.data || []) putMsg(m)
            S.hasMore = !!d.meta?.has_more && (d.data || []).length > 0
            await calls?.older()
            if (seq !== S.openSeq) return false
            S.loadingOlder = false
            renderAll()
            el.scroll.scrollTop = top + (el.scroll.scrollHeight - before)
            return true
        } catch {
            S.loadingOlder = false
            $('[data-more]', el.msgs)?.setAttribute('hidden', '')
            return false
        }
    }

    /* ════════════════ realtime: the poll ════════════════ */
    function stopPoll() {
        clearTimeout(S.pollTimer)
        S.pollTimer = 0
    }
    function schedulePoll() {
        stopPoll()
        if (!S.cur) return
        const quiet = Date.now() - S.quietSince > 30000
        S.pollTimer = setTimeout(poll, quiet ? 5000 : 3000)
    }
    async function poll() {
        if (!S.cur) return
        stopPoll()
        if (document.hidden) return schedulePoll()
        const seq = S.openSeq
        try {
            const d = await api('GET', withQuery(url('messages', S.cur.id), { after: S.lastId || 0 }))
            if (seq !== S.openSeq) return
            const stick = nearBottom()
            let incoming = false
            for (const m of d.data || []) {
                if (putMsg(m)) {
                    appendMsg(m)
                    if (!m.mine) incoming = true
                } else if (S.menuFor !== String(m.id)) {
                    redrawMsg(m.id)
                }
            }
            applyMeta(d.meta)
            if ((d.data || []).length) {
                S.quietSince = Date.now()
                if (stick || !incoming) toBottom()
                if (incoming) {
                    markRead()
                    loadList()
                }
            }
        } catch {
            /* the next tick tries again */
        }
        if (seq === S.openSeq) schedulePoll()
        return undefined
    }
    function applyMeta(meta) {
        if (!meta) return
        const typing = Array.isArray(meta.typing) ? meta.typing : []
        const changed = typing.map((p) => p.id).join() !== S.typing.map((p) => p.id).join() || !!meta.peer_online !== S.peerOnline
        S.typing = typing
        S.peerOnline = !!meta.peer_online
        if (changed) renderHead()
        for (const st of meta.states || []) {
            const m = S.msgs.get(String(st.id))
            if (!m) continue
            if (st.state === 'deleted') {
                if (m.kind !== 'deleted') {
                    Object.assign(m, { kind: 'deleted', text: '', attachment: null, paper: null, reply_to: null, lat: null, lng: null })
                    if (S.menuFor !== String(m.id)) redrawMsg(m.id)
                }
                continue
            }
            const rc = st.read_count ?? m.read_count
            if (m.mine && (m.state !== st.state || Number(m.read_count) !== Number(rc))) {
                m.state = st.state
                m.read_count = rc
                if (S.menuFor !== String(m.id)) redrawMsg(m.id)
            }
        }
    }
    function markRead() {
        if (!S.cur || document.hidden || !S.lastId || S.lastId <= S.readUpTo) return
        S.readUpTo = S.lastId
        const t = S.threads.find((x) => x.id === S.cur.id)
        if (t && t.unread) {
            S.unreadTotal = Math.max(0, S.unreadTotal - t.unread)
            t.unread = 0
            renderList()
        }
        api('POST', url('read', S.cur.id), { up_to: S.lastId }).catch(() => {})
    }
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return
        loadList()
        if (S.cur) {
            markRead()
            poll()
        }
    })

    /* ════════════════ the composer ════════════════ */
    function autosize() {
        el.input.style.height = 'auto'
        el.input.style.height = Math.min(140, el.input.scrollHeight) + 'px'
    }
    function updateSend() {
        el.send.disabled = !(REC.on || el.input.value.trim() || S.staged.length)
    }
    el.input.addEventListener('input', () => {
        autosize()
        updateSend()
        if (S.cur && el.input.value.trim() && Date.now() - S.lastTypingSent > 3000) {
            S.lastTypingSent = Date.now()
            api('POST', url('typing', S.cur.id)).catch(() => {})
        }
    })
    el.input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault()
            if (!el.send.disabled) send()
        }
    })
    el.send.addEventListener('click', () => send())

    const newClientId = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : 'w' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12))

    function replyRef() {
        const r = S.reply
        if (!r) return null
        return { id: String(r.id), kind: r.kind, text: r.kind === 'paper' ? r.paper?.no || '' : r.text || r.attachment?.name || '', sender: r.sender, mine: r.mine }
    }

    function send() {
        if (REC.on) return stopRecording(true)
        if (!S.cur) return undefined
        if (DICT.rec) stopDictation()
        const text = el.input.value.trim()
        const files = S.staged.slice()
        if (!text && !files.length) return undefined
        const reply = replyRef()
        el.input.value = ''
        autosize()
        clearStage(false)
        clearReply()
        closeEmoji()
        updateSend()
        if (files.length) files.forEach((f, i) => sendOne({ kind: f.kind, file: f.file, local: f.url, text: i === 0 ? text : '', reply: i === 0 ? reply : null }))
        else sendOne({ kind: 'text', text, reply })
        return undefined
    }

    function sendOne(job) {
        if (!S.cur) return
        job.cid = job.cid || newClientId()
        const tmpId = 'c:' + job.cid
        const existing = S.msgs.get(tmpId)
        if (existing) {
            Object.assign(existing, { failed: false, state: 'pending' })
            redrawMsg(tmpId)
        } else {
            const pending = {
                id: tmpId,
                client_id: job.cid,
                kind: job.kind,
                at: new Date().toISOString(),
                mine: true,
                sender: null,
                text: job.text || '',
                state: 'pending',
                read_count: 0,
                forwarded: false,
                attachment: job.file ? { name: job.file.name, size: job.file.size, mime: job.file.type, local: job.local, duration_ms: job.duration_ms || 0, waveform: job.waveform || [] } : null,
                paper: null,
                reply_to: job.reply,
            }
            putMsg(pending)
            appendMsg(pending)
        }
        toBottom()
        S.quietSince = Date.now()
        const seq = S.openSeq
        let body
        if (job.file) {
            body = new FormData()
            body.append('kind', job.kind)
            body.append('file', job.file, job.file.name)
            body.append('client_id', job.cid)
            if (job.text) body.append('text', job.text)
            if (job.reply) body.append('reply_to_id', job.reply.id)
            if (job.duration_ms) body.append('duration_ms', String(Math.round(job.duration_ms)))
            if (job.waveform?.length) body.append('waveform', job.waveform.map((v) => v.toFixed(2)).join(','))
        } else {
            body = { kind: 'text', text: job.text, client_id: job.cid }
            if (job.reply) body.reply_to_id = Number(job.reply.id)
        }
        api('POST', url('send', S.cur.id), body)
            .then((d) => {
                if (seq !== S.openSeq || !d?.data) return
                const stick = nearBottom()
                putMsg(d.data)
                if (!byMid(d.data.id)) renderAll()
                if (stick) toBottom()
                if (job.local) URL.revokeObjectURL(job.local)
                loadList()
            })
            .catch((e) => {
                toast(e.message)
                if (seq !== S.openSeq) return
                const m = S.msgs.get(tmpId)
                if (!m) return
                m.failed = true
                m.retry = job
                redrawMsg(tmpId)
            })
    }

    /* ── staged files: attach → chips above the composer → send ── */
    function renderStage() {
        el.stage.hidden = !S.staged.length
        el.stage.innerHTML = S.staged
            .map((f, i) => `<span class="sf">${f.kind === 'image' ? `<img src="${esc(f.url)}" alt="">` : fileIcon(f.file.name)}<span>${esc(f.file.name)}</span><button type="button" class="hb" data-act="unstage" data-i="${i}" aria-label="${esc(w('remove'))}">${ic('x', '')}</button></span>`)
            .join('')
    }
    function stageFiles(list, kind) {
        for (const file of Array.from(list || [])) {
            const k = kind === 'image' && /^image\//.test(file.type) ? 'image' : 'file'
            S.staged.push({ kind: k, file, url: k === 'image' ? URL.createObjectURL(file) : '' })
        }
        renderStage()
        updateSend()
        el.input.focus()
    }
    function clearStage(revoke = true) {
        if (revoke) S.staged.forEach((f) => f.url && URL.revokeObjectURL(f.url))
        S.staged = []
        renderStage()
    }
    $$('[data-pick]').forEach((input) =>
        input.addEventListener('change', () => {
            stageFiles(input.files, input.dataset.pick === 'file' ? 'file' : 'image')
            input.value = ''
        }),
    )

    /* ── the reply bar ── */
    function setReply(m) {
        S.reply = m
        el.replyQuote.outerHTML = quoteHtml(replyRef(), 'div').replace('class="quote"', 'class="quote" data-reply-quote')
        el.replyQuote = $('[data-reply-quote]')
        el.replybar.hidden = false
        el.input.focus()
    }
    function clearReply() {
        S.reply = null
        el.replybar.hidden = true
    }

    /* ── the attach tray ── */
    function toggleTray(force) {
        const open = force ?? el.atray.hidden
        el.atray.hidden = !open
        const b = $('[data-act="attach"]')
        b.classList.toggle('on', open)
        b.setAttribute('aria-expanded', open ? 'true' : 'false')
    }

    /* ════════════════ the emoji picker ════════════════ */
    const splitEmoji = (s) => String(s).split(/\s+/).filter(Boolean)
    let emoCat = 'smileys'
    function recentEmoji() {
        const r = parse(store('msgr.emoji.recent'), [])
        return Array.isArray(r) ? r : []
    }
    function renderEmoji() {
        const q = el.emoQ.value.trim().toLowerCase()
        let list
        let label
        if (q) {
            const found = []
            for (const [words, chars] of Object.entries(EMOJI_WORDS)) {
                if (words.toLowerCase().split(/\s+/).some((x) => x.startsWith(q) || q.startsWith(x))) found.push(...splitEmoji(chars))
            }
            list = Array.from(new Set(found))
            label = list.length ? w('emoji_search') : w('no_results')
        } else {
            list = emoCat === 'recent' ? recentEmoji() : splitEmoji(EMOJI[emoCat] || '')
            label = w('emoji_cats.' + emoCat)
        }
        el.emoLabel.textContent = label
        el.emoGrid.innerHTML = list.map((e) => `<button type="button" data-emoji="${esc(e)}" aria-label="${esc(e)}">${esc(e)}</button>`).join('')
        $$('[data-cat]', el.emo).forEach((b) => b.classList.toggle('on', !q && b.dataset.cat === emoCat))
    }
    function openEmoji() {
        el.emo.hidden = false
        const b = $('[data-act="emoji"]')
        b.classList.add('emoon')
        b.setAttribute('aria-expanded', 'true')
        $('[data-emo-icon]').setAttribute('href', '#mg-x')
        renderEmoji()
    }
    function closeEmoji() {
        el.emo.hidden = true
        const b = $('[data-act="emoji"]')
        b.classList.remove('emoon')
        b.setAttribute('aria-expanded', 'false')
        $('[data-emo-icon]').setAttribute('href', '#mg-smile')
    }
    function insertAtCaret(text) {
        const i = el.input
        const s = i.selectionStart ?? i.value.length
        const e = i.selectionEnd ?? i.value.length
        i.value = i.value.slice(0, s) + text + i.value.slice(e)
        i.selectionStart = i.selectionEnd = s + text.length
        autosize()
        updateSend()
    }
    el.emo.addEventListener('click', (e) => {
        const cat = e.target.closest('[data-cat]')
        if (cat) {
            emoCat = cat.dataset.cat
            el.emoQ.value = ''
            renderEmoji()
            return
        }
        const em = e.target.closest('[data-emoji]')
        if (em) {
            insertAtCaret(em.dataset.emoji)
            const rec = [em.dataset.emoji, ...recentEmoji().filter((x) => x !== em.dataset.emoji)].slice(0, 32)
            store('msgr.emoji.recent', JSON.stringify(rec))
        }
    })
    el.emoQ.addEventListener('input', renderEmoji)

    /* ════════════════ "বলে লিখুন" — dictation (Web Speech API) ════════════════ */
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition
    const DICT = { rec: null, base: '', lang: store('msgr.dictate.lang') || (LOCALE.startsWith('bn') ? 'bn-BD' : 'en-US'), held: false, timer: 0 }
    el.dict.hidden = !SR
    function markDictLang() {
        $$('[data-dict-lang]').forEach((b) => {
            const on = b.dataset.dictLang === DICT.lang
            b.classList.toggle('on', on)
            b.setAttribute('aria-checked', on ? 'true' : 'false')
        })
    }
    markDictLang()
    function startDictation() {
        if (!SR || DICT.rec) return
        const rec = new SR()
        rec.lang = DICT.lang
        rec.interimResults = true
        rec.continuous = true
        DICT.base = el.input.value
        if (DICT.base && !/\s$/.test(DICT.base)) DICT.base += ' '
        rec.addEventListener('result', (e) => {
            let fin = ''
            let interim = ''
            for (let i = 0; i < e.results.length; i++) {
                const r = e.results[i]
                if (r.isFinal) fin += r[0].transcript
                else interim += r[0].transcript
            }
            el.input.value = DICT.base + fin + interim
            autosize()
            updateSend()
        })
        rec.addEventListener('error', (e) => {
            if (e.error === 'not-allowed' || e.error === 'service-not-allowed') toast(w('mic_denied'))
        })
        rec.addEventListener('end', () => {
            DICT.rec = null
            el.dict.classList.remove('live')
            el.dict.setAttribute('aria-label', w('dictate'))
            autosize()
            updateSend()
        })
        DICT.rec = rec
        try {
            rec.start()
            el.dict.classList.add('live')
            el.dict.setAttribute('aria-label', w('dictate_stop'))
        } catch {
            DICT.rec = null
        }
    }
    function stopDictation() {
        try {
            DICT.rec?.stop()
        } catch {
            DICT.rec = null
        }
    }
    // a long press (or right-click) opens the small language menu; a tap starts or stops listening
    el.dict.addEventListener('pointerdown', () => {
        DICT.held = false
        clearTimeout(DICT.timer)
        DICT.timer = setTimeout(() => {
            DICT.held = true
            el.dictMenu.hidden = false
        }, 550)
    })
    for (const ev of ['pointerup', 'pointerleave', 'pointercancel']) el.dict.addEventListener(ev, () => clearTimeout(DICT.timer))
    el.dict.addEventListener('contextmenu', (e) => {
        e.preventDefault()
        DICT.held = true
        el.dictMenu.hidden = false
    })
    el.dict.addEventListener('click', () => {
        if (DICT.held) {
            DICT.held = false
            return
        }
        if (DICT.rec) stopDictation()
        else startDictation()
    })
    el.dictMenu.addEventListener('click', (e) => {
        const b = e.target.closest('[data-dict-lang]')
        if (!b) return
        DICT.lang = b.dataset.dictLang
        store('msgr.dictate.lang', DICT.lang)
        markDictLang()
        el.dictMenu.hidden = true
        if (DICT.rec) {
            stopDictation()
            setTimeout(startDictation, 300)
        }
    })

    /* ════════════════ the voice message (MediaRecorder) ════════════════ */
    const REC = { on: false, mr: null, stream: null, chunks: [], started: 0, levels: [], timer: 0, ctx: null, mime: '' }
    // ⭐ m4a first (the server's voice road); a browser that only records webm/ogg still sends what it has
    const VOICE_TYPES = ['audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/aac', 'audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus']
    async function startRecording() {
        if (REC.on || !S.cur) return undefined
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) return toast(w('voice_unsupported'))
        if (DICT.rec) stopDictation()
        let stream
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true })
        } catch {
            return toast(w('mic_denied'))
        }
        const mime = VOICE_TYPES.find((t) => window.MediaRecorder.isTypeSupported?.(t)) || ''
        let mr
        try {
            mr = new window.MediaRecorder(stream, mime ? { mimeType: mime } : undefined)
        } catch {
            stream.getTracks().forEach((t) => t.stop())
            return toast(w('voice_unsupported'))
        }
        Object.assign(REC, { on: true, mr, stream, chunks: [], started: Date.now(), levels: [], mime })
        mr.addEventListener('dataavailable', (e) => {
            if (e.data && e.data.size) REC.chunks.push(e.data)
        })
        let analyser = null
        let buf = null
        try {
            const AC = window.AudioContext || window.webkitAudioContext
            REC.ctx = new AC()
            analyser = REC.ctx.createAnalyser()
            analyser.fftSize = 512
            REC.ctx.createMediaStreamSource(stream).connect(analyser)
            buf = new Uint8Array(analyser.fftSize)
        } catch {
            analyser = null
        }
        REC.timer = setInterval(() => {
            const ms = Date.now() - REC.started
            el.recTime.textContent = dur(ms)
            if (analyser) {
                analyser.getByteTimeDomainData(buf)
                let sum = 0
                for (const v of buf) sum += ((v - 128) / 128) ** 2
                const rms = Math.sqrt(sum / buf.length)
                REC.levels.push(rms)
                el.recLevel.style.setProperty('--lvl', Math.min(100, Math.round(rms * 300)) + '%')
            }
            if (ms > 15 * 60 * 1000) stopRecording(true)
        }, 100)
        mr.start(250)
        el.input.hidden = true
        el.dict.hidden = true
        el.rec.hidden = false
        el.recTime.textContent = '0:00'
        el.voiceBtn.classList.add('recon')
        el.voiceBtn.setAttribute('aria-label', w('voice_stop_send'))
        updateSend()
        return undefined
    }
    function waveOf(levels, n = 48) {
        if (!levels.length) return []
        const out = []
        for (let i = 0; i < n; i++) {
            const a = Math.floor((i * levels.length) / n)
            const b = Math.max(a + 1, Math.floor(((i + 1) * levels.length) / n))
            const slice = levels.slice(a, b)
            out.push(slice.reduce((x, y) => x + y, 0) / slice.length)
        }
        const max = Math.max(...out, 0.0001)
        return out.map((v) => Math.max(0.05, Math.min(1, v / max)))
    }
    function stopRecording(sendIt) {
        if (!REC.on) return
        const ms = Date.now() - REC.started
        const mr = REC.mr
        const levels = REC.levels.slice()
        clearInterval(REC.timer)
        mr.addEventListener(
            'stop',
            () => {
                REC.stream?.getTracks().forEach((t) => t.stop())
                try {
                    REC.ctx?.close()
                } catch {
                    /* already closed */
                }
                const type = (mr.mimeType || REC.mime || 'audio/webm').split(';')[0]
                const chunks = REC.chunks
                Object.assign(REC, { on: false, mr: null, stream: null, chunks: [], ctx: null })
                el.rec.hidden = true
                el.input.hidden = false
                el.dict.hidden = !SR
                el.voiceBtn.classList.remove('recon')
                el.voiceBtn.setAttribute('aria-label', w('voice_message'))
                updateSend()
                if (!sendIt || ms < 700 || !chunks.length || !S.cur) return
                const ext = /mp4|m4a/.test(type) ? 'm4a' : /aac/.test(type) ? 'aac' : /ogg/.test(type) ? 'ogg' : 'webm'
                const file = new File([new Blob(chunks, { type })], 'voice-' + Date.now() + '.' + ext, { type })
                sendOne({ kind: 'voice', file, text: '', reply: replyRef(), duration_ms: ms, waveform: waveOf(levels) })
                clearReply()
            },
            { once: true },
        )
        try {
            mr.stop()
        } catch {
            /* already stopped */
        }
    }

    /* ── the voice player ── */
    function stopAllAudio() {
        for (const st of S.audio.values()) st.el.pause()
        S.audio.clear()
    }
    function patchVoice(id) {
        const node = byMid(id)
        const m = S.msgs.get(String(id))
        const st = S.audio.get(String(id))
        const v = node?.querySelector('.voice')
        if (!v || !m || !st) return
        const bs = v.querySelectorAll('.wf i')
        bs.forEach((b, i) => b.classList.toggle('pd', (i + 0.5) / bs.length <= st.frac))
        v.querySelector('.knob').style.left = (st.frac * 100).toFixed(1) + '%'
        const pl = v.querySelector('.pl')
        pl.innerHTML = ic(st.playing ? 'pause' : 'play', '')
        pl.setAttribute('aria-label', st.playing ? w('pause') : w('play'))
        const d = node.querySelector('.vfoot .dur')
        if (d) d.textContent = st.playing || st.frac > 0 ? dur(st.pos * 1000) : dur(m.attachment?.duration_ms)
    }
    function audioFor(m) {
        const id = String(m.id)
        let st = S.audio.get(id)
        if (st) return st
        const a = new Audio(m.attachment?.web_url || m.attachment?.local || '')
        a.preload = 'metadata'
        st = { el: a, frac: 0, pos: 0, playing: false }
        st.total = () => (Number.isFinite(a.duration) && a.duration > 0 ? a.duration : (m.attachment?.duration_ms || 0) / 1000 || 1)
        a.addEventListener('timeupdate', () => {
            st.pos = a.currentTime
            st.frac = Math.min(1, a.currentTime / st.total())
            patchVoice(id)
        })
        a.addEventListener('play', () => {
            st.playing = true
            patchVoice(id)
        })
        a.addEventListener('pause', () => {
            st.playing = false
            patchVoice(id)
        })
        a.addEventListener('ended', () => {
            Object.assign(st, { playing: false, frac: 0, pos: 0 })
            patchVoice(id)
        })
        S.audio.set(id, st)
        return st
    }

    /* ════════════════ the ⌄ message menu (sample v7) ════════════════ */
    function closeMenus() {
        $$('.bm', el.msgs).forEach((x) => x.remove())
        $$('.bub.hov', el.msgs).forEach((x) => x.classList.remove('hov'))
        const was = S.menuFor
        S.menuFor = null
        if (was) redrawMsg(was)
    }
    function openMenu(bub, m) {
        closeMenus()
        bub = byMid(m.id)?.querySelector('.bub') || bub
        S.menuFor = String(m.id)
        bub.classList.add('hov')
        const live = m.kind !== 'deleted'
        const young = Date.now() - (dateOf(m.at)?.getTime() || 0) < 3600 * 1000
        // ⛔ "সবার কাছ থেকে" only on my own message within an hour (the server refuses it otherwise too)
        const canAll = m.mine && live && young
        let html = '<div class="bm" role="menu">'
        if (live) {
            html += `<button type="button" role="menuitem" data-act="m-reply">${ic('reply')}${esc(w('reply'))}</button>`
            html += `<button type="button" role="menuitem" data-act="m-forward">${ic('fwd')}${esc(w('forward'))}</button>`
            if (m.text || m.paper?.no) html += `<button type="button" role="menuitem" data-act="m-copy">${ic('copy')}${esc(w('copy'))}</button>`
        }
        html += `<div class="delrow"><button type="button" role="menuitem" aria-haspopup="menu" data-act="m-delete">${ic('trash')}${esc(w('delete'))}<i class="ar"></i></button><div class="sub" data-del-sub role="menu" hidden><button type="button" role="menuitem" data-act="m-del-me">${esc(w('delete_me'))}</button>${canAll ? `<button type="button" role="menuitem" class="dl2" data-act="m-del-all">${esc(w('delete_all'))}</button>` : ''}</div></div>`
        if (live) {
            const line = m.mine ? `<small>${esc(w('loading'))}</small>` : `<small>${esc(w('arrived_at', { time: hm(dateOf(m.at)) }))}</small>`
            html += `<div class="inf" data-info-lines><span>${ic('info')}${esc(w('info'))}</span>${line}</div>`
        }
        html += '</div>'
        // nosemgrep: javascript.browser.security.insecure-document-method.insecure-document-method, react-unsanitized-method -- every value is esc()-ed; tick()/ic() take fixed names (Semgrep sweep 28 Nov 2026, read line by line)
        bub.querySelector('.chev').insertAdjacentHTML('afterend', html)
        const menu = bub.querySelector('.bm')
        const row = menu.querySelector('.delrow')
        const sub = menu.querySelector('[data-del-sub]')
        row.addEventListener('mouseenter', () => {
            sub.hidden = false
            row.classList.add('on')
        })
        row.addEventListener('mouseleave', () => {
            sub.hidden = true
            row.classList.remove('on')
        })
        const box = el.scroll.getBoundingClientRect()
        if (bub.getBoundingClientRect().top + 24 + menu.offsetHeight > box.bottom - 8) menu.classList.add('up')
        if (m.mine && live) loadInfo(m, menu)
        menu.querySelector('button')?.focus()
    }
    async function loadInfo(m, menu) {
        const box = menu.querySelector('[data-info-lines]')
        try {
            const d = await api('GET', msgUrl('info', m.id))
            if (!box || !menu.isConnected) return
            const group = S.cur?.kind === 'group'
            let lines = ''
            for (const r of d.data || []) {
                const who = group ? esc(r.person?.name || '') + ' · ' : ''
                const dAt = dateOf(r.delivered_at)
                const rAt = dateOf(r.read_at)
                if (!group) {
                    lines += `<small>${tick('delivered', '')}${esc(dAt ? w('delivered_at', { time: hm(dAt) }) : w('not_delivered'))}</small>`
                    if (rAt) lines += `<small>${tick('read', '')}${esc(w('read_at', { time: hm(rAt) }))}</small>`
                } else if (rAt) {
                    lines += `<small>${tick('read', '')}${who}${esc(w('read_at', { time: hm(rAt) }))}</small>`
                } else if (dAt) {
                    lines += `<small>${tick('delivered', '')}${who}${esc(w('delivered_at', { time: hm(dAt) }))}</small>`
                } else {
                    lines += `<small>${tick('sent', '')}${who}${esc(w('not_delivered'))}</small>`
                }
            }
            box.querySelectorAll('small').forEach((s) => s.remove())
            // nosemgrep: javascript.browser.security.insecure-document-method.insecure-document-method, react-unsanitized-method -- every value is esc()-ed; tick()/ic() take fixed names (Semgrep sweep 28 Nov 2026, read line by line)
            box.insertAdjacentHTML('beforeend', lines)
        } catch {
            box?.querySelector('small')?.remove()
        }
    }
    async function deleteMsg(m, forWhom) {
        closeMenus()
        try {
            await api('DELETE', withQuery(msgUrl('delete', m.id), { for: forWhom }))
            if (forWhom === 'me') {
                S.msgs.delete(String(m.id))
                S.order = S.order.filter((x) => x !== String(m.id))
                const top = el.scroll.scrollTop
                renderAll()
                el.scroll.scrollTop = top
            } else {
                Object.assign(m, { kind: 'deleted', text: '', attachment: null, paper: null, reply_to: null, lat: null, lng: null })
                redrawMsg(m.id)
            }
            loadList()
        } catch (e) {
            toast(e.message)
        }
    }
    async function copyMsg(m) {
        try {
            await navigator.clipboard.writeText(m.text || m.paper?.no || '')
            toast(w('copied'))
        } catch {
            toast(w('error'))
        }
    }
    async function jumpTo(id) {
        let node = byMid(id)
        for (let i = 0; !node && S.hasMore && i < 10; i++) {
            if (!(await loadOlder())) break
            node = byMid(id)
        }
        if (!node) return
        node.scrollIntoView({ block: 'center', behavior: 'smooth' })
        node.classList.remove('flash')
        void node.offsetWidth
        node.classList.add('flash')
    }

    /* ── the paper card's "খুলুন": opens only with the right to open; otherwise a short toast, no page ── */
    function openPaper(p) {
        if (p && p.can_open && p.web_url) window.open(p.web_url, '_blank', 'noopener')
        else toast(w('paper_no_access'))
    }

    /* ════════════════ dialogs: forward, new message ════════════════ */
    function closeDialog() {
        el.dialogHost.innerHTML = ''
    }
    function dialog({ title, multi, withThreads, onPick }) {
        const picked = new Set()
        const ph = multi ? w('forward_search') : w('people_search')
        el.dialogHost.innerHTML = `<div class="scrim" data-act="dlg-x"></div><div class="dlg" role="dialog" aria-modal="true" aria-label="${esc(title)}"><div class="tr"><h4>${esc(title)}</h4><button type="button" class="hb" data-act="dlg-x" aria-label="${esc(w('close'))}">${ic('x', '')}</button></div><label class="msrch">${ic('search', '')}<input type="search" data-dlg-q autocomplete="off" placeholder="${esc(ph)}" aria-label="${esc(ph)}"></label><div class="plist" data-dlg-list></div>${multi ? `<div class="fb"><button type="button" class="cn" data-act="dlg-x">${esc(w('cancel'))}</button><button type="button" class="go" data-dlg-go disabled>${ic('send', '')}<span data-go-label>${esc(w('send_to', { count: 0 }))}</span></button></div>` : ''}</div>`
        const box = $('.dlg', el.dialogHost)
        const list = $('[data-dlg-list]', box)
        const q = $('[data-dlg-q]', box)
        const go = $('[data-dlg-go]', box)
        let seq = 0
        const check = (key) => (multi ? `<span class="ck${picked.has(key) ? ' on' : ''}">${picked.has(key) ? ic('check', '') : ''}</span>` : '')
        const row = (key, av, name, sub) => `<button type="button" class="prow" data-key="${esc(key)}">${av}<div class="tx"><b>${esc(name)}</b>${sub ? `<small>${esc(sub)}</small>` : ''}</div>${check(key)}</button>`
        async function draw() {
            const term = q.value.trim().toLowerCase()
            const my = ++seq
            let threadsHtml = ''
            const shownDirect = new Set()
            if (withThreads) {
                const ts = S.threads.filter((t) => !term || String(t.title).toLowerCase().includes(term)).slice(0, 12)
                ts.forEach((t) => t.kind === 'direct' && t.person && shownDirect.add(String(t.person.id)))
                threadsHtml = ts.map((t) => row('thread:' + t.id, threadAvatar(t, 42), t.title, t.subtitle)).join('')
                if (threadsHtml) threadsHtml = `<div class="lbl2">${esc(w('recent'))}</div>` + threadsHtml
            }
            list.innerHTML = threadsHtml + `<p class="none">${esc(w('loading'))}</p>`
            try {
                const d = await api('GET', withQuery(R.people, { q: q.value.trim() }))
                if (my !== seq) return
                const people = (d.data || []).filter((p) => String(p.id) !== String(ME.id) && !shownDirect.has(String(p.id)))
                let peopleHtml = people.map((p) => row('person:' + p.id, avatar(p, 42, !!p.online), p.name, [p.designation, p.branch].filter(Boolean).join(' · '))).join('')
                if (peopleHtml && withThreads) peopleHtml = `<div class="lbl2">${esc(w('people'))}</div>` + peopleHtml
                list.innerHTML = threadsHtml + peopleHtml || `<p class="none">${esc(w('no_results'))}</p>`
            } catch (e) {
                if (my === seq) list.innerHTML = threadsHtml || `<p class="none">${esc(e.message)}</p>`
            }
        }
        let timer = 0
        q.addEventListener('input', () => {
            clearTimeout(timer)
            timer = setTimeout(draw, 250)
        })
        list.addEventListener('click', (e) => {
            const r = e.target.closest('[data-key]')
            if (!r) return
            const key = r.dataset.key
            if (!multi) {
                onPick(key)
                return
            }
            if (picked.has(key)) picked.delete(key)
            else picked.add(key)
            const ck = r.querySelector('.ck')
            ck.classList.toggle('on', picked.has(key))
            ck.innerHTML = picked.has(key) ? ic('check', '') : ''
            go.disabled = picked.size === 0
            $('[data-go-label]', box).textContent = w('send_to', { count: picked.size })
        })
        go?.addEventListener('click', async () => {
            if (go.disabled) return
            go.disabled = true
            try {
                await onPick(Array.from(picked))
            } finally {
                if (go.isConnected) go.disabled = picked.size === 0
            }
        })
        draw()
        q.focus()
    }
    function forwardDialog(m) {
        dialog({
            title: w('forward_title'),
            multi: true,
            withThreads: true,
            onPick: async (to) => {
                try {
                    await api('POST', R.forward, { message_ids: [Number(m.id)], to, client_id: newClientId().slice(0, 40) })
                    closeDialog()
                    toast(w('forward_done'))
                    loadList()
                    if (S.cur && to.includes('thread:' + S.cur.id)) poll()
                } catch (e) {
                    toast(e.message)
                }
            },
        })
    }
    function newMessageDialog() {
        dialog({
            title: w('new_title'),
            multi: false,
            withThreads: false,
            onPick: async (key) => {
                try {
                    const d = await api('POST', R.direct, { person: Number(key.split(':')[1]) })
                    closeDialog()
                    await loadList()
                    if (d?.data && !S.threads.find((x) => x.id === d.data.id)) {
                        S.threads.unshift(d.data)
                        renderList()
                    }
                    if (d?.data) openThread(d.data.id)
                } catch (e) {
                    toast(e.message)
                }
            },
        })
    }

    /*
     * ── attach → "ERP কাগজ": find one of MY papers by its number and send it into this chat (sample v7, tab খ).
     * ⓘ The door lists only papers I may open myself; the card each receiver sees follows THEIR right (PaperCards).
     *   Empty search = my most recent papers. A text already typed in the composer goes along as the note.
     */
    function paperDialog() {
        if (!S.cur || !R.paper_find || !R.papers) return
        const t = S.cur
        el.dialogHost.innerHTML = `<div class="scrim" data-act="dlg-x"></div><div class="dlg" role="dialog" aria-modal="true" aria-label="${esc(w('paper_title'))}"><div class="tr"><h4>${esc(w('paper_title'))}</h4><button type="button" class="hb" data-act="dlg-x" aria-label="${esc(w('close'))}">${ic('x', '')}</button></div><label class="msrch">${ic('search', '')}<input type="search" data-dlg-q autocomplete="off" placeholder="${esc(w('paper_search'))}" aria-label="${esc(w('paper_search'))}"></label><div class="plist" data-dlg-list></div></div>`
        const box = $('.dlg', el.dialogHost)
        const list = $('[data-dlg-list]', box)
        const q = $('[data-dlg-q]', box)
        let found = []
        let seq = 0
        let busy = false
        async function draw() {
            const my = ++seq
            list.innerHTML = `<p class="none">${esc(w('loading'))}</p>`
            try {
                const d = await api('GET', withQuery(R.paper_find, { q: q.value.trim() }))
                if (my !== seq) return
                found = d?.data || []
                list.innerHTML = found
                    .map((p, i) => {
                        const money = paperIsMoney(p)
                        const sub = [p.amount, p.party].filter(Boolean).join(' · ')
                        return `<button type="button" class="shp" data-pick-paper="${i}"><span class="pic${money ? ' g' : ''}">${ic(money ? 'deposit' : 'paper', '')}</span><div class="tx"><b>${esc(p.title)} ${esc(p.no)}</b>${sub ? `<small>${esc(sub)}</small>` : ''}</div></button>`
                    })
                    .join('') || `<p class="none">${esc(w('no_results'))}</p>`
            } catch (e) {
                if (my === seq) list.innerHTML = `<p class="none">${esc(e.message)}</p>`
            }
        }
        let timer = 0
        q.addEventListener('input', () => {
            clearTimeout(timer)
            timer = setTimeout(draw, 300)
        })
        list.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-pick-paper]')
            const p = b ? found[Number(b.dataset.pickPaper)] : null
            if (!p || busy) return
            busy = true
            const note = el.input.value.trim()
            try {
                await api('POST', R.papers, { paper_type: p.type, paper_id: p.id, to: ['thread:' + t.id], note: note || null, client_id: newClientId().slice(0, 40) })
                closeDialog()
                if (note) {
                    el.input.value = ''
                    autosize()
                    updateSend()
                }
                toast(w('paper_sent'))
                if (S.cur?.id === t.id) poll()
                loadList()
            } catch (err) {
                toast(err.message)
            } finally {
                busy = false
            }
        })
        draw()
        q.focus()
    }

    /* ════════════════ the info drawer (members, shared papers; ⋮ search) ════════════════ */
    function closeInfo() {
        el.info.hidden = true
        S.infoMode = null
    }
    function sharedRow(m) {
        const a = m.attachment || {}
        const time = `<time>${esc(listTime(m.at))}</time>`
        if (m.kind === 'paper' && m.paper) {
            const p = m.paper
            const money = paperIsMoney(p)
            const sub = [p.title, p.can_open ? p.amount : ''].filter(Boolean).join(' · ')
            return `<button type="button" class="shp" data-act="shared-paper" data-shared="${esc(m.id)}"><span class="pic${money ? ' g' : ''}">${ic(money ? 'deposit' : 'paper', '')}</span><div class="tx"><b>${esc(p.no)}</b><small>${esc(sub)}</small></div>${time}</button>`
        }
        if (m.kind === 'image') return `<a class="shp" href="${esc(a.web_url)}" target="_blank" rel="noopener"><span class="pic im">${ic('image', '')}</span><div class="tx"><b>${esc(a.name || w('photo'))}</b><small>${esc(w('photo'))} · ${size(a.size)}</small></div>${time}</a>`
        if (m.kind === 'file') return `<a class="shp" href="${esc(a.web_url)}" download="${esc(a.name)}"><span class="pic f">${ic('doc', '')}</span><div class="tx"><b>${esc(a.name)}</b><small>${esc(extOf(a.name).toUpperCase())} · ${size(a.size)}</small></div>${time}</a>`
        return ''
    }
    function infoSearch(t) {
        el.infoBody.innerHTML = `<div class="misec"><label class="msrch misrch">${ic('search', '')}<input type="search" data-in-q autocomplete="off" placeholder="${esc(w('search_in_chat'))}" aria-label="${esc(w('search_in_chat'))}"></label><div data-in-res></div></div>`
        const q = $('[data-in-q]', el.infoBody)
        const res = $('[data-in-res]', el.infoBody)
        let timer = 0
        let seq = 0
        q.addEventListener('input', () => {
            clearTimeout(timer)
            timer = setTimeout(async () => {
                const term = q.value.trim()
                const my = ++seq
                if (!term) {
                    res.innerHTML = ''
                    return
                }
                try {
                    const d = await api('GET', withQuery(url('search', t.id), { q: term }))
                    if (my !== seq) return
                    const rows = (d.data || []).map((m) => {
                        const who = m.mine ? w('you') : m.sender?.name || ''
                        const [icon, text] = previewOf(m.kind, m.text, m.paper?.no)
                        return `<button type="button" class="shp" data-act="jump" data-to="${esc(m.id)}"><div class="tx"><b>${esc(who)}</b><small>${icon}${esc(text)}</small></div><time>${esc(listTime(m.at))}</time></button>`
                    })
                    res.innerHTML = rows.join('') || `<p class="none">${esc(w('no_results'))}</p>`
                } catch (e) {
                    if (my === seq) res.innerHTML = `<p class="none">${esc(e.message)}</p>`
                }
            }, 300)
        })
        q.focus()
    }
    async function openInfo(mode) {
        if (!S.cur) return
        const t = S.cur
        const group = t.kind === 'group'
        S.infoMode = mode
        el.info.hidden = false
        el.infoTitle.textContent = mode === 'search' ? w('search_in_chat') : group ? w('group_info') : w('contact_info')
        if (mode === 'search') {
            infoSearch(t)
            return
        }
        const top = `<div class="mitop">${threadAvatar(t, 96, false)}<b>${esc(t.title)}</b><small>${esc(group ? w('group_people', { count: t.member_count || S.members.length }) : t.subtitle || '')}</small></div>`
        let members = ''
        if (group) {
            const meFirst = S.members.slice().sort((a, b) => (String(b.id) === String(ME.id)) - (String(a.id) === String(ME.id)))
            members = `<div class="misec" data-sec-members><h4>${esc(w('members'))}<span class="n">${meFirst.length}</span></h4>${meFirst
                .map((p) => {
                    const me = String(p.id) === String(ME.id)
                    const sub = [p.designation, p.branch].filter(Boolean).join(' · ')
                    return `<div class="mem">${avatar(p, 38, !me && !!p.online)}<div class="tx"><b>${esc(me ? w('you') : p.name)}</b><small>${esc(sub)}</small></div>${p.admin ? `<em>${esc(w('group_admin'))}</em>` : ''}</div>`
                })
                .join('')}</div>`
        }
        el.infoBody.innerHTML = top + members + `<div class="misec" data-sec-shared><h4>${esc(w('shared'))}<span class="n" data-shared-count></span></h4><div data-shared><p class="none">${esc(w('loading'))}</p></div></div>`
        if (mode === 'members') $('[data-sec-members]', el.infoBody)?.scrollIntoView({ block: 'start' })
        try {
            const d = await api('GET', url('media', t.id))
            if (S.cur?.id !== t.id || S.infoMode !== mode) return
            S.shared = (d.data || []).filter((m) => ['paper', 'file', 'image'].includes(m.kind))
            $('[data-shared]', el.infoBody).innerHTML = S.shared.map(sharedRow).join('') || `<p class="none">${esc(w('none_shared'))}</p>`
            $('[data-shared-count]', el.infoBody).textContent = S.shared.length ? String(S.shared.length) : ''
            if (mode === 'media') $('[data-sec-shared]', el.infoBody)?.scrollIntoView({ block: 'start' })
        } catch (e) {
            const box = $('[data-shared]', el.infoBody)
            if (box) box.innerHTML = `<p class="none">${esc(e.message)}</p>`
        }
    }

    /* ── mute ── */
    async function toggleMute() {
        if (!S.cur) return
        const muted = !S.cur.muted
        try {
            const d = await api('POST', url('mute', S.cur.id), { muted })
            S.cur = { ...S.cur, ...(d?.data || {}), muted }
            renderHead()
            toast(muted ? w('muted_on') : w('muted_off'))
            loadList()
        } catch (e) {
            toast(e.message)
        }
    }

    /* ── calls: only announce; the call screen (another part) listens on document/window ── */
    function call(video) {
        if (!S.cur) return
        // ⭐ a group call is a meeting hung on this thread (messenger-meeting.js listens)
        if (S.cur.kind === 'group' && root.dataset.meetings === '1') {
            window.dispatchEvent(new CustomEvent('messenger:meeting', { detail: { thread: String(S.cur.id), video: !!video } }))
            return
        }
        if (S.cur.kind === 'group' || !S.cur.person) {
            toast(w('group_calls_soon'))
            return
        }
        root.dispatchEvent(new CustomEvent('messenger:call', { bubbles: true, composed: true, detail: { person_id: String(S.cur.person.id), video: !!video } }))
    }

    function toggleKebab(force) {
        const open = force ?? el.kebab.hidden
        el.kebab.hidden = !open
        const b = $('[data-act="kebab"]')
        b.classList.toggle('on', open)
        b.setAttribute('aria-expanded', open ? 'true' : 'false')
    }

    /* ════════════════ one click handler for the page ════════════════ */
    root.addEventListener('click', (e) => {
        const actEl = e.target.closest('[data-act]')
        const act = actEl?.dataset.act
        // a click outside a floating menu closes it
        if (!e.target.closest('[data-kebab]') && act !== 'kebab') toggleKebab(false)
        if (!e.target.closest('[data-atray]') && act !== 'attach') toggleTray(false)
        if (!e.target.closest('[data-dict-menu]') && act !== 'dictate') el.dictMenu.hidden = true
        if (S.menuFor && !e.target.closest('.bm') && act !== 'menu') closeMenus()
        if (!act) {
            const failed = e.target.closest('.bub.fail')
            const fm = failed ? S.msgs.get(failed.closest('[data-mid]')?.dataset.mid) : null
            if (fm?.retry) sendOne(fm.retry)
            return
        }
        const mid = actEl.closest('[data-mid]')?.dataset.mid
        const m = mid ? S.msgs.get(mid) : null
        switch (act) {
            case 'new':
                newMessageDialog()
                break
            case 'back':
                closeChat()
                break
            case 'info':
                if (el.info.hidden || S.infoMode === 'search') openInfo('about')
                else closeInfo()
                break
            case 'info-x':
                closeInfo()
                break
            case 'call-video':
                call(true)
                break
            case 'call-voice':
                call(false)
                break
            case 'kebab':
                toggleKebab()
                break
            case 'k-search':
                toggleKebab(false)
                openInfo('search')
                break
            case 'k-media':
                toggleKebab(false)
                openInfo('media')
                break
            case 'k-mute':
                toggleKebab(false)
                toggleMute()
                break
            case 'k-members':
                toggleKebab(false)
                openInfo('members')
                break
            case 'emoji':
                if (el.emo.hidden) openEmoji()
                else closeEmoji()
                break
            case 'attach':
                toggleTray()
                break
            case 'a-file':
            case 'a-image':
            case 'a-camera':
                toggleTray(false)
                $(`[data-pick="${act.slice(2)}"]`)?.click()
                break
            case 'a-paper':
                toggleTray(false)
                paperDialog()
                break
            case 'unstage': {
                const f = S.staged.splice(Number(actEl.dataset.i), 1)[0]
                if (f?.url) URL.revokeObjectURL(f.url)
                renderStage()
                updateSend()
                break
            }
            case 'reply-x':
                clearReply()
                break
            case 'voice':
                if (REC.on) stopRecording(true)
                else startRecording()
                break
            case 'rec-cancel':
                stopRecording(false)
                break
            case 'menu':
                if (!m) break
                if (S.menuFor === mid) closeMenus()
                else openMenu(actEl.closest('.bub'), m)
                break
            case 'm-reply':
                closeMenus()
                setReply(m)
                break
            case 'm-forward':
                closeMenus()
                forwardDialog(m)
                break
            case 'm-copy':
                closeMenus()
                copyMsg(m)
                break
            case 'm-delete': {
                const row = actEl.closest('.delrow')
                const sub = row.querySelector('[data-del-sub]')
                sub.hidden = !sub.hidden
                row.classList.toggle('on', !sub.hidden)
                break
            }
            case 'm-del-me':
                deleteMsg(m, 'me')
                break
            case 'm-del-all':
                deleteMsg(m, 'everyone')
                break
            case 'jump':
                e.preventDefault()
                jumpTo(actEl.dataset.to)
                break
            case 'paper-open':
                openPaper(m?.paper)
                break
            case 'shared-paper':
                openPaper(S.shared.find((x) => String(x.id) === actEl.dataset.shared)?.paper)
                break
            case 'v-play': {
                if (!m) break
                const st = audioFor(m)
                for (const [k, o] of S.audio) if (k !== String(m.id)) o.el.pause()
                if (st.el.paused) st.el.play().catch(() => toast(w('error')))
                else st.el.pause()
                break
            }
            case 'v-seek': {
                if (!m) break
                const st = audioFor(m)
                const r = actEl.getBoundingClientRect()
                const frac = Math.max(0, Math.min(1, (e.clientX - r.left) / r.width))
                const total = st.total()
                st.el.currentTime = frac * total
                Object.assign(st, { frac, pos: frac * total })
                patchVoice(m.id)
                break
            }
            case 'new-meeting':
                window.dispatchEvent(new CustomEvent('messenger:meeting', { detail: {} }))
                break
            case 'dlg-x':
                closeDialog()
                break
            default:
                calls?.act(act, actEl)
                break
        }
    })
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return
        if (el.dialogHost.firstChild) closeDialog()
        else if (S.menuFor) closeMenus()
        else if (!el.kebab.hidden) toggleKebab(false)
        else if (!el.atray.hidden) toggleTray(false)
        else if (!el.dictMenu.hidden) el.dictMenu.hidden = true
        else if (!el.emo.hidden) closeEmoji()
        else if (!el.info.hidden) closeInfo()
        else if (S.reply) clearReply()
    })
    // a click anywhere else on the page closes the messenger's floating menus too
    document.addEventListener('click', (e) => {
        if (root.contains(e.target)) return
        toggleKebab(false)
        toggleTray(false)
        el.dictMenu.hidden = true
        if (S.menuFor) closeMenus()
    })

    calls = callHistory({
        root, $, $$, S, R, w, ic, esc, api, withQuery, avatar, dateOf, hm, dialog, closeDialog, toast, renderAll, renderList, loadList, toBottom, store,
        atBottom: nearBottom,
        leaveChat: closeChat,
    })

    /* ── start ── */
    showChat(false)
    updateSend()
    loadList().then(() => {
        if (root.dataset.open) openThread(root.dataset.open)
        calls.refresh(true)
    })
    scheduleList()
}

// ⭐ the page itself — once, after every constant above exists
start(document.querySelector('[data-msgr]'))
