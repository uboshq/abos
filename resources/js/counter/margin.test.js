import { describe, expect, it } from 'vitest'
import { lineMargin, marginLabel } from './margin.js'

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
