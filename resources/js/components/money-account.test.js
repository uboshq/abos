// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './index.js'
import { accountsForMethod, fitsMethod } from './money.js'

/*
 * ⭐ পদ্ধতি আগে, তারপর কেবল সেই পদ্ধতির খাত (মালিকের প্রশ্ন, ২৪ অক্টোবর ২০২৬, fe: SAP/Oracle-এর payment method → house bank)।
 *
 * ⓘ আসল CSP-Alpine-এ [[components.money.method]]-এর `accounts` পথ: MFS বাছলে তালিকায় কেবল MFS-এর খাত (নগদ বা ব্যাংকের একটাও
 * নেই, ধূসরও নয়); আগের খাত মিললে থাকে, না মিললে ফাঁকা আর কারণ; একটাই খাত থাকলে নিজে বসে; একটাও না থাকলে তালিকার জায়গায়
 * কারণ আর জমার বোতাম বন্ধ। হোস্টের `account` (স্থিতি, প্রিভিউ) প্রতিবার খবর পায়।
 * ⓘ ব্লেডের দিক আর সার্ভারের ফেরানো PHP-র দাবিতে ([[TheMethodComesFirstAndOffersOnlyItsAccountsTest]])।
 */

const warnings = []

beforeAll(() => {
    Alpine.setErrorHandler((error, el, expression) => {
        warnings.push(`Alpine Expression Error: ${error?.message} — ${expression}`)
    })

    // ⓘ হোস্টের নিজের মান — ভাউচার আর পরিশোধের পর্দায় `account`, যা স্থিতি আর প্রিভিউ দেখে
    Alpine.data('moneyHost', () => ({ account: '' }))
    window.Alpine = Alpine
    registerComponents(Alpine)
    Alpine.start()
})

async function settle () {
    await Alpine.nextTick()
    await new Promise(r => setTimeout(r, 0))
    await new Promise(r => (typeof requestAnimationFrame === 'function' ? requestAnimationFrame : setTimeout)(() => r()))
    await Alpine.nextTick()
}

async function mount (html) {
    warnings.length = 0
    const host = document.createElement('div')
    host.innerHTML = html
    document.body.appendChild(host)
    await settle()

    return host
}

const CASH = "{ id: '11', label: 'Cash in hand', kind: 'cash' }"
const PETTY = "{ id: '14', label: 'Petty cash', kind: 'cash' }"
const DBBL = "{ id: '12', label: 'Dutch-Bangla', kind: 'bank' }"
const CITY = "{ id: '15', label: 'City Bank', kind: 'bank' }"
const BKASH = "{ id: '13', label: 'bKash', kind: 'mfs' }"

/*
 * ⓘ ব্লেডের মতো: সার্ভার চলতি পদ্ধতির খাতগুলোই আঁকে, বাছাটা `selected`; ঘর হোস্টের `account`-এ বাঁধা। ⚠️ x-data একক উদ্ধৃতির
 * লেখা — দ্বৈত উদ্ধৃতি অ্যাট্রিবিউট শেষ করে দিত (a-test-mount-needs-the-pages-escaping)।
 */
const form = ({ method = 'cash', rows = [CASH, PETTY, DBBL, CITY, BKASH], options = '', picked = '' } = {}) => `
    <form x-data="moneyHost">
        <div data-money-method
             x-data="moneyMethod({ method: '${method}', amountField: 'amount', direction: 'out', accounts: [${rows.join(', ')}], methodNames: { cash: 'নগদ', mfs: 'MFS', transfer: 'ব্যাংক ট্রান্সফার', cheque: 'চেক' } })"
             data-account-none-line="এই কোম্পানিতে :method-এর কোনো খাত নেই — হিসাবরক্ষককে বলুন।"
             data-account-cleared-line="&quot;:account&quot; :method-এর খাত নয় — তাই খাতটা সরানো হলো।"
             data-line-in="" data-line-out="" data-head-bank="" data-head-mfs="">
            <input type="radio" name="instrument" value="cash" x-model="method">
            <input type="radio" name="instrument" value="mfs" x-model="method">
            <input type="radio" name="instrument" value="transfer" x-model="method">
            <input type="radio" name="instrument" value="cheque" x-model="method">
            <div data-money-account-row>
                <select name="account_id" data-money-account x-model="account" x-show="hasAccounts">
                    <option value="">—</option>
                    ${options}
                </select>
                <p data-account-none x-show="! hasAccounts" x-text="noAccountsLine"></p>
                <p data-account-cleared x-show="clearedLine !== ''" x-text="clearedLine"></p>
            </div>
        </div>
        <button type="submit">Post</button>
    </form>`

