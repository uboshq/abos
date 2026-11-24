/*
 * ⭐ The full-screen Copilot page — owner's web sample copilot-web-sample (21 Nov 2026), tabs ক and খ.
 *
 *  ক · start: the big live Ai mark, greeting, ask box (attach a paper · mic · send), chips, key hints
 *  খ · a talk: history on the left (আজ / গতকাল / আগে), the question on the right, the answer on the left with a short
 *      text, a table, a small chart, "উৎস: …" chips and the actions (তালিকা খুলুন · বার্তায় পাঠান · PDF · কপি · 👍 ·
 *      আবার লিখুন); "থামান" while the answer is coming, and the Ai mark goes busy.
 *
 * ⓘ Answers come from [[CopilotPageController::ask()]] — the same engine, tools, keys and log as the quick ask.
 * ⓘ CSP-Alpine: no arrow functions or `?.` in the Blade expressions — everything the template needs is
 *   precomputed here ([[shape()]]), the template only reads fields and calls methods.
 * ⛔ No audio is kept — the browser's own speech recognition turns speech into the text of the box, nothing more.
 */

const recognizer = () => (typeof window === 'undefined' ? null : (window.SpeechRecognition || window.webkitSpeechRecognition || null))

let seq = 0
const nextKey = (prefix) => `${prefix}-${Date.now().toString(36)}-${++seq}`

/** ⓘ a stored or fresh message → what the template reads (no logic in the Blade) */
export function shape (m) {
    const me = m?.role === 'me'
    const rows = Array.isArray(m?.rows) ? m.rows : []
    const columns = Array.isArray(m?.columns) ? m.columns : []
    const headings = Array.isArray(m?.headings) ? m.headings : columns
    const sources = Array.isArray(m?.sources) ? m.sources.filter((s) => s && s.label) : []
    const bars = Array.isArray(m?.chart?.bars) ? m.chart.bars : []

    return {
        key: m?.key || nextKey(me ? 'me' : 'ai'),
        me,
        text: String(m?.text ?? ''),
        question: String(m?.question ?? ''),
        state: String(m?.state ?? (me ? '' : 'answered')),
        answered: !me && (m?.state ?? 'answered') === 'answered',
        heads: headings.map((h, i) => ({ key: `h${i}`, text: String(h), num: i > 0 })),
        table: rows.map((r, i) => ({
            key: `r${i}`,
            no: String(i + 1),
            cells: columns.map((c, j) => ({ key: `c${j}`, text: String(r?.[c] ?? ''), num: j > 0, href: j === 0 ? String(r?.href ?? '') : '' })),
        })),
        hasTable: rows.length > 0 && columns.length > 0,
        chart: bars.length > 0 ? {
            title: String(m.chart.title ?? ''),
            bars: bars.map((b, i) => ({ key: `b${i}`, label: String(b.label ?? ''), value: String(b.value ?? ''), style: `height:${Math.max(2, Math.min(100, Number(b.pct) || 0))}%` })),
        } : null,
        hasChart: bars.length > 0,
        sources: sources.map((s, i) => ({ key: `s${i}`, label: String(s.label), href: String(s.href ?? '') })),
        hasSources: sources.length > 0,
        listHref: String(sources.find((s) => s.href)?.href ?? rows.find((r) => r?.href)?.href ?? ''),
        by: String(m?.by ?? ''),
        answerId: m?.answer_id ?? null,
        voted: 0,
        stopped: Boolean(m?.stopped),
    }
}

/** ⓘ the words that go into a message, a copy or a print — question, answer, table rows, sources */
export function plainOf (item, { title = '', link = '' } = {}) {
    const lines = []
    if (title) lines.push(title)
    if (item.question) lines.push(`❓ ${item.question}`)
    if (item.text) lines.push(item.text)
    for (const row of item.table || []) lines.push('• ' + row.cells.map((c) => c.text).filter((t) => t !== '').join(' · '))
    if (item.hasSources) lines.push(item.sources.map((s) => s.label).join(' · '))
    if (link) lines.push(link)
    return lines.join('\n')
}

/** ⓘ the time of day for the hello line is the server's; this only finds the question that an answer belongs to */
export function pairUp (messages) {
    const out = []
    let lastQuestion = ''
    for (const m of Array.isArray(messages) ? messages : []) {
        if (m?.role === 'me') lastQuestion = String(m.text ?? '')
        out.push(shape(m?.role === 'me' ? m : { ...m, question: lastQuestion }))
    }
    return out
}

