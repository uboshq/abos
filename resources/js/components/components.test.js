// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './index.js'
import { countNotes } from './money.js'

/*
 * নতুন কম্পোনেন্টগুলো — আসল CSP-Alpine-এ বসিয়ে, পর্দায় যেভাবে বসে।
 *
 * ⓘ কেন এখানে, কেবল অঙ্কের পরীক্ষা নয়: ভুলটা প্রায়ই অঙ্কে থাকে না, থাকে
 * জোড়ায় — পর্দার এক্সপ্রেশন যে নামটা ডাকে সেটা কম্পোনেন্টে আছে কি না,
 * `this`-টা ঠিক জিনিস কি না। ⚠️ CSP-Alpine ভুল নাম পেলে পাতা ভাঙে না,
 * কেবল কনসোলে একটা সতর্কবার্তা দেয় — তাই এখানে সতর্কবার্তাও ধরা হয়।
 */

const warnings = []

beforeAll(() => {
    const warn = console.warn
    console.warn = (...args) => {
        warnings.push(String(args[0]))
        warn(...args)
    }

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

describe('ছোট সহায়কেরা ($str, $abs …)', () => {
    it('পর্দা থেকে ডাকা যায়, আর বৈশ্বিক নামের মতো আটকায় না', async () => {
        const host = await mount(`
            <div x-data="{ id: 7, gap: -12.5, picked: null }">
                <b id="s" x-text="$str(id) === '7' ? 'same' : 'diff'"></b>
                <b id="a" x-text="$abs(gap)"></b>
                <b id="f" x-text="$fixed('3')"></b>
                <b id="p" x-text="(picked && picked.name) || 'none'"></b>
            </div>`)

        expect(host.querySelector('#s').textContent).toBe('same')
        expect(host.querySelector('#a').textContent).toBe('12.5')
        expect(host.querySelector('#f').textContent).toBe('3.00')
        expect(host.querySelector('#p').textContent).toBe('none')
        expect(warnings).toEqual([])
    })

    it('⛔ আর আসল বৈশ্বিক নাম সত্যিই আটকায় — পরীক্ষাটা অন্ধ নয়', async () => {
        await mount('<div x-data="{ n: 1 }"><b x-text="Math.max(n, 2)"></b></div>')

        expect(warnings.join(' ')).toMatch('Math')
    })
})

describe('সার্ভারের মান, @js দিয়ে', () => {
    it('বাংলা লেখা বাংলাই থাকে — `\\u09ac` নয়', async () => {
        // ⓘ AlpineLiteral যা লেখে: সরল JSON, ইউনিকোড অক্ষত
        const host = await mount(`
            <div x-data="noteTally({ &quot;matches&quot;: &quot;মিলেছে 'ঠিক'&quot;, &quot;differs&quot;: &quot;x&quot; })">
                <b x-text="verdict"></b>
            </div>`)

        expect(host.querySelector('b').textContent).toBe("মিলেছে 'ঠিক'")
        expect(warnings).toEqual([])
    })
})

describe('নগদ গোনা', () => {
    it('নোটের যোগফল', () => {
        expect(countNotes({ 1000: 2, 500: '1', 10: '' })).toBe(2500)
    })

    it('না মিললে কত কম-বেশি তা কথায় বলে', async () => {
        const host = await mount(`
            <form>
                <input name="amount" value="1500">
                <fieldset x-data="noteTally({ matches: 'ok', differs: 'গোনা __C__, লেখা __W__, ফারাক __G__' })">
                    <input x-model.number="n[1000]">
                    <b x-text="verdict"></b>
                </fieldset>
            </form>`)

        const input = host.querySelector('fieldset input')
        input.value = '1'
        input.dispatchEvent(new Event('input'))
        await Alpine.nextTick()

        expect(host.querySelector('b').textContent)
            .toBe('গোনা ৳ 1,000.00, লেখা ৳ 1,500.00, ফারাক ৳ 500.00')
        expect(warnings).toEqual([])
    })
})

describe('সারির তালিকা', () => {
    it('খালি এলে একটা ফাঁকা সারি নিয়ে খোলে, আর শেষটা মুছলেও একটা থাকে', async () => {
        const host = await mount(`
            <div x-data="lineRows({ rows: [], blank: { qty: '' } })">
                <template x-for="(row, i) in rows" :key="i">
                    <p><input :name="'lines[' + i + '][qty]'"><button type="button" @click="remove(i)">x</button></p>
                </template>
            </div>`)

        expect(host.querySelectorAll('p')).toHaveLength(1)
        expect(host.querySelector('input').name).toBe('lines[0][qty]')

        host.querySelector('button').click()
        await Alpine.nextTick()

        expect(host.querySelectorAll('p')).toHaveLength(1)
        expect(warnings).toEqual([])
    })
})

describe('নতুন জমা', () => {
    it('ব্যক্তিগত ধরন বাছলে ধারক মালিকই — বদলানোর সাথে সাথে', async () => {
        const host = await mount(`
            <div x-data="depositOpener({ kinds: { '1': { shape: 'fixed', personal: false }, '2': { shape: 'dps', personal: true } }, kindId: '1', heldBy: 'business' })">
                <select x-model="kindId"><option value="1">1</option><option value="2">2</option></select>
                <b x-text="heldBy"></b><i x-text="shape"></i>
            </div>`)

        expect(host.querySelector('b').textContent).toBe('business')

        const select = host.querySelector('select')
        select.value = '2'
        select.dispatchEvent(new Event('change'))
        await Alpine.nextTick()
        await Alpine.nextTick()

        expect(host.querySelector('b').textContent).toBe('owner')
        expect(host.querySelector('i').textContent).toBe('dps')
        expect(warnings).toEqual([])
    })
})

describe('খরচের ভাগ', () => {
    it('হাতে লেখা ভাগ থাকে, বাকিটা অনুপাতে, আর শেষ সারি পয়সা মেলায়', async () => {
        const host = await mount(`
            <div x-data="expenseFields({ amount: 100, bills: [{ qty: 1 }, { qty: 1 }, { qty: 1 }], texts: { directLabel: 'D' } })">
                <input x-ref="share0" value="40">
                <input x-ref="share1">
                <input x-ref="share2">
                <b x-text="isDirect ? directLabel : 'no'"></b>
                <button type="button" @click="toggle(0, true)">0</button>
                <button type="button" @click="toggle(1, true)">1</button>
                <button type="button" @click="toggle(2, true)">2</button>
            </div>`)

        for (const button of host.querySelectorAll('button')) {
            button.click()
        }
        await Alpine.nextTick()

        const [a, b, c] = [...host.querySelectorAll('input')].map(i => i.value)

        expect(a).toBe('40')
        expect(Number(b) + Number(c)).toBeCloseTo(60, 2)
        expect(host.querySelector('b').textContent).toBe('D')
        expect(warnings).toEqual([])
    })
})

describe('নিয়ন্ত্রণ-প্যানেলের বদল গোনা', () => {
    it('টিক দিয়ে আবার তুলে নিলে বদল শূন্য — গোনা আগের মানের সাথে', async () => {
        const host = await mount(`
            <form x-data="switchBoard({ on: {} })" @change="touch($event.target)">
                <input type="checkbox" name="a" data-was="">
                <input type="text" name="b" value="x" data-was="x">
                <b x-text="'n' + count"></b>
            </form>`)

        const box = host.querySelector('[name=a]')
        box.click()
        await Alpine.nextTick()
        expect(host.querySelector('b').textContent).toBe('n1')

        box.click()
        await Alpine.nextTick()
        expect(host.querySelector('b').textContent).toBe('n0')
        expect(warnings).toEqual([])
    })
})
