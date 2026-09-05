import { describe, expect, it } from 'vitest'
import { applyAll, BLOCK, deepest, optionsFor, RACK, SHELF } from './placement.js'

/*
 * তাক বাছার নিয়মগুলো — ছোট, কিন্তু ভুল হলে কার্টনটা খুঁজে পাওয়া যায় না।
 */

const places = {
    7: [
        { id: 1, code: 'B1', name: 'ব্লক ১', depth: BLOCK, parent: null },
        { id: 2, code: 'B2', name: 'ব্লক ২', depth: BLOCK, parent: null },
        { id: 3, code: 'R1', name: 'র‍্যাক ১', depth: RACK, parent: 1 },
        { id: 4, code: 'R2', name: 'র‍্যাক ২', depth: RACK, parent: 2 },
        { id: 5, code: 'S1', name: 'শেলফ ১', depth: SHELF, parent: 3 },
        { id: 6, code: 'S2', name: 'শেলফ ২', depth: SHELF, parent: 4 },
    ],
    9: [],
}

describe('optionsFor', () => {
    it('ব্লক দেখায় কেবল বাবা-ছাড়া সারিগুলো', () => {
        expect(optionsFor(places, 7, BLOCK, null).map((o) => o.id)).toEqual([1, 2])
    })

    it('বাবা না বাছলে নিচের ধাপ খালি — "সব" নয়', () => {
        /*
         * ⛔ এখানেই আসল বিপদ। "বাবা নেই মানে সব দেখাও" লিখলে গুদামের
         * লোক অন্য র‍্যাকের শেলফ বেছে ফেলতে পারতেন, আর কার্টনটা খাতায়
         * এক জায়গায় হাতে আরেক জায়গায় থাকত।
         */
        expect(optionsFor(places, 7, RACK, '')).toEqual([])
        expect(optionsFor(places, 7, SHELF, null)).toEqual([])
    })

    it('বাছা বাবার সন্তানরাই আসে, অন্য শাখার নয়', () => {
        expect(optionsFor(places, 7, RACK, 1).map((o) => o.id)).toEqual([3])
        expect(optionsFor(places, 7, SHELF, 3).map((o) => o.id)).toEqual([5])
        expect(optionsFor(places, 7, SHELF, 4).map((o) => o.id)).toEqual([6])
    })

    it('আইডি স্ট্রিং হলেও মেলে — select সবসময় স্ট্রিং দেয়', () => {
        expect(optionsFor(places, 7, RACK, '1').map((o) => o.id)).toEqual([3])
    })

    it('তাকহীন গুদামে খালি — ছোট দোকানের স্বাভাবিক রূপ', () => {
        expect(optionsFor(places, 9, BLOCK, null)).toEqual([])
        expect(optionsFor(places, 404, BLOCK, null)).toEqual([])
    })
})

describe('deepest', () => {
    it('সবচেয়ে গভীরটাই সার্ভারে যায়', () => {
        expect(deepest({ block: 1, rack: 3, shelf: 5 })).toBe(5)
        expect(deepest({ block: 1, rack: 3, shelf: '' })).toBe(3)
        expect(deepest({ block: 1, rack: '', shelf: '' })).toBe(1)
    })

    it('কিছু না বাছলে খালি — তাক ঐচ্ছিক', () => {
        expect(deepest({ block: '', rack: '', shelf: '' })).toBe('')
    })
})

describe('applyAll', () => {
    it('একই গুদামের সারিগুলোতেই বসে', () => {
        const rows = [
            { w: 7, block: '', rack: '', shelf: '' },
            { w: 8, block: '', rack: '', shelf: '' },
        ]

        applyAll(rows, { warehouse: '7', block: 1, rack: 3, shelf: 5 })

        expect(rows[0]).toMatchObject({ block: 1, rack: 3, shelf: 5 })
        expect(rows[1]).toMatchObject({ block: '', rack: '', shelf: '' })
    })

    it('বারে গুদাম না বাছলে সবগুলোয় বসে', () => {
        const rows = [{ w: 7, block: '', rack: '', shelf: '' }, { w: 8, block: '', rack: '', shelf: '' }]

        applyAll(rows, { warehouse: '', block: 1, rack: '', shelf: '' })

        expect(rows.every((r) => r.block === 1)).toBe(true)
    })
})
