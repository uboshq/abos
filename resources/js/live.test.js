import { describe, expect, it } from 'vitest'
import { isFresh } from './live.js'

// ⭐ রিয়েল-টাইম সিঙ্ক — খোলা পাতা কখন "নতুন তথ্য এসেছে" বলবে (মালিক, ১০ অক্টোবর ২০২৬)
describe('isFresh', () => {
    it('says so only when the company wrote after the page was drawn', () => {
        expect(isFresh('1000', 1001)).toBe(true)
        expect(isFresh('1000', 1000)).toBe(false)
        expect(isFresh('1000', 999)).toBe(false)
    })

    it('a company nobody has written to yet is not news', () => {
        expect(isFresh('0', 0)).toBe(false)
        expect(isFresh(undefined, undefined)).toBe(false)
    })
})
