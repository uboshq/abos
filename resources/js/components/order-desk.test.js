/*
 * অর্ডারের ডেস্ক — মালিকের চারটা চাওয়ার যুক্তিটুকু।
 *
 * ⓘ পর্দার দিকটা PHP-র [[TheOrderFormHadNothingOnItTest]] মাপে (ঘরগুলো
 * সত্যিই আঁকা হচ্ছে কি না, আর টাকার প্যানেলটা ঢোকেনি তো)। ⚠️ এখানে মাপা
 * হয় **সিদ্ধান্তগুলো**: সীমা কখন ছাড়ানো ধরা হবে, বারকোড না মিললে কী হবে,
 * আর মজুদ না জানা থাকলে কী দেখানো হবে।
 */
import { describe, expect, it, vi } from 'vitest'

import { salesLineEditor, salesOrderDesk } from './documents.js'

const desk = (config) => {
    const it = salesOrderDesk(config)

    it.$dispatch = vi.fn()

    return it
}

describe('salesOrderDesk — ক্রেতার খাতা', () => {
    const terms = { 7: { limit: 5000, due: 6000 }, 9: { limit: 0, due: 90000 } }

    it('কেউ না বাছলে পটিটা আসে না', () => {
        expect(desk({ terms }).party).toBeNull()
    })

    it('বকেয়া সীমার বেশি হলে সতর্কবার্তা ওঠে', () => {
        expect(desk({ terms, customerId: '7' }).overLimit).toBe(true)
    })

    /*
     * ⛔ সীমা শূন্য মানে "সীমা বসানো হয়নি", "শূন্য টাকার সীমা" নয়।
     *
     * ⚠️ উল্টোটা ধরলে যাঁদের সীমা কখনো বসানো হয়নি — অর্থাৎ বেশিরভাগ
     * ক্রেতা — তাঁদের প্রত্যেকের পাশে লাল বাক্সটা বসত, আর তিন দিনের
     * মধ্যে কেউ ওটা আর পড়ত না।
     */
    it('সীমা বসানো না থাকলে কোনো সতর্কবার্তা নেই', () => {
        expect(desk({ terms, customerId: '9' }).overLimit).toBe(false)
    })

    it('আইডি সংখ্যা হয়ে এলেও পটিটা মেলে', () => {
        expect(desk({ terms, customerId: 7 }).party).toEqual(terms[7])
    })
})

describe('salesOrderDesk — বারকোড', () => {
    const barcodes = { 'BAR-1': 42 }

    it('মিললে সারিটা সংকেত হয়ে যায়, আর ঘরটা খালি হয়', () => {
        const d = desk({ barcodes })

        d.code = 'BAR-1'
        d.scan()

        expect(d.$dispatch).toHaveBeenCalledWith('bulk-applied', {
            rows: [{ product_id: '42', qty: '1' }],
        })

        expect(d.code).toBe('')
        expect(d.missed).toBe('')
    })

    /*
     * ⚠️ না মিললে ঘরটা খালি হয় না।
     *
     * ⓘ খালি করে দিলে স্ক্যানারটা কাজ করল কি না সেটাই বোঝা যেত না —
     * পর্দা ফাঁকা, সারি যোগ হয়নি, আর কারণটা কোথাও লেখা নেই।
     */
    it('না মিললে কোডটা পর্দায় থাকে আর কিছুই পাঠানো হয় না', () => {
        const d = desk({ barcodes })

        d.code = 'NOPE'
        d.scan()

        expect(d.$dispatch).not.toHaveBeenCalled()
        expect(d.missed).toBe('NOPE')
    })

    it('ফাঁকা ঘরে Enter চাপলে কিছুই হয় না', () => {
        const d = desk({ barcodes })

        d.code = '   '
        d.scan()

        expect(d.$dispatch).not.toHaveBeenCalled()
    })

    /*
     * ⭐ ধাপ ৬ — কার্টনের বারকোড স্ক্যান করলে কার্টনের পরিমাণ।
     *
     * ⛔ বারকোডগুলো সংরক্ষিত হত, অথচ কেউ খুঁজত না — ঘরটা
     * আছে, কাজ নেই।
     */
    const packBarcodes = { 'CTN-1': { product_id: 42, qty: '12' } }

    it('প্যাকের বারকোডে প্যাকের পরিমাণ বসে, ১ নয়', () => {
        const d = desk({ barcodes, packBarcodes })

        d.code = 'CTN-1'
        d.scan()

        expect(d.$dispatch).toHaveBeenCalledWith('bulk-applied', {
            rows: [{ product_id: '42', qty: '12' }],
        })

        expect(d.code).toBe('')
        expect(d.missed).toBe('')
    })

    /*
     * ⚠️ পণ্যের নিজের বারকোড এখনো এক পিসই।
     *
     * ⓘ এই দাবিটা না থাকলে দুই তালিকার ক্রম উল্টালেও
     * উপরেরটা সবুজ থাকত, আর পিস স্ক্যান করলে বারোটা বসত।
     */
    it('পণ্যের বারকোড আগে, আর সেটায় পরিমাণ ১', () => {
        const d = desk({ barcodes, packBarcodes })

        d.code = 'BAR-1'
        d.scan()

        expect(d.$dispatch).toHaveBeenCalledWith('bulk-applied', {
            rows: [{ product_id: '42', qty: '1' }],
        })
    })

    it('দুই তালিকার কোনোটাতেই না মিললে কোডটা পর্দায় থাকে', () => {
        const d = desk({ barcodes, packBarcodes })

        d.code = 'CTN-9'
        d.scan()

        expect(d.$dispatch).not.toHaveBeenCalled()
        expect(d.missed).toBe('CTN-9')
    })
})

describe('salesLineEditor — ভাঙা যোগফল ও মজুদ', () => {
    const rows = [
        { product_id: '3', qty: '2', rate: '100', discount: '10', tax: '5' },
        { product_id: '4', qty: '1', rate: '50', discount: '', tax: '' },
    ]

    const editor = (extra = {}) => salesLineEditor({ rows, packs: {}, ...extra })

    it('মোট, ছাড় আর কর আলাদা করে গোনে', () => {
        const e = editor()

        expect(e.subtotal).toBe(250)
        expect(e.discountTotal).toBe(10)
        expect(e.taxTotal).toBe(5)

        // ⓘ পুরনো `total`-টা যা ছিল তাই আছে: মোট − ছাড় + কর
        expect(e.total).toBe(245)
    })

    it('মজুদের তালিকা না এলে ঘরটাই আসে না', () => {
        expect(editor().stockFor(rows[0])).toBeNull()
    })

    it('তালিকায় থাকলে সংখ্যাটা দেয়, না থাকলে নয়', () => {
        const e = editor({ stock: { 3: '12.5' } })

        expect(e.stockFor(rows[0])).toBe(12.5)
        expect(e.stockFor(rows[1])).toBeNull()
    })

    /*
     * ⭐ শূন্য মজুদ আর "জানা নেই" এক জিনিস নয়।
     *
     * ⛔ শূন্যকে `null` ধরলে যে পণ্যটা সত্যিই ফুরিয়ে গেছে তার পাশে
     * কিছুই লেখা থাকত না — অর্থাৎ ঠিক যে সারিটায় ইঙ্গিতটা সবচেয়ে
     * দরকার, সেখানেই চুপ।
     */
    it('শূন্য মজুদ দেখানো হয়, লুকানো হয় না', () => {
        expect(editor({ stock: { 3: '0' } }).stockFor(rows[0])).toBe(0)
    })
})
