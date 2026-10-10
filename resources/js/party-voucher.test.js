import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import partyVoucher, { matchParties, SHOWN_AT_ONCE } from './party-voucher.js'

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

const key = (k, extra = {}) => ({ key: k, preventDefault: vi.fn(), ...extra })

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

describe('partyVoucher — খোঁজা যায় এমন নামের তালিকা', () => {
    beforeEach(() => {
        globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ known: true, amount: 500, bills: [] }) }))
    })

    afterEach(() => {
        vi.restoreAllMocks()
        delete globalThis.fetch
    })

    it('তালিকা কেবল বাছা ধরনের নাম দেখায়', () => {
        const box = make()

        expect(box.shown.map((p) => p.id)).toEqual([11, 12, 13])

        box.partyType = 'supplier'
        expect(box.shown.map((p) => p.id)).toEqual([21])
    })

    it('লিখলে তালিকা ছোট হয়, আর আলো প্রথম সারিতে ফেরে', () => {
        const box = make()

        box.openList()
        box.moveDown()
        expect(box.cursor).toBe(1)

        box.search = 'afia'
        box.searched()

        expect(box.shown.map((p) => p.id)).toEqual([13])
        expect(box.cursor).toBe(0)
    })

    it('খুললেই খোঁজার ঘরে ফোকাস', () => {
        const box = make()

        box.openList()

        expect(box.listOpen).toBe(true)
        expect(box.$refs.search.focus).toHaveBeenCalled()
    })

    it('↓ ↓ Enter — দ্বিতীয় নামটা বাছা হয়, partyId বসে, আর loadDue চলে', async () => {
        const box = make()
        const due = vi.spyOn(box, 'loadDue')

        box.openList()
        box.moveDown()
        box.moveDown()
        box.moveUp()
        box.pickCursor()

        expect(box.partyId).toBe('12')
        expect(due).toHaveBeenCalledOnce()
        expect(box.listOpen).toBe(false)
        expect(box.search).toBe('')
        expect(box.$refs.trigger.focus).toHaveBeenCalled()
    })

    it('বাছার পরে পাওনা সত্যিই সার্ভার থেকে চাওয়া হয় — একই ঠিকানায়, একই নামে', async () => {
        const box = make()

        box.search = 'mirpur'
        box.pickCursor()
        await vi.waitFor(() => expect(box.due).toBe('500.00'))

        expect(globalThis.fetch).toHaveBeenCalledWith(
            '/accounts/voucher/due?party_type=customer&party_id=12',
            expect.anything(),
        )
    })

    it('↓ শেষ সারির পরে যায় না, ↑ প্রথমের আগে যায় না', () => {
        const box = make()

        box.openList()
        for (let i = 0; i < 10; i++) box.moveDown()
        expect(box.cursor).toBe(2)

        for (let i = 0; i < 10; i++) box.moveUp()
        expect(box.cursor).toBe(0)
    })

    it('ক্লিকে বাছাও একই কাজ করে', () => {
        const box = make()
        const due = vi.spyOn(box, 'loadDue')

        box.pickParty(13)

        expect(box.partyId).toBe('13')
        expect(due).toHaveBeenCalledOnce()
        expect(box.pickedLabel).toBe('Afia Enterprise')
        expect(box.pickedHint).toBe('C-0103 · Uttara')
    })

    it('Esc — তালিকা বন্ধ, বাছাই অটুট, ফোকাস বোতামে', () => {
        const box = make({ partyId: '11' })

        box.openList()
        box.search = 'afia'
        box.escape()

        expect(box.listOpen).toBe(false)
        expect(box.search).toBe('')
        expect(box.partyId).toBe('11')
        expect(box.$refs.trigger.focus).toHaveBeenCalled()
    })

    it('আগে বাছা নাম থাকলে খুললে আলো তার উপরেই', () => {
        const box = make({ partyId: '13' })

        box.openList()

        expect(box.cursor).toBe(2)
    })

    it('বোতামে অক্ষর টাইপ করলে তালিকা খোলে, আর অক্ষরটাই খোঁজা শুরু করে', () => {
        const box = make()
        const e = key('a')

        box.triggerKey(e)

        expect(e.preventDefault).toHaveBeenCalled()
        expect(box.listOpen).toBe(true)
        expect(box.search).toBe('a')
    })

    it('বোতামে ↓ — তালিকা খোলে; Ctrl-যুক্ত চাপ ছোঁয় না', () => {
        const box = make()

        box.triggerKey(key('c', { ctrlKey: true }))
        expect(box.listOpen).toBe(false)

        box.triggerKey(key('ArrowDown'))
        expect(box.listOpen).toBe(true)
        expect(box.search).toBe('')
    })

    it('ধরন বদলালে তালিকা বন্ধ আর বাছাই খালি', () => {
        const box = make({ partyId: '11' })

        box.openList()
        box.partyType = 'supplier'
        box.resetParty()

        expect(box.listOpen).toBe(false)
        expect(box.partyId).toBe('')
    })

    it('বেশি নাম হলে একবারে কেবল প্রথম অংশ আঁকা হয়, আর বলা হয় আরও আছে', () => {
        const many = Array.from({ length: SHOWN_AT_ONCE + 5 }, (_, i) => ({ type: 'customer', id: i + 1, label: 'Shop ' + i, hint: '' }))
        const box = make({ parties: many })

        expect(box.shown).toHaveLength(SHOWN_AT_ONCE)
        expect(box.moreHidden).toBe(true)

        box.search = 'shop 104'
        expect(box.moreHidden).toBe(false)
        expect(box.shown.map((p) => p.id)).toEqual([105])
    })

    it('কিছু না মিললে Enter লেখাটাকে নতুন নাম বানায় — "+" ঘর খোলে, পুরনো বাছাই মোছে', () => {
        const box = make({ partyId: '11' })
        const due = vi.spyOn(box, 'loadDue')

        box.openList()
        box.search = 'Walk-in Karim'
        box.searched()

        expect(box.noMatch).toBe(true)
        expect(box.hasSearch).toBe(true)

        box.pickCursor()

        expect(box.adding).toBe(true)
        expect(box.newName).toBe('Walk-in Karim')
        expect(box.partyId).toBe('')
        expect(box.pickedLabel).toBe('+ Walk-in Karim')
        expect(due).not.toHaveBeenCalled()
        expect(box.$refs.newMobile.focus).toHaveBeenCalled()
    })

    it('"+" ঘরে নাম লিখলে তালিকার বাছাই ছেড়ে দেয় — নাহলে সার্ভার লেখাটা ফেলে দিত', () => {
        const box = make({ partyId: '11' })

        box.toggleAdding()
        box.newName = 'Karim'
        box.newNameTyped()

        expect(box.partyId).toBe('')
        expect(box.pickedLabel).toBe('+ Karim')
    })

    it('তালিকা থেকে বাছলে নতুন নামের ঘর বন্ধ ও খালি', () => {
        const box = make({ adding: true, newName: 'Karim' })

        box.pickParty(11)

        expect(box.adding).toBe(false)
        expect(box.newName).toBe('')
        expect(box.partyId).toBe('11')
    })

    it('খালি বাছাইয়ে বোতামে "—"', () => {
        expect(make().pickedLabel).toBe('—')
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
