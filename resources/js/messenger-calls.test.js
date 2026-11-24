// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { minutes, resultOf, takeBefore } from './messenger-calls.js'
import { start } from './messenger-page.js'

/*
 * ⭐ Calls in the messenger — owner's sample VxwPoxm77whS6ToNqsyZPU, tabs ১ and ২ (24 Nov 2026).
 * ⓘ The DOM is the page's own blade (its directives cut out), so a renamed hook goes red here.
 */
function bladeToHtml (relative) {
    return readFileSync(join(process.cwd(), relative), 'utf8')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@php[\s\S]*?@endphp/g, '')
        .replace(/\{\{[\s\S]*?\}\}/g, '')
        .replace(/<\/?x-[^>]*>/g, '')
        .replace(/^\s*@\w+.*$/gm, '')
}

const flush = async (n = 14) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)) }
const reply = (data) => ({ ok: true, status: 200, json: async () => data })

beforeAll(() => {
    globalThis.CSS ??= {}
    globalThis.CSS.escape ??= (s) => String(s)
    try { window.happyDOM?.setURL?.('http://abos.test/messages') } catch { /* not needed */ }
    window.matchMedia = () => ({ matches: true })
})

afterEach(() => {
    vi.restoreAllMocks()
    document.body.innerHTML = ''
    window.localStorage.clear()
})

const RAHIM = { id: '7', name: 'Rahim', designation: 'DSM', branch: 'Star Line', photo_url: null, online: false }
const KARIM = { id: '8', name: 'Karim', designation: 'Accounts', branch: 'Head Office', photo_url: null, online: false }
const at = (minute) => new Date(2025, 10, 24, 10, minute).toISOString()
const call = (id, over) => ({ call_id: id, video: false, direction: 'in', peer: RAHIM, status: 'missed', at: at(0), answered_at: null, ended_at: null, duration_s: null, ...over })

const MISSED = call('c-missed', { at: at(40) })
const VIDEO = call('c-video', { at: at(20), video: true, status: 'ended', answered_at: at(20), duration_s: 1082 })
const OUT = call('c-out', { at: at(5), direction: 'out', status: 'ended', answered_at: at(5), duration_s: 374 })
const NOBODY = call('c-nobody', { at: at(3), direction: 'out', peer: KARIM })
const ALL = [MISSED, VIDEO, OUT, NOBODY]

const WORDS = {
    title: 'বার্তা', loading: '…', error: 'ভুল', back: 'ফিরে', search_list: 'খুঁজুন', today: 'আজ', yesterday: 'গতকাল', weekdays: [], msg_menu: 'মেনু', empty_list: 'খালি',
    calls: {
        voice: 'ভয়েস', video: 'ভিডিও', voice_call: 'ভয়েস কল', video_call: 'ভিডিও কল', missed_of: 'মিসড :kind', in: 'এসেছে', out: 'গেছে', none: 'কোনো কল নেই',
        call_back: 'কল করুন', total: 'মোট কল', time: 'মোট সময়', min_sec: ':m মিনিট :s সেকেন্ড', sec: ':s সেকেন্ড', search: 'নাম খুঁজুন', more: 'আরও',
        result: { talked: 'কথা হয়েছে', missed: 'মিসড', unanswered: 'ধরেননি', declined: 'কেটে দেওয়া', open: 'চলছে' },
    },
}
const THREAD = { id: 'T1', kind: 'direct', title: 'Rahim', subtitle: 'DSM', person: RAHIM, last: { id: '11', kind: 'text', text: 'হ্যালো', mine: false, at: at(10) }, last_at: at(10), unread: 0 }
const TOTALS = [
    { peer: RAHIM, calls: 3, answered: 2, missed: 1, seconds: 1456, last_at: MISSED.at, last: MISSED },
    { peer: KARIM, calls: 1, answered: 0, missed: 0, seconds: 0, last_at: NOBODY.at, last: NOBODY },
]

