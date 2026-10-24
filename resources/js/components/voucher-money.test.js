// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { registerComponents } from './index.js'

/*
 * ⭐ রসিদ ও পরিশোধ ভাউচারের টাকার অংশ ([[voucherMoney]]) — চূড়ান্ত নকশার মিল (fe, ২৩ অক্টোবর ২০২৬)।
 *   · অঙ্ক লাখ-কমায় সাজে ("45,000.00")
 *   · পরিশোধে খাতে কম থাকলে লাল, রসিদে কখনো নয়
 *   · প্রিভিউ: সারি এলে খাতার ছবি; সারি খালি আর বার্তা এলে পোস্ট যে কারণে থামত; অর্ধেক ভরা ফর্মে চুপ
 *   · রেফারেন্সের উত্তর কেবল শেষ প্রশ্নের
 */

beforeAll(() => {
    window.Alpine = Alpine
    registerComponents(Alpine)
    Alpine.start()
})

afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
})

async function mount ({ out = false, amount = '', account = '229', balances = { 229: '1000.00' } } = {}) {
    // ⛔ JSON-এর ডবল কোট অ্যাট্রিবিউট আগেই শেষ করে দিত (cb_v15: বাক্সটা জোড়াই লাগেনি, পাঁচটা দাবি "not a function") — একক কোটে লেখা
    const balanceText = JSON.stringify(balances).replace(/"/g, "'")
    const host = document.createElement('div')
    host.innerHTML = `
        <form x-data="voucherMoney({ amount: '${amount}', account: '${account}', balances: ${balanceText}, simulateUrl: '/accounts/vouchers/simulate', referenceUrl: '/accounts/vouchers/reference-check', out: ${out} })">
            <input name="_token" value="t">
            <input name="amount" x-model="amount">
            <input name="instrument_no" value="">
            <span data-short x-text="short ? 'short' : 'ok'"></span>
            <span data-blocked x-text="previewBlocked ? preview.message : ''"></span>
            <span data-rows x-text="preview.rows.length"></span>
        </form>`
    document.body.appendChild(host)
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()

    return Alpine.$data(host.querySelector('form'))
}

describe('অঙ্ক আর খাতের স্থিতি', () => {
    it('ঘর ছাড়লে অঙ্ক লাখ-কমায় আর দুই দশমিকে দেখায়, কিন্তু মান কাঁচা অঙ্কই থাকে', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ amount: '4500000' })
        // ⓘ ২৪ অক্টোবর ২০২৬ থেকে দেখানোর ঘর আলাদা ([[registerMoneyDisplay]]) — আগে tidyAmount() মানটাতেই কমা বসাত
        const shown = document.createElement('input')
        shown.setAttribute('x-money-display', 'amount')
        document.querySelector('form').appendChild(shown)
        await new Promise(r => setTimeout(r, 0))
        await Alpine.nextTick()

        expect(shown.value, '⛔ ঘরে লাখ-কমা আর দুই দশমিক নেই').toBe('45,00,000.00')
        expect(data.amount, '⛔ দেখানোর কমা মানে ঢুকল — পাঠানো অঙ্ক কাঁচা থাকার কথা').toBe('4500000')
    })

    it('পরিশোধে খাতের চেয়ে বেশি দিলে "কম" — রসিদে নয়', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const paying = await mount({ out: true, amount: '1,500.00' })
        expect(paying.short).toBe(true)
        expect(paying.balanceText).toBe('1,000.00')

        document.body.innerHTML = ''
        const receiving = await mount({ out: false, amount: '1,500.00' })
        expect(receiving.short).toBe(false)
    })
})

describe('খাতায় যা যাবে', () => {
    it('সারি এলে খাতার ছবি আর মিল; সারি খালি আর বার্তা এলে পোস্ট যে কারণে থামত; অর্ধেক ভরা ফর্মে চুপ', async () => {
        const replies = [
            { rows: [{ account: '1102' }, { account: '1110' }], debit: '1,000.00', credit: '1,000.00', balanced: true },
            { rows: [], message: 'পক্ষ অন্য শাখার' },
            { rows: [], message: '', missing: ['narration'] },
        ]
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => replies.shift() })))
        const data = await mount({ amount: '1000' })
        clearTimeout(data.timer)

        await data.simulate()
        expect(data.hasPreview).toBe(true)
        expect(data.previewMatches).toBe(true)
        expect(data.previewBlocked).toBe(false)

        await data.simulate()
        expect(data.hasPreview).toBe(false)
        expect(data.previewBlocked).toBe(true)
        expect(data.preview.message).toBe('পক্ষ অন্য শাখার')

        await data.simulate()
        expect(data.hasPreview).toBe(false)
        expect(data.previewBlocked).toBe(false)
    })

    it('অঙ্ক বা খাত না থাকলে সার্ভারকে জিজ্ঞেসই করে না', async () => {
        const fetchSpy = vi.fn()
        vi.stubGlobal('fetch', fetchSpy)
        const data = await mount({ amount: '', account: '' })
        clearTimeout(data.timer)
        await data.simulate()
        expect(fetchSpy).not.toHaveBeenCalled()
        expect(data.hasPreview).toBe(false)
    })
})

