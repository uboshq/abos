import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import partyVoucher, { matchParties } from './party-voucher.js'

/*
 * ⭐ ডিপোজিটর / প্রাপকের নামে খোঁজা — মালিক, ৩ অক্টোবর ২০২৬।
 *
 * অভিযোগ: রসিদ ও পরিশোধের নামের তালিকা একটা লম্বা সাধারণ ড্রপডাউন, খোঁজার
 * ঘর নেই — ইউবি-র ৪১৪ জন গ্রাহকের ভিতরে *নাম খুঁজে পাওয়া যায় না*। আর একই
 * নামের দুইটা দোকান ("M/S. Bismillah Store") কোড-পয়েন্ট ছাড়া আলাদা করা যায় না।
 *
 * ⓘ কম্পোনেন্টটা সরাসরি গড়া হয়, Alpine ছাড়া — `$nextTick` সাথে সাথে চালায়,
 * `$refs`-এ নকল ঘর, আর `fetch` ধরা থাকে যাতে দেখা যায় পাওনা সত্যিই চাওয়া হলো।
 */
const parties = [
    { type: 'customer', id: 11, label: 'M/S. Bismillah Store', hint: 'C-0101 · Kawran Bazar · 01711000001', find: 'm/s. bismillah store c-0101 · kawran bazar · 01711000001' },
    { type: 'customer', id: 12, label: 'M/S. Bismillah Store', hint: 'C-0102 · Mirpur 10 · 01711000002', find: 'm/s. bismillah store c-0102 · mirpur 10 · 01711000002' },
    { type: 'customer', id: 13, label: 'Afia Enterprise', hint: 'C-0103 · Uttara', find: 'afia enterprise আফিয়া এন্টারপ্রাইজ c-0103 · uttara' },
    { type: 'supplier', id: 21, label: 'M/S. EVANA ENTERPRISE', hint: 'S-0001', find: 'm/s. evana enterprise s-0001' },
]

function make(overrides = {}) {
    const box = partyVoucher({
        partyType: 'customer',
        partyId: '',
        parties,
        dueUrl: '/accounts/voucher/due',
        picked: [],
        texts: {},
        ...overrides,
    })

    box.$nextTick = (fn) => fn()
    box.$refs = {
        search: { focus: vi.fn() },
        trigger: { focus: vi.fn() },
        newMobile: { focus: vi.fn() },
        list: { querySelector: () => null },
    }
    box.$root = { closest: () => null }

    return box
}

describe('matchParties', () => {
    it('খালি লেখায় সবাই', () => {
        expect(matchParties(parties, '  ')).toHaveLength(4)
    })

    it('নাম, কোড, মোবাইল বা পয়েন্টের যেকোনো অংশে মেলে', () => {
        expect(matchParties(parties, 'afia').map((p) => p.id)).toEqual([13])
        expect(matchParties(parties, 'C-0102').map((p) => p.id)).toEqual([12])
        expect(matchParties(parties, '01711000001').map((p) => p.id)).toEqual([11])
        expect(matchParties(parties, 'mirpur').map((p) => p.id)).toEqual([12])
    })

    it('ইংরেজিতে লেখা নামের বাংলা রূপেও মেলে', () => {
        expect(matchParties(parties, 'আফিয়া').map((p) => p.id)).toEqual([13])
    })

    it('প্রতিটা শব্দ মিলতে হয় — একই নামের দুই দোকান পয়েন্টে আলাদা', () => {
        expect(matchParties(parties, 'bismillah').map((p) => p.id)).toEqual([11, 12])
        expect(matchParties(parties, 'bismillah kawran').map((p) => p.id)).toEqual([11])
    })

    it('`find` না থাকলে নাম আর ছোট লাইনটাই দেখা হয়', () => {
        expect(matchParties([{ id: 1, label: 'Rahim', hint: 'P-9' }], 'p-9').map((p) => p.id)).toEqual([1])
    })
})

/*
 * ⭐ নাম বাছা এখন ভাগ করা পিকারে ([[x-ui.party-search]] `stacked addable`; খোঁজা, ↑ ↓ Enter আর "+ নতুন নাম"-এর দাবি
 * party-search.test.js-এ) — এখানে কেবল এই বাক্সের কাজ: পিকারের `change` শুনে বকেয়া আর বিল আনা বা মোছা (২৪ অক্টোবর ২০২৬)।
 */