function mount ({ calls = '1', routes = true, messages } = {}) {
    document.body.innerHTML = bladeToHtml('app/Modules/Messaging/Resources/views/index.blade.php')
    const root = document.querySelector('[data-msgr]')
    Object.assign(root.dataset, {
        words: JSON.stringify(WORDS), me: JSON.stringify({ id: '1', name: 'Me' }), open: '', calls, locale: 'bn', csrf: 't',
        routes: JSON.stringify({
            threads: '/t', thread: '/t/__T__', messages: '/t/__T__/m', read: '/t/__T__/read', people: '/p', direct: '/d', page: '/messages',
            ...(routes ? { calls: '/calls', call_people: '/calls/people' } : {}),
        }),
    })
    const asked = []
    globalThis.fetch = vi.fn(async (url) => {
        const u = new URL(String(url), 'http://abos.test')
        asked.push(u.pathname + u.search)
        const person = u.searchParams.get('person')
        if (u.pathname === '/calls/people') return reply({ data: person ? TOTALS.filter((p) => p.peer.id === person) : TOTALS })
        if (u.pathname === '/calls') {
            const kind = u.searchParams.get('kind')
            const q = u.searchParams.get('q')
            return reply({
                data: ALL.filter((c) => (!person || c.peer.id === person) && (!q || c.peer.name.includes(q))
                    && (!kind || (kind === 'missed' ? c.direction === 'in' && c.status === 'missed' : c.direction === kind))),
                next: null,
            })
        }
        if (u.pathname === '/t') return reply({ data: [THREAD], unread_total: 0 })
        if (u.pathname === '/t/T1/m') {
            return reply(messages ?? { data: [{ id: '11', kind: 'text', text: 'হ্যালো', mine: false, sender: RAHIM, at: at(10), state: 'sent' }], meta: { typing: [], states: [], has_more: false } })
        }
        if (u.pathname === '/t/T1') return reply({ data: { ...THREAD, members: [RAHIM] } })
        return reply({ data: [] })
    })
    start(root)
    return { root, asked, click: (sel) => root.querySelector(sel).click() }
}

describe('the small rules', () => {
    it('what a call came to', () => {
        expect(ALL.map(resultOf)).toEqual(['missed', 'talked', 'talked', 'unanswered'])
        expect(resultOf(call('x', { status: 'declined' }))).toBe('declined')
        expect(resultOf(call('x', { status: 'ringing' }))).toBe('open')
    })

    it('minutes and seconds', () => {
        expect(minutes(374)).toEqual({ m: 6, s: 14 })
        expect(minutes(41)).toEqual({ m: 0, s: 41 })
        expect(minutes(null)).toEqual({ m: 0, s: 0 })
    })

    it('takes the calls standing before a moment, in order, once', () => {
        const queue = [OUT, VIDEO, MISSED]
        expect(takeBefore(queue, Date.parse(at(10))).map((c) => c.call_id)).toEqual(['c-out'])
        expect(takeBefore(queue, Infinity).map((c) => c.call_id)).toEqual(['c-video', 'c-missed'])
        expect(queue).toEqual([])
    })
})

