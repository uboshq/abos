import { describe, expect, it, vi } from 'vitest'
import partySearch, { matchParties, SHOWN_AT_ONCE } from './party-search.js'
import * as voucher from './party-voucher.js'

/*
 * ⭐ খোঁজা যায় এমন পক্ষের তালিকা — মালিক, ৩ অক্টোবর ২০২৬: *"ডেবিট নোট পার্টি সার্চ
 * দেয়ার অপশন নাই"*।
 *
 * ⓘ কম্পোনেন্টটা সরাসরি গড়া হয়, Alpine ছাড়া — `$nextTick` সাথে সাথে চালায়, আর
 * `$refs`-এ নকল ঘর, যাতে দেখা যায় ফোকাস আর `change` সত্যিই গেল।
 */
const options = [
    { id: 11, label: 'M/S. Bismillah Store', hint: 'C-0101 · Kawran Bazar · 01711000001', find: 'm/s. bismillah store c-0101 · kawran bazar · 01711000001' },
    { id: 12, label: 'M/S. Bismillah Store', hint: 'C-0102 · Mirpur 10 · 01711000002', find: 'm/s. bismillah store c-0102 · mirpur 10 · 01711000002' },
    { id: 13, label: 'Afia Enterprise', hint: 'C-0103 · Uttara', find: 'afia enterprise আফিয়া এন্টারপ্রাইজ c-0103 · uttara' },
]

function make(overrides = {}) {
    const box = partySearch({ value: '', options, uid: 'ps-party-id-x', ...overrides })

    box.$nextTick = (fn) => fn()
    box.$refs = {
        search: { focus: vi.fn() },
        trigger: { focus: vi.fn() },
        input: { dispatchEvent: vi.fn() },
        list: { querySelector: () => null },
    }

    return box
}

const key = (k, extra = {}) => ({ key: k, preventDefault: vi.fn(), ...extra })

describe('partySearch — খোঁজার নিয়ম একটাই', () => {
    it('রসিদের পিকার আর এই কম্পোনেন্ট একই ফাংশন ব্যবহার করে — দুইটা কপি নয়', () => {
        expect(voucher.matchParties).toBe(matchParties)
        expect(voucher.SHOWN_AT_ONCE).toBe(SHOWN_AT_ONCE)
        expect(SHOWN_AT_ONCE).toBe(100)
    })

    it('নাম, কোড, মোবাইল, পয়েন্ট, বাংলা নাম — প্রতিটা শব্দ মিলতে হয়', () => {
        expect(matchParties(options, 'C-0102').map((p) => p.id)).toEqual([12])
        expect(matchParties(options, '01711000001').map((p) => p.id)).toEqual([11])
        expect(matchParties(options, 'আফিয়া').map((p) => p.id)).toEqual([13])
        expect(matchParties(options, 'bismillah').map((p) => p.id)).toEqual([11, 12])
        expect(matchParties(options, 'bismillah mirpur').map((p) => p.id)).toEqual([12])
    })
})

describe('partySearch — কম্পোনেন্ট', () => {
    it('খালি শুরুতে বোতামে "—", আর আগে বাছা মান থাকলে তার নাম ও কোড-পয়েন্ট', () => {
        expect(make().pickedLabel).toBe('—')
        expect(make().hasPickedHint).toBe(false)

        const box = make({ value: 12 })

        expect(box.value).toBe('12')
        expect(box.pickedLabel).toBe('M/S. Bismillah Store')
        expect(box.pickedHint).toBe('C-0102 · Mirpur 10 · 01711000002')
    })

    it('null মান খালি হিসেবে পড়া হয় — "null" লেখা ফর্মে যায় না', () => {
        expect(make({ value: null }).value).toBe('')
    })

    it('খুললেই খোঁজার ঘরে ফোকাস, আর আগে বাছা নামের উপরে আলো', () => {
        const box = make({ value: '13' })

        box.openList()

        expect(box.listOpen).toBe(true)
        expect(box.$refs.search.focus).toHaveBeenCalled()
        expect(box.cursor).toBe(2)
        expect(box.activeOption).toBe('ps-party-id-x-opt-2')
    })

    it('লিখলে তালিকা ছোট হয়, আর আলো প্রথম সারিতে ফেরে', () => {
        const box = make()

        box.openList()
        box.moveDown()
        expect(box.cursor).toBe(1)

        box.search = 'kawran'
        box.searched()

        expect(box.shown.map((p) => p.id)).toEqual([11])
        expect(box.cursor).toBe(0)
    })

    it('↓ ↓ ↑ Enter — দ্বিতীয় নাম বাছা হয়, লুকানো ঘরে change যায়, ফোকাস বোতামে', () => {
        const box = make()

        box.openList()
        box.moveDown()
        box.moveDown()
        box.moveUp()
        box.pickCursor()

        expect(box.value).toBe('12')
        expect(box.listOpen).toBe(false)
        expect(box.search).toBe('')
        expect(box.$refs.input.dispatchEvent).toHaveBeenCalledOnce()
        expect(box.$refs.input.dispatchEvent.mock.calls[0][0].type).toBe('change')
        expect(box.$refs.trigger.focus).toHaveBeenCalled()
    })

    it('↓ শেষ সারির পরে যায় না, ↑ প্রথমের আগে যায় না', () => {
        const box = make()

        box.openList()
        for (let i = 0; i < 10; i++) box.moveDown()
        expect(box.cursor).toBe(2)

        for (let i = 0; i < 10; i++) box.moveUp()
        expect(box.cursor).toBe(0)
    })

    it('ক্লিক / মোবাইলে চাপ — একই বাছাই', () => {
        const box = make()

        box.pick(13)

        expect(box.value).toBe('13')
        expect(box.pickedLabel).toBe('Afia Enterprise')
    })

    it('কিছু না মিললে "মেলেনি" দেখায়, আর Enter আগের বাছাই ছোঁয় না', () => {
        const box = make({ value: '11' })

        box.openList()
        box.search = 'nobody here'
        box.searched()

        expect(box.noMatch).toBe(true)
        expect(box.activeOption).toBe('')

        box.pickCursor()

        expect(box.value).toBe('11')
        expect(box.listOpen).toBe(true)
    })

    it('Esc — তালিকা বন্ধ, বাছাই অটুট, ফোকাস বোতামে', () => {
        const box = make({ value: '11' })

        box.openList()
        box.search = 'afia'
        box.escape()

        expect(box.listOpen).toBe(false)
        expect(box.search).toBe('')
        expect(box.value).toBe('11')
        expect(box.$refs.trigger.focus).toHaveBeenCalled()
    })

    it('বোতামে অক্ষর টাইপ করলে তালিকা খোলে আর অক্ষরটাই খোঁজা শুরু করে; Ctrl-যুক্ত চাপ ছোঁয় না', () => {
        const box = make()

        box.triggerKey(key('c', { ctrlKey: true }))
        expect(box.listOpen).toBe(false)

        const e = key('a')

        box.triggerKey(e)

        expect(e.preventDefault).toHaveBeenCalled()
        expect(box.listOpen).toBe(true)
        expect(box.search).toBe('a')

        box.closeList()
        box.triggerKey(key('ArrowDown'))
        expect(box.listOpen).toBe(true)
        expect(box.search).toBe('')
    })

    it('বেশি নাম হলে একবারে কেবল ১০০টা আঁকা হয়, আর বলা হয় আরও আছে', () => {
        const many = Array.from({ length: SHOWN_AT_ONCE + 5 }, (_, i) => ({ id: i + 1, label: 'Shop ' + i, hint: '' }))
        const box = make({ options: many })

        expect(box.shown).toHaveLength(SHOWN_AT_ONCE)
        expect(box.moreHidden).toBe(true)

        box.search = 'shop 104'
        expect(box.moreHidden).toBe(false)
        expect(box.shown.map((p) => p.id)).toEqual([105])
    })

    it('প্রতিটা ঘরের সারির id নিজের — একই পাতায় দুই পিকার মেশে না', () => {
        expect(make({ uid: 'ps-a' }).optionId(0)).not.toBe(make({ uid: 'ps-b' }).optionId(0))
    })
})

