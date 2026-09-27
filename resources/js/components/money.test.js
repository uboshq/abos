// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './index.js'
import { wayForKind } from './money.js'

/*
 * টাকার মাধ্যমের ব্লক — আসল CSP-Alpine-এ, পর্দায় যেভাবে বসে।
 *
 * ── ⛔ লাইভে TCL-এ ধরা (hp2, ২৭ সেপ্টেম্বর ২০২৬) ─────────────────────
 *   ১. ব্যাংক খাত বাছার পরেও চিপ "নগদ"-এ থাকত — লেনদেন-নম্বরের ঘর আসতই না,
 *      অথচ সার্ভার সেটা চাইত।
 *   ২. ভুল নিয়ে ফর্ম ফিরলে মাধ্যম আবার "নগদ" — বাছা ট্রান্সফার আর লেখা
 *      নম্বর হারাত।
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

/** পর্দার মতোই: উপরে টাকার খাত, নিচে মাধ্যমের চিপ আর তার ঘর */
const form = (config) => `
    <form>
        <input name="amount" value="1000">
        <select name="to_account_id">
            <option value="">—</option>
            <option value="11" data-kind="cash">Cash</option>
            <option value="12" data-kind="bank">Dutch-Bangla</option>
            <option value="13" data-kind="mfs">bKash</option>
        </select>
        <div x-data="moneyMovement(${config})">
            <input type="radio" name="instrument" value="cash" x-model="method">
            <input type="radio" name="instrument" value="mfs" x-model="method">
            <input type="radio" name="instrument" value="transfer" x-model="method">
            <input type="radio" name="instrument" value="cheque" x-model="method">
            <template x-if="method === 'transfer'"><input id="ref" name="instrument_no"></template>
            <template x-if="method === 'mfs'"><input id="trx" name="instrument_no"></template>
        </div>
    </form>`

async function choose (host, value) {
    const select = host.querySelector('select')
    select.value = value
    select.dispatchEvent(new Event('change', { bubbles: true }))
    await Alpine.nextTick()
}

const picked = host => host.querySelector('input[name="instrument"]:checked')?.value

describe('খাতের ধরন দেখে মাধ্যম', () => {
    it('নগদে নগদ, MFS-এ MFS, ব্যাংকে ব্যাংকের পথ', () => {
        expect(wayForKind('cash', 'transfer')).toBe('cash')
        expect(wayForKind('mfs', 'cash')).toBe('mfs')
        expect(wayForKind('bank', 'cash')).toBe('transfer')
        expect(wayForKind('bank', 'cheque')).toBe('cheque')
        expect(wayForKind('', 'mfs')).toBe('mfs')
    })

    it('⛔ ব্যাংক বাছলে চিপ নগদ থেকে সরে, আর লেনদেন-নম্বরের ঘর আসে', async () => {
        const host = await mount(form("{ amountField: 'amount', moneyField: 'to_account_id' }"))

        expect(picked(host)).toBe('cash')
        expect(host.querySelector('#ref')).toBeNull()

        await choose(host, '12')

        expect(picked(host)).toBe('transfer')
        expect(host.querySelector('#ref'), 'ব্যাংক বাছার পরেও লেনদেন-নম্বরের ঘর আসেনি').not.toBeNull()
        expect(warnings).toEqual([])
    })

    it('বিকাশ বাছলে MFS, আর নগদ বাছলে আবার নগদ', async () => {
        const host = await mount(form("{ amountField: 'amount', moneyField: 'to_account_id' }"))

        await choose(host, '13')
        expect(picked(host)).toBe('mfs')
        expect(host.querySelector('#trx')).not.toBeNull()

        await choose(host, '11')
        expect(picked(host)).toBe('cash')
        expect(warnings).toEqual([])
    })
})

/*
 * হাতধার, আমানত, মূলধন, উত্তোলন আর ভাড়ার পর্দা — `<x-ui.money-account>`।
 *
 * ⛔ লাইভে TCL-এ (hp2, ২৭ সেপ্টেম্বর ২০২৬): ব্যাংক বাছার পরেও লেনদেন-নম্বরের
 * ঘর আসত না। ⚠️ ধরনটা `$refs.picker.selectedOptions` থেকে পড়া হত — যা
 * Alpine-এর চোখে বদলায় না, তাই `x-show` একবার মেপে আর কখনো মাপত না।
 */
const accountPicker = (chosen = '') => `
    <div x-data="moneyAccount({ chosen: '${chosen}' })">
        <select name="money_account_id" x-ref="picker" x-model="chosen">
            <option value="">—</option>
            <option value="21" data-kind="cash">Cash</option>
            <option value="22" data-kind="bank">Dutch-Bangla</option>
            <option value="23" data-kind="mfs">bKash</option>
        </select>
        <label id="ref" x-show="needsReference"><input name="instrument_no"></label>
    </div>`

describe('টাকার খাতের ঘর (হাতধার, আমানত …)', () => {
    it('⛔ ব্যাংক বাছলে লেনদেন-নম্বরের ঘর দেখা যায়, নগদে লুকায়', async () => {
        const host = await mount(accountPicker())
        const shown = () => host.querySelector('#ref').style.display !== 'none'

        expect(shown()).toBe(false)

        await choose(host, '22')
        expect(shown(), 'ব্যাংক বাছার পরেও লেনদেন-নম্বরের ঘর আসেনি').toBe(true)

        await choose(host, '21')
        expect(shown()).toBe(false)

        await choose(host, '23')
        expect(shown()).toBe(true)
        expect(warnings).toEqual([])
    })

    it('আগে থেকে বাছা ব্যাংক (সম্পাদনা, ভুলের পরে) — ঘরটা শুরু থেকেই দেখা যায়', async () => {
        const host = await mount(accountPicker('22'))

        expect(host.querySelector('#ref').style.display).not.toBe('none')
        expect(warnings).toEqual([])
    })
})

describe('ভুল নিয়ে ফিরলে মাধ্যম মনে থাকে', () => {
    it('⛔ সার্ভারের পুরনো মান — "নগদ"-এ ফিরে যায় না', async () => {
        const host = await mount(form("{ amountField: 'amount', method: 'transfer', charge: '15', moneyField: 'to_account_id' }"))

        expect(picked(host), 'ফিরে আসার পরে মাধ্যম আবার নগদ হয়ে গেছে').toBe('transfer')
        expect(host.querySelector('#ref')).not.toBeNull()
        expect(warnings).toEqual([])
    })

    it('খাত বলা না থাকলে (moneyField নেই) খাত বদলে কিছু হয় না', async () => {
        const host = await mount(form("{ amountField: 'amount', method: 'mfs' }"))

        await choose(host, '12')

        expect(picked(host)).toBe('mfs')
        expect(warnings).toEqual([])
    })
})
