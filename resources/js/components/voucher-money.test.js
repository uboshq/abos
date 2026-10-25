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

async function mount ({ out = false, amount = '', account = '229', balances = { 229: '1000.00' }, owedLabels = {}, branchLabels = {}, bankFileUrl = '' } = {}) {
    // ⛔ JSON-এর ডবল কোট অ্যাট্রিবিউট আগেই শেষ করে দিত (cb_v15: বাক্সটা জোড়াই লাগেনি, পাঁচটা দাবি "not a function") — একক কোটে লেখা
    const balanceText = JSON.stringify(balances).replace(/"/g, "'")
    const labelText = JSON.stringify(owedLabels).replace(/"/g, "'")
    const branchText = JSON.stringify(branchLabels).replace(/"/g, "'")
    const host = document.createElement('div')
    host.innerHTML = `
        <form x-data="voucherMoney({ amount: '${amount}', account: '${account}', balances: ${balanceText}, simulateUrl: '/accounts/vouchers/simulate', referenceUrl: '/accounts/vouchers/reference-check', out: ${out}, owedLabels: ${labelText}, branchLabels: ${branchText}, bankFileUrl: '${bankFileUrl}' })">
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

/*
 * ⭐ নকশার মাথা (পাতা ২ আর ৪; fe, ২৫ অক্টোবর ২০২৬): প্রথম অঙ্কের নাম পক্ষের ধরনে, রং — বকেয়া লাল, অগ্রিম সবুজ, শূন্য রং ছাড়া;
 * আর পক্ষের বাক্সের "পুরো বকেয়া · নির্বাচিত বিল" টাকার ঘরে পৌঁছায় (`heardFill`)।
 */
describe('মাথার নাম, রং আর এক চাপের অঙ্ক', () => {
    const owedLabels = { customer: 'গ্রাহকের বকেয়া', supplier: 'সরবরাহকারীর বকেয়া', '': 'পক্ষের বকেয়া' }

    it('প্রথম অঙ্কের নাম পক্ষের ধরনে, অজানায় সাধারণ নাম', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ owedLabels })
        clearTimeout(data.timer)

        data.heardParty({ due: '500', hasParty: true, partyType: 'customer' })
        expect(data.headOwedLabel, '⛔ গ্রাহকের রসিদে মাথার নাম "গ্রাহকের বকেয়া" নয়').toBe('গ্রাহকের বকেয়া')
        data.heardParty({ due: '500', hasParty: true, partyType: 'supplier' })
        expect(data.headOwedLabel, '⛔ ধরন বদলালেও মাথার নাম বদলায়নি').toBe('সরবরাহকারীর বকেয়া')
        data.heardParty({ due: '500', hasParty: true, partyType: 'employee' })
        expect(data.headOwedLabel, '⛔ অজানা ধরনে মাথার নাম ফাঁকা').toBe('পক্ষের বকেয়া')
    })

    it('বকেয়া লাল, অগ্রিম সবুজ, শূন্য রং ছাড়া', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ owedLabels })
        clearTimeout(data.timer)

        data.heardParty({ due: '500', hasParty: true, partyType: 'customer' })
        expect(data.headOwedClass, '⛔ বকেয়া লালে নয়').toBe('text-(--color-danger)')
        data.heardParty({ due: '-500', hasParty: true, partyType: 'customer' })
        expect(data.headOwedClass, '⛔ অগ্রিম লালে — সবুজে "অগ্রিম"-এর কথা').toBe('text-(--color-badge-success-ink)')
        data.heardParty({ due: '0', hasParty: true, partyType: 'customer' })
        expect(data.headOwedClass, '⛔ শূন্য বকেয়া রঙিন — নকশায় "৳0.00" রং ছাড়া').toBe('')
    })

    it('এক চাপের অঙ্ক টাকার ঘরে কাঁচা অঙ্কে বসে; অ-সংখ্যা কিছু বদলায় না', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ amount: '100' })
        clearTimeout(data.timer)

        data.heardFill('6,860.00')
        expect(data.amount, '⛔ "পুরো বকেয়া" টাকার ঘরে বসেনি, বা কমাসহ বসল').toBe('6860.00')
        data.heardFill('abc')
        expect(data.amount, '⛔ অ-সংখ্যা এসে টাকার ঘর বদলাল').toBe('6860.00')
        data.heardFill('')
        expect(data.amount, '⛔ ফাঁকা খবরে টাকার ঘর মুছল').toBe('6860.00')
    })
})

/*
 * ⭐ পরিশোধে লেখা ঘরটা মোট (`gross_amount`, পরিশোধে-মোট-টাকা-চুক্তি, ২৫ অক্টোবর ২০২৬): মাথার "এই পরিশোধ (মোট)" ঠিক লেখা অঙ্কটাই,
 * আর খাত থেকে বেরোয় নিট (মোট − AIT − VDS) + চার্জ — "কম" সেটাই মাপে।
 */
describe('পরিশোধে মোট টাকা', () => {
    it('মাথার "এই পরিশোধ (মোট)" লেখা মোটই — কাটা আবার যোগ হয় না', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ out: true, amount: '1000' })
        clearTimeout(data.timer)
        data.ait = '50'
        data.vds = '75'
        data.heardParty({ due: '-1000', hasParty: true, partyType: 'supplier' })

        expect(data.headThisText, '⛔ পরিশোধের মোটে কাটা আবার যোগ হলো — লেখা মোট ১,০০০, অথচ মাথায় বেশি').toBe('৳1,000.00')
        expect(data.headLeftText, '⛔ পুরো মোট দিলে বাকি শূন্য নয়').toBe('৳0.00')
    })

    it('খাতে কম কি না নিট দেখে — মোট নয়', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ out: true, amount: '1100', balances: { 229: '1000.00' } })
        clearTimeout(data.timer)
        data.ait = '60'
        data.vds = '40'

        expect(data.short, '⛔ পরিশোধে কাটা বাদে নিটই খাত থেকে যায় — মোট দেখে "কম" বলল').toBe(false)
        data.vds = '0'
        expect(data.short, '⛔ নিট খাতের চেয়ে বেশি, অথচ "কম" বলল না').toBe(true)
    })
})

describe('কমলা করণীয়ের লাইন', () => {
    it('কাটা থাকলেই দেখায় — না থাকলে নয়', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ out: true, amount: '1000' })
        clearTimeout(data.timer)

        expect(data.hasWithheld, '⛔ কাটা ছাড়াই "করণীয় তালিকায় উঠবে" বলে').toBe(false)
        data.vds = '75'
        expect(data.hasWithheld, '⛔ VDS কাটলেও করণীয়ের লাইন আসে না').toBe(true)
    })
})

describe('মাথার শাখা — তথ্য আর যেখানে পোস্ট হবে', () => {
    it('"শাখা: গ্রাহকের শাখা (ময়মনসিংহ)" কেবল তথ্য; "পোস্ট হবে" সার্ভারের প্রিভিউর শাখা', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ branchLabels: { customer: 'গ্রাহকের শাখা', '': 'পক্ষের শাখা' } })
        clearTimeout(data.timer)

        data.heardParty({ due: '0', hasParty: true, partyType: 'customer', partyBranch: 'ময়মনসিংহ' })
        expect(data.partyBranchText, '⛔ মাথায় নকশার "গ্রাহকের শাখা (…)" নেই').toBe('গ্রাহকের শাখা (ময়মনসিংহ)')

        data.preview = { ...data.preview, branch: 'প্রধান' }
        expect(data.postBranchText, '⛔ "পোস্ট হবে" সার্ভারের প্রিভিউর শাখা নয় — বাক্সের শাখা দেখায় না').toBe('প্রধান')
    })
})

describe('ব্যাংক ফাইলের লিংক', () => {
    it('কেবল ব্যাংক ট্রান্সফারে, বাছা খাতের আজকের ফাইল', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ out: true, bankFileUrl: '/accounts/vouchers/bank-file' })
        clearTimeout(data.timer)

        data.heard({ method: 'cash', charge: 0 })
        expect(data.bankFileHref, '⛔ নগদে ব্যাংক ফাইলের লিংক — ক্যাশ খাতের ফাইল চাইত').toBe('')

        data.heard({ method: 'transfer', charge: 0 })
        expect(data.bankFileHref, '⛔ ট্রান্সফারে বাছা খাতের ব্যাংক ফাইলের লিংক নেই').toMatch(/^\/accounts\/vouchers\/bank-file\?account=229&date=\d{4}-\d{2}-\d{2}$/)
    })
})

describe('সারাংশে বিলে প্রয়োগ আর অপ্রয়োগকৃত', () => {
    it('বিলের যোগফল আর মোটের বাকিটা — টিক না থাকলে সারি নেই', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ out: true, amount: '1000' })
        clearTimeout(data.timer)

        expect(data.hasApplied, '⛔ বিল টিক ছাড়াই সারাংশে "বিলে প্রয়োগ"').toBe(false)

        data.heardApplied({ paisa: 75000 })
        expect(data.sumApplied, '⛔ সারাংশের "বিলে প্রয়োগ" বিলের কার্ডের যোগফল নয়').toBe('750.00')
        expect(data.sumUnapplied, '⛔ সারাংশের "বিলে প্রয়োগ হয়নি" মোট − প্রয়োগ নয়').toBe('250.00')

        data.heardApplied({ paisa: 0 })
        expect(data.hasApplied, '⛔ টিক তুলে নিলেও সারাংশে "বিলে প্রয়োগ" থেকে গেল').toBe(false)
    })
})

describe('কম-বেশি — ছাড় বা রাউন্ডিং', () => {
    it('৳০.৫০ পর্যন্ত রাউন্ডিং সই ছাড়া, বেশি হলে আর যেকোনো ছাড়ে মালিকের সই; পক্ষের খাত পার্থক্যসহ', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ rows: [] }) })))
        const data = await mount({ out: false, amount: '1000' })
        clearTimeout(data.timer)

        data.differenceKind = 'rounding'
        data.difference = '0.50'
        expect(data.hasRounding, '⛔ রাউন্ডিং দিলেও সারাংশে রাউন্ডিংয়ের সারি নেই').toBe(true)
        expect(data.ownerSigns, '⛔ ৳০.৫০ রাউন্ডিংয়ে সই চাওয়া হলো — ৳০.৫০ পর্যন্ত সই-মুক্ত').toBe(false)
        expect(data.headGrossPaisa, '⛔ রাউন্ডিংসহ পক্ষের খাতের অঙ্ক নয়').toBe(100050)

        data.difference = '0.60'
        expect(data.roundingOwner, '⛔ ৳০.৫০-এর বেশি রাউন্ডিংয়ে মালিকের সইয়ের চিপ নেই').toBe(true)
        expect(data.ownerSigns, '⛔ ৳০.৫০-এর বেশি রাউন্ডিংয়েও বোতাম "সইয়ে পাঠান" হয় না').toBe(true)

        data.differenceKind = 'discount'
        data.difference = '1'
        expect(data.hasDiscount, '⛔ ছাড় দিলেও সারাংশে ছাড়ের সারি নেই').toBe(true)
        expect(data.ownerSigns, '⛔ ছাড়ে মালিকের সই চাওয়া হয় না').toBe(true)

        data.differenceKind = ''
        expect(data.differencePaisa, '⛔ ধরন তুলে নিলেও পার্থক্য গোনা হলো').toBe(0)
        expect(data.ownerSigns, '⛔ পার্থক্য ছাড়াই সই চাওয়া হলো').toBe(false)
    })
})
