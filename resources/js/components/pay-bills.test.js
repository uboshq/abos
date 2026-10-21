// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { registerComponents } from './index.js'

/*
 * ⭐ টাকা দেওয়ার পর্দার প্রিভিউ — দফা #১০ (মালিক, ২১ অক্টোবর ২০২৬; [[payBills]])।
 *
 * ⛔ ১৩৬৬-এর মিল-তালিকা বানাতে গিয়ে ধরা (২২ অক্টোবর ২০২৬): চার্জ লেখার পরে প্রিভিউতে চার্জের সারি নেই, ব্যাংক থেকে ১,১৪,০০০ —
 * কারণ আগের প্রশ্নের (চার্জ ছাড়া) উত্তর দেরিতে এসে নতুনটাকে ঢেকে দিত। এখন কেবল শেষ প্রশ্নের উত্তর বসে।
 */

beforeAll(() => {
    window.Alpine = Alpine
    registerComponents(Alpine)
    Alpine.start()
})

afterEach(() => {
    vi.unstubAllGlobals()
})

const answer = (rows) => ({ rows, debit: '1', credit: '1', balanced: true, ait: '0.00', vds: '0.00', net: '1.00' })

async function mount () {
    const host = document.createElement('div')
    host.innerHTML = `
        <form data-pay-screen
              x-data="payBills({ bills: [], supplier: '7', amount: '120000', account: '', picks: {}, balances: {}, taxIds: {}, bankIds: [], simulateUrl: '/purchase/payments/simulate' })">
            <input name="_token" value="t">
            <input name="_once" value="one-time">
            <span data-rows x-text="preview.rows.length"></span>
        </form>`
    document.body.appendChild(host)
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()

    return host
}

describe('দফা #১০ — প্রিভিউতে কেবল শেষ প্রশ্নের উত্তর', () => {
    it('উল্টো ক্রমে উত্তর এলেও শেষ প্রশ্নের চার্জের সারি টিকে থাকে', async () => {
        const pending = []
        vi.stubGlobal('fetch', vi.fn(() => new Promise(resolve => pending.push(resolve))))

        const host = await mount()
        const data = Alpine.$data(host.querySelector('form'))
        // ⓘ শুরুর প্রিভিউর টাইমার বাদ — নিজে দুইটা প্রশ্ন, ক্রমে
        clearTimeout(data.timer)
        const first = data.simulate()     // চার্জের আগে
        const second = data.simulate()    // চার্জের পরে

        expect(pending.length).toBe(2)

        // শেষ প্রশ্নের উত্তর আগে আসে (চার্জের সারিসহ), তারপর পুরনোটা (চার্জ ছাড়া)
        pending[1]({ ok: true, json: async () => answer([{ account: '2111' }, { account: '5210 ব্যাংক চার্জ' }, { account: '1102' }]) })
        await second
        pending[0]({ ok: true, json: async () => answer([{ account: '2111' }, { account: '1102' }]) })
        await first
        await Alpine.nextTick()

        expect(data.preview.rows.map(r => r.account), '⛔ #১০: দেরিতে-আসা পুরনো উত্তর নতুন প্রিভিউ ঢেকে দিল — চার্জের সারি হারাল')
            .toContain('5210 ব্যাংক চার্জ')
        host.remove()
    })

    it('প্রিভিউ ফর্মের একবারের জমার টোকেন পাঠায় না', async () => {
        const bodies = []
        vi.stubGlobal('fetch', vi.fn((url, init) => { bodies.push(init.body); return Promise.resolve({ ok: true, json: async () => answer([]) }) }))

        const host = await mount()
        const data = Alpine.$data(host.querySelector('form'))
        clearTimeout(data.timer)
        await data.simulate()

        expect(bodies.at(-1).has('_once'), '⛔ #১০: প্রিভিউ একবারের টোকেন খরচ করল — পরে আসল "পোস্ট" ফিরে আসত').toBe(false)
        expect(bodies.at(-1).get('_token')).toBe('t')
        host.remove()
    })
})

