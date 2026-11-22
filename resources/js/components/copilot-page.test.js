// ⭐ The full-screen Copilot page (owner's web sample copilot-web-sample, 21 Nov 2026) — the shaping of answers, the talk,
//   stop / regenerate, and that a "বার্তায় পাঠান" carries only what is on the screen.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { copilotPage, pairUp, plainOf, shape } from './copilot-page.js'
import { sendText } from '../messenger-share.js'

const answer = {
    role: 'ai',
    state: 'answered',
    tool: 'credit.overdue',
    text: 'Most is owed by Ma Enterprise — ৳3,48,200.',
    columns: ['customer', 'overdue'],
    headings: ['Customer', 'Overdue'],
    rows: [
        { customer: 'Ma Enterprise', overdue: '৳3,48,200', href: '/customers/1' },
        { customer: 'Rahman', overdue: '৳1,74,100', href: '' },
    ],
    chart: { title: 'Overdue', bars: [{ label: 'Ma Enterprise', value: '৳3,48,200', pct: 100 }, { label: 'Rahman', value: '৳1,74,100', pct: 50 }] },
    sources: [{ label: 'Source: Customer ledger · 21-11-2026', href: '/credit/positions' }],
    by: 'words',
    answer_id: 7,
}

function page (cfg = {}) {
    const c = copilotPage()
    c.$el = { querySelector: () => ({ textContent: JSON.stringify({ askUrl: '/ask', chatUrl: '/chat', csrf: 't', words: { failed: 'failed', stopped: 'stopped', today: 'Today' }, history: [], ...cfg }) }) }
    c.$nextTick = (fn) => fn()
    c.$refs = {}
    c.init()
    return c
}

describe('shape', () => {
    it('turns an answer into table rows, chart bars and source chips', () => {
        const s = shape({ ...answer, question: 'who owes most?' })
        expect(s.me).toBe(false)
        expect(s.answered).toBe(true)
        expect(s.heads.map((h) => h.text)).toEqual(['Customer', 'Overdue'])
        expect(s.table[0].cells.map((c) => c.text)).toEqual(['Ma Enterprise', '৳3,48,200'])
        expect(s.table[0].cells[0].href).toBe('/customers/1')
        expect(s.table[0].cells[1].num).toBe(true)
        expect(s.chart.bars.map((b) => b.style)).toEqual(['height:100%', 'height:50%'])
        expect(s.sources[0].label).toMatch(/^Source: /)
        expect(s.listHref).toBe('/credit/positions')
    })

    it('a refused answer has no table, no chart and no sources', () => {
        const s = shape({ role: 'ai', state: 'blocked', text: 'no key', rows: [], columns: [], sources: [], chart: null })
        expect(s.answered).toBe(false)
        expect(s.hasTable).toBe(false)
        expect(s.hasChart).toBe(false)
        expect(s.hasSources).toBe(false)
    })

    it('keeps each answer with its own question', () => {
        const items = pairUp([{ role: 'me', text: 'q1' }, answer, { role: 'me', text: 'q2' }, { ...answer, text: 'a2' }])
        expect(items.map((i) => i.question)).toEqual(['', 'q1', '', 'q2'])
    })

    it('the shared or copied text is only what is on the screen', () => {
        const text = plainOf(shape({ ...answer, question: 'who owes most?' }), { title: 'Copilot', link: '/copilot/c/1' })
        expect(text).toContain('who owes most?')
        expect(text).toContain('Ma Enterprise · ৳3,48,200')
        expect(text).toContain('Source: Customer ledger')
        expect(text.endsWith('/copilot/c/1')).toBe(true)
    })
})

describe('copilotPage', () => {
    let replaced
    beforeEach(() => {
        replaced = []
        vi.stubGlobal('history', { replaceState: (_s, _t, url) => replaced.push(url) })
        window.history.replaceState = (_s, _t, url) => replaced.push(url)
    })
    afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks() })

    it('opens on the start screen, or on the kept talk with the history shown', () => {
        expect(page().fresh()).toBe(true)
        const kept = page({ talk: { id: 'x', title: 'q1', url: '/copilot/c/x', messages: [{ role: 'me', text: 'q1' }, answer] } })
        expect(kept.started()).toBe(true)
        expect(kept.histOpen).toBe(true)
        expect(kept.items).toHaveLength(2)
    })

    it('asks, keeps the talk id, puts the talk in the history and moves the address', async () => {
        const c = page()
        globalThis.fetch = vi.fn(async (_url, opts) => ({
            status: 200,
            json: async () => ({ conversation: { id: 'abc', title: 'who owes most?', url: '/copilot/c/abc' }, answer, sent: JSON.parse(opts.body) }),
        }))
        c.q = 'who owes most?'
        await c.submit()
        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toEqual({ q: 'who owes most?', conversation: null, regenerate: false })
        expect(c.items.map((i) => i.me)).toEqual([true, false])
        expect(c.conv.id).toBe('abc')
        expect(c.cfg.history[0].items[0]).toMatchObject({ id: 'abc', on: true })
        expect(c.busy).toBe(false)
    })

    it('regenerate replaces the last answer and sends the same talk', async () => {
        const c = page({ talk: { id: 'abc', title: 'q', url: '/copilot/c/abc', messages: [{ role: 'me', text: 'q' }, answer] } })
        globalThis.fetch = vi.fn(async () => ({ status: 200, json: async () => ({ conversation: { id: 'abc', title: 'q', url: '/copilot/c/abc' }, answer: { ...answer, text: 'again' } }) }))
        await c.regenerate(c.items[1])
        expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toMatchObject({ q: 'q', conversation: 'abc', regenerate: true })
        expect(c.items).toHaveLength(2)
        expect(c.items[1].text).toBe('again')
    })

    it('stop aborts the answer and says so', async () => {
        const c = page()
        globalThis.fetch = vi.fn((_url, opts) => new Promise((_resolve, reject) => {
            opts.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })))
        }))
        const asking = c.ask('slow question', false)
        expect(c.busy).toBe(true)
        c.stop()
        await asking
        expect(c.items[1].state).toBe('stopped')
        expect(c.items[1].text).toBe('stopped')
        expect(c.busy).toBe(false)
    })

    it('a 403 shows the no-key line and nothing else', async () => {
        const c = page({ words: { no_key: 'no key' } })
        globalThis.fetch = vi.fn(async () => ({ status: 403, json: async () => ({}) }))
        await c.ask('salaries?', false)
        expect(c.items[1]).toMatchObject({ state: 'blocked', text: 'no key', hasTable: false, hasSources: false })
    })

    it('the history filter matches the title', () => {
        const c = page()
        c.histQ = 'BAKI'
        expect(c.historyShown({ title: 'kar kache baki beshi' })).toBe(true)
        expect(c.historyShown({ title: 'aj koto bikri' })).toBe(false)
    })
})

describe('sendText (বার্তায় পাঠান with Copilot\'s answer)', () => {
    it('opens the chat with a person first, posts straight into a group', async () => {
        const calls = []
        const call = async (url, { body }) => {
            calls.push([url, body])
            if (body && body.person) return { ok: true, data: { data: { id: 'T-' + body.person } } }
            return { ok: true, data: {} }
        }
        const { sent } = await sendText(['person:5', 'thread:G1'], 'hello', call)
        expect(sent).toBe(2)
        expect(calls[0][1]).toEqual({ person: 5 })
        expect(calls.filter(([, b]) => b.kind === 'text').map(([, b]) => b.text)).toEqual(['hello', 'hello'])
    })
})
