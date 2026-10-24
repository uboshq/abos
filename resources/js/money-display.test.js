// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './components/index.js'
import { plainMoney, shownMoney } from './money-display.js'

/*
 * ⭐ টাকার ঘর — দেখায় লাখ-কমায়, পাঠায় কাঁচা অঙ্ক ([[registerMoneyDisplay]]; fe, ২৪ অক্টোবর ২০২৬)।
 *
 * ⓘ আসল CSP-Alpine-এ, পর্দার মতো দুই ঘর: দেখানোর ঘর (name নেই, x-money-display) আর পাঠানোর লুকানো ঘর (name, :value)।
 * ⛔ আগে ঘরটাই কমাওয়ালা লেখা পাঠাত — সার্ভার ছেঁটে পড়ত, কিন্তু প্রতিটা অনুরোধকে মনে রাখতে হত।
 */

const warnings = []

beforeAll(() => {
    Alpine.setErrorHandler((error, el, expression) => {
        warnings.push(`Alpine Expression Error: ${error?.message} — ${expression}`)
    })
    window.Alpine = Alpine
    registerComponents(Alpine)
    Alpine.start()
})

/** ⓘ x-data একক উদ্ধৃতিতে — JSON-এর " অ্যাট্রিবিউট শেষ করে দিত (a-test-mount-needs-the-pages-escaping) */
async function mount ({ amount = '', charge = '' } = {}) {
    warnings.length = 0
    document.body.innerHTML = `
        <form x-data="{ amount: '${amount}', charge: '${charge}' }">
            <input id="amount-shown" type="text" x-money-display="amount">
            <input type="hidden" name="amount" :value="amount">
            <input id="charge-shown" type="text" x-money-display="charge" data-money-empty="0.00">
            <input type="hidden" name="charge_amount" :value="charge">
        </form>`
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()

    const form = document.querySelector('form')

    return {
        form,
        data: Alpine.$data(form),
        shown: document.getElementById('amount-shown'),
        sent: form.querySelector('[name="amount"]'),
        charge: document.getElementById('charge-shown'),
        chargeSent: form.querySelector('[name="charge_amount"]'),
    }
}

async function type (field, text) {
    field.focus()
    field.value = text
    field.dispatchEvent(new Event('input', { bubbles: true }))
    await Alpine.nextTick()
}

async function leave (field) {
    field.blur()
    await Alpine.nextTick()
}

describe('লেখা থেকে কাঁচা অঙ্ক', () => {
    it('বাংলা অঙ্ক, কমা আর ফাঁকা বাদ, একটাই বিন্দু থাকে', () => {
        expect(plainMoney('৪৮,৪৪০.৪৯'), '⛔ বাংলা অঙ্ক কাঁচা অঙ্কে বদলাল না').toBe('48440.49')
        expect(plainMoney(' 1,20,000.00 '), '⛔ কমা বা ফাঁকা রয়ে গেল').toBe('120000.00')
        expect(plainMoney('1.2.3'), '⛔ দুটো বিন্দু রইল — অঙ্ক নয়').toBe('1.23')
        expect(plainMoney('abc'), '⛔ অক্ষর অঙ্কে ঢুকল').toBe('')
        expect(shownMoney('', '0.00'), '⛔ ফাঁকা থাকলে "0.00" দেখায় না').toBe('0.00')
    })
})

describe('দেখানোর ঘর আর পাঠানোর ঘর', () => {
    it('বাংলা অঙ্কে লিখলেও মান কাঁচা অঙ্ক, আর ঘর ছাড়লে লাখ-কমা', async () => {
        const { data, shown, sent } = await mount()

        await type(shown, '৪৮৪৪০.৪৯')
        expect(data.amount, '⛔ বাংলা অঙ্কে লেখা মানে কাঁচা অঙ্ক হলো না').toBe('48440.49')
        expect(sent.value, '⛔ পাঠানোর ঘরে কাঁচা অঙ্ক নেই').toBe('48440.49')

        await leave(shown)
        expect(shown.value, '⛔ ঘর ছাড়লে লাখ-কমা আর দুই দশমিক দেখায় না').toBe('48,440.49')
        expect(sent.value, '⛔ লাখ-কমা পাঠানোর ঘরে ঢুকল').toBe('48440.49')
        expect(warnings).toEqual([])
    })

    it('লেখার সময় যা লেখা হচ্ছে তাই — "12." লিখতেই "12.00" হয়ে যায় না', async () => {
        const { shown } = await mount()

        await type(shown, '12.')
        expect(shown.value, '⛔ লেখার মাঝে ঘরের লেখা বদলে গেল — কার্সর লাফাত').toBe('12.')
    })

    it('ঘরে ঢুকলে কাঁচা অঙ্ক, শূন্যে ঢুকলে ফাঁকা', async () => {
        const { shown, charge } = await mount({ amount: '120000' })

        expect(shown.value, 'শুরুতে লাখ-কমা').toBe('1,20,000.00')
        shown.focus()
        shown.dispatchEvent(new Event('focus'))
        expect(shown.value, '⛔ ঘরে ঢুকলে লাখ-কমার লেখা রইল — কমা মুছে লিখতে হত').toBe('120000')

        expect(charge.value, '⛔ ফাঁকা চার্জে "0.00" দেখায় না').toBe('0.00')
        charge.focus()
        charge.dispatchEvent(new Event('focus'))
        expect(charge.value, '⛔ ফাঁকা চার্জে ঢুকলে "0.00" মুছে লিখতে হত').toBe('')
    })

    it('শুরুর লেখায় কমা থাকলেও (ফেরত-আসা পুরনো লেখা) পাঠানোর ঘর কাঁচা অঙ্ক পায়', async () => {
        const { data, sent, shown } = await mount({ amount: '1,20,000.00' })

        expect(data.amount, '⛔ শুরুর কমাওয়ালা লেখা মানে রয়ে গেল').toBe('120000.00')
        expect(sent.value, '⛔ শুরুর কমা পাঠানোর ঘরে গেল').toBe('120000.00')
        expect(shown.value).toBe('1,20,000.00')
    })

    it('ফর্ম যা পাঠায় (post, পপ-আপের FormData, দুবার জমা) তাতে কমা নেই, দেখানোর ঘরও নেই', async () => {
        const { form, shown, charge } = await mount()

        await type(shown, '4500000')
        await leave(shown)
        await type(charge, '1,250.5')
        await leave(charge)

        for (const round of [1, 2]) {
            const sent = [...new FormData(form).entries()]

            expect(sent, `⛔ ${round}: পাঠানো ঘর কাঁচা অঙ্কে নয়, বা দেখানোর ঘরও পাঠানো হলো`)
                .toEqual([['amount', '4500000'], ['charge_amount', '1250.5']])
            expect(sent.some(([, v]) => String(v).includes(',')), `⛔ ${round}: পাঠানো মানে কমা গেল`).toBe(false)
        }

        expect(shown.value, 'দেখানোয় লাখ-কমা').toBe('45,00,000.00')
        expect(charge.value).toBe('1,250.50')
    })
})