describe('মিল-তালিকা #৯ — মোট টাকার ঘর লাখ-কমায়', () => {
    it('ঘর ছাড়লে ১,২০,০০০.০০ দেখায়, আর হাতে বসানো প্রয়োগ মুছে যায় না', async () => {
        const host = document.createElement('div')
        host.innerHTML = `
            <form x-data="payBills({ bills: [{ id: 1, supplier: '7', remaining: '120000', total: '120000' }], supplier: '7', amount: '', account: '', picks: {}, balances: {}, taxIds: {}, bankIds: [], simulateUrl: '' })">
                <input name="amount" x-model="amount" @change="tidyAmount()">
            </form>`
        document.body.appendChild(host)
        await new Promise(r => setTimeout(r, 0))
        await Alpine.nextTick()

        const form = host.querySelector('form')
        const field = host.querySelector('[name="amount"]')
        const pay = Alpine.$data(form)

        field.value = '120000'
        field.dispatchEvent(new Event('input'))
        await Alpine.nextTick()
        expect(pay.shown(1), 'অঙ্ক লিখলে বিলে বসেনি').toBe('120000.00')

        pay.typed(1, '50000')
        field.dispatchEvent(new Event('change'))
        await Alpine.nextTick()
        await Alpine.nextTick()

        expect(field.value, '⛔ #৯: ঘর ছাড়ার পরেও লাখ-কমা নেই').toBe('1,20,000.00')
        expect(pay.shown(1), '⛔ #৯: কমা বসাতে গিয়ে হাতে বসানো প্রয়োগ মুছে গেল').toBe('50000.00')
        host.remove()
    })
})

describe('মিল-তালিকা #৫ — স্লিপ/TrxID লেখার সাথে সাথে যাচাই', () => {
    it('লিখলে সরবরাহকারী আর নম্বর নিয়ে জিজ্ঞেস করে, উত্তরটা দেখায়, আর কেবল শেষ প্রশ্নের উত্তর রাখে', async () => {
        const asked = []
        let release
        vi.stubGlobal('fetch', vi.fn((url) => {
            asked.push(url)
            const no = new URL(url, 'http://x').searchParams.get('instrument_no')
            const body = no === 'OLD-1'
                ? { state: 'used', message: 'আগে দেওয়া' }
                : { state: 'free', message: 'নতুন ✓' }
            // ⓘ প্রথম প্রশ্নের উত্তর দেরিতে আসে
            if (no === 'OLD-1') return new Promise(r => { release = () => r({ ok: true, json: async () => body }) })
            return Promise.resolve({ ok: true, json: async () => body })
        }))

        const host = document.createElement('div')
        host.innerHTML = `
            <form x-data="payBills({ bills: [], supplier: '7', amount: '', account: '', picks: {}, balances: {}, taxIds: {}, bankIds: [], simulateUrl: '', slipUrl: '/purchase/payments/slip-check', paymentId: 41 })">
                <input name="instrument_no">
                <p data-slip-state x-show="slipShown" x-text="slip.message"></p>
            </form>`
        document.body.appendChild(host)
        await new Promise(r => setTimeout(r, 0))
        await Alpine.nextTick()

        const field = host.querySelector('[name="instrument_no"]')
        field.value = 'OLD-1'
        field.dispatchEvent(new Event('input', { bubbles: true }))
        await new Promise(r => setTimeout(r, 350))

        expect(asked.length, '⛔ #৫: লিখলেও যাচাইয়ের প্রশ্ন যায়নি').toBe(1)
        const q = new URL(asked[0], 'http://x').searchParams
        expect([q.get('supplier_id'), q.get('instrument_no'), q.get('payment')], '⛔ #৫: প্রশ্নে সরবরাহকারী, নম্বর বা নিজের কাগজ নেই').toEqual(['7', 'OLD-1', '41'])

        field.value = 'NEW-2'
        field.dispatchEvent(new Event('input', { bubbles: true }))
        await new Promise(r => setTimeout(r, 350))
        await Alpine.nextTick()
        release()
        await new Promise(r => setTimeout(r, 0))
        await Alpine.nextTick()

        expect(host.querySelector('[data-slip-state]').textContent, '⛔ #৫: আগের প্রশ্নের দেরিতে-আসা উত্তর নতুনটাকে ঢেকে দিল').toBe('নতুন ✓')
        host.remove()
    })
})
