// @vitest-environment happy-dom
//
// ⛔ নেভিগেশন দুইভাবে থামানো, আর দুইটাই মাপা কারণে — সাজসজ্জা নয়।
//
// ── ⚠️ যা মেপে পাওয়া গেছে ─────────────────────────────────────────────
// এই ফাইলের অর্ধেক দাবি ঠিক এটাই মাপে যে ক্লিকটা **আটকানো হয়নি**। ⛔ আর
// happy-dom ঐ ক্লিকের উপর নিজে কাজ করে: মাউন্ট করা জানালাটা আর টেকে না,
// ফলে ঐ টেস্টের **পরের** প্রতিটা মাউন্ট মৃত হয়ে যায়।
//
// ⚠️ আর মৃত জানালাতেও "পিকে যায়নি" কথাটা নিখুঁতভাবে সত্য — অর্থাৎ প্রতিটা
// দলের প্রথম দাবি ছাড়া বাকিগুলো কিছুই মাপত না, অথচ সবটা সবুজ দেখাত।
// ⓘ মিউট্যান্ট ছাড়া এটা ধরা পড়ত না: বোতামের পাহারাটা মুছে দিলে দাবিটা
// একা চালালে লাল হত, গোটা ফাইল চালালে সবুজ।
//
// ⭐ তাই `suppress` — কম্পোনেন্টের **পরে** বসানো একটা শ্রোতা, যে আগে
// সিদ্ধান্তটা টুকে রাখে, তারপর নেভিগেশনটা থামায়। কম্পোনেন্টের কোড
// অপরিবর্তিত, আর পরিবেশ আর নথি নষ্ট করতে পারে না।
//
// ⓘ jsdom নেভিগেট করে না, কিন্তু প্যাকেজটা এই রিপোতে নেই — একটা নির্ভরতা
// যোগ করা আলাদা সিদ্ধান্ত, তাই জিজ্ঞেস না করে নেওয়া হয়নি।
// @vitest-environment-options { "settings": { "navigation": { "disableMainFrameNavigation": true } } }
import Alpine from '@alpinejs/csp'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { registerComponents } from './index.js'

/*
 * পিক — তালিকার লিংকে চাপলে ডকুমেন্টটা উপরেই খোলে, ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⭐ মালিকের নিয়ম ───────────────────────────────────────────────────
 * *"হাইপার লিংকে চাপলে পপআপ এলেই ভালো, নাহলে মডিউল/মেনু পরিবর্তন হয়ে
 * যায়, সেটা অত্যন্ত বিরক্তিকর।"*
 *
 * ── ⭐ কেন আসল ব্লেডটাই পড়া হয় ───────────────────────────────────────
 * ⛔ হাতে লেখা একটা নকল HTML-এ পরীক্ষা করলে দাবিটা নকলটাকেই মাপত — আর
 * ব্লেড থেকে `@keydown.escape.window` বা `x-data` হারিয়ে গেলেও সবুজ
 * থাকত। ⓘ [[shell.test.js]]-এর একই ছাঁচ, একই কারণে।
 *
 * ── ⚠️ আর এই পরীক্ষাটা ছাড়া কিছুই প্রমাণ হয় না ───────────────────────
 * ⓘ রেন্ডার হওয়া HTML-এ binding থাকা মানে Alpine সেটা **চালিয়েছে** এমন
 * নয় — CSP-Alpine নীরবে ফেলে দিতে পারে, আর এই প্রকল্পে সেটা একবার
 * "১০/১০ সবুজ" দেখিয়েছিল।
 */

const BLADE = join(process.cwd(), 'resources/views/components/shell/peek.blade.php')