const listed = host => [...host.querySelectorAll('select[data-money-account] option')].map(o => o.value).filter(v => v !== '')
const hostAccount = host => Alpine.$data(host.querySelector('form')).account
const methodOf = host => Alpine.$data(host.querySelector('[data-money-method]'))
const cashOptions = '<option value="11" data-kind="cash">Cash in hand</option><option value="14" data-kind="cash">Petty cash</option>'

async function pick (host, value) {
    const select = host.querySelector('select[data-money-account]')
    select.value = value
    select.dispatchEvent(new Event('change', { bubbles: true }))
    await settle()
}

async function method (host, value) {
    methodOf(host).method = value
    await settle()
}

describe('পদ্ধতি → কেবল সেই পদ্ধতির খাত', () => {
    it('নিয়ম: নগদে নগদ, MFS-এ MFS, ব্যাংকের চার পথে কেবল ব্যাংক — MFS ব্যাংক নয়', () => {
        expect(fitsMethod('mfs', 'cash'), '⛔ MFS-এ নগদের খাত চলে গেল').toBe(false)
        expect(fitsMethod('mfs', 'bank'), '⛔ MFS-এ ব্যাংকের খাত চলে গেল').toBe(false)
        expect(fitsMethod('cash', 'mfs'), '⛔ নগদে MFS-এর খাত চলে গেল ("Roket" বেছে নগদ)').toBe(false)
        expect(fitsMethod('transfer', 'mfs'), '⛔ ব্যাংক ট্রান্সফারে MFS-এর খাত চলে গেল').toBe(false)
        expect(['transfer', 'deposit', 'cheque', 'card'].every(m => fitsMethod(m, 'bank')), '⛔ ব্যাংকের কোনো পথে ব্যাংকের খাত চলে না').toBe(true)
        expect(fitsMethod('mfs', 'mfs') && fitsMethod('cash', 'cash'), '⛔ নিজের ধরনের খাতই চলে না').toBe(true)
    })

    it('MFS বাছলে তালিকায় কেবল MFS-এর খাত — নগদ বা ব্যাংকের একটাও নেই, আর একটাই বলে নিজে বসে', async () => {
        const host = await mount(form({ options: cashOptions }))

        expect(listed(host), '⛔ নগদে শুরু করে তালিকায় নগদের দুই খাতের বাইরেও কিছু').toEqual(['11', '14'])

        await method(host, 'mfs')
        expect(listed(host), '⛔ MFS বাছার পরেও তালিকায় নগদ বা ব্যাংকের খাত আছে').toEqual(['13'])
        expect(host.querySelector('select[data-money-account]').value, '⛔ MFS-এর একটাই খাত, অথচ নিজে বসল না').toBe('13')
        expect(hostAccount(host), '⛔ নিজে বসা খাতের খবর হোস্টের account পায়নি — স্থিতি আর প্রিভিউ পুরনো খাত দেখাত').toBe('13')
        expect(warnings, '⛔ পর্দায় Alpine-এর সতর্কতা').toEqual([])
        host.remove()
    })

    it('পদ্ধতি বদলালে আগের খাত মিললে থাকে — ট্রান্সফার থেকে চেক, একই ব্যাংক', async () => {
        const banks = '<option value="12" data-kind="bank">Dutch-Bangla</option><option value="15" data-kind="bank">City Bank</option>'
        const host = await mount(form({ method: 'transfer', options: banks }))

        await pick(host, '15')
        await method(host, 'cheque')

        expect(listed(host), '⛔ চেকে ব্যাংকের খাতগুলো নেই').toEqual(['12', '15'])
        expect(host.querySelector('select[data-money-account]').value, '⛔ চেকেও চলে এমন ব্যাংক, অথচ বাছা খাতটা সরে গেল').toBe('15')
        expect(host.querySelector('[data-account-cleared]').style.display, '⛔ খাত সরেনি, অথচ "সরানো হলো" লেখা এল').toBe('none')
        host.remove()
    })

    it('না মিললে খাত ফাঁকা হয়, আর কারণ বলে — ব্যাংক বাছা ছিল, তারপর নগদ', async () => {
        const banks = '<option value="12" data-kind="bank">Dutch-Bangla</option><option value="15" data-kind="bank">City Bank</option>'
        const host = await mount(form({ method: 'transfer', options: banks }))

        await pick(host, '12')
        expect(hostAccount(host), 'দৃশ্যটাই ঠিক নয় — ব্যাংক বাছা হয়নি').toBe('12')

        await method(host, 'cash')
        expect(listed(host), '⛔ নগদে ব্যাংকের খাত তালিকায় রয়ে গেল').toEqual(['11', '14'])
        expect(host.querySelector('select[data-money-account]').value, '⛔ নগদে ব্যাংকের খাত বাছা রয়ে গেল').toBe('')
        expect(hostAccount(host), '⛔ খাত সরল, অথচ হোস্ট এখনো ব্যাংকের খাত ধরে আছে').toBe('')
        const why = host.querySelector('[data-account-cleared]')
        expect(why.style.display, '⛔ খাত সরল, কারণ দেখা যায় না').not.toBe('none')
        expect(why.textContent, '⛔ কারণে সরানো খাতের নাম আর নতুন পদ্ধতি নেই').toBe('"Dutch-Bangla" নগদ-এর খাত নয় — তাই খাতটা সরানো হলো।')
        host.remove()
    })

    it('কোনো পদ্ধতির একটাও খাত না থাকলে তালিকার জায়গায় বাংলায় কারণ, আর জমার বোতাম বন্ধ — ফিরলে খোলে', async () => {
        const host = await mount(form({ rows: [CASH, PETTY, DBBL], options: cashOptions }))
        const button = host.querySelector('button[type="submit"]')

        await method(host, 'mfs')
        expect(listed(host), '⛔ MFS-এর খাত নেই, অথচ তালিকায় অন্য খাত').toEqual([])
        expect(host.querySelector('select[data-money-account]').style.display, '⛔ খাত নেই, অথচ ফাঁকা তালিকাটা দেখা যায়').toBe('none')
        const none = host.querySelector('[data-account-none]')
        expect(none.style.display, '⛔ খাত নেই, অথচ কারণ দেখা যায় না').not.toBe('none')
        expect(none.textContent, '⛔ কারণে পদ্ধতির নাম নেই').toBe('এই কোম্পানিতে MFS-এর কোনো খাত নেই — হিসাবরক্ষককে বলুন।')
        expect(button.disabled, '⛔ খাত নেই, অথচ জমার বোতাম চাপা যায়').toBe(true)

        await method(host, 'cash')
        expect(button.disabled, '⛔ নগদে ফিরে খাত পাওয়া গেল, অথচ জমার বোতাম বন্ধই রইল').toBe(false)
        expect(warnings, '⛔ পর্দায় Alpine-এর সতর্কতা').toEqual([])
        host.remove()
    })

    it('হোস্টের নিজের বন্ধ বোতাম বাক্স খোলে না', async () => {
        const host = await mount(form({ rows: [CASH, PETTY], options: cashOptions }))
        const button = host.querySelector('button[type="submit"]')
        button.disabled = true

        await method(host, 'mfs')
        await method(host, 'cash')
        expect(button.disabled, '⛔ হোস্ট নিজে যে বোতাম বন্ধ রেখেছিল, বাক্স সেটা খুলে দিল').toBe(true)
        host.remove()
    })

    it('হিসাব: আগেরটা মিললে থাকে, না মিললে সরে, একটাই হলে বসে', () => {
        const rows = [{ id: 11, label: 'Cash', kind: 'cash' }, { id: 12, label: 'DBBL', kind: 'bank' }, { id: 13, label: 'bKash', kind: 'mfs' }]

        expect(accountsForMethod(rows, 'mfs', '12'), '⛔ MFS-এ ব্যাংকের খাত রইল, বা একমাত্র MFS বসল না').toEqual({ list: [rows[2]], picked: '13', dropped: rows[1] })
        expect(accountsForMethod(rows, 'cheque', '12'), '⛔ চেকে মিলে যাওয়া ব্যাংক সরল').toEqual({ list: [rows[1]], picked: '12', dropped: null })
    })
})