export function copilotPage () {
    return {
        cfg: {},
        items: [],
        conv: null,
        q: '',
        busy: false,
        histOpen: false,
        histQ: '',
        listening: false,
        rec: null,
        attachOpen: false,
        attachQ: '',
        attachHits: [],
        attachEmpty: false,
        attachTimer: null,
        controller: null,
        copiedKey: '',
        shareOn: false,

        init () {
            const node = this.$el.querySelector('[data-cpp-state]')
            try { this.cfg = JSON.parse(node ? node.textContent : '{}') || {} } catch (_) { this.cfg = {} }
            this.conv = this.cfg.talk ? { id: this.cfg.talk.id, title: this.cfg.talk.title, url: this.cfg.talk.url } : null
            this.items = pairUp(this.cfg.talk ? this.cfg.talk.messages : [])
            this.histOpen = this.items.length > 0
            this.shareOn = typeof document !== 'undefined' && document.querySelector('[data-msgr-root]') !== null
            this.keys = (e) => this.onKey(e)
            window.addEventListener('keydown', this.keys, true)
            this.scroll()
            this.focusBox()
        },
        destroy () { window.removeEventListener('keydown', this.keys, true) },

        // ── what the template asks ─────────────────────────────────────
        started () { return this.items.length > 0 },
        fresh () { return this.items.length === 0 },
        canListen () { return recognizer() !== null },
        micTitle () { return this.listening ? this.word('mic_on') : (this.canListen() ? this.word('mic') : this.word('mic_off')) },
        word (key) { return String((this.cfg.words || {})[key] ?? key) },
        sendDim () { return this.q.trim() === '' && ! this.busy },
        isCopied (item) { return this.copiedKey === item.key },
        votedUp (item) { return item.voted === 1 },
        hasList (item) { return item.listHref !== '' },
        canVote (item) { return Boolean(item.answerId) },
        lastAnswer (item) {
            for (let i = this.items.length - 1; i >= 0; i--) if (! this.items[i].me) return this.items[i].key === item.key
            return false
        },
        canRegenerate (item) { return ! this.busy && item.question !== '' && this.lastAnswer(item) },
        historyShown (row) {
            const t = this.histQ.trim().toLocaleLowerCase()
            return t === '' || String(row.title).toLocaleLowerCase().includes(t)
        },

        // ── keys: Ctrl+J focuses the box here (the quick ask stays shut on this page), Esc stops or leaves ──
        onKey (e) {
            if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === 'j') {
                e.preventDefault()
                e.stopImmediatePropagation()
                this.focusBox()
                return
            }
            if (e.key !== 'Escape' || this.attachOpen) {
                if (e.key === 'Escape') this.closeAttach()
                return
            }
            if (this.busy) { this.stop(); return }
            if (this.listening) { this.stopListening(); return }
            if (this.q.trim() !== '') { this.q = ''; return }
            if (typeof document !== 'undefined' && document.querySelector('[role="dialog"]:not([hidden])[aria-modal="true"]')) return
            this.leave()
        },
        leave () {
            const back = typeof document !== 'undefined' && document.referrer && document.referrer.startsWith(window.location.origin) && ! document.referrer.includes('/copilot/')
            if (back) window.history.back()
            else if (this.cfg.homeUrl) window.location.assign(this.cfg.homeUrl)
        },
        focusBox () {
            this.$nextTick(() => { const b = this.$refs.box; if (b) b.focus() })
        },

        // ── asking ─────────────────────────────────────────────────────
        // ⓘ each returns ask()'s promise, so a caller (or a test) can wait for the answer to land
        submit () { return this.ask(this.q, false) },
        pick (e) {
            const t = e && e.currentTarget ? e.currentTarget.getAttribute('data-cpp-chip') : ''
            return this.ask(t || '', false)
        },
        regenerate (item) { return this.canRegenerate(item) ? this.ask(item.question, true) : Promise.resolve() },
        toggleHistory () { this.histOpen = ! this.histOpen },

        async ask (text, regenerate) {
            const question = String(text ?? '').trim()
            if (question === '' || this.busy) return
            this.stopListening()
            this.q = ''
            if (regenerate) {
                // ⓘ the old answer to the same question goes; the server drops it from the talk too
                const last = this.items[this.items.length - 1]
                if (last && ! last.me) this.items.pop()
                const mine = this.items[this.items.length - 1]
                if (mine && mine.me && mine.text === question) this.items.pop()
            }
            this.items.push(shape({ role: 'me', text: question }))
            this.busy = true
            this.scroll()
            this.controller = typeof AbortController === 'undefined' ? null : new AbortController()
            let line
            try {
                const r = await fetch(this.cfg.askUrl, {
                    method: 'POST',
                    signal: this.controller ? this.controller.signal : undefined,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ q: question, conversation: this.conv ? this.conv.id : null, regenerate: Boolean(regenerate) }),
                })
                if (r.status === 403) {
                    line = { role: 'ai', state: 'blocked', text: this.word('no_key') }
                } else {
                    const d = await r.json()
                    if (d && d.conversation) this.remember(d.conversation)
                    line = d && d.answer ? d.answer : { role: 'ai', state: 'failed', text: String((d && d.message) || this.word('failed')) }
                }
            } catch (e) {
                line = e && e.name === 'AbortError'
                    ? { role: 'ai', state: 'stopped', text: this.word('stopped'), stopped: true }
                    : { role: 'ai', state: 'failed', text: this.word('failed') }
            }
            this.items.push(shape({ ...line, question }))
            this.busy = false
            this.controller = null
            this.histOpen = true
            this.scroll()
            this.focusBox()
        },
        stop () {
            if (this.controller) this.controller.abort()
        },
        /** ⓘ the first answer of a new talk: the address becomes the talk's own, and it joins the history (আজ) */
        remember (c) {
            const isNew = ! this.conv || this.conv.id !== c.id
            this.conv = { id: c.id, title: c.title, url: c.url }
            if (isNew && typeof window !== 'undefined' && window.history && window.history.replaceState) {
                window.history.replaceState({}, '', c.url)
            }
            if (isNew) {
                const groups = Array.isArray(this.cfg.history) ? this.cfg.history : []
                for (const g of groups) for (const row of g.items) row.on = false
                const now = new Date()
                const row = { id: c.id, title: c.title, url: c.url, on: true, time: `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}` }
                if (groups.length > 0 && groups[0].key === 'today') groups[0].items.unshift(row)
                else groups.unshift({ key: 'today', group: this.word('today'), items: [row] })
                this.cfg.history = groups
            }
        },

        // ── answer actions ─────────────────────────────────────────────
        openList (item) { if (item.listHref) window.location.assign(item.listHref) },
        shareInfo (item) {
            return JSON.stringify({
                text: plainOf(item, { link: this.conv ? this.conv.url : '' }),
                title: this.cfg.name || 'Copilot',
                no: '',
                party: item.question,
            })
        },
        async copy (item) {
            const text = plainOf(item)
            try {
                if (navigator.clipboard) await navigator.clipboard.writeText(text)
                this.copiedKey = item.key
                setTimeout(() => { if (this.copiedKey === item.key) this.copiedKey = '' }, 1500)
            } catch (_) { /* ⓘ no clipboard in this browser — nothing to do */ }
        },
        vote (item, value) {
            if (! item.answerId || item.voted !== 0 || ! this.cfg.feedbackUrl) return
            item.voted = value
            fetch(String(this.cfg.feedbackUrl).replace('__ID__', String(item.answerId)), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ value }),
            })
        },
        /** ⭐ PDF — the browser's own print (Save as PDF) of this one answer and its question; the rest of the page is hidden in print */
        pdf (item) {
            const node = document.querySelector(`[data-cpp-answer="${item.key}"]`)
            if (! node) return
            node.classList.add('cpp-printing')
            document.body.setAttribute('data-cpp-print', '1')
            const done = () => {
                node.classList.remove('cpp-printing')
                document.body.removeAttribute('data-cpp-print')
                window.removeEventListener('afterprint', done)
            }
            window.addEventListener('afterprint', done)
            window.print()
        },

        // ── attach a paper: its number goes into the question (only papers the person may open) ──
        toggleAttach () {
            this.attachOpen = ! this.attachOpen
            if (this.attachOpen) this.$nextTick(() => { const b = this.$refs.attach; if (b) b.focus() })
        },
        closeAttach () { this.attachOpen = false },
        findPaper () {
            clearTimeout(this.attachTimer)
            const term = this.attachQ.trim()
            if (term.length < 2 || ! this.cfg.papersUrl) { this.attachHits = []; this.attachEmpty = false; return }
            this.attachTimer = setTimeout(async () => {
                try {
                    const r = await fetch(`${this.cfg.papersUrl}?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    const d = r.ok ? await r.json() : { data: [] }
                    this.attachHits = (Array.isArray(d.data) ? d.data : []).slice(0, 8).map((p, i) => ({ key: `p${i}`, no: String(p.no ?? ''), title: String(p.title ?? ''), meta: [p.amount, p.party].filter(Boolean).join(' · ') }))
                } catch (_) { this.attachHits = [] }
                this.attachEmpty = this.attachHits.length === 0
            }, 250)
        },
        attachPaper (hit) {
            this.q = [this.q.trim(), hit.no].filter((s) => s !== '').join(' ') + ' '
            this.attachOpen = false
            this.attachQ = ''
            this.attachHits = []
            this.focusBox()
        },

        // ── mic: the browser's speech → the box; Enter or send to ask ──
        toggleMic () { return this.listening ? this.stopListening() : this.listen() },
        listen () {
            const Rec = recognizer()
            if (Rec === null || this.busy) return
            const kept = this.q.trim()
            const rec = new Rec()
            rec.lang = this.cfg.locale === 'en' ? 'en-US' : 'bn-BD'
            rec.interimResults = true
            rec.continuous = true
            rec.onresult = (e) => {
                let said = ''
                for (let i = 0; i < e.results.length; i++) said += e.results[i][0].transcript
                this.q = [kept, said.trim()].filter((s) => s !== '').join(' ')
            }
            rec.onerror = () => { this.listening = false }
            rec.onend = () => { this.listening = false; this.rec = null; this.focusBox() }
            this.rec = rec
            this.listening = true
            try { rec.start() } catch (_) { this.listening = false; this.rec = null }
        },
        stopListening () {
            if (this.rec) { try { this.rec.stop() } catch (_) { /* already stopped */ } }
            this.listening = false
        },

        scroll () {
            this.$nextTick(() => {
                const t = this.$refs.thread
                if (t) t.scrollTop = t.scrollHeight
            })
        },
    }
}
