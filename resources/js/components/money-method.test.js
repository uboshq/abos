// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './index.js'
import { chargeLine, methodForKind } from './money.js'

/*
 * ⭐ [[components.money.method]]-এর মগজ — দফা #২ আর #৯ (মালিক, ২১ অক্টোবর ২০২৬, fe-র মাধ্যমে)।
 *
 * ⓘ ব্লেডের মার্কআপ মাপে PHP-র দাবি ([[TheMoneyMethodAsksOnlyWhatItsMethodNeedsTest]]); এখানে আসল CSP-Alpine-এ আচরণ:
 * খাত বাছলে পদ্ধতি সরে, চার্জের লাইন পুরো টাকা আর চার্জের খাত বলে, পদ্ধতি বদলালে পুরনো চার্জ লাইনে থাকে না, আর বাইরে
 * `money-method` ঘটনা যায় (a5-এর আদায়ের সারাংশ শোনে)।
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

async function mount (html) {
    warnings.length = 0
    const host = document.createElement('div')
    host.innerHTML = html
    document.body.appendChild(host)
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()

    return host
}

const form = (method = 'cash', direction = 'out') => `
    <form>
        <input name="amount" value="120000">
        <select name="account_id">
            <option value="">—</option>
            <option value="11" data-kind="cash">Cash</option>
            <option value="12" data-kind="bank">Dutch-Bangla</option>
            <option value="13" data-kind="mfs">bKash</option>
        </select>
        <div data-money-method
             x-data="moneyMethod({ method: '${method}', charge: '', amountField: 'amount', chargeField: 'charge_amount', moneyField: 'account_id', direction: '${direction}' })"
             data-line-in="IN ৳:charge → :head; party ৳:amount"
             data-line-out="OUT ৳:charge → :head; party ৳:amount"
             data-head-bank="Bank charges" data-head-mfs="MFS charges">
            <input type="radio" name="instrument" value="cash" x-model="method">
            <input type="radio" name="instrument" value="mfs" x-model="method">
            <input type="radio" name="instrument" value="transfer" x-model="method">
            <input type="radio" name="instrument" value="deposit" x-model="method">
            <input type="radio" name="instrument" value="cheque" x-model="method">
            <template x-if="method === 'transfer'"><input id="charge" name="charge_amount" x-model="charge"></template>
            <template x-if="method === 'mfs'"><input id="mfs-charge" name="charge_amount" x-model="charge"></template>
            <p data-charge-line x-show="hasCharge" x-text="chargeLine"></p>
        </div>
    </form>`

const picked = host => host.querySelector('input[name="instrument"]:checked')?.value
const data = host => Alpine.$data(host.querySelector('[data-money-method]'))

async function choose (host, value) {
    const select = host.querySelector('select')
    select.value = value
    select.dispatchEvent(new Event('change', { bubbles: true }))
    await Alpine.nextTick()
}

describe('দফা #২ — খাতের ধরন দেখে পদ্ধতি', () => {
    it('নগদে নগদ, MFS-এ MFS, ব্যাংকে ট্রান্সফার — আর ব্যাংকের অন্য পথ (নগদ জমা, চেক) বাছা থাকলে সেটাই থাকে', () => {
        expect(methodForKind('cash', 'transfer'), '⛔ খাতের ধরন 'cash' হলে পদ্ধতি 'cash' নয়').toBe('cash')
        expect(methodForKind('mfs', 'cash'), '⛔ খাতের ধরন 'mfs' হলে পদ্ধতি 'mfs' নয়').toBe('mfs')
        expect(methodForKind('bank', 'cash'), '⛔ খাতের ধরন 'bank' হলে পদ্ধতি 'transfer' নয়').toBe('transfer')
        expect(methodForKind('bank', 'deposit'), '⛔ খাতের ধরন 'bank' হলে পদ্ধতি 'deposit' নয়').toBe('deposit')
        expect(methodForKind('bank', 'cheque'), '⛔ খাতের ধরন 'bank' হলে পদ্ধতি 'cheque' নয়').toBe('cheque')
        expect(methodForKind('', 'mfs'), '⛔ খাতের ধরন '' হলে পদ্ধতি 'mfs' নয়').toBe('mfs')
    })

    it('পর্দায়: ব্যাংক বাছলে চিপ ট্রান্সফারে, MFS বাছলে MFS-এ', async () => {
        const host = await mount(form())

        await choose(host, '12')
        expect(picked(host), '⛔ ব্যাংকের খাত বাছার পরেও পদ্ধতি ট্রান্সফারে সরেনি').toBe('transfer')
        await choose(host, '13')
        expect(picked(host), '⛔ MFS-এর খাত বাছার পরেও পদ্ধতি MFS-এ সরেনি').toBe('mfs')
        expect(warnings, '⛔ পর্দায় Alpine-এর সতর্কতা').toEqual([])
        host.remove()
    })
})

describe('দফা #৯ — চার্জ আমাদের খরচ, এক লাইনে', () => {
    it('লাইনে চার্জ, চার্জের খাত আর পক্ষের পুরো টাকা — লাখ-কমায়', () => {
        expect(chargeLine('৳:charge → :head; ৳:amount', { charge: 25, amount: 120000, head: 'Bank charges' }), '⛔ #৯: চার্জের লাইনে চার্জ, খাত বা লাখ-কমার পুরো টাকা নেই')
            .toBe('৳25.00 → Bank charges; ৳1,20,000.00')
    })

    it('ট্রান্সফারে চার্জ দিলে লাইন আসে, ব্যাংকের খাত বলে; MFS-এ MFS-এর খাত; নগদে গেলে লাইন যায়', async () => {
        const host = await mount(form('transfer'))
        const line = host.querySelector('[data-charge-line]')

        expect(line.style.display, '⛔ #৯: চার্জ না দিয়েই চার্জের লাইন দেখা গেল').toBe('none')

        const charge = host.querySelector('#charge')
        charge.value = '25'
        charge.dispatchEvent(new Event('input', { bubbles: true }))
        await Alpine.nextTick()

        expect(line.style.display, '⛔ #৯: ট্রান্সফারে চার্জ দিলেও চার্জের লাইন এল না').not.toBe('none')
        expect(line.textContent, '⛔ #৯: চার্জের লাইন ব্যাংক চার্জের খাত বা পক্ষের পুরো টাকা বলে না').toBe('OUT ৳25.00 → Bank charges; party ৳1,20,000.00')

        data(host).method = 'mfs'
        await Alpine.nextTick()
        expect(line.textContent, '⛔ #৯: MFS-এ চার্জ গেল ব্যাংক চার্জের খাতে').toContain('MFS charges')

        // ⛔ নগদে চার্জের ঘর নেই — পুরনো ২৫ লাইনে থেকে গেলে পর্দা মিথ্যা বলত
        data(host).method = 'cash'
        await Alpine.nextTick()
        expect(line.style.display, '⛔ #৯: নগদে ফিরলেও পুরনো চার্জের লাইন রয়ে গেল').toBe('none')
        expect(warnings, '⛔ পর্দায় Alpine-এর সতর্কতা').toEqual([])
        host.remove()
    })

    it('পদ্ধতি বা চার্জ বদলালে `money-method` ঘটনা যায় — পদ্ধতি আর চার্জসহ (আদায়ের সারাংশের জন্য)', async () => {
        const heard = []
        const listen = e => heard.push(e.detail)
        window.addEventListener('money-method', listen)

        const host = await mount(form('transfer', 'in'))
        const charge = host.querySelector('#charge')
        charge.value = '40'
        charge.dispatchEvent(new Event('input', { bubbles: true }))
        await Alpine.nextTick()
        await Alpine.nextTick()

        data(host).method = 'cash'
        await Alpine.nextTick()
        await Alpine.nextTick()

        window.removeEventListener('money-method', listen)
        expect(heard.some(d => d.method === 'transfer' && d.charge === 40), '⛔ চার্জ বদলেও `money-method` ঘটনা গেল না').toBe(true)
        expect(heard.at(-1), '⛔ নগদে ফিরলে ঘটনা চার্জ শূন্য আর পদ্ধতি নগদ বলে না').toEqual({ method: 'cash', charge: 0 })
        host.remove()
    })
})