describe('partyVoucher — ভাগ করা পিকারের খবর', () => {
    beforeEach(() => {
        globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ known: true, amount: 500, bills: [] }) }))
    })

    afterEach(() => {
        vi.restoreAllMocks()
        delete globalThis.fetch
    })

    const changed = (value, name = 'party_pick') => ({ target: { name, value } })

    it('পিকারে নাম বাছলে partyId বসে আর পাওনা সত্যিই সার্ভার থেকে চাওয়া হয় — একই ঠিকানায়, একই নামে', async () => {
        const box = make()

        box.partyPicked(changed('12'))
        expect(box.partyId, '⛔ পিকারে বাছা নাম partyId-তে বসেনি').toBe('12')
        await vi.waitFor(() => expect(box.due).toBe('500.00'))
        expect(globalThis.fetch, '⛔ বাছা পক্ষের পাওনা সার্ভার থেকে চাওয়া হয়নি').toHaveBeenCalledWith(
            '/accounts/voucher/due?party_type=customer&party_id=12',
            expect.anything(),
        )

        // ⭐ চিহ্ন ধরে শব্দ, অঙ্ক লাখ-কমায় আর কখনো "−" নয় — রসিদে তিন অবস্থা
        box.texts = { ...box.texts, dueInOwed: 'বকেয়া', dueInAdvance: 'অগ্রিম', dueInNone: 'বকেয়া নেই', dueOutOwed: 'দেনা', dueOutAdvance: 'অগ্রিম দেওয়া', dueOutNone: 'দেনা নেই' }
        box.due = '125000.00'
        expect([box.dueWord, box.dueAmount]).toEqual(['বকেয়া', '৳1,25,000.00'])
        expect(box.dueClass).toContain('warning')
        box.due = '-45000.00'
        expect([box.dueWord, box.dueAmount]).toEqual(['অগ্রিম', '৳45,000.00'])
        expect(box.dueClass).toContain('success')
        box.due = '0.00'
        expect([box.dueWord, box.dueAmount]).toEqual(['বকেয়া নেই', ''])

        // ⭐ পরিশোধে উল্টো দিক — আমরা দেব (−) মানে "দেনা", তিনি দেবেন (+) মানে "অগ্রিম দেওয়া"
        box.out = true
        box.due = '-80000.00'
        expect([box.dueWord, box.dueAmount]).toEqual(['দেনা', '৳80,000.00'])
        box.due = '2500.00'
        expect([box.dueWord, box.dueAmount]).toEqual(['অগ্রিম দেওয়া', '৳2,500.00'])
        box.due = '0'
        expect([box.dueWord, box.dueAmount]).toEqual(['দেনা নেই', ''])
    })

    it('হাতে লেখা নতুন নামে (পিকারের মান ফাঁকা) বকেয়া, বিল আর বাছা বিল মোছে — সার্ভারকে আর জিজ্ঞেস নয়', () => {
        const box = make({ partyId: '11', pickedType: 'sales_invoice', pickedId: '7' })
        box.due = '500.00'
        box.bills = [{ against_type: 'sales_invoice', id: 7, outstanding: '250' }]

        box.partyPicked(changed(''))

        expect(box.partyId, '⛔ নতুন নাম লেখার পরেও পুরনো বাছাই রয়ে গেল').toBe('')
        expect(box.due, '⛔ নতুন নাম লেখার পরেও পুরনো পক্ষের বকেয়া দেখায়').toBe(null)
        expect(box.bills, '⛔ নতুন নাম লেখার পরেও পুরনো পক্ষের বিল দেখায়').toEqual([])
        expect(box.pickedId, '⛔ নতুন নাম লেখার পরেও পুরনো বিলের বিপরীতে রয়ে গেল').toBe('')
        expect(globalThis.fetch, '⛔ পক্ষ ছাড়া সার্ভারে পাওনা চাওয়া হলো').not.toHaveBeenCalled()
    })

    it('দেরিতে আসা পুরনো পক্ষের উত্তর নতুন নাম লেখার পরে মোছা বকেয়া আবার ভরায় না', async () => {
        let answer
        globalThis.fetch = vi.fn(() => new Promise((resolve) => { answer = resolve }))
        const box = make()

        box.partyPicked(changed('12'))
        box.partyPicked(changed(''))
        answer({ ok: true, json: async () => ({ known: true, amount: -45000, bills: [{ against_type: 'sales_invoice', id: 3, outstanding: '9' }] }) })
        await new Promise((r) => setTimeout(r, 0))

        expect(box.due, '⛔ নতুন নাম লেখার পরে পুরনো পক্ষের দেরির উত্তর বকেয়া ফেরাল').toBe(null)
        expect(box.bills, '⛔ নতুন নাম লেখার পরে পুরনো পক্ষের দেরির উত্তর বিল ফেরাল').toEqual([])
    })

    it('অন্য ঘরের change কিছু ছোঁয় না', () => {
        const box = make({ partyId: '11' })

        box.partyPicked(changed('', 'narration'))

        expect(box.partyId, '⛔ অন্য ঘরের change পক্ষের বাছাই মুছে দিল').toBe('11')
    })

    it('ধরন বদলালে বাছাই, বকেয়া আর বিল খালি', () => {
        const box = make({ partyId: '11' })
        box.due = '10.00'
        box.bills = [{ against_type: 'sales_invoice', id: 1, outstanding: '10' }]

        box.partyType = 'supplier'
        box.resetParty()

        expect([box.partyId, box.due, box.bills], '⛔ ধরন বদলালেও আগের পক্ষের বাছাই বা বকেয়া রইল').toEqual(['', null, []])
    })

    it('শুরুর ধরনে ফিরলে শুরুর নামই — পিকার সেই বাছাই নিয়ে আবার জন্মায়, তাই তার বকেয়াও আবার আসে', async () => {
        const box = make({ partyId: '11' })

        box.partyType = 'supplier'
        box.resetParty()
        box.partyType = 'customer'
        box.resetParty()

        expect(box.partyId, '⛔ শুরুর ধরনে ফিরে পিকার নাম দেখায় অথচ বাক্স ফাঁকা ভাবে').toBe('11')
        await vi.waitFor(() => expect(box.due, '⛔ শুরুর ধরনে ফিরেও শুরুর নামের বকেয়া আসেনি').toBe('500.00'))
        expect(globalThis.fetch).toHaveBeenCalledWith('/accounts/voucher/due?party_type=customer&party_id=11', expect.anything())
    })

    it('"অন্য (সরাসরি খাত)" — পক্ষ নেই', () => {
        expect(make({ partyType: '' }).noParty, '⛔ ফাঁকা ধরনকে পক্ষ ছাড়া কাগজ ধরা হয় না').toBe(true)
        expect(make().noParty).toBe(false)
    })
})

