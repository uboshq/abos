// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { bootMessenger, clockOf, initialOf, urlFor, word } from './messenger-call.js'
import { esc, hash, initial, start } from './messenger-page.js'
import { arrange, bootShare } from './messenger-share.js'

/*
 * ⭐ বার্তার ওয়েব — মালিকের নমুনা M1UddRxK v7 (২১ নভেম্বর ২০২৬)।
 *   ক — বার্তার পাতা: তালিকা, সবুজ ব্যাজ, কম্পোজারের ক্রম, Enter পাঠায় / Shift+Enter নতুন লাইন, "ERP কাগজ"
 *   গ — টপবারের ব্যাজ, কল আসার পপআপ ("কেটে দিন" / "ধরুন")
 *   ঘ — "বার্তায় পাঠান": অনুমতি নেই এমন মানুষের সারিতে অ্যাম্বার লাইন, বাছাই, পাঠানো
 * ⓘ পাতার আসল ব্লেড থেকেই DOM বানানো (Blade-এর অংশ ছেঁটে) — বাছাইকারী বদলালে এখানেই লাল।
 */

/** Blade → সাধা HTML: মন্তব্য, @php, {{ }}, <x-…>, @-লাইন বাদ */
function bladeToHtml (relative) {
    const src = readFileSync(join(process.cwd(), relative), 'utf8')
    return src
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/@php[\s\S]*?@endphp/g, '')
        .replace(/\{\{[\s\S]*?\}\}/g, '')
        .replace(/<\/?x-[^>]*>/g, '')
        .replace(/^\s*@\w+.*$/gm, '')
}

const flush = async (n = 8) => { for (let i = 0; i < n; i++) await new Promise((r) => setTimeout(r, 0)) }

function reply (data, status = 200) {
    return { ok: status >= 200 && status < 300, status, json: async () => data }
}