/*
 * ⭐ শূন্যে নকশার "৳0.00" — কোনো শব্দ নয়, কোনো চিহ্ন নয় (নকশা পাতা ৪: "বাকি থাকবে ৳0.00"; fe, ২৪ অক্টোবর ২০২৬)।
 * ⓘ "অগ্রিম" কেবল সত্যিই উল্টো দিকে গেলে — যেমন দেনা ৳0 আর দেওয়া ৳45,000 (তখন বাকি থাকবে ৳45,000 অগ্রিম)।
 */
describe('মাথার তিন অঙ্ক — শূন্যে শূন্যই', () => {
    const cases = [
        // [বর্ণনা, out, পক্ষের উত্তরের বকেয়া, লেখা অঙ্ক, পাওনা দেখায়, পাওনা অগ্রিম?, বাকি দেখায়, বাকি অগ্রিম?]
        ['রসিদ: বকেয়া শূন্য, অঙ্ক নেই', false, '0.0000', '', '৳0.00', false, '৳0.00', false],
        ['রসিদ: বকেয়া পুরো শোধ', false, '45000.0000', '45000', '৳45,000.00', false, '৳0.00', false],
        ['পরিশোধ: দেনা শূন্য, অঙ্ক নেই', true, '0', '', '৳0.00', false, '৳0.00', false],
        ['পরিশোধ: দেনা পুরো শোধ', true, '-120000.0000', '120000', '৳1,20,000.00', false, '৳0.00', false],
        ['পরিশোধ: পয়সার ভগ্নাংশেও শূন্য', true, '-0.001', '', '৳0.00', false, '৳0.00', false],
        ['পরিশোধ: দেনা শূন্য, দেওয়া ৳45,000 — সত্যিই অগ্রিম', true, '0', '45000', '৳0.00', false, '৳45,000.00', true],
    ]

    for (const [what, out, due, amount, owedText, owedAdvance, leftText, leftAdvance] of cases) {
        it(what, async () => {
            vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
            const data = await mount({ out, amount })
            clearTimeout(data.timer)
            data.heardParty({ due, hasParty: true })

            expect(data.headOwedText, `⛔ ${what}: পাওনার অঙ্ক নকশার মতো নয়`).toBe(owedText)
            expect(data.headOwedIsAdvance, `⛔ ${what}: শূন্য বা পাওনাকে "অগ্রিম" বলল`).toBe(owedAdvance)
            expect(data.headLeftText, `⛔ ${what}: বাকির অঙ্ক নকশার মতো নয়`).toBe(leftText)
            expect(data.headLeftIsAdvance, `⛔ ${what}: শূন্য বাকিকে "অগ্রিম" বলল`).toBe(leftAdvance)
            expect(`${data.headOwedText}${data.headLeftText}`, `⛔ ${what}: অঙ্কে চিহ্ন (−) বসল`).not.toMatch(/[-−]/)
        })
    }
})

describe('রেফারেন্সের চলতি উত্তর', () => {
    it('কেবল শেষ প্রশ্নের উত্তর বসে — দেরিতে আসা পুরনো "free" নতুন "used"-কে ঢাকে না', async () => {
        const pending = []
        vi.stubGlobal('fetch', vi.fn((url) => String(url).includes('reference-check')
            ? new Promise(resolve => pending.push(resolve))
            : Promise.resolve({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ amount: '1000' })
        document.querySelector('[name="instrument_no"]').value = 'NP1'

        const first = data.checkSlip()
        const second = data.checkSlip()
        pending[1]({ ok: true, json: async () => ({ state: 'used', message: 'আগে বসেছে' }) })
        await second
        pending[0]({ ok: true, json: async () => ({ state: 'free', message: 'আগে ব্যবহার হয়নি ✓' }) })
        await first

        expect(data.slipUsed).toBe(true)
        expect(data.slip.message).toBe('আগে বসেছে')
    })
})