/*
 * ⭐ রসিদ একটা বিলের বিপরীতে — Accounts-Finance অডিট ম১, ৪ অক্টোবর ২০২৬।
 * ⛔ আগে বহু বিলে ভাগের ঘর ছিল যা সার্ভার রাখত না; এখন বাছা বিলটাই `against_type` / `against_id`।
 */
describe('partyVoucher — কোন বিলের বিপরীতে', () => {
    const bill = { against_type: 'sales_invoice', id: 7, no: 'INV-7', date: '2026-09-01', age: 34, outstanding: '250.0000' }

    function withAmount(value) {
        const input = { value, dispatchEvent: vi.fn() }
        const box = make()
        box.$root = { closest: () => ({ querySelector: (q) => (q === '[name="amount"]' ? input : null) }) }
        box.bills = [bill]

        return { box, input }
    }

    it('বাছা বিলটাই "বিপরীতে" ঘরে, আর খালি টাকার ঘরে বিলের বাকি বসে', () => {
        const { box, input } = withAmount('')

        box.pick(bill)

        expect(box.pickedType).toBe('sales_invoice')
        expect(box.pickedId).toBe('7')
        expect(box.pickedBill).toBe(bill)
        expect(input.value).toBe('250.00')
    })

    // ⓘ ২৪ অক্টোবর ২০২৬ থেকে টাকার ঘর দুই ভাগে (x-ui.field type="money") — লেখা যায় দেখানোর ঘরে, পাঠানোর ঘর লুকানো
    it('দুই-ভাগের টাকার ঘরে বিলের বাকি বসে দেখানোর ঘরে — লুকানো পাঠানোর ঘরে সরাসরি নয়', () => {
        const shown = { value: '', dispatchEvent: vi.fn() }
        const sent = { value: '', dispatchEvent: vi.fn() }
        const box = make()
        box.$root = { closest: () => ({ querySelector: (q) => (q === '[data-money-shown="amount"]' ? shown : (q === '[name="amount"]' ? sent : null)) }) }
        box.bills = [bill]

        box.pick(bill)

        expect(shown.value, '⛔ বিলের বাকি দেখানোর ঘরে বসল না — পর্দার অঙ্ক বদলাত না').toBe('250.00')
        expect(shown.dispatchEvent, '⛔ দেখানোর ঘর জানল না যে অঙ্ক বসেছে — পর্দার মান বাসি থাকত').toHaveBeenCalled()
        expect(sent.value, '⛔ লুকানো পাঠানোর ঘরে সরাসরি বসল — পরের টিকে Alpine মুছে দিত').toBe('')
    })

    it('টাকার ঘরে আগে থেকে অঙ্ক থাকলে বদলায় না — আংশিক টাকা', () => {
        const { box, input } = withAmount('100')

        box.pick(bill)

        expect(input.value).toBe('100')
        expect(box.overDue).toBe(false)
    })

    it('বিলের বাকির চেয়ে বেশি টাকা হলে আগেই বলে', () => {
        const { box } = withAmount('300')

        box.pick(bill)

        expect(box.overDue).toBe(true)
    })

    it('বাছাই তোলা যায়, আর পক্ষ বদলালে নিজেই ওঠে', () => {
        const { box } = withAmount('')

        box.pick(bill)
        box.unpick()
        expect(box.pickedId).toBe('')

        box.pick(bill)
        box.resetParty()
        expect(box.pickedId).toBe('')
        expect(box.pickedType).toBe('')
    })

    it('অন্য মডিউলের আগাম-ভরা "বিপরীতে" বসে থাকে', () => {
        const box = make({ pickedType: 'capital_entry', pickedId: 42 })

        expect(box.pickedType).toBe('capital_entry')
        expect(box.pickedId).toBe('42')
    })
})