beforeAll(() => {
    // ⓘ happy-dom-এর পুরনো সংস্করণে CSS.escape না থাকলেও চলুক
    globalThis.CSS ??= {}
    globalThis.CSS.escape ??= (s) => String(s).replace(/["\\\]]/g, '\\$&')
    try { window.happyDOM?.setURL?.('http://abos.test/messages') } catch { /* ⓘ the code never needs the origin */ }
})

afterEach(() => {
    vi.restoreAllMocks()
    document.body.innerHTML = ''
})

describe('ছোট সাহায্যকারী — ইংরেজি অঙ্ক, পুরো অক্ষর', () => {
    it('clockOf — মিনিট:সেকেন্ড, ঘণ্টা পেরোলে ঘণ্টাও', () => {
        expect(clockOf(0)).toBe('00:00')
        expect(clockOf(65)).toBe('01:05')
        expect(clockOf(3725)).toBe('1:02:05')
    })

    it('নামের প্রথম অক্ষর যুক্তবর্ণ/কার ভাঙে না', () => {
        expect(initial('রাকিব হাসান')).toBe('রা')
        expect(initialOf('রাকিব হাসান')).toBe('রা')
        expect(initial('nasir')).toBe('N')
        expect(initial('')).toBe('?')
    })

    it('esc আর hash', () => {
        expect(esc('<b a="1">&\'')).toBe('&lt;b a=&quot;1&quot;&gt;&amp;&#39;')
        expect(hash('abc')).toBe(hash('abc'))
        expect(hash('abc')).not.toBe(hash('abd'))
    })

    it('arrange — কথোপকথনের ক্রমে মানুষ ও গ্রুপ, তারপর বাকি মানুষ, কেউ দুইবার নয়', () => {
        const rows = arrange(
            [{ id: 't1', kind: 'direct', person: { id: '2', name: 'B' } }, { id: 'g1', kind: 'group', title: 'G' }],
            [{ id: '1', name: 'A' }, { id: '2', name: 'B', can_open_paper: false }],
        )
        expect(rows.map((r) => r.key)).toEqual(['person:2', 'thread:g1', 'person:1'])
        expect(rows[0].noOpen).toBe(true)
        expect(rows[2].noOpen).toBe(false)
    })
})

/* ─────────────── ক — বার্তার পাতা ─────────────── */

const PAGE_WORDS = {
    title: 'বার্তা', you: 'আপনি', said: ':name: ', loading: '…', no_results: 'নেই', close: 'বন্ধ', error: 'ভুল',
    unread_badge: ':count টি না-পড়া', online: 'অনলাইন', today: 'আজ', yesterday: 'গতকাল', weekdays: [],
    menu_mute: 'নোটিফিকেশন বন্ধ', menu_unmute: 'নোটিফিকেশন চালু', group_info: 'গ্রুপের তথ্য', contact_info: 'তথ্য',
    video_call: 'ভিডিও কল', voice_call: 'কল', group_video: 'গ্রুপ ভিডিও', group_call: 'গ্রুপ কল',
    group_head: ':count জন সদস্য · :online জন অনলাইন', paper_title: 'ERP কাগজ পাঠান', paper_search: 'নম্বর',
    paper_sent: 'পাঠানো হয়েছে', msg_menu: 'মেনু', empty_list: 'খালি', open: 'খুলুন',
}

const ROUTES = {
    threads: '/messages/x/threads',
    thread: '/messages/x/threads/__T__',
    messages: '/messages/x/threads/__T__/messages',
    send: '/messages/x/threads/__T__/messages',
    read: '/messages/x/threads/__T__/read',
    typing: '/messages/x/threads/__T__/typing',
    mute: '/messages/x/threads/__T__/mute',
    members: '/messages/x/threads/__T__/members',
    media: '/messages/x/threads/__T__/media',
    search: '/messages/x/threads/__T__/search',
    people: '/messages/x/people',
    direct: '/messages/x/direct',
    forward: '/messages/x/forward',
    papers: '/messages/x/papers',
    paper_find: '/messages/x/papers/find',
    delete: '/messages/x/messages/987654321',
    info: '/messages/x/messages/987654321/info',
    page: '/messages',
}

const RAKIB = { id: '7', name: 'রাকিব হাসান', designation: 'বিক্রয়', branch: 'ঢাকা', photo_url: null, online: true }
const THREADS = [
    { id: 'T1', kind: 'direct', title: 'রাকিব হাসান', subtitle: 'বিক্রয় · ঢাকা', person: RAKIB, last: { id: '11', kind: 'text', text: 'চালানটা দেখুন', mine: false, at: new Date().toISOString() }, last_at: new Date().toISOString(), unread: 2, muted: false, member_count: 2 },
    { id: 'G1', kind: 'group', title: 'বিক্রয় দল', subtitle: 'গ্রুপ · 5 জন', person: null, last: null, last_at: null, unread: 0, muted: false, member_count: 5 },
]

function mountPage () {
    document.body.innerHTML = bladeToHtml('app/Modules/Messaging/Resources/views/index.blade.php')
    const root = document.querySelector('[data-msgr]')
    root.dataset.words = JSON.stringify(PAGE_WORDS)
    root.dataset.routes = JSON.stringify(ROUTES)
    root.dataset.me = JSON.stringify({ id: '1', name: 'আমি' })
    root.dataset.open = ''
    root.dataset.locale = 'bn'
    root.dataset.csrf = 't0k3n'

    const sent = []
    globalThis.fetch = vi.fn(async (url, opts = {}) => {
        const u = String(url)
        const method = opts.method || 'GET'
        if (method === 'POST') sent.push({ url: u, body: opts.body })
        if (u.startsWith('/messages/x/threads?') || u === '/messages/x/threads') return reply({ data: THREADS, unread_total: 2 })
        if (u.startsWith('/messages/x/threads/T1/messages') && method === 'GET') {
            return reply({ data: [{ id: '11', kind: 'text', text: 'চালানটা দেখুন', mine: false, sender: RAKIB, at: new Date().toISOString(), state: 'sent', reply_to: null }], meta: { typing: [], peer_online: true, states: [], has_more: false } })
        }
        if (u.startsWith('/messages/x/threads/T1/messages') && method === 'POST') {
            const body = JSON.parse(opts.body)
            return reply({ data: { id: '12', client_id: body.client_id, kind: 'text', text: body.text, mine: true, at: new Date().toISOString(), state: 'sent', reply_to: null } }, 201)
        }
        if (u === '/messages/x/threads/T1') return reply({ data: { ...THREADS[0], members: [RAKIB] } })
        if (u.startsWith('/messages/x/papers/find')) return reply({ data: [{ type: 'SalesInvoice', id: 'P1', no: 'INV-0042', title: 'বিক্রয় চালান', amount: '৳1,200', party: 'রহিম ট্রেডার্স' }] })
        if (u === '/messages/x/papers') return reply({ data: { sent: 1, thread_ids: ['T1'] } }, 201)
        if (u.includes('/read') || u.includes('/typing')) return reply(null, 204)
        return reply({ data: [] })
    })

    start(root)
    return { root, sent }
}

describe('ক — বার্তার পাতা (WhatsApp ওয়েবের ধাঁচে)', () => {
    it('তালিকা: সারি, সবুজ ব্যাজ, না-পড়া চিপের সংখ্যা', async () => {
        const { root } = mountPage()
        await flush()

        const rows = root.querySelectorAll('.crow')
        expect(rows.length).toBe(2)
        expect(rows[0].querySelector('.badge')?.textContent).toBe('2')
        expect(rows[1].querySelector('.badge')).toBeNull()
        expect(root.querySelector('[data-unread-total]').textContent).toBe('2')
        // ⓘ গ্রুপের সারিতে মানুষের ছবি নয়, গ্রুপের চিহ্ন
        expect(rows[1].querySelector('use')?.getAttribute('href')).toBe('#mg-users')
    })

    it('কম্পোজারের ক্রম: ইমোজি → জুড়ুন → লেখার ঘর ("বলে লিখুন" ভেতরে) → মাইক → সবসময় দেখা পাঠানোর বোতাম', () => {
        const { root } = mountPage()
        const order = Array.from(root.querySelector('[data-comp]').children)
            .filter((c) => c.matches('[data-act="emoji"], [data-act="attach"], .cpill, [data-act="voice"], [data-act="send"]'))
            .map((c) => c.dataset.act || 'pill')
        expect(order).toEqual(['emoji', 'attach', 'pill', 'voice', 'send'])
        expect(root.querySelector('.cpill [data-act="dictate"]')).not.toBeNull()
        const send = root.querySelector('[data-act="send"]')
        expect(send.hidden).toBe(false)
        expect(send.classList.contains('mic')).toBe(true)
    })

    it('"বলে লিখুন" — ব্রাউজারে Web Speech না থাকলে লুকানো', () => {
        delete window.SpeechRecognition
        delete window.webkitSpeechRecognition
        const { root } = mountPage()
        expect(root.querySelector('[data-act="dictate"]').hidden).toBe(true)
    })

    it('জুড়ুন-এর পপআপে চারটা: ফাইল · ছবি · ERP কাগজ · ক্যামেরা', () => {
        const { root } = mountPage()
        const acts = Array.from(root.querySelectorAll('[data-atray] [data-act]')).map((b) => b.dataset.act)
        expect(acts).toEqual(['a-file', 'a-image', 'a-paper', 'a-camera'])
    })

    it('Enter পাঠায়, Shift+Enter পাঠায় না', async () => {
        const { root, sent } = mountPage()
        await flush()
        root.querySelector('.crow[data-tid="T1"]').click()
        await flush()
        expect(root.querySelector('[data-head-title]').textContent).toBe('রাকিব হাসান')

        const input = root.querySelector('[data-input]')
        input.value = 'নতুন লাইন'
        input.dispatchEvent(new Event('input', { bubbles: true }))
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true, cancelable: true }))
        await flush()
        expect(sent.filter((s) => s.url.endsWith('/T1/messages'))).toHaveLength(0)

        input.value = 'চালান পেয়েছি'
        input.dispatchEvent(new Event('input', { bubbles: true }))
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }))
        await flush()
        const posts = sent.filter((s) => s.url.endsWith('/T1/messages'))
        expect(posts).toHaveLength(1)
        expect(JSON.parse(posts[0].body)).toMatchObject({ kind: 'text', text: 'চালান পেয়েছি' })
        expect(input.value).toBe('')
        expect(root.querySelector('[data-msgs]').textContent).toContain('চালান পেয়েছি')
    })

    it('জুড়ুন → ERP কাগজ: নিজের কাগজ খুঁজে এই কথোপকথনে পাঠায়', async () => {
        const { root, sent } = mountPage()
        await flush()
        root.querySelector('.crow[data-tid="T1"]').click()
        await flush()

        root.querySelector('[data-act="a-paper"]').click()
        await flush()
        const pick = root.querySelector('[data-dialog-host] [data-pick-paper="0"]')
        expect(pick).not.toBeNull()
        expect(pick.textContent).toContain('INV-0042')

        pick.click()
        await flush()
        const post = sent.find((s) => s.url === '/messages/x/papers')
        expect(post).toBeTruthy()
        expect(JSON.parse(post.body)).toMatchObject({ paper_type: 'SalesInvoice', paper_id: 'P1', to: ['thread:T1'] })
        expect(root.querySelector('[data-dialog-host]').innerHTML).toBe('')
    })
})

