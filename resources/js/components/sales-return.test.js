// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { salesReturn } from './documents.js'

/*
 * ⛔ লাইভ, ১৭ নভেম্বর ২০২৬ (মালিকের অডিট, পাতা ৪২২): বিক্রি ফেরতের ফর্মে "lotsFor is not defined" —
 * ফর্ম প্রতিটা সারিতে লটের তালিকা চাইত, কম্পোনেন্টে সেটা ছিলই না।
 */
describe('বিক্রি ফেরত — সারির লট', () => {
    const lots = [
        { id: '1', product_id: '10', label: 'L-1 · 2026-12-01' },
        { id: '2', product_id: '11', label: 'L-2' },
        { id: '3', product_id: '10', label: 'L-3' },
    ]

    it('একটা সারি কেবল নিজের পণ্যের লট দেখে', () => {
        const form = salesReturn({ rows: [], lots })

        expect(form.lotsFor({ product_id: '10' }).map(l => l.id)).toEqual(['1', '3'])
        expect(form.lotsFor({ product_id: 11 }).map(l => l.id)).toEqual(['2'])
    })

    it('পণ্য না বাছা সারি কোনো লট দেখে না, আর লটের তালিকা না এলে ভাঙে না', () => {
        expect(salesReturn({ rows: [], lots }).lotsFor({ product_id: '' })).toEqual([])
        expect(salesReturn({ rows: [] }).lotsFor({ product_id: '10' })).toEqual([])
    })
})
