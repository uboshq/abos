/*
 * ⭐ Calls inside the messenger page — owner's sample VxwPoxm77whS6ToNqsyZPU, tabs ১ (the call list, a person's totals and log) and
 * ২ (calls as entries inside the chat). 24 Nov 2026.
 *
 * ⓘ Nothing is written: the entries come from the call log itself (`/messages/x/calls`), so every old call is there, and the phone's
 *   messages stay as they are. ⓘ Words come from the page's `data-words` (`calls.*`); digits are English.
 */

const PAGE = 50
const pad2 = (n) => String(n).padStart(2, '0')
const SEEN = 'msgr.calls.seen'

/** what a call came to: talked | missed | unanswered | declined | open */
export function resultOf(call) {
    if (call.status === 'ringing' || call.status === 'answered') return 'open'
    if (call.answered_at) return 'talked'
    if (call.direction === 'in') return call.status === 'missed' ? 'missed' : 'declined'
    return 'unanswered'
}

/** 374 → { m: 6, s: 14 } */
export function minutes(seconds) {
    const n = Math.max(0, Math.round(Number(seconds) || 0))
    return { m: Math.floor(n / 60), s: n % 60 }
}

/** the calls that stand before `at` in the chat, oldest first; taken off the front of `queue` */
export function takeBefore(queue, at) {
    const out = []
    while (queue.length && Date.parse(queue[0].at) <= at) out.push(queue.shift())
    return out
}

/**
 * @param {object} x the page's own tools — root, $, $$, S, R, w, ic, esc, api, withQuery, avatar, dateOf, hm, dialog, closeDialog,
 *   toast, renderAll, renderList, loadList, showChat, store, atBottom, toBottom
 */