function window_ () {
    return readFileSync(BLADE, 'utf8')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/\{\{\s*__\('([^']+)'\)\s*\}\}/g, '$1')
}

const warnings = []

beforeAll(() => {
    Alpine.setErrorHandler((error, el, expression) => {
        warnings.push(`Alpine Expression Error: ${error?.message} — ${expression}`)
    })

    const warn = console.warn
    console.warn = (...args) => {
        warnings.push(args.join(' '))
        warn(...args)
    }

    window.Alpine = Alpine
    registerComponents(Alpine)
    Alpine.start()
})

let host = null

/*
 * ⓘ কম্পোনেন্ট কী ঠিক করল — তার নিজের হাতের কাজ দেখে।
 * ⚠️ `suppress` নিজেই `preventDefault()` ডাকে, তাই পরে `event`-টা দেখে আর
 * বলা যায় না কে আটকাল। সেজন্যই মানটা এখানে টুকে রাখা হয়।
 */
let decided = null

function suppress (event) {
    decided = event.defaultPrevented
    event.preventDefault()
}

afterEach(async () => {
    document.removeEventListener('click', suppress)
    host?.remove()
    host = null
    decided = null
    await Alpine.nextTick()
    vi.restoreAllMocks()
})

const PAGE = `
<div class="table-responsive">
    <table><tbody>
        <tr><td><a id="doc" href="/sales/invoice/7">INV-7</a></td></tr>
        <tr><td><a id="editing" href="/sales/invoice/7/edit">বদল</a></td></tr>
        <tr><td><a id="paper" href="/sales/invoice/7/print" target="_blank">ছাপা</a></td></tr>
        <tr><td><a id="file" href="/sales/invoice/7/x" download>নামান</a></td></tr>
        <!-- ⓘ পথে বাদ-যোগ্য কিছু নেই, কেবল target — নাহলে target-পাহারাটাকে
             কখনো একা দাঁড়াতে হত না, আর ওটা মুছে ফেললেও সব সবুজ থাকত -->
        <tr><td><a id="newtab" href="/sales/invoice/8" target="_blank">নতুন ট্যাবে</a></td></tr>
        <tr><td><a id="outside" href="https://example.test/x">বাইরে</a></td></tr>
        <tr><td><a id="anchor" href="#top">উপরে</a></td></tr>
        <tr><td><form><a id="inform" href="/sales/invoice/9">বাতিল</a></form></td></tr>
    </tbody></table>
</div>
`

async function mount () {
    warnings.length = 0
    host = document.createElement('div')
    host.innerHTML = PAGE + window_()
    document.body.appendChild(host)
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()

    /*
     * ⛔ Alpine-এর init হয়ে যাওয়ার **পরে** — একই লক্ষ্যের শ্রোতারা
     * নিবন্ধনের ক্রমে ডাকা হয়, তাই আগে বসালে এটাই আগে চলত আর কম্পোনেন্ট
     * `defaultPrevented` সত্য দেখে সরে দাঁড়াত।
     */
    document.addEventListener('click', suppress)

    return host
}

const dialog = () => host.querySelector('[role="dialog"]')
const state = () => Alpine.$data(dialog())
const body = () => dialog().querySelector('[data-peek-body]')

const tick = async () => {
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()
}

/** সার্ভারের বদলে একটা নকল উত্তর */
function serverSays (html, status = 200) {
    return vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: status >= 200 && status < 300,
        status,
        text: async () => html,
    })
}

function click (id, options = {}) {
    const event = new MouseEvent('click', {
        bubbles: true, cancelable: true, button: 0, ...options,
    })

    host.querySelector('#' + id).dispatchEvent(event)

    return event
}

/*
 * ⭐ জানালাটা এই মুহূর্তে সত্যিই কাজ করে — একই ফিকশ্চারে মেপে।
 *
 * ⛔ "পিকে যায়নি" দাবিটা একটা **মৃত** কম্পোনেন্টেও নিখুঁতভাবে সত্য, তাই
 * প্রতিটা "যায়নি" দাবির সাথে একটা "যায়" থাকে — একই পাতা, একই জানালা,
 * কেবল ক্লিকটা আলাদা।
 */
async function peeksOnAPlainClick () {
    const fetched = serverSays('<h1>জীবিত</h1>')

    click('doc')
    await tick()

    expect(decided).toBe(true)
    expect(fetched).toHaveBeenCalled()
    expect(state().open).toBe(true)

    state().close()
    await tick()
    vi.restoreAllMocks()
}

describe('সাধারণ ক্লিকে ডকুমেন্টটা উপরেই খোলে', () => {
    it('জানালাটা খোলে, আর অনুরোধে X-Peek হেডার যায়', async () => {
        await mount()

        const fetched = serverSays('<h1>INV-7</h1><p>সাত</p>')

        click('doc')
        await tick()

        expect(decided).toBe(true)
        expect(state().open).toBe(true)

        const [url, init] = fetched.mock.calls[0]

        expect(url).toContain('/sales/invoice/7')
        expect(init.headers['X-Peek']).toBe('1')

        /* ⛔ ভিতরটা সত্যিই এসেছে — খালি বাক্স হলে উপরের দাবিগুলোও সবুজ থাকত */
        expect(body().innerHTML).toContain('INV-7')
        expect(warnings).toEqual([])
    })

    it('পুরো পাতার লিংকটা ঐ ডকুমেন্টেই যায়', async () => {
        await mount()
        serverSays('<h1>INV-7</h1>')

        click('doc')
        await tick()

        const full = dialog().querySelector('a[href]')

        expect(full.getAttribute('href')).toContain('/sales/invoice/7')
    })

    it('৪০৩ হলে ফাঁকা বাক্স নয়, সার্ভারের নিজের উত্তরটাই', async () => {
        /*
         * ⛔ খোলস বাদ দেওয়া একটা আঁকার সিদ্ধান্ত, দরজার নয়। ⚠️ পিকে ৪০৩
         * গিলে ফেললে মানুষ ভাবতেন নথিটা ফাঁকা, অথচ আসলে তাঁর অনুমতি নেই —
         * দুইটা সম্পূর্ণ আলাদা কথা।
         */
        await mount()
        serverSays('<h1>অনুমতি নেই</h1>', 403)

        click('doc')
        await tick()

        expect(state().open).toBe(true)
        expect(body().innerHTML).toContain('অনুমতি নেই')
    })

    it('Esc চাপলে বন্ধ হয়, আর ভিতরটা মুছে যায়', async () => {
        await mount()
        serverSays('<h1>INV-7</h1>')

        click('doc')
        await tick()

        window.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Escape', bubbles: true, cancelable: true,
        }))
        await tick()

        expect(state().open).toBe(false)

        /* ⓘ না মুছলে পরের বার পুরনো নথিটা এক পলকের জন্য দেখা যেত */
        expect(body().innerHTML).toBe('')
    })
})

