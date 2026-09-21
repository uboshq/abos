// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './index.js'
import { bankFacilityForm } from './forms.js'
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
    /*
     * ⓘ Alpine নিজে ভুলটা কনসোলে লেখে, তারপর `setTimeout`-এ আবার **ছুঁড়ে
     * দেয়**। ⛔ পরীক্ষায় সেটা vitest-এর কাছে "unhandled error" — সব পরীক্ষা
     * পাস করলেও `npm test` exit 1 দিত, আর "কমিটের আগে npm test" নিয়মটা
     * সবার জন্য লাল থাকত (১৯ সেপ্টেম্বর ২০২৬, abos-45 ধরেছে)।
     *
     * ⭐ তাই ভুলটা এখানে ধরে রাখা হয়, আর দাবিগুলো সেটাই পড়ে — ছুঁড়ে
     * দেওয়া হয় না।
     */
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

/*
 * ব্যাংকের সুবিধার ফর্ম — কিস্তির অঙ্ক দুই দিকেই, আর শাখা নিজে থেকে।
 * ২০ সেপ্টেম্বর ২০২৬, মালিকের নির্দেশে।
 */
describe('ব্যাংকের সুবিধা', () => {
    it('সীমা, হার ও সংখ্যা দিলে কিস্তির অঙ্ক বসে', () => {
        const f = bankFacilityForm({ kind: 'term' })

        f.limit = '1200000'
        f.rate = '10'
        f.count = '12'
        f.fromTerms()

        // ⓘ ক্ষয়িষ্ণু জেরে ১২ কিস্তি — সরল সুদে হত ১,১০,০০০
        expect(f.instalment).toBe('105499.06')
    })

    it('⭐ মালিকের ব্যাংকের ক্যালকুলেটরের সংখ্যাতেই মিলে', () => {
        const f = bankFacilityForm({ kind: 'term' })

        f.limit = '2500000'
        f.rate = '13.55'
        f.count = '36'
        f.fromTerms()

        // ⓘ ব্যাংকের অ্যাপ বলে ৮৪,৮৯৮.৯৬ — ৮৯ পয়সার ফারাক, ওরা
        // মাসিক হারটা ষষ্ঠ দশমিকে ছাঁটে বলে। ⛔ পয়সা মেলাতে গিয়ে
        // হার ছাঁটা মানে ভুলকে নকল করা।
        expect(f.instalment).toBe('84898.69')
    })

    it('⭐ আর কিস্তির অঙ্ক টাইপ করলে হার নতুন করে বসে — উল্টো দিকটাও', () => {
        const f = bankFacilityForm({ kind: 'term' })

        f.limit = '1200000'
        f.count = '12'
        f.instalment = '105499.06'
        f.fromInstalment()

        expect(f.rate).toBe('10.00')
    })

    it('⛔ অসম্পূর্ণ ঘরে কিছুই বসায় না — মানুষের টাইপ করা জিনিস মুছে যায় না', () => {
        const f = bankFacilityForm({ kind: 'term' })

        f.limit = ''
        f.count = '12'
        f.instalment = '110000'
        f.fromInstalment()

        expect(f.rate).toBe('')
    })

    /*
     * ⭐ চলতি ঋণের তিনটা ঘর — মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬:
     * *"যেকোনো একটা দিলে বাকিগুলো auto হবে"*।
     *
     * ⓘ নমুনাটা এক জায়গা থেকেই: ১২,০০,০০০ · ১০% · ১২ কিস্তি, অর্থাৎ
     * কিস্তি ১,০৫,৪৯৯.০৬ (উপরের দাবিটার সংখ্যা)। ৪টা দেওয়া হলে বাকি ৮।
     */
    const running = () => {
        const f = bankFacilityForm({ kind: 'term', running: true })

        f.limit = '1200000'
        f.rate = '10'
        f.count = '12'
        f.instalment = '105499.06'

        return f
    }

    it('কয়টা দেওয়া লিখলে বাকি কিস্তি আর আজকের বকেয়া বসে', () => {
        const f = running()

        f.paid = '4'
        f.fromPaid()

        expect(f.left).toBe('8')

        /*
         * ⛔ ৮ × ১,০৫,৪৯৯.০৬ = ৮,৪৩,৯৯২.৪৮ — আর সেটাই ভুল উত্তর।
         *
         * ⚠️ ওটা গুণ করলে ভবিষ্যতের আট মাসের সুদটাও আজকের বকেয়ায়
         * ঢুকে যায়। ⓘ ক্ষয়িষ্ণু জেরে বাকি কিস্তিগুলোর বর্তমান মূল্য
         * প্রায় ৮.১৪ লাখ — ৩০ হাজার টাকার ফারাক, আর খাতায় দায়টা
         * ব্যাংকের কাগজের চেয়ে বড় হয়ে বসত।
         */
        expect(parseFloat(f.outstanding)).toBeCloseTo(813202.17, 0)
        expect(parseFloat(f.outstanding)).toBeLessThan(8 * 105499.06)
    })

    it('বাকি কিস্তি লিখলে উল্টো দিকটাও বসে', () => {
        const f = running()

        f.left = '8'
        f.fromLeft()

        expect(f.paid).toBe('4')
        expect(parseFloat(f.outstanding)).toBeCloseTo(813202.17, 0)
    })

    /*
     * ⭐ আর এই দিকটাই সবচেয়ে বেশি কাজে লাগবে — ব্যাংকের কাগজে কিস্তির
     * গোনা লেখা থাকে না, আজকের বকেয়াটা লেখা থাকে।
     */
    it('আজকের বকেয়া লিখলে কয়টা কিস্তি বাকি তা বেরোয়', () => {
        const f = running()

        f.outstanding = '813202.17'
        f.fromOutstanding()

        expect(f.left).toBe('8')
        expect(f.paid).toBe('4')
    })

    /*
     * ⛔ অসম্পূর্ণ ঘরে কিছুই বসে না — উপরের নিয়মটাই এখানেও।
     *
     * ⚠️ কিস্তির অঙ্ক না জানলে বকেয়া গোনার মতো কিছুই নেই, আর ০ বসিয়ে
     * দিলে সেটা একটা **উত্তর** হিসেবে পড়া হত।
     */
    it('কিস্তির অঙ্ক না জানা থাকলে বকেয়ার ঘরটা ছোঁয়া হয় না', () => {
        const f = bankFacilityForm({ kind: 'term', running: true })

        f.count = '12'
        f.paid = '4'
        f.fromPaid()

        expect(f.left).toBe('8')
        expect(f.outstanding).toBe('')
    })

    /*
     * ⛔ দেওয়া কিস্তি মোট কিস্তির চেয়ে বেশি হতে পারে না।
     *
     * ⓘ টাইপ করার মাঝপথে ("1" → "14") এটা ঘটেই, তাই ঘরগুলো তখন
     * ছোঁয়াই হয় না — নাহলে বাকি কিস্তি ঋণাত্মক হয়ে বসত।
     */
    it('মোটের চেয়ে বেশি কিস্তি দিলে কিছুই বদলায় না', () => {
        const f = running()

        f.paid = '14'
        f.fromPaid()

        expect(f.left).toBe('')
        expect(f.outstanding).toBe('')
    })

    it('ব্যাংক বাছলে শাখা বসে, কিন্তু টাইপ করা শাখা অক্ষত থাকে', () => {
        const f = bankFacilityForm({ branches: { 3: 'Gulshan' } })

        f.pickedBank(3)
        expect(f.branch).toBe('Gulshan')

        const typed = bankFacilityForm({ branches: { 3: 'Gulshan' } })
        typed.branch = 'Banani'
        typed.branchTyped()
        typed.pickedBank(3)

        expect(typed.branch).toBe('Banani')
    })

    it('⛔ প্রতিষ্ঠানের শাখা লেখা না থাকলে ঘরটা খালিও করে না', () => {
        const f = bankFacilityForm({ branches: {} })

        f.branch = 'Mirpur'
        f.pickedBank(9)

        expect(f.branch).toBe('Mirpur')
    })

    it('ধরন অনুযায়ী কোন ঘর থাকবে — নবায়ন শুধু CC-তে, কিস্তি গ্যারান্টিতে নয়', () => {
        const cc = bankFacilityForm({ kind: 'cc' })
        const term = bankFacilityForm({ kind: 'term' })
        const bg = bankFacilityForm({ kind: 'bg' })

        expect([cc.hasRenewal, cc.hasInstalments, cc.hasExpiry]).toEqual([true, false, false])
        expect([term.hasRenewal, term.hasInstalments, term.hasInterest]).toEqual([false, true, true])
        expect([bg.hasExpiry, bg.hasInstalments, bg.hasInterest]).toEqual([true, false, false])
    })
})