/*
 * ⭐ জাবেদার সারি — কয়েক ধরনের পক্ষ এক ঘরে, দল ধরে, আর ঐচ্ছিক ঘর খালি করা যায় (মালিক, ৫ অক্টোবর ২০২৬,
 * সমন্বয়কের মারফত: "খোঁজা যায় এমন বাছাই … পক্ষের ধরন দল ধরে")।
 */
describe('partySearch — জাবেদার সারি', () => {
    const grouped = [
        { id: 'customer:11', label: 'Rahim Store', hint: 'C-0101', find: 'rahim store c-0101 গ্রাহক', group: 'গ্রাহক' },
        { id: 'customer:12', label: 'Karim Traders', hint: 'C-0102', find: 'karim traders c-0102 গ্রাহক', group: 'গ্রাহক' },
        { id: 'supplier:7', label: 'Rahim Agro', hint: 'S-0007', find: 'rahim agro s-0007 সরবরাহকারী', group: 'সরবরাহকারী' },
        { id: 'person:3', label: 'Sujon Sumon', hint: '01711', find: 'sujon sumon 01711 ব্যক্তি', group: 'ব্যক্তি' },
    ]

    it('দলের নাম কেবল দলের প্রথম সারিতে — খোঁজার পরেও যা মেলে তার দল', () => {
        const box = make({ options: grouped })

        expect(box.shown.map((_, i) => box.groupLabel(i))).toEqual(['গ্রাহক', '', 'সরবরাহকারী', 'ব্যক্তি'])

        box.search = 'rahim'
        expect(box.shown.map((p) => p.id)).toEqual(['customer:11', 'supplier:7'])
        expect(box.shown.map((_, i) => box.groupLabel(i))).toEqual(['গ্রাহক', 'সরবরাহকারী'])
    })

    it('মান "type:id" — আগের select-এর হুবহু; ↓ Enter-এ বাছা, লুকানো ঘরে change যায়', () => {
        const box = make({ options: grouped, value: 'person:3' })

        expect(box.pickedLabel).toBe('Sujon Sumon')

        box.openList()
        box.search = 'karim'
        box.searched()
        box.pickCursor()

        expect(box.value).toBe('customer:12')
        expect(box.$refs.input.dispatchEvent).toHaveBeenCalled()
    })

    it('ঐচ্ছিক ঘর — মাথায় "—", বাছলে খালি; খোঁজার শব্দ দিলে "—" সরে যায়', () => {
        const box = make({ options: grouped, value: 'customer:11', clearable: true })

        box.openList('')
        expect(box.shown[0]).toMatchObject({ id: '', label: '—' })
        expect(box.groupLabel(0)).toBe('')
        expect(box.groupLabel(1)).toBe('গ্রাহক')

        box.cursor = 0
        box.pickCursor()
        expect(box.value).toBe('')
        expect(box.pickedLabel).toBe('—')

        box.search = 'sujon'
        expect(box.shown.map((p) => p.id)).toEqual(['person:3'])
    })

    it('ঐচ্ছিক নয় এমন ঘরে "—" সারি নেই — নোটের পক্ষ আগের মতোই', () => {
        expect(make().shown.some((p) => p.id === '')).toBe(false)
    })
})
