// ⭐ কোপাইলটের দ্রুত জিজ্ঞাসা — উত্তরের সারি আর যা পড়ে শোনানো হয় (মালিক, ১২ নভেম্বর ২০২৬)
import { describe, expect, it } from 'vitest'
import { pairsOf, sayOf } from './copilot.js'

describe('কোপাইলটের উত্তর', () => {
    it('"কী · কত" সারি জোড়ায়, আর তালিকার সারি প্রথম ঘর · বাকিগুলো', () => {
        expect(pairsOf({ rows: [{ what: 'নিট বিক্রি', value: '৳48,650' }, { what: 'বিল', value: 1 }] }))
            .toEqual([['নিট বিক্রি', '৳48,650'], ['বিল', '1']])
        expect(pairsOf({ rows: [{ customer: 'মাহাবুব', overdue: '৳4.8 লা', oldest: '38' }] }))
            .toEqual([['মাহাবুব', '৳4.8 লা · 38']])
        expect(pairsOf({})).toEqual([])
    })

    it('পড়ে শোনানো — সারাংশ আর প্রথম তিন সারি, ফাঁকা বাদ', () => {
        const answer = { summary: 'এ মাসে নিট বিক্রি ৳48,650', rows: [{ what: 'বিল', value: '1' }, { what: 'আদায়', value: '৳0' }] }
        expect(sayOf(answer)).toBe('এ মাসে নিট বিক্রি ৳48,650। বিল 1। আদায় ৳0')
        expect(sayOf({ summary: '' })).toBe('')
    })
})
