// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { amountOf, balanceOf, fillOldestFirst, rowOf, shortDate } from './collection-math.js'

/*
 * ⭐ টাকা নেওয়ার পর্দার হিসাব — মালিকের নকশা ১০০, পাতা ২ (২১ অক্টোবর ২০২৬)।
 *
 * ⓘ নকশার নমুনাটাই মাপা: বকেয়া ৫১,২০০ (INV-0094 ৩০,০০০ · INV-0101 ১৫,২০০ · CN-0007 −২,০০০ · INV-0112 ৮,০০০), পাওয়া ৪৫,০০০।
 * পুরনো আগে নিজে বসে — ক্রেডিট নোট বিল ঢাকে, তাই শেষ বিলে ১,৮০০; মোট প্রয়োগ ৪৫,০০০, অপ্রয়োগকৃত ০, "মিলেছে"।
 * ⓘ সার্ভারের দাবি আলাদা (PHP-র HTTP দাবি); এখানে কেবল পর্দার নিজের যোগ-বিয়োগ।
 */

const design = () => [
    rowOf({ id: 'a', kind: 'bill', no: 'INV-0094', date: '2026-10-02', due_date: '2026-10-17', amount: '30000', open: '30000', overdue_days: 8 }, { days: 'দিন' }),
    rowOf({ id: 'b', kind: 'bill', no: 'INV-0101', date: '2026-10-09', due_date: '2026-10-24', amount: '25200', open: '15200', overdue_days: null }),
    rowOf({ id: 'c', kind: 'credit_note', no: 'CN-0007', date: '2026-10-12', due_date: null, amount: '-2000', open: '-2000', overdue_days: null }, { chips: { credit_note: 'ফেরত' } }),
    rowOf({ id: 'd', kind: 'bill', no: 'INV-0112', date: '2026-10-18', due_date: '2026-11-02', amount: '8000', open: '8000', overdue_days: null }),
]

describe('টাকা নেওয়া — দফা ৩: পুরনো আগে নিজে বসে', () => {
    it('দফা ৩ — নকশার নমুনা: ক্রেডিট নোট বিল ঢাকে, শেষ বিলে ১,৮০০, মোট ৪৫,০০০', () => {
        const rows = fillOldestFirst(design(), 45000)

        expect(rows.map(r => [r.no, r.on, r.apply])).toEqual([
            ['INV-0094', true, '30000.00'],
            ['INV-0101', true, '15200.00'],
            ['CN-0007', true, '-2000.00'],
            ['INV-0112', true, '1800.00'],
        ])

        const b = balanceOf({ amount: '45000', vds: '', ait: '', discount: '', rounding: '' }, rows)
        expect(b.applied).toBe(45000)
        expect(b.unapplied).toBe(0)
        expect(b.openTotal).toBe(51200)
        expect(b.balanced).toBe(true)
    })

    it('দফা ৩ — বিল না থাকলে ক্রেডিট বসে না (ক্রেডিট কেবল এই কাগজের বিলে)', () => {
        const rows = fillOldestFirst([design()[2]], 500)

        expect(rows[0].on).toBe(false)
        expect(rows[0].apply).toBe('')
    })

    it('দফা ১, ৩ — দেরি, তারিখ আর চিপ নকশার লেখায়', () => {
        const [late, , cn] = design()

        expect(late.late).toBe('8 দিন')
        expect(late.lateOn).toBe(true)
        expect(late.date).toBe('02-10')
        expect(cn.dueDate).toBe('—')
        expect(cn.chip).toBe('ফেরত')
        expect(cn.credit).toBe(true)
        expect(shortDate(null)).toBe('—')
    })
})

describe('টাকা নেওয়া — দফা ৪, ৫: সারাংশ', () => {
    it('দফা ৫ — VDS আর AIT পাওনা থেকে কমে, তাই বিলে বেশি বসে', () => {
        const rows = fillOldestFirst(design(), 45000 + 1500 + 500)
        const b = balanceOf({ amount: '45000', vds: '1500', ait: '500', discount: '', rounding: '' }, rows)

        expect(b.gross).toBe(47000)
        expect(b.applied).toBe(47000)
        expect(b.balanced).toBe(true)
    })

    it('দফা ৪ — বেশি প্রয়োগ হলে "মিলেছে" নয়; বেশি টাকা অপ্রয়োগকৃত', () => {
        const rows = design()
        rows[0].on = true
        rows[0].apply = '30000'

        expect(balanceOf({ amount: '20000' }, rows).balanced).toBe(false)
        expect(balanceOf({ amount: '20000' }, rows).unapplied).toBe(-10000)
        expect(balanceOf({ amount: '35000' }, rows).unapplied).toBe(5000)
        expect(balanceOf({ amount: '35000' }, rows).balanced).toBe(true)
    })

    it('দফা ৪ — ছাড় আর রাউন্ডিং একসাথে নয়', () => {
        const b = balanceOf({ amount: '100', discount: '5', rounding: '1' }, [])

        expect(b.both).toBe(true)
        expect(b.balanced).toBe(false)
    })

    it('দফা ২৫ — কমা দেওয়া লেখা আর ভুল লেখা', () => {
        expect(amountOf('1,00,000.50')).toBe(100000.5)
        expect(amountOf('abc')).toBe(0)
        expect(amountOf('')).toBe(0)
    })
})