/* ─────────────── গ আর ঘ — প্রতিটা ABOS পাতার খোলস ─────────────── */

const CALL_WORDS = {
    topbar: { title: 'বার্তা' },
    incoming: { voice: 'ABOS ভয়েস কল আসছে…', video: 'ABOS ভিডিও কল আসছে…' },
    screen: { voice: 'ভয়েস কল', video: 'ভিডিও কল', mute: 'মিউট', unmute: 'মিউট খুলুন', speaker_is: 'স্পিকার: :name', speaker_default: 'এই কম্পিউটার' },
    toast: { failed: 'হলো না' },
    share: { no_open: 'এই কাগজ খোলার অনুমতি নেই — শুধু নম্বর দেখবেন', send: 'পাঠান · :count জন', sent: 'পাঠানো হয়েছে · :count জন', failed: 'হলো না' },
}

const CHROME_URLS = {
    index: '/messages', unread: '/messages/x/unread', calls: '/messages/x/calls', start: '/messages/x/calls',
    answer: '/messages/x/calls/__CALL__/answer', decline: '/messages/x/calls/__CALL__/decline', end: '/messages/x/calls/__CALL__/end',
    people: '/messages/x/people', threads: '/messages/x/threads', papers: '/messages/x/papers', direct: '/messages/x/direct',
}