describe('tab ১ — the call list', () => {
    it('opens from its chip: rows, missed in red, the first person with totals and log', async () => {
        const { root, click } = mount()
        await flush()
        expect(root.querySelector('[data-calls-missed]').textContent).toBe('1')
        click('[data-act="calls"]')
        await flush()

        const rows = [...root.querySelectorAll('.call-row')]
        expect(rows).toHaveLength(4)
        expect(rows.map((r) => r.classList.contains('miss'))).toEqual([true, false, false, false])
        expect(rows[1].textContent).toContain('ভিডিও · কথা হয়েছে')
        expect(rows[1].textContent).toContain('18 মিনিট 2 সেকেন্ড')
        expect(rows[3].textContent).toContain('ধরেননি')

        expect([...root.querySelectorAll('.csum b')].map((b) => b.textContent)).toEqual(['3', '2', '1', '24 মিনিট 16 সেকেন্ড'])
        expect(root.querySelector('.csum .bad b').textContent).toBe('1')
        expect(root.querySelectorAll('.ctw tbody tr')).toHaveLength(3)
        expect(root.querySelectorAll('.ctw tbody tr.miss')).toHaveLength(1)
        expect(root.querySelector('[data-msg-chips]').hidden).toBe(true)
        expect(root.querySelector('[data-call-chips]').hidden).toBe(false)
    })

    it('filters on the server, changes person, calls back, and goes back to the messages', async () => {
        const { root, asked, click } = mount()
        await flush()
        click('[data-act="calls"]')
        await flush()
        click('[data-call-kind="missed"]')
        await flush()
        expect(asked.some((u) => u.startsWith('/calls?') && u.includes('kind=missed'))).toBe(true)
        expect(root.querySelectorAll('.call-row')).toHaveLength(1)

        click('[data-call-kind="out"]')
        await flush()
        root.querySelector('.call-row[data-peer="8"]').click()
        await flush()
        expect(root.querySelector('[data-call-pane] .who b').textContent).toBe('Karim')
        expect(root.querySelector('.csum .bad')).toBeNull() // no missed call, no red box

        const rung = vi.fn()
        root.addEventListener('messenger:call', (e) => rung(e.detail))
        click('[data-call-pane] [data-act="call-back"]')
        expect(rung).toHaveBeenCalledWith({ person_id: '8', video: false })

        click('[data-act="calls-x"]')
        await flush()
        expect(root.querySelectorAll('.call-row')).toHaveLength(0)
        expect(root.querySelectorAll('.crow[data-tid]')).toHaveLength(1)
        expect(root.querySelector('[data-call-pane]').hidden).toBe(true)
    })

    it('a list tick while the calls are open leaves the call rows alone', async () => {
        const { root, click } = mount()
        await flush()
        click('[data-act="calls"]')
        await flush()
        document.dispatchEvent(new Event('visibilitychange'))
        await flush()
        expect(root.querySelectorAll('.call-row')).toHaveLength(4)
    })

    it('offers no call button while calls are off, but the history is still there', async () => {
        const { root, click } = mount({ calls: '0' })
        await flush()
        click('[data-act="calls"]')
        await flush()
        expect(root.querySelectorAll('.call-row')).toHaveLength(4)
        expect(root.querySelector('[data-act="call-back"]')).toBeNull()
    })

    it('the new-missed number goes once the calls are looked at', async () => {
        const { root, click } = mount()
        await flush()
        expect(root.querySelector('[data-calls-new]').textContent).toBe('1')
        click('[data-act="calls"]')
        await flush()
        expect(root.querySelector('[data-calls-new]').textContent).toBe('')
    })
})

describe('tab ২ — calls inside the chat', () => {
    it('stand among the messages by time: talked with its length, missed with "call back"', async () => {
        const { root, click } = mount()
        await flush()
        // ⓘ the latest thing with Rahim is the missed call, so the row says so
        expect(root.querySelector('.crow').classList.contains('miss')).toBe(true)
        expect(root.querySelector('.crow .last').textContent).toContain('মিসড ভয়েস কল')

        click('.crow[data-tid="T1"]')
        await flush()
        const order = [...root.querySelectorAll('[data-msgs] > .mr')].map((n) => n.dataset.call || n.dataset.mid)
        expect(order).toEqual(['c-out', '11', 'c-video', 'c-missed'])

        const out = root.querySelector('[data-call="c-out"]')
        expect(out.classList.contains('me')).toBe(true)
        expect(out.textContent).toContain('6 মিনিট 14 সেকেন্ড')
        expect(root.querySelector('[data-call="c-video"]').textContent).toContain('18 মিনিট 02 সেকেন্ড')

        const missed = root.querySelector('[data-call="c-missed"]')
        expect(missed.querySelector('.ccall.miss b').textContent).toBe('মিসড ভয়েস কল')
        const rung = vi.fn()
        root.addEventListener('messenger:call', (e) => rung(e.detail))
        missed.querySelector('[data-act="call-back"]').click()
        expect(rung).toHaveBeenCalledWith({ person_id: '7', video: false })
    })

    it('never draws a call above messages that are not loaded yet', async () => {
        const { root, click } = mount({ messages: { data: [{ id: '11', kind: 'text', text: 'x', mine: false, sender: RAHIM, at: at(10), state: 'sent' }], meta: { typing: [], states: [], has_more: true } } })
        await flush()
        click('.crow[data-tid="T1"]')
        await flush()
        expect(root.querySelector('[data-call="c-out"]')).toBeNull()
        expect(root.querySelector('[data-call="c-missed"]')).not.toBeNull()
    })

    it('a page without the call doors works as before', async () => {
        const { root, asked, click } = mount({ routes: false })
        await flush()
        click('.crow[data-tid="T1"]')
        await flush()
        expect(root.querySelectorAll('[data-msgs] [data-mid]')).toHaveLength(1)
        expect(asked.some((u) => u.startsWith('/calls'))).toBe(false)
        click('[data-act="calls"]')
        expect(root.classList.contains('calls-on')).toBe(false)
    })
})