describe('⛔ যেসব ক্লিক পিকে যায় না', () => {
    /*
     * ⚠️ Ctrl/⌘ নতুন ট্যাব, Shift নতুন জানালা, মাঝের বোতামও নতুন ট্যাব।
     * ⛔ ঐ তিনটা কেড়ে নিলে মানুষ ডকুমেন্ট পাশাপাশি খোলার ক্ষমতাটাই
     * হারাতেন — আর ঠিক সেই ক্ষমতার জন্যই লিংকটা আসল `<a href>` রাখা।
     */
    const modifiers = [
        ['Ctrl', { ctrlKey: true }],
        ['⌘', { metaKey: true }],
        ['Shift', { shiftKey: true }],
        ['Alt', { altKey: true }],
        ['মাঝের বোতাম', { button: 1 }],
    ]

    for (const [name, options] of modifiers) {
        it(`${name} দিয়ে চাপলে ব্রাউজারই পাতাটা খোলে`, async () => {
            await mount()

            /* ⛔ জীবিত-থাকার মাপটা আগে — ফাইলের মাথার ব্যাখ্যাটা দেখুন */
            await peeksOnAPlainClick()

            const fetched = serverSays('<h1>INV-7</h1>')

            const event = click('doc', options)
            await tick()

            /*
             * ⛔ ঘটনাটা সত্যিই চাবিটা বহন করছে — নাহলে "পিকে যায়নি" কথাটা
             * সত্য হত ভুল কারণে, আর পাহারাটা মুছে দিলেও সবুজ থাকত।
             */
            for (const [field, value] of Object.entries(options)) {
                expect(event[field]).toBe(value)
            }

            expect(decided).toBe(false)
            expect(fetched).not.toHaveBeenCalled()
            expect(state().open).toBe(false)
        })
    }

    const excluded = [
        ['ফর্মের পাতা', 'editing'],
        ['ছাপার লিংক', 'paper'],
        ['নতুন ট্যাবে খোলার লিংক', 'newtab'],
        ['নামানোর লিংক', 'file'],
        ['বাইরের সাইট', 'outside'],
        ['একই পাতার নোঙর', 'anchor'],
        ['ফর্মের ভিতরের লিংক', 'inform'],
    ]

    for (const [name, id] of excluded) {
        it(`${name} পিকে যায় না`, async () => {
            await mount()
            await peeksOnAPlainClick()

            const fetched = serverSays('<h1>কিছু</h1>')

            click(id)
            await tick()

            expect(decided).toBe(false)
            expect(fetched).not.toHaveBeenCalled()
            expect(state().open).toBe(false)
        })
    }
})

describe('⭐ ভুল আন্দাজ হলে আগের আচরণটাই ফেরে', () => {
    it('অনুরোধ ব্যর্থ হলে জানালা খোলা রেখে দেওয়া হয় না', async () => {
        /*
         * ⓘ এটাই নকশার আসল কথা: বাদের তালিকাটা নির্ভুলতার শর্ত নয়, কেবল
         * ছাঁকনি। ⚠️ যেটা ছাঁকনি পেরিয়েও পিক-যোগ্য নয়, সে পুরো পাতায়
         * গড়িয়ে পড়ে — সবচেয়ে খারাপ ফল "আগের মতোই পাতা বদলাল", কখনো
         * "একটা ফাঁকা বাক্স" নয়।
         */
        await mount()
        await peeksOnAPlainClick()

        vi.spyOn(globalThis, 'fetch').mockRejectedValue(new Error('নেটওয়ার্ক নেই'))

        /* ⓘ সত্যিকারের নেভিগেশন হতে দেওয়া যায় না — হলে পরের দাবির
             জানালাটা আর টিকত না (ফাইলের মাথার ব্যাখ্যা) */
        const went = vi.spyOn(window.location, 'assign').mockImplementation(() => {})

        click('doc')
        await tick()

        expect(state().open).toBe(false)
        expect(body().innerHTML).toBe('')

        /* ⭐ আর সে সত্যিই ঐ ডকুমেন্টের পাতাতেই গেল */
        expect(went).toHaveBeenCalled()
        expect(went.mock.calls[0][0]).toContain('/sales/invoice/7')
    })
})

describe('ⓘ শ্রোতাটা আবার সরেও যায়', () => {
    it('জানালা মুছে গেলে ক্লিকে আর কোনো অনুরোধ যায় না', async () => {
        /*
         * ⛔ না সরলে দুইটা শ্রোতা একসাথে দাঁড়াতে পারত, আর তখন একটা ক্লিকে
         * দুইটা অনুরোধ যেত।
         */
        await mount()
        await peeksOnAPlainClick()

        dialog().remove()
        await tick()

        const fetched = serverSays('<h1>INV-7</h1>')

        click('doc')
        await tick()

        expect(fetched).not.toHaveBeenCalled()
    })
})
