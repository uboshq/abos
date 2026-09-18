import { beforeEach, describe, expect, it } from 'vitest'
import directPurchase from './direct-purchase.js'

/*
 * কাউন্টারে ক্রয়ের অঙ্ক।
 *
 * ── ⭐ কেন বিক্রয়ের পাশাপাশি আলাদা ফাইল — ১৮ সেপ্টেম্বর ২০২৬ ──────────
 * দুইটা পর্দার যুক্তি প্রায় এক, কিন্তু **প্রায়**। ⚠️ ক্রয়ে ছাড় সবসময়
 * টাকায় বসে (`pur_bill_lines.discount` একটা টাকার কলাম), আর বিক্রয়ে
 * শতাংশে। ⓘ এক ফাইলে মিলিয়ে লিখলে ঐ তফাতটা চোখে পড়ত না, আর একদিন
 * কেউ একটাকে অন্যটার মতো "ঠিক" করে দিতেন।
 *
 * ⛔ নিরীক্ষায় দেখা গেছে একই বাগ ইতিমধ্যে **চার জায়গায়** পাওয়া গেছে
 * (কমিট 9197153) — এটাই নকলের দাম, আর সেজন্যই দুইটার আলাদা পরীক্ষা।
 */

const product = (over = {}) => ({
    id: 1,
    name: 'চাল',
    tax_rate: 0,
    tax_inclusive: false,
    ...over,
})

const counter = (over = {}) => directPurchase({
    catalogue: [product()],
    vatEnabled: false,
    lastRatesUrl: '/last-rates/0',
    depositMethods: [],
    moneyAccounts: [],
    carriers: [],
    packs: [],
    suppliers: [],
    paymentTermDefault: 'cash',
    draftKey: 'test.purchase',
    hasErrors: false,
    accountCodes: { cash: '1010', bank: '1020', mfs: '1030', cheque: '1020' },
    texts: { paidMoreConfirm: 'বেশি দিচ্ছেন?' },
    ...over,
})

describe('ছাড় — ক্রয়ে সবসময় টাকায় বসে', () => {
    let c

    beforeEach(() => {
        c = counter()
        c.picked = product()
        c.entry.qty = '10'
        c.entry.rate = '100'
    })

    it('টাকার ছাড় যেমন লেখা তেমনই', () => {
        c.entry.discount = '150'
        c.entry.discount_mode = 'amount'

        expect(c.entryDiscount).toBe(150)
    })

    /*
     * ⭐ শতাংশটা হারায় না, **রূপান্তরিত হয়**।
     *
     * ⚠️ একই কলামে কখনো টাকা কখনো শতাংশ বসলে একদিন কেউ ৫ লিখতেন আর
     * ৫ টাকা বাদ যেত, যেখানে তিনি ৫% বুঝিয়েছিলেন — ⛔ আর কোনো ত্রুটি
     * হত না, কেবল সংখ্যাটা ভুল হত।
     */
    it('শতাংশ এখানেই টাকা হয়ে যায়', () => {
        c.entry.discount = '5'
        c.entry.discount_mode = 'percent'

        expect(c.entryDiscount).toBe(50)
    })

    /*
     * ⛔ ছাড় লাইনের চেয়ে বড় হলে পর্দা **থামিয়ে দেয়**, চুপচাপ কেটে
     * দেয় না। ⓘ বিক্রয়ের দিকে ওটা কেটে দেওয়া হয় (সেখানে ঋণাত্মক
     * লাইন মানে গ্রাহককে টাকা ফেরত), আর এখানে জানানো হয় — দুইটা
     * আলাদা সিদ্ধান্ত, আর দুইটাই ইচ্ছাকৃত।
     */
    it('ছাড় লাইনের চেয়ে বড় হলে পর্দা সেটা বলে', () => {
        c.entry.discount = '2000'
        c.entry.discount_mode = 'amount'

        expect(c.discountOverLine).toBe(true)
    })
})

