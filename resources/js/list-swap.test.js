// @vitest-environment happy-dom
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { FRAGMENT, PART_HEADER, formUrl, listSwap, placeParts, swapsForm } from './list-swap.js'

/*
 * ⭐ তালিকার কেবল তালিকা-অংশ বদলায় (মালিক, ২৩ নভেম্বর ২০২৬; [[list-swap.js]], সার্ভারে [[ListPart]])।
 *
 * ⓘ সার্ভার এখানে একটা নকল `fetch` — উত্তরটা খোলসহীন টুকরো, যেমন [[layouts.app]]-এর `ListPart` শাখা আঁকে।
 */

const TABLE = (rows) => `<div class="table-responsive" data-list-part="table"><table class="ui-list"><tbody>${
    rows.map((r) => `<tr><td>${r}</td></tr>`).join('')}</tbody></table></div>`

const PAGER = (page) => `<div data-pager data-list-part="pager"><span>page ${page}</span>
    <nav data-pager-links><a id="next" href="/customers?page=${page + 1}">next</a></nav></div>`

const PAGE = (q = '') => `
    <main id="main">
        <div data-boxed>
            <form id="list-form" method="GET" class="contents">
                <div class="toolbar-view">
                    <span data-record-count data-list-part="count">2</span>
                    <input type="search" name="q" value="${q}" data-live-search="${q}" id="find">
                    <button type="submit" id="go">go</button>
                    <button type="submit" name="compact" value="1" id="compact">dense</button>
                </div>
            </form>
            ${TABLE(['Rahim', 'Karim'])}
            ${PAGER(1)}
        </div>
    </main>
    <footer><div data-look-listfoot><span id="old-total">10</span><span data-look-hints>keys</span></div></footer>`

const FRESH = ({ rows = ['Rahim'], count = '1', page = 1, extra = '', total = '5' } = {}) => `
    <div data-list-part-fragment>
        <title>Customers — ABOS</title>
        <main id="main">
            <span data-record-count data-list-part="count">${count}</span>
            ${TABLE(rows)}
            ${PAGER(page)}
            ${extra}
        </main>
        <div data-list-foot-totals><span id="new-total">${total}</span></div>
    </div>`

let respond
const fetcher = vi.fn((url, init) => respond(url, init))
const go = vi.fn()

const ok = (body) => () => Promise.resolve({ ok: true, status: 200, redirected: false, text: () => Promise.resolve(body) })
const status = (code) => () => Promise.resolve({ ok: false, status: code, redirected: false, text: () => Promise.resolve('') })

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))
const cells = () => [...document.querySelectorAll('main td')].map((td) => td.textContent)

beforeAll(() => {
    listSwap(document, { fetcher, go })
})

beforeEach(() => {
    fetcher.mockClear()
    go.mockClear()
    window.history.replaceState(null, '', '/customers')
    document.body.innerHTML = PAGE()
    respond = ok(FRESH())
})

function search(value) {
    const box = document.getElementById('find')
    box.value = value
    box.focus()
    document.getElementById('list-form').requestSubmit(document.getElementById('go'))
}

describe('খোঁজায় কেবল তালিকা বদলায়', () => {
    it('একই ঠিকানা আনে, হেডার X-ABOS-Part: list সহ', async () => {
        search('rah')
        await flush()

        expect(fetcher).toHaveBeenCalledTimes(1)
        const [url, init] = fetcher.mock.calls[0]
        expect(new URL(url).pathname).toBe('/customers')
        expect(new URL(url).searchParams.get('q')).toBe('rah')
        expect(init.headers[PART_HEADER]).toBe('list')
        expect(go).not.toHaveBeenCalled()
    })

    it('টেবিল, গোনা আর যোগফল বদলায়; খোঁজার ঘর একই থাকে, ফোকাসসহ', async () => {
        const box = document.getElementById('find')
        search('rah')
        await flush()

        expect(cells()).toEqual(['Rahim'])
        expect(document.querySelector('[data-record-count]').textContent).toBe('1')
        expect(document.getElementById('new-total')).not.toBeNull()
        expect(document.getElementById('old-total')).toBeNull()
        expect(document.querySelector('[data-look-hints]')).not.toBeNull()
        expect(document.getElementById('find')).toBe(box)
        expect(document.activeElement).toBe(box)
        expect(box.dataset.liveSearch).toBe('rah')
        expect(document.title).toBe('Customers — ABOS')
    })

    it('ঠিকানা বদলায় (pushState)', async () => {
        search('rah')
        await flush()

        expect(window.location.pathname).toBe('/customers')
        expect(new URL(window.location.href).searchParams.get('q')).toBe('rah')
    })

    it('পাতার তীরে চাপ — একই ভাবে, পুরো পাতা নয়', async () => {
        respond = ok(FRESH({ rows: ['Zaman'], page: 2 }))
        document.getElementById('next').click()
        await flush()

        expect(new URL(fetcher.mock.calls[0][0]).searchParams.get('page')).toBe('2')
        expect(cells()).toEqual(['Zaman'])
        expect(go).not.toHaveBeenCalled()
    })

    it('নতুন অনুরোধ এলে আগেরটা বাতিল', async () => {
        const signals = []
        respond = (url, init) => {
            signals.push(init.signal)
            return ok(FRESH())()
        }
        search('rah')
        search('rahim')
        await flush()

        expect(signals[0].aborted).toBe(true)
        expect(signals[1].aborted).toBe(false)
    })
})