/*
 * ⭐ বাছা বিলের রেডিওতে দাগ — `isPicked()` (১০ অক্টোবর ২০২৬)। ⛔ আগে ব্লেডে `String(b.id) === pickedId` ছিল, আর CSP-Alpine
 * বৈশ্বিক `String` চেনে না — দাগটা কখনো পড়ত না। ⓘ আইডি সংখ্যা হলেও মেলে, ধরন আলাদা হলে মেলে না, কিছু বাছা না থাকলে না।
 */
describe('partyVoucher — বাছা বিলের দাগ', () => {
    it('marks only the picked bill, by id and kind', () => {
        const box = make()
        const invoice = { against_type: 'sales_invoice', id: 7 }
        const order = { against_type: 'sales_order', id: 7 }

        expect(box.isBillPicked(invoice)).toBe(false)

        box.pickedType = 'sales_invoice'
        box.pickedId = '7'
        expect(box.isBillPicked(invoice)).toBe(true)
        expect(box.isBillPicked(order)).toBe(false)
        expect(box.isBillPicked({ against_type: 'sales_invoice', id: 8 })).toBe(false)

        box.unpick()
        expect(box.isBillPicked(invoice)).toBe(false)
    })
})

/*
 * ⭐ নকশার মিল-তালিকা (পাতা ২ আর ৪; fe, ২৫ অক্টোবর ২০২৬): পক্ষের ঘরের নাম ধরনে, নিচে "সবচেয়ে পুরনো বাকি N দিন", আর টাকার নিচে
 * "পুরো বকেয়া · নির্বাচিত বিল" — এক চাপে অঙ্ক, ঘটনায় ফর্মের টাকায় ([[voucherMoney]] `heardFill`)।
 */