export function callHistory(x) {
    const { root, $, $$, S, R, w, ic, esc, api, withQuery, avatar, dateOf, hm } = x
    const ready = !!(R.calls && R.call_people)
    const canCall = root.dataset.calls === '1'
    const el = {
        rows: $('[data-rows]'),
        msgChips: $('[data-msg-chips]'),
        callChips: $('[data-call-chips]'),
        newMsg: $('[data-act="new"]'),
        newCall: $('[data-act="new-call"]'),
        q: $('[data-list-q]'),
        pane: $('[data-call-pane]'),
        badge: $('[data-calls-new]'),
        missed: $('[data-calls-missed]'),
    }
    const C = { on: false, kind: 'all', q: '', rows: [], next: null, seq: 0, busy: false, people: new Map(), person: null, log: [], logNext: null, logSeq: 0, chat: [], chatNext: null, chatOf: null, at: 0 }

    // ── words ────────────────────────────────────────────────────────────
    const word = (key, params) => w('calls.' + key, params)
    const kindWord = (c) => word(c.video ? 'video' : 'voice')
    const callWord = (c) => word(c.video ? 'video_call' : 'voice_call')
    const resultWord = (c) => word('result.' + resultOf(c))
    function length(seconds, padded = false) {
        if (!seconds) return '—'
        const t = minutes(seconds)
        return t.m ? word('min_sec', { m: t.m, s: padded ? pad2(t.s) : t.s }) : word('sec', { s: t.s })
    }
    function when(iso) {
        const d = dateOf(iso)
        if (!d) return ''
        const year = d.getFullYear() === new Date().getFullYear() ? '' : '/' + d.getFullYear()
        return pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + year + ' ' + hm(d)
    }
    const arrow = (c) => (c.direction === 'in' ? '↙' : '↗')
    const isMissed = (c) => resultOf(c) === 'missed'

    // ── the totals per person: the chip numbers and the thread rows ──────
    async function refresh(force = false) {
        if (!ready || (!force && Date.now() - C.at < 25000)) return
        C.at = Date.now()
        try {
            const d = await api('GET', R.call_people)
            C.people = new Map((d.data || []).map((p) => [String(p.peer.id), p]))
        } catch {
            return /* the next tick tries again */
        }
        drawNumbers()
        x.renderList()
    }
    function drawNumbers() {
        const seen = Number(x.store(SEEN)) || 0
        let missed = 0
        let fresh = 0
        for (const p of C.people.values()) {
            missed += Number(p.missed) || 0
            // ⓘ a person whose latest call is a missed one, and newer than the last time I looked at the calls
            if (p.last && isMissed(p.last) && Date.parse(p.last.at) > seen) fresh++
        }
        if (el.badge) el.badge.textContent = fresh ? String(fresh) : ''
        if (el.missed) el.missed.textContent = missed ? String(missed) : ''
    }
    /** the thread's latest call when it is newer than its latest message — the list row shows that instead */
    function lastFor(t) {
        const last = t.kind === 'direct' && t.person ? C.people.get(String(t.person.id))?.last : null
        if (!last) return null
        return Date.parse(last.at) > (Date.parse(t.last_at || t.last?.at || '') || 0) ? last : null
    }
    const lastHtml = (c) => ic(c.video ? 'video' : 'phone') + `<span>${esc(isMissed(c) ? word('missed_of', { kind: callWord(c) }) : callWord(c))}</span>`
    function sorted(threads) {
        if (!ready || !C.people.size) return threads
        const at = (t) => Math.max(Date.parse(lastFor(t)?.at || '') || 0, Date.parse(t.last_at || t.last?.at || '') || 0)
        return threads.slice().sort((a, b) => at(b) - at(a))
    }

    // ── tab ১: the list ──────────────────────────────────────────────────
    function params(extra = {}) {
        return { kind: C.kind === 'all' ? '' : C.kind, q: C.q, limit: PAGE, ...extra }
    }
    async function load(more = false) {
        if (C.busy && more) return
        const seq = ++C.seq
        C.busy = true
        if (!more) el.rows.innerHTML = `<p class="none">${esc(w('loading'))}</p>`
        try {
            const d = await api('GET', withQuery(R.calls, params(more ? { cursor: C.next } : {})))
            if (seq !== C.seq || !C.on) return
            C.rows = more ? C.rows.concat(d.data || []) : d.data || []
            C.next = d.next || null
            drawRows()
            if (!more && !C.person && C.rows[0] && window.matchMedia('(min-width: 900px)').matches) pick(C.rows[0].peer, false)
        } catch (e) {
            if (seq === C.seq) x.toast(e.message)
        } finally {
            if (seq === C.seq) C.busy = false
        }
    }
    function rowHtml(c) {
        const on = C.person && String(C.person.id) === String(c.peer.id)
        return `<button type="button" class="crow call-row${isMissed(c) ? ' miss' : ''}${on ? ' sel' : ''}" role="listitem" data-peer="${esc(c.peer.id)}">${avatar(c.peer, 46)}<span class="mid"><b>${esc(c.peer.name)}</b><small><span class="dir" aria-hidden="true">${arrow(c)}</span> ${esc(kindWord(c))} · ${esc(resultWord(c))}</small></span><span class="rt n"><span>${esc(length(c.duration_s))}</span>${esc(when(c.at))}</span></button>`
    }
    function drawRows() {
        if (!C.on) return
        el.rows.innerHTML = C.rows.length ? C.rows.map(rowHtml).join('') : `<p class="none">${esc(word('none'))}</p>`
    }

    // ── tab ১: one person ────────────────────────────────────────────────
    async function pick(peer, open = true) {
        C.person = peer
        C.log = []
        C.logNext = null
        const seq = ++C.logSeq
        if (open) root.classList.add('chat-open')
        drawRows()
        drawPane(true)
        try {
            const [totals, log] = await Promise.all([
                api('GET', withQuery(R.call_people, { person: peer.id })),
                api('GET', withQuery(R.calls, { person: peer.id, limit: PAGE })),
            ])
            if (seq !== C.logSeq) return
            if (totals.data?.[0]) C.people.set(String(peer.id), totals.data[0])
            C.log = log.data || []
            C.logNext = log.next || null
            drawPane()
        } catch (e) {
            if (seq === C.logSeq) x.toast(e.message)
        }
    }
    async function moreLog() {
        if (!C.person || !C.logNext) return
        const seq = C.logSeq
        try {
            const d = await api('GET', withQuery(R.calls, { person: C.person.id, limit: PAGE, cursor: C.logNext }))
            if (seq !== C.logSeq) return
            C.log = C.log.concat(d.data || [])
            C.logNext = d.next || null
            drawPane()
        } catch (e) {
            x.toast(e.message)
        }
    }
    function drawPane(loading = false) {
        const p = C.person
        if (!p) {
            el.pane.innerHTML = ''
            return
        }
        const t = C.people.get(String(p.id)) || { calls: 0, answered: 0, missed: 0, seconds: 0 }
        const sub = [p.designation, p.branch].filter(Boolean).join(' · ')
        const back = canCall ? `<button type="button" class="newb" data-act="call-back" data-peer="${esc(p.id)}">${ic('phone', '')}${esc(word('call_back'))}</button>` : ''
        const card = (label, value, cls = '') => `<div${cls ? ` class="${cls}"` : ''}><span>${esc(label)}</span><b${cls === 'time' ? ' class="t"' : ''}>${esc(value)}</b></div>`
        const rows = C.log
            .map((c) => `<tr class="${isMissed(c) ? 'miss' : ''}"><td class="n">${esc(when(c.at))}</td><td>${arrow(c)} ${esc(word(c.direction))} · ${esc(kindWord(c))}</td><td>${esc(resultWord(c))}</td><td class="r n">${esc(length(c.duration_s))}</td></tr>`)
            .join('')
        const empty = `<tr><td colspan="4" class="none">${esc(loading ? w('loading') : word('none'))}</td></tr>`
        el.pane.innerHTML = `<div class="wh"><button type="button" class="hb back" data-act="calls-list" aria-label="${esc(w('back'))}">${ic('back', '')}</button>${avatar(p, 42)}<span class="who"><b>${esc(p.name)}</b><small>${esc(sub)}</small></span>${back}</div>
<div class="csum n">${card(word('total'), t.calls)}${card(word('result.talked'), t.answered)}${card(word('result.missed'), t.missed, Number(t.missed) ? 'bad' : '')}${card(word('time'), length(t.seconds), 'time')}</div>
<div class="ctw"><table><thead><tr><th>${esc(word('col_at'))}</th><th>${esc(word('col_kind'))}</th><th>${esc(word('col_result'))}</th><th class="r">${esc(word('col_length'))}</th></tr></thead><tbody>${rows || empty}</tbody></table>${C.logNext ? `<button type="button" class="cmore" data-act="calls-more">${esc(word('more'))}</button>` : ''}</div>`
    }

    // ── in and out of the calls ──────────────────────────────────────────
    function enter() {
        if (!ready || C.on) return
        C.on = true
        C.q = ''
        C.person = null
        x.leaveChat()
        root.classList.add('calls-on')
        el.msgChips.hidden = true
        el.callChips.hidden = false
        el.newMsg.hidden = true
        if (el.newCall) el.newCall.hidden = !canCall
        el.q.value = ''
        el.q.placeholder = word('search')
        $('[data-empty]').hidden = true
        el.pane.hidden = false
        drawPane()
        x.store(SEEN, String(Date.now()))
        drawNumbers()
        setKind('all')
        refresh(true)
    }
    function leave() {
        if (!C.on) return
        C.on = false
        C.seq++
        C.logSeq++
        root.classList.remove('calls-on', 'chat-open')
        el.msgChips.hidden = false
        el.callChips.hidden = true
        el.newMsg.hidden = false
        if (el.newCall) el.newCall.hidden = true
        el.q.value = S.q || ''
        el.q.placeholder = w('search_list')
        el.pane.hidden = true
        el.pane.innerHTML = ''
        $('[data-empty]').hidden = !!S.cur
        x.renderList()
        x.loadList()
    }
    function setKind(kind) {
        C.kind = kind
        $$('[data-call-kind]').forEach((b) => {
            b.classList.toggle('on', b.dataset.callKind === kind)
            b.setAttribute('aria-selected', b.dataset.callKind === kind ? 'true' : 'false')
        })
        C.person = null
        drawPane()
        load()
    }
    function ring(personId, video) {
        root.dispatchEvent(new CustomEvent('messenger:call', { bubbles: true, composed: true, detail: { person_id: String(personId), video: !!video } }))
    }

    // ── tab ২: the calls inside the open chat ────────────────────────────
    async function openChat(thread) {
        Object.assign(C, { chat: [], chatNext: null, chatOf: null })
        if (!ready || thread?.kind !== 'direct' || !thread.person) return
        C.chatOf = String(thread.person.id)
        await older(1)
    }
    /** older calls, until they reach back past the oldest message drawn (or there are no more) */
    async function older(minPages = 0) {
        const of = C.chatOf
        const seq = S.openSeq
        let pages = 0
        while (of && of === C.chatOf && seq === S.openSeq && pages < 20) {
            const floor = chatFloor()
            const oldest = C.chat.length ? Date.parse(C.chat[0].at) : Infinity
            if (pages >= minPages && (!C.chatNext || oldest <= floor)) return
            if (pages > 0 && !C.chatNext) return
            let d
            try {
                d = await api('GET', withQuery(R.calls, { person: of, limit: PAGE, cursor: C.chatNext || '' }))
            } catch {
                return /* the messages are there; the next poll tries the calls again */
            }
            if (of !== C.chatOf || seq !== S.openSeq) return
            keep(d.data || [])
            C.chatNext = d.next || null
            pages++
        }
    }
    /** the newest page again — a call that just ended stands in the chat without a reload */
    async function pollChat() {
        const of = C.chatOf
        if (!of) return
        try {
            const d = await api('GET', withQuery(R.calls, { person: of, limit: 10 }))
            if (of !== C.chatOf) return
            if (keep(d.data || [])) {
                const stick = x.atBottom()
                x.renderAll()
                if (stick) x.toBottom()
            }
        } catch {
            /* the next tick tries again */
        }
    }
    function keep(rows) {
        const byId = new Map(C.chat.map((c) => [c.call_id, c]))
        let changed = false
        for (const c of rows) {
            const had = byId.get(c.call_id)
            if (!had || had.status !== c.status || had.duration_s !== c.duration_s) changed = true
            byId.set(c.call_id, c)
        }
        C.chat = Array.from(byId.values()).sort((a, b) => Date.parse(a.at) - Date.parse(b.at))
        return changed
    }
    function chatFloor() {
        if (!S.hasMore) return -Infinity
        const first = S.order.map((id) => S.msgs.get(id)).find(Boolean)
        return first ? Date.parse(first.at) : -Infinity
    }
    /** what the chat draws now: only as far back as the messages go, so a call never stands above a message not loaded yet */
    const drawn = () => {
        const floor = chatFloor()
        return C.chat.filter((c) => Date.parse(c.at) >= floor)
    }
    function bubbleHtml(c) {
        const out = c.direction === 'out'
        const miss = isMissed(c)
        const icon = c.video && !miss ? 'video' : out ? 'callout' : 'callin'
        const title = miss ? word('missed_of', { kind: callWord(c) }) : callWord(c)
        const time = hm(dateOf(c.at))
        const sub = miss ? time : resultOf(c) === 'talked' ? length(c.duration_s || 0, true) : resultWord(c)
        const back = miss && canCall ? `<button type="button" data-act="call-back" data-peer="${esc(c.peer.id)}"${c.video ? ' data-video="1"' : ''}>${esc(word('call_back'))}</button>` : ''
        return `<div class="mr ${out ? 'me' : 'th'} tl" data-call="${esc(c.call_id)}"><div class="bub tail"><div class="ccall${miss ? ' miss' : ''}"><i>${ic(icon, '')}</i><span><b>${esc(title)}</b><small class="n">${esc(sub)}</small></span>${back}</div>${miss ? '' : `<span class="mt">${esc(time)}</span>`}</div></div>`
    }

    // ── clicks ───────────────────────────────────────────────────────────
    function act(name, node) {
        switch (name) {
            case 'calls':
                enter()
                return true
            case 'calls-x':
                leave()
                return true
            case 'calls-kind':
                setKind(node.dataset.callKind)
                return true
            case 'calls-more':
                moreLog()
                return true
            case 'calls-list':
                root.classList.remove('chat-open')
                return true
            case 'call-back':
                ring(node.dataset.peer, node.dataset.video === '1')
                return true
            case 'new-call':
                x.dialog({
                    title: word('new'),
                    multi: false,
                    withThreads: false,
                    onPick: async (key) => {
                        x.closeDialog()
                        ring(key.split(':')[1], false)
                    },
                })
                return true
            default:
                return false
        }
    }
    el.rows.addEventListener('click', (e) => {
        const row = C.on ? e.target.closest('.call-row') : null
        const call = row ? C.rows.find((c) => String(c.peer.id) === row.dataset.peer) : null
        if (call) pick(call.peer)
    })
    el.rows.addEventListener('scroll', () => {
        if (C.on && C.next && !C.busy && el.rows.scrollTop + el.rows.clientHeight > el.rows.scrollHeight - 160) load(true)
    })

    return {
        get on() {
            return C.on
        },
        act,
        refresh,
        lastFor,
        lastHtml,
        isMissed,
        sorted,
        openChat,
        older,
        pollChat,
        drawn,
        bubbleHtml,
        search(q) {
            C.q = q
            C.person = null
            drawPane()
            load()
        },
    }
}