describe('পেছনে গেলে আগের তালিকা', () => {
    it('popstate — ঠিকানার তালিকা আবার আনে, খোঁজার ঘরে সেই লেখা', async () => {
        search('rah')
        await flush()

        respond = ok(FRESH({ rows: ['Old one'] }))
        document.getElementById('find').blur()
        window.history.replaceState({ abosList: true }, '', '/customers?q=old')
        window.dispatchEvent(new PopStateEvent('popstate', { state: { abosList: true } }))
        await flush()

        const [url] = fetcher.mock.calls.at(-1)
        expect(new URL(url).searchParams.get('q')).toBe('old')
        expect(cells()).toEqual(['Old one'])
        expect(document.getElementById('find').value).toBe('old')
    })
})

describe('ভুল হলে পুরো পাতা — আগের মতো', () => {
    it.each([[401], [419], [500]])('%s — পুরো পাতা', async (code) => {
        respond = status(code)
        search('rah')
        await flush()

        expect(go).toHaveBeenCalledTimes(1)
        expect(new URL(go.mock.calls[0][0]).searchParams.get('q')).toBe('rah')
        expect(cells()).toEqual(['Rahim', 'Karim'])
    })

    it('সংযোগ নেই (fetch ছুঁড়ে ফেলে) — পুরো পাতা', async () => {
        respond = () => Promise.reject(new TypeError('offline'))
        search('rah')
        await flush()

        expect(go).toHaveBeenCalledTimes(1)
    })

    it('টুকরোর চিহ্ন নেই (লগইনের পাতা এল) — পুরো পাতা', async () => {
        respond = ok('<html><body><form action="/login"></form></body></html>')
        search('rah')
        await flush()

        expect(go).toHaveBeenCalledTimes(1)
    })

    it('গড়ন আলাদা (নতুন চিপের সারি) — জোড়াতালি নয়, পুরো পাতা', async () => {
        respond = ok(FRESH({ extra: '<span data-list-part="chips">x</span>' }))
        search('rah')
        await flush()

        expect(go).toHaveBeenCalledTimes(1)
        expect(cells()).toEqual(['Rahim', 'Karim'])
    })
})

describe('কোন ফর্ম বদলায় না', () => {
    it('রিপোর্টের ফর্ম (data-report) — ধরা হয় না', () => {
        document.getElementById('list-form').setAttribute('data-report', '')

        expect(swapsForm(document.getElementById('list-form'))).toBe(false)
    })

    it('নামওয়ালা বোতাম (ঘনত্ব) — আগের মতো পুরো জমা', async () => {
        const seen = vi.fn((event) => event.preventDefault())
        document.addEventListener('submit', seen)
        document.getElementById('list-form').requestSubmit(document.getElementById('compact'))
        await flush()
        document.removeEventListener('submit', seen)

        expect(fetcher).not.toHaveBeenCalled()
    })

    it('তালিকার টেবিল না থাকলে — ধরা হয় না', () => {
        document.querySelector('[data-list-part="table"]').remove()

        expect(swapsForm(document.getElementById('list-form'))).toBe(false)
    })
})

describe('formUrl আর placeParts', () => {
    it('ফর্মের বাইরের form="…" ঘরও ঠিকানায়', () => {
        document.body.insertAdjacentHTML('beforeend', '<input form="list-form" name="status" value="active">')
        const url = new URL(formUrl(document.getElementById('list-form'), null, 'http://localhost/customers?page=3'))

        expect(url.searchParams.get('status')).toBe('active')
        expect(url.searchParams.get('page')).toBeNull()
    })

    it('টুকরো না থাকলে কিছুই বসে না', () => {
        const fresh = new DOMParser().parseFromString('<main id="main"></main>', 'text/html')

        expect(fresh.querySelector(FRAGMENT)).toBeNull()
        expect(placeParts(document, fresh)).toBe(false)
    })
})