describe('partyVoucher — নকশার নাম, বয়স আর এক চাপের অঙ্ক', () => {
    const texts = { typeLabels: { customer: 'গ্রাহক', supplier: 'সরবরাহকারী' }, partyWord: 'পক্ষ', oldestDue: 'সবচেয়ে পুরনো বাকি :days দিন' }

    it('পক্ষের ঘরের নাম ধরনে, অজানা ধরনে সাধারণ "পক্ষ"', () => {
        const box = make({ texts })
        expect(box.partyLabel, '⛔ গ্রাহকের রসিদে ঘরের নাম "গ্রাহক" নয়').toBe('গ্রাহক')
        box.partyType = 'supplier'
        expect(box.partyLabel, '⛔ ধরন বদলালেও ঘরের নাম বদলায়নি').toBe('সরবরাহকারী')
        box.partyType = 'employee'
        expect(box.partyLabel, '⛔ অজানা ধরনে ঘরের নাম ফাঁকা').toBe('পক্ষ')
    })

    it('সবচেয়ে পুরনো বাকি — যে বিলে এখনো বাকি আছে তার বয়স; বিল না থাকলে কিছুই নয়', () => {
        const box = make({ texts })
        expect(box.oldestDueText, '⛔ বিল ছাড়াই বয়সের লাইন').toBe('')

        box.bills = [
            { against_type: 'sales_invoice', id: 1, age: 3, outstanding: '100' },
            { against_type: 'sales_invoice', id: 2, age: 19, outstanding: '250.50' },
            { against_type: 'sales_invoice', id: 3, age: 40, outstanding: '0' },
        ]
        expect(box.oldestDueText, '⛔ পুরনো বাকির বয়স ভুল — শোধ হওয়া বিলও গোনা হলো, বা সবচেয়ে পুরনোটা নয়').toBe('সবচেয়ে পুরনো বাকি 19 দিন')
    })

    it('"পুরো বকেয়া" কেবল সত্যিকারের বকেয়ায়, অঙ্ক দুই দশমিকে; অগ্রিম বা শূন্যে কিছুই নয়', () => {
        const box = make({ texts })
        box.$dispatch = vi.fn()

        box.due = '1250.5'
        expect(box.canFillDue).toBe(true)
        box.fillWholeDue()
        expect(box.$dispatch, '⛔ "পুরো বকেয়া" অঙ্কটা ফর্মে পাঠায়নি').toHaveBeenCalledWith('fill-amount', '1250.50')

        box.$dispatch.mockClear()
        for (const due of ['-500', '0', '0.001']) {
            box.due = due
            expect(box.canFillDue, `⛔ ${due}-এ "পুরো বকেয়া" দেখায় — অগ্রিম বা শূন্যে আদায়ের কিছু নেই`).toBe(false)
            box.fillWholeDue()
        }
        expect(box.$dispatch, '⛔ অগ্রিম বা শূন্যে অঙ্ক বসাল').not.toHaveBeenCalled()

        box.out = true
        box.due = '-8000'
        expect(box.canFillDue, '⛔ পরিশোধে দেনায় "পুরো বকেয়া" চলে না — দিক উল্টো').toBe(true)
    })

    it('"নির্বাচিত বিল" বাছা বিলের বাকি বসায়; বিল বাছা না থাকলে কিছুই নয়', () => {
        const box = make({ texts })
        box.$dispatch = vi.fn()

        box.fillPickedBill()
        expect(box.$dispatch, '⛔ বিল ছাড়াই অঙ্ক বসাল').not.toHaveBeenCalled()

        box.bills = [{ against_type: 'sales_invoice', id: 7, age: 2, outstanding: '250' }]
        box.pick(box.bills[0])
        box.fillPickedBill()
        expect(box.$dispatch, '⛔ "নির্বাচিত বিল" বাছা বিলের বাকি বসায়নি').toHaveBeenCalledWith('fill-amount', '250.00')
    })

    it('পাওনার খবরে ধরনও যায় — মাথার অঙ্কের নাম তাতে', () => {
        const box = make({ texts })
        box.$dispatch = vi.fn()
        box.due = '10.00'
        box.tellDue()
        expect(box.$dispatch, '⛔ পাওনার খবরে পক্ষের ধরন নেই — মাথার নাম "গ্রাহকের বকেয়া" হতে পারে না')
            .toHaveBeenCalledWith('party-due', { due: '10.00', hasParty: true, partyType: 'customer', partyBranch: '' })
    })
})

