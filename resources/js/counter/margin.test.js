import { describe, expect, it } from 'vitest'
import { freeFromStock, lineMargin, marginLabel } from './margin.js'

/*
 * কার্টের মার্জিন — দেয়ালের একই নিয়ম পর্দায় ([[MarginGuard::isBelow()]])।
 *
 * ⚠️ প্রতিটা দাবি এমন একটা সারি খাওয়ায় যেটা নিয়মের ঠিক কিনারায় বসে —
 * ঠিক সীমায়, সীমার এক পয়সা নিচে, পুরো ছাড়ে। ⛔ মাঝের সহজ সারি দিয়ে
 * মাপলে `<` আর `<=`-এর ভুল কখনো ধরা পড়ত না।
 */
describe('lineMargin', () => {
    const entry = { cost: '96', factors: { 7: '12' } }

    it('shows nothing without the cost list — the key is missing', () => {
        expect(lineMargin({ qty: '1', net: 100 }, undefined, 0)).toBeNull()
    })

    it('says the cost is unknown when the layers had none', () => {
        expect(lineMargin({ qty: '1', net: 100 }, { cost: null }, 0)).toEqual({ unknown: true })
    })

    it('passes a line sitting exactly on the floor', () => {
        // বিক্রয় ১০০, খরচ ৯৬ → মার্জিন ঠিক ৪%
        const m = lineMargin({ qty: '1', net: 100 }, entry, 4)

        expect(m.below).toBe(false)
        expect(m.percent).toBeCloseTo(4, 6)
    })

    it('stops a line one paisa under the floor', () => {
        expect(lineMargin({ qty: '1', net: 99.99 }, entry, 4).below).toBe(true)
    })

    it('stops a sale below cost at the default floor of zero', () => {
        expect(lineMargin({ qty: '1', net: 95 }, entry, 0).below).toBe(true)
    })

    it('counts a pack in base units — 1 box of 12 costs 12 × 96', () => {
        const m = lineMargin({ qty: '1', unitId: '7', net: 1200 }, entry, 0)

        expect(m.below).toBe(false)
        expect(m.percent).toBeCloseTo((1200 - 1152) * 100 / 1200, 6)

        expect(lineMargin({ qty: '1', unitId: '7', net: 1100 }, entry, 0).below).toBe(true)
    })

    it('calls a fully discounted line below whenever it has a cost', () => {
        expect(lineMargin({ qty: '1', net: 0 }, entry, 0)).toEqual({ unknown: false, percent: null, below: true })
        expect(lineMargin({ qty: '1', net: 0 }, { cost: '0' }, 0).below).toBe(false)
    })
})

/*
 * ⭐ বাছা লটের খরচ — মালিকের প্রশ্ন, ৪ অক্টোবর ২০২৬: "মার্জিন এত বেশি দেখায় কেন?" পুরনো সস্তা স্তর (১০০) দেখে
 * ১৫৬.২৫-এর সারি 36% দেখাত, অথচ নতুন লটের খরচ ১৫০ — আসল মার্জিন 4%।
 */
describe('lineMargin by lot', () => {
    const entry = { cost: '100', lots: { 9: '150' }, other: '120' }

    it('uses the cost of the chosen lot', () => {
        expect(lineMargin({ qty: '1', batchId: '9', net: 156.25 }, entry, 0).percent).toBeCloseTo(4, 6)
    })

    it('falls back to other for a lot without its own layer, and to FIFO without a lot', () => {
        expect(lineMargin({ qty: '1', batchId: '5', net: 200 }, entry, 0).percent).toBeCloseTo(40, 6)
        expect(lineMargin({ qty: '1', batchId: '', net: 200 }, entry, 0).percent).toBeCloseTo(50, 6)
    })
})

/*
 * ⭐ ফ্রির খরচ — মালিকের সিদ্ধান্ত "ক", ৪ অক্টোবর ২০২৬। ভাণ্ডারের ফ্রি শূন্যে, নিজের মাল থেকে যাওয়া ফ্রি লটের খরচে।
 */
describe('free in the margin', () => {
    const entry = { cost: '100', lots: { 9: '150' }, other: '120' }

    it('adds the cost of free taken from own stock', () => {
        expect(lineMargin({ qty: '1', batchId: '9', freeFromStock: 0, net: 156.25 }, entry, 0).percent).toBeCloseTo(4, 6)
        expect(lineMargin({ qty: '1', batchId: '9', freeFromStock: 1, net: 156.25 }, entry, 0).percent).toBeCloseTo(-92, 6)
    })

    it('takes from own stock only what the pool lacks, and only with the switch on', () => {
        const pool = { lots: { 9: '2' }, any: '0' }

        expect(freeFromStock('3', '9', pool, true)).toBe(1)
        expect(freeFromStock('2', '9', pool, true)).toBe(0)
        expect(freeFromStock('3', '9', pool, false)).toBe(0)
        expect(freeFromStock('1', '', pool, true)).toBe(1)
    })
})

describe('marginLabel', () => {
    const words = { margin: 'মার্জিন', below: 'সীমার নিচে', unknown: 'খরচ অজানা' }

    it('writes the percent and the warning', () => {
        expect(marginLabel({ unknown: false, percent: -5, below: true }, words)).toBe('মার্জিন -5.00% · সীমার নিচে')
        expect(marginLabel({ unknown: false, percent: 4, below: false }, words)).toBe('মার্জিন 4.00%')
    })

    it('says unknown, and nothing at all without a key', () => {
        expect(marginLabel({ unknown: true }, words)).toBe('খরচ অজানা')
        expect(marginLabel(null, words)).toBe('')
    })
})