describe('ভ্যাট — সার্ভারের সূত্রেরই নকল', () => {
    it('ভ্যাট বন্ধ থাকলে শূন্য', () => {
        const c = counter({ vatEnabled: false })

        expect(c.taxOn(1000, 'product', 0, product({ tax_rate: 15 }))).toBe(0)
    })

    it('ধরন "নেই" হলে শূন্য', () => {
        const c = counter({ vatEnabled: true })

        expect(c.taxOn(1000, 'none', 0, product({ tax_rate: 15 }))).toBe(0)
    })

    it('হাতে লেখা অঙ্ক থাকলে সেটাই', () => {
        const c = counter({ vatEnabled: true })

        expect(c.taxOn(1000, 'amount', '75', product({ tax_rate: 15 }))).toBe(75)
    })

    it('দামের বাইরের ভ্যাট উপরে যোগ হয়', () => {
        const c = counter({ vatEnabled: true })

        expect(c.taxOn(1000, 'product', 0, product({ tax_rate: 15 }))).toBe(150)
    })

    /*
     * ⚠️ দামের ভিতরে থাকলে মোট **বাড়ে না** — ১১৫-তে ১৫% মানে
     * ১১৫ − (১১৫ ÷ ১.১৫) = ১৫, ১১৫ × ০.১৫ নয়।
     *
     * ⛔ ভুলটা হলে প্রতিটা ক্রয়ে ভ্যাট বেশি বসত, আর খতিয়ানে রেয়াত
     * দাবি করা হত এমন টাকার উপর যা কখনো দেওয়াই হয়নি।
     */
    it('দামের ভিতরের ভ্যাট ভিতর থেকেই বেরোয়', () => {
        const c = counter({ vatEnabled: true })
        const inclusive = product({ tax_rate: 15, tax_inclusive: true })

        expect(c.taxOn(115, 'product', 0, inclusive)).toBeCloseTo(15, 6)
    })

    it('ভিতরের ভ্যাটে লাইনের মোট বাড়ে না', () => {
        const c = counter({ vatEnabled: true })
        c.picked = product({ tax_rate: 15, tax_inclusive: true })
        c.entry.qty = '1'
        c.entry.rate = '115'
        c.entry.vat_mode = 'product'

        expect(c.entryNet).toBe(115)
    })
})

describe('বকেয়া — দুইটা, আর দুইটার মানে আলাদা', () => {
    /*
     * ⓘ পর্দায় দুইটা বকেয়া: **এই বিলে** কত বাকি, আর সরবরাহকারীকে
     * **মোট** কত। ⚠️ এক নামে দুই অর্থ থাকলে দুইজন মানুষ দুইটা উত্তর পান।
     */
    it('এই বিলের বকেয়া = দেয় − দেওয়া', () => {
        const c = counter()
        c.lines = [{ qty: '10', rate: '100', discount: '0', vat_mode: 'none', tax: 0 }]
        c.paidNow = '400'

        expect(c.netPayable).toBe(1000)
        expect(c.invoiceDue).toBe(600)
    })

    /*
     * ⛔ বেশি দিলে বকেয়া ঋণাত্মক হয় না, শূন্য হয়।
     *
     * ⚠️ ঋণাত্মক দেখালে সেটা "সরবরাহকারী আমাদের টাকা দেবেন" বলে পড়া
     * হত — অথচ ওটা অগ্রিম, আর অগ্রিমের হিসাব আলাদা খাতে।
     */
    it('বেশি দিলে বকেয়া শূন্যে থামে', () => {
        const c = counter()
        c.lines = [{ qty: '1', rate: '100', discount: '0', vat_mode: 'none', tax: 0 }]
        c.paidNow = '500'

        expect(c.invoiceDue).toBe(0)
    })

    /*
     * ⭐ মোট বকেয়া = এই বিলে বাকি + আগের বকেয়া।
     *
     * ⓘ আগের বকেয়া ঋণাত্মক হতে পারে — অগ্রিম দেওয়া থাকলে। ⚠️ তখন
     * যোগফলটা এমনিতেই কমে, আর সেটাই ঠিক: অগ্রিম টাকাটা এই বিলের দায় মেটায়।
     */
    it('আগের অগ্রিম মোট বকেয়া কমিয়ে দেয়', () => {
        const c = counter()
        c.lines = [{ qty: '1', rate: '100', discount: '0', vat_mode: 'none', tax: 0 }]
        c.paidNow = '0'
        c.previousDue = -40

        expect(c.invoiceDue).toBe(100)
        expect(c.totalDue).toBe(60)
    })
})