function mountChrome (unread) {
    document.body.innerHTML = '<header data-topbar></header>'
        + bladeToHtml('app/Modules/Messaging/Resources/views/partials/topbar-chat.blade.php')
        + bladeToHtml('app/Modules/Messaging/Resources/views/partials/chrome.blade.php')
    const root = document.querySelector('[data-msgr-root]')
    root.dataset.urls = JSON.stringify(CHROME_URLS)
    root.dataset.words = JSON.stringify(CALL_WORDS)
    root.dataset.csrf = 't0k3n'

    const sent = []
    globalThis.fetch = vi.fn(async (url, opts = {}) => {
        const u = new URL(String(url), 'http://abos.test')
        if ((opts.method || 'GET') !== 'GET') sent.push({ url: u.pathname, body: opts.body })
        if (u.pathname === '/messages/x/unread') return reply(unread)
        if (u.pathname === '/messages/x/people') {
            return reply({ data: [{ id: '2', name: 'আনিস', can_open_paper: false }, { id: '3', name: 'ফারজানা', can_open_paper: true }] })
        }
        if (u.pathname === '/messages/x/threads') return reply({ data: [], unread_total: 0 })
        if (u.pathname === '/messages/x/papers') return reply({ data: { sent: 1, thread_ids: ['X'] } }, 201)
        return reply(null, 204)
    })

    return { root, sent }
}

