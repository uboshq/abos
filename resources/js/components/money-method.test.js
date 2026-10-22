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
        expect(methodForKind('cash', 'transfer')).toBe('cash')
        expect(methodForKind('mfs', 'cash')).toBe('mfs')
        expect(methodForKind('bank', 'cash')).toBe('transfer')
        expect(methodForKind('bank', 'deposit')).toBe('deposit')
        expect(methodForKind('bank', 'cheque')).toBe('cheque')
        expect(methodForKind('', 'mfs')).toBe('mfs')
    })

    it('পর্দায়: ব্যাংক বাছলে চিপ ট্রান্সফারে, MFS বাছলে MFS-এ', async () => {
        const host = await mount(form())

        await choose(host, '12')
        expect(picked(host)).toBe('transfer')
        await choose(host, '13')
        expect(picked(host)).toBe('mfs')
        expect(warnings).toEqual([])
        host.remove()
    })
})

describe('দফা #৯ — চার্জ আমাদের খরচ, এক লাইনে', () => {
    it('লাইনে চার্জ, চার্জের খাত আর পক্ষের পুরো টাকা — লাখ-কমায়', () => {
        expect(chargeLine('৳:charge → :head; ৳:amount', { charge: 25, amount: 120000, head: 'Bank charges' }))
            .toBe('৳25.00 → Bank charges; ৳1,20,000.00')
    })

    it('ট্রান্সফারে চার্জ দিলে লাইন আসে, ব্যাংকের খাত বলে; MFS-এ MFS-এর খাত; নগদে গেলে লাইন যায়', async () => {
        const host = await mount(form('transfer'))
        const line = host.querySelector('[data-charge-line]')

        expect(line.style.display).toBe('none')

        const charge = host.querySelector('#charge')
        charge.value = '25'
        charge.dispatchEvent(new Event('input', { bubbles: true }))
        await Alpine.nextTick()

        expect(line.style.display).not.toBe('none')
        expect(line.textContent).toBe('OUT ৳25.00 → Bank charges; party ৳1,20,000.00')

        data(host).method = 'mfs'
        await Alpine.nextTick()
        expect(line.textContent).toContain('MFS charges')

        // ⛔ নগদে চার্জের ঘর নেই — পুরনো ২৫ লাইনে থেকে গেলে পর্দা মিথ্যা বলত
        data(host).method = 'cash'
        await Alpine.nextTick()
        expect(line.style.display).toBe('none')
        expect(warnings).toEqual([])
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
        expect(heard.some(d => d.method === 'transfer' && d.charge === 40)).toBe(true)
        expect(heard.at(-1)).toEqual({ method: 'cash', charge: 0 })
        host.remove()
    })
})