/*
 * ⭐ এক টাকা, বহু বিল (cb-ma-4, ২৫ অক্টোবর ২০২৬) — নকশার খোলা বিলের কলাম আর "এই রসিদে কত": টিকে পুরো বকেয়া, টাকার ঘরে যোগফল (লেখক না
 * বদলালে), যোগফলের বাইরে অগ্রিম কথায়, বেশি হলে লাল; দেরি কেবল > ০ হলে লালে।
 */
describe('partyVoucher — বহু বিল', () => {
    const texts = { lateDays: ':n দিন', unapplied: 'বিলে বসল :sum; ৳:amount বিলে প্রয়োগ হয়নি — :party খাতায় থাকবে (:side)', partyOf: { customer: 'গ্রাহকের', supplier: 'সরবরাহকারীর', '': 'পক্ষের' }, over: 'বিলে ৳:sum, টাকা ৳:gross' }
    const bills = () => [
        { against_type: 'sales_invoice', id: 1, public_id: 'p-1', no: 'INV-1', date: '2026-10-02', due_date: '2026-10-17', days_late: 4, total: '30000', outstanding: '30000' },
        { against_type: 'sales_invoice', id: 2, public_id: 'p-2', no: 'INV-2', date: '2026-10-09', due_date: '2026-10-24', days_late: 0, total: '25200', outstanding: '15200' },
    ]

    it('টিকে পুরো বকেয়া বসে, টাকার ঘরে যোগফল যায়, আর পাঠানোর সারি public_id আর অঙ্কে', () => {
        const box = make({ texts })
        box.$dispatch = vi.fn()
        box.bills = bills()

        box.toggle(box.bills[0])
        box.toggle(box.bills[1])

        expect(box.rowAmount(box.bills[1]), '⛔ টিকে বিলের পুরো বকেয়া বসেনি').toBe('15200.00')
        expect(box.pickedRows, '⛔ পাঠানোর সারি বিলের public_id আর অঙ্কে নয়').toEqual([{ id: 'p-1', amount: '30000.00' }, { id: 'p-2', amount: '15200.00' }])
        expect(box.billField(1, 'amount'), '⛔ সারির ঘরের নাম চুক্তির নয়').toBe('bills[1][amount]')
        expect(box.$dispatch, '⛔ বিল বাছলেও টাকার ঘরে যোগফল বসেনি').toHaveBeenLastCalledWith('fill-amount', '45200.00')
        expect(box.allocSumText).toBe('৳45,200.00')
    })

    it('লেখক নিজে টাকা বদলালে বাক্স আর ছোঁয় না; যোগফলের বাইরে "বিলে প্রয়োগ হয়নি — পক্ষের খাতায়" কথায়, বেশি হলে লাল', () => {
        const box = make({ texts })
        box.$dispatch = vi.fn()
        box.bills = bills()
        box.toggle(box.bills[1])
        box.heardGross(1520000)

        box.heardGross(2000000)
        box.$dispatch.mockClear()
        box.setRow(box.bills[1], '10,000')
        expect(box.$dispatch, '⛔ লেখকের নিজের লেখা টাকা বাক্স মুছে দিল').not.toHaveBeenCalled()
        expect(box.unappliedText, '⛔ রসিদে বিলের বাইরের টাকা "গ্রাহকের খাতায় থাকবে (Cr)" বলা হয় না').toBe('বিলে বসল ৳10,000.00; ৳10,000.00 বিলে প্রয়োগ হয়নি — গ্রাহকের খাতায় থাকবে (Cr)')
        expect(box.unappliedText, '⛔ বিলের বাইরের টাকাকে "অগ্রিম" বলা — সরবরাহকারীর অগ্রিম খাতই নেই').not.toContain('অগ্রিম')
        box.out = true
        box.partyType = 'supplier'
        expect(box.unappliedText, '⛔ পরিশোধে বিলের বাইরের টাকা "সরবরাহকারীর খাতায় থাকবে (Dr)" বলা হয় না').toBe('বিলে বসল ৳10,000.00; ৳10,000.00 বিলে প্রয়োগ হয়নি — সরবরাহকারীর খাতায় থাকবে (Dr)')
        box.out = false
        box.partyType = 'customer'

        box.heardGross(500000)
        expect(box.overText, '⛔ বিলের যোগফল টাকার বেশি, অথচ লালে বলা হয় না').toBe('বিলে ৳10,000.00, টাকা ৳5,000.00')
        expect(box.unappliedText).toBe('')
    })

    it('দেরি কেবল শেষ দিন পেরোলে লালে "N দিন" — ০ হলে "—", লাল নয়; তারিখ নকশার "17-10"', () => {
        const box = make({ texts })
        const [late, onTime] = bills()

        expect(box.lateText(late)).toBe('4 দিন')
        expect(box.lateClass(late), '⛔ শেষ দিন পেরোনো বিল লালে নয়').toContain('text-(--color-danger)')
        expect(box.lateText(onTime), '⛔ সময়ের বিলে দেরি লেখা').toBe('—')
        expect(box.lateClass(onTime), '⛔ দেরি ০ হলেও লাল').not.toContain('danger')
        expect(box.dayMonth(late.due_date)).toBe('17-10')
        expect(box.lakh(onTime.total), '⛔ মূল টাকা লাখ-কমায় নয়').toBe('25,200.00')
    })

    it('অন্য মডিউলের আগাম-বাছা একক বিল তালিকায় থাকলে সারিতে টিক, একক ঘর খালি', () => {
        const box = make({ texts, pickedType: 'sales_invoice', pickedId: '2' })
        box.bills = bills()

        box.adoptPicked()

        expect(box.pickedId, '⛔ আগাম-বাছা বিল একক ঘরেও রয়ে গেল — দুই পথে দুবার বসত').toBe('')
        expect(box.isTicked(box.bills[1]), '⛔ আগাম-বাছা বিল সারিতে টিক পেল না').toBe(true)
    })
})

