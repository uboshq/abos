// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { fitRail, hideOrder, layoutOf, matches, moved } from './rail-fit.js'

/*
 * ⭐ বাঁ মডিউল-রেল — যা ধরে না তা "⋯ আরও"-তে, কিছুই হারায় না (মালিক, ২৪ অক্টোবর ২০২৬: "১০০% জুমে সব মডিউলের আইকন আসে না")।
 * আসল ব্রাউজারে মাপা: ১৩৬৬×৭৬৮-এ ১৯টার ১১টা রেলে, ৮টা "আরও"-তে; ১৯২০×১০৮০-এ ১৭ + ২ (নিচের স্থির বার শেষ দুটো ঢাকত)।
 */

const rail = (n, pinned = []) => Array.from({ length: n }, (_, i) => ({ code: `m${i + 1}`, pinned: pinned.includes(`m${i + 1}`) }))

/** নকল মাপ — রেলে `slots`টা ঘর; "আরও" দেখালে তারও একটা ঘর লাগে */
const room = slots => (hidden, withMore) => (n => n + (withMore ? 1 : 0) <= slots)

describe('কোনগুলো "আরও"-তে', () => {
    it('ধরলে কিছুই সরে না; না ধরলে শেষ থেকে সরে, আর "আরও"-র নিজের ঘরও ধরা হয়', () => {
        const items = rail(5)
        expect(fitRail(items, (hidden, withMore) => room(6)(hidden, withMore)(5 - hidden.size))).toEqual([])
        expect(fitRail(items, (hidden, withMore) => room(4)(hidden, withMore)(5 - hidden.size))).toEqual(['m4', 'm5'])
    })

    it('প্রতিটা মডিউল ঠিক এক জায়গায় — রেলে, নয় "আরও"-তে; একটাও হারায় না, একটাও দুবার নয়', () => {
        const items = rail(19)
        const hidden = fitRail(items, (h, withMore) => room(12)(h, withMore)(19 - h.size))
        const shown = items.map(i => i.code).filter(c => ! hidden.includes(c))

        expect(shown.length + hidden.length).toBe(19)
        expect(new Set([...shown, ...hidden]).size).toBe(19)
        expect(shown.length + 1).toBeLessThanOrEqual(12)
    })

    it('পিন করা মডিউল বারে থাকে — জায়গা কম হলে না-পিন করাগুলো আগে সরে', () => {
        const items = rail(8, ['m8', 'm7'])
        const hidden = fitRail(items, (h, withMore) => room(5)(h, withMore)(8 - h.size))

        expect(hidden).not.toContain('m8')
        expect(hidden).not.toContain('m7')
        expect(hideOrder(items)).toEqual(['m6', 'm5', 'm4', 'm3', 'm2', 'm1', 'm8', 'm7'])
    })

    it('"⋯" সবসময় থাকলে (সাজান, আগের মতো করুন) তার ঘরও শুরু থেকেই ধরা', () => {
        const items = rail(5)
        const fits = (h, withMore) => room(5)(h, withMore)(5 - h.size)

        expect(fitRail(items, fits)).toEqual([])
        expect(fitRail(items, fits, { alwaysMore: true })).toEqual(['m5'])
    })
})

describe('"আরও" তালিকা আর সাজানো', () => {
    it('খোঁজা নামের যেকোনো অংশে, বাংলায় আর বড়-ছোট হাতের অক্ষরে', () => {
        expect(matches('ব্যাকআপ ও পুনরুদ্ধার', 'পুনরু')).toBe(true)
        expect(matches('Fixed Assets', 'assets')).toBe(true)
        expect(matches('মানব সম্পদ', 'হিসাব')).toBe(false)
        expect(matches('যেকোনো', '  ')).toBe(true)
    })

    it('এক ঘর সরানো নতুন তালিকা দেয়, কিনারার বাইরে কিছু বদলায় না; রাখার জিনিস পুরো ক্রম আর পিন', () => {
        const rows = [{ code: 'a', pinned: false }, { code: 'b', pinned: true }, { code: 'c', pinned: false }]

        expect(moved(rows, 2, 1).map(r => r.code)).toEqual(['a', 'c', 'b'])
        expect(moved(rows, 0, -1).map(r => r.code)).toEqual(['a', 'b', 'c'])
        expect(rows.map(r => r.code)).toEqual(['a', 'b', 'c'])
        expect(layoutOf(rows)).toEqual({ order: ['a', 'b', 'c'], pinned: ['b'] })
    })
})