describe('গ — টপবারের ব্যাজ আর কল আসার পপআপ', () => {
    it('ব্যাজে না-পড়ার সংখ্যা; কেউ ভিডিও কল দিলে নাম, "কেটে দিন" আর "ধরুন"', async () => {
        const { root, sent } = mountChrome({
            unread_total: 5,
            ringing: { call_id: 'C1', video: true, peer: { id: '7', name: 'রাকিব হাসান', designation: 'বিক্রয়', branch: 'ঢাকা' } },
        })
        bootMessenger()
        await flush()

        const badge = document.querySelector('[data-msgr-badge]')
        expect(badge.hidden).toBe(false)
        expect(badge.textContent).toBe('5')

        const pop = root.querySelector('[data-msgr-incoming]')
        expect(pop.hidden).toBe(false)
        expect(pop.querySelector('[data-msgr-inc-name]').textContent).toBe('রাকিব হাসান')
        expect(pop.querySelector('[data-msgr-inc-what]').textContent).toBe('ABOS ভিডিও কল আসছে…')
        expect(pop.querySelector('[data-msgr-inc-icon="video"]').hidden).toBe(false)
        expect(pop.querySelector('[data-msgr-decline]')).not.toBeNull()
        expect(pop.querySelector('[data-msgr-answer]')).not.toBeNull()

        pop.querySelector('[data-msgr-decline]').click()
        await flush()
        expect(pop.hidden).toBe(true)
        expect(sent.map((s) => s.url)).toContain('/messages/x/calls/C1/decline')
        expect(urlFor('end', 'C1')).toBe('/messages/x/calls/C1/end')
        expect(word('share.send', { count: 2 })).toBe('পাঠান · 2 জন')
    })
})

describe('ঘ — বার্তায় পাঠান', () => {
    it('অনুমতি নেই এমন মানুষের সারিতে অ্যাম্বার লাইন; বাছলে "পাঠান · 1 জন", পাঠালে papers দরজায়', async () => {
        const { root, sent } = mountChrome({ unread_total: 0, ringing: null })
        bootMessenger()
        bootShare()
        await flush()

        const item = document.createElement('button')
        item.type = 'button'
        item.setAttribute('data-msgr-share', JSON.stringify({ type: 'SalesInvoice', id: 'P1', title: 'বিক্রয় চালান', no: 'INV-0042', amount: '৳1,200', party: 'রহিম' }))
        document.body.appendChild(item)
        item.click()
        await flush()

        const dialog = root.querySelector('[data-msgr-share-dialog]')
        expect(dialog.hidden).toBe(false)
        expect(dialog.querySelector('[data-msgr-share-paper]').textContent).toBe('বিক্রয় চালান INV-0042')

        const rows = dialog.querySelectorAll('.msgr-share__row')
        expect(rows.length).toBe(2)
        expect(rows[0].querySelector('.msgr-share__no')?.textContent).toContain('এই কাগজ খোলার অনুমতি নেই — শুধু নম্বর দেখবেন')
        expect(rows[1].querySelector('.msgr-share__no')).toBeNull()

        const go = dialog.querySelector('[data-msgr-share-send]')
        expect(go.disabled).toBe(true)
        rows[1].click()
        expect(go.disabled).toBe(false)
        expect(dialog.querySelector('[data-msgr-share-send-label]').textContent).toBe('পাঠান · 1 জন')

        go.click()
        await flush()
        const post = sent.find((s) => s.url === '/messages/x/papers')
        expect(JSON.parse(post.body)).toMatchObject({ paper_type: 'SalesInvoice', paper_id: 'P1', to: ['person:3'] })
        expect(dialog.hidden).toBe(true)
    })
})