/*
 * ⭐ পক্ষের মালিক মডিউলের তিন উত্তর (Core চুক্তি, cb-ma-4): বাকির সীমা, BIN/TIN, প্রাপকের ব্যাংক — আর সই-না-পাওয়া ব্যাংকে কেবল
 * ব্যাংকে যায় এমন পদ্ধতিতে লাল।
 */
describe('partyVoucher — বাকির সীমা, BIN/TIN, প্রাপকের ব্যাংক', () => {
    const texts = { creditLimit: 'বাকির সীমা ৳:limit', creditNone: 'বাকির সীমা নেই — কেবল নগদে', bankNone: 'প্রাপকের ব্যাংক তথ্য নেই' }

    it('বাকির সীমা লাখ-কমায়; ০ মানে কেবল নগদে; সুইচ বন্ধ (null) হলে কিছুই নয়', () => {
        const box = make({ texts })
        expect(box.creditText, '⛔ সুইচ বন্ধে বাকির সীমা দেখায়').toBe('')
        box.credit = { limit: '100000.00', used: '0', available: '100000.00' }
        expect(box.creditText, '⛔ বাকির সীমা নকশার মতো নয়').toBe('বাকির সীমা ৳1,00,000.00')
        box.credit = { limit: '0.00', used: '0', available: '0' }
        expect(box.creditText, '⛔ ০ সীমায় "কেবল নগদে" বলা হয় না').toBe('বাকির সীমা নেই — কেবল নগদে')
    })

    it('BIN · TIN — যা আছে তাই', () => {
        const box = make({ texts })
        box.taxIds = { bin: '000123456-0101', tin: null }
        expect(box.taxText, '⛔ BIN দেখায় না, বা নেই এমন TIN দেখায়').toBe('BIN 000123456-0101')
        box.taxIds = { bin: '000123456-0101', tin: '1234567890' }
        expect(box.taxText).toBe('BIN 000123456-0101 · TIN 1234567890')
    })

    it('সই-না-পাওয়া ব্যাংক লালে কেবল পরিশোধে আর ট্রান্সফার বা নগদ জমায়', () => {
        const box = make({ texts, out: true })
        box.payeeBank = { state: 'pending', line: 'সোনালী ব্যাংক · ••••4521' }

        box.heardChange({ target: { name: 'instrument', value: 'cash' } })
        expect(box.bankBlocks, '⛔ নগদে প্রাপকের ব্যাংকের লাল নিয়ম — নগদে ব্যাংক লাগে না').toBe(false)
        box.heardChange({ target: { name: 'instrument', value: 'transfer' } })
        expect(box.bankBlocks, '⛔ সই-না-পাওয়া ব্যাংকে ট্রান্সফার, অথচ লাল নিয়ম নেই').toBe(true)
        box.heardChange({ target: { name: 'instrument', value: 'deposit' } })
        expect(box.bankBlocks, '⛔ সই-না-পাওয়া ব্যাংকে নগদ জমা, অথচ লাল নিয়ম নেই').toBe(true)

        box.payeeBank = { state: 'signed', line: 'সোনালী ব্যাংক · ••••4521' }
        expect(box.bankBlocks, '⛔ সই-পাওয়া ব্যাংকেও লাল').toBe(false)
        expect(box.bankSigned).toBe(true)

        box.payeeBank = { state: 'none', line: null }
        expect(box.bankLineText, '⛔ ব্যাংক তথ্য না থাকলে কারণ বলা হয় না').toBe('প্রাপকের ব্যাংক তথ্য নেই')

        box.out = false
        box.payeeBank = { state: 'pending', line: 'x' }
        expect(box.bankBlocks, '⛔ রসিদে প্রাপকের ব্যাংকের লাল নিয়ম').toBe(false)
    })
})

describe('partyVoucher — পক্ষের শাখা, কেবল তথ্য', () => {
    it('বকেয়ার উত্তরের শাখা খবরে যায় — মাথার "শাখা: গ্রাহকের শাখা (…)"', () => {
        const box = make()
        box.$dispatch = vi.fn()
        box.partyBranch = 'ময়মনসিংহ'
        box.due = '10.00'
        box.tellDue()
        expect(box.$dispatch, '⛔ পক্ষের শাখা মাথায় পৌঁছায় না')
            .toHaveBeenCalledWith('party-due', { due: '10.00', hasParty: true, partyType: 'customer', partyBranch: 'ময়মনসিংহ' })
    })
})

describe('বিলে প্রয়োগের খবর সারাংশকে', () => {
    it('টিকের যোগফল পয়সায় যায়', () => {
        const box = make()
        box.$dispatch = vi.fn()
        box.bills = [{ public_id: 'b1', outstanding: '300' }, { public_id: 'b2', outstanding: '150.50' }]
        box.allocs = { b1: '300.00', b2: '150.50' }
        box.tellApplied()
        expect(box.$dispatch, '⛔ বিলে প্রয়োগের যোগফল সারাংশে যায় না').toHaveBeenCalledWith('bills-applied', { paisa: 45050 })
    })
})

describe('খোলা বিলে নোটের সারি', () => {
    it('নোট দেখায়, নিজের নামে, কিন্তু টিক হয় না', () => {
        const box = make({ texts: { kinds: { credit_note: 'ক্রেডিট নোট', debit_note: 'ডেবিট নোট' } } })
        box.$dispatch = vi.fn()
        const bill = { kind: 'invoice', public_id: 'inv-1', outstanding: '500' }
        const note = { kind: 'credit_note', public_id: 'cn-9', outstanding: '-80.0000' }
        box.bills = [bill, note]

        expect(box.isBill(bill), '⛔ বিলের সারি বিল ধরা হয় না').toBe(true)
        expect(box.isBill(note), '⛔ নোটের সারি বিল ধরা হলো — টিক আর অঙ্কের ঘর পেত').toBe(false)
        expect(box.kindText(note), '⛔ নোটের সারিতে ধরনের নাম নেই').toBe('ক্রেডিট নোট')
        expect(box.hasNotes, '⛔ নোট থাকলেও কথার লাইন নেই').toBe(true)

        box.toggle(note)
        expect(box.pickedRows, '⛔ ক্রেডিট নোট টিক হয়ে প্রয়োগের সারিতে গেল').toEqual([])
    })
})
