import { describe, expect, it } from 'vitest'
import { reprice } from './pricing.js'

/*
 * বিক্রয়মূল্যের অঙ্ক।
 *
 * ── এই ফাইলটা কেন আছে ───────────────────────────────────────────────
 * এখানকার ভুল কোনো ত্রুটিবার্তা দেয় না। শুধু প্রতিটা লাইনে একটু কম
 * দামে বিক্রি হয় — সারা বছর, প্রতিটা পণ্যে — আর বছরশেষে কেউ ধরতে পারে
 * না কেন মুনাফা কম পড়ল।
 *
 * সবচেয়ে জরুরি পরীক্ষাটা প্রথমেই: markup আর margin আলাদা সংখ্যা।
 */

const row = (over = {}) => ({
    rate: '', sales_price: '', markup: '', margin: '', anchor: '', ...over,
})

describe('markup আর margin এক জিনিস নয়', () => {
    it('১০০-তে কিনে ১৫০-তে বেচা মানে ৫০% markup, কিন্তু ৩৩.৩৩% margin', () => {
        const patch = reprice(row({ rate: '100', sales_price: '150' }), 'sales_price')

        expect(patch.markup).toBe('50.00')
        expect(patch.margin).toBe('33.33')
    })

    it('৪০% markup আর ৪০% margin দুইটা আলাদা দামে পৌঁছায়', () => {
        const byMarkup = reprice(row({ rate: '100', markup: '40' }), 'markup')
        const byMargin = reprice(row({ rate: '100', margin: '40' }), 'margin')

        expect(byMarkup.sales_price).toBe('140.00')
        expect(byMargin.sales_price).toBe('166.67')

        // ঠিক এই পার্থক্যটাই ধরা না পড়লে ডিপো সারা বছর ২৬.৬৭ টাকা কমে বেচত
        expect(byMarkup.sales_price).not.toBe(byMargin.sales_price)
    })
})

describe('নোঙর — শেষে যে ঘরে লেখা হয়েছিল', () => {
    it('markup লেখা থাকলে দর বদলালে দাম নতুন করে বসে', () => {
        const r = row({ rate: '100', markup: '50' })
        Object.assign(r, reprice(r, 'markup'))

        expect(r.sales_price).toBe('150.00')

        // দর ১১০ হল — নীতিটা ৫০% markup, তাই দাম ১৬৫
        r.rate = '110'
        Object.assign(r, reprice(r, 'rate'))

        expect(r.sales_price).toBe('165.00')
        expect(r.markup).toBe('50.00')
    })

    it('দাম লেখা থাকলে দর বদলালে দামটাই টেকে, বদলায় margin', () => {
        const r = row({ rate: '100', sales_price: '150' })
        Object.assign(r, reprice(r, 'sales_price'))

        expect(r.markup).toBe('50.00')

        // দর ১১০ হল — দামটা মানুষের বলা, ওটা নড়ে না
        r.rate = '110'
        Object.assign(r, reprice(r, 'rate'))

        // অঙ্কটা দেখা হয়, লেখার ধরনটা নয় — মানুষ যা টাইপ করেছেন তা
        // হুবহু থাকে, "150" কে "150.00" বানিয়ে দেওয়া হয় না
        expect(parseFloat(r.sales_price)).toBe(150)
        expect(r.markup).toBe('36.36')
        expect(r.margin).toBe('26.67')
    })

    it('markup থেকে margin-এ সরে গেলে নোঙরও সরে', () => {
        const r = row({ rate: '100', markup: '50' })
        Object.assign(r, reprice(r, 'markup'))

        r.margin = '20'
        Object.assign(r, reprice(r, 'margin'))

        expect(r.anchor).toBe('margin')
        expect(r.sales_price).toBe('125.00')
        expect(r.markup).toBe('25.00')
    })
})

describe('যে ঘরে কার্সর আছে সেটা ছোঁয়া হয় না', () => {
    it('markup লিখতে থাকলে markup ফেরত আসে না', () => {
        const patch = reprice(row({ rate: '100', markup: '4' }), 'markup')

        expect(patch).not.toHaveProperty('markup')
        expect(patch.sales_price).toBe('104.00')
    })

    it('margin লিখতে থাকলে margin ফেরত আসে না', () => {
        const patch = reprice(row({ rate: '100', margin: '3' }), 'margin')

        expect(patch).not.toHaveProperty('margin')
    })

    it('দাম লিখতে থাকলে দাম ফেরত আসে না', () => {
        const patch = reprice(row({ rate: '100', sales_price: '12' }), 'sales_price')

        expect(patch).not.toHaveProperty('sales_price')
        expect(patch.markup).toBe('-88.00')
    })
})

describe('কোনোটা না ছোঁয়া পর্যন্ত কিছুই বসে না', () => {
    it('শুধু ক্রয়দর লিখলে কোনো বিক্রয়মূল্য ভেসে ওঠে না', () => {
        const patch = reprice(row({ rate: '100' }), 'rate')

        expect(patch).toEqual({})
    })
})

describe('যেসব অঙ্কের সমাধান নেই', () => {
    it('ক্রয়দর শূন্য হলে markup বসানো যায় না — অসীম', () => {
        const patch = reprice(row({ rate: '0', markup: '50' }), 'markup')

        expect(patch).toEqual({ anchor: 'markup' })
    })

    it('১০০% margin মানে খরচ শূন্য — কোনো দামেই পৌঁছানো যায় না', () => {
        const patch = reprice(row({ rate: '100', margin: '100' }), 'margin')

        expect(patch).toEqual({ anchor: 'margin' })
    })

    it('১০০-র বেশি margin-ও নয়', () => {
        const patch = reprice(row({ rate: '100', margin: '120' }), 'margin')

        expect(patch).toEqual({ anchor: 'margin' })
    })

    it('অর্ধেক লেখা সংখ্যা ("-", ".") কিছু ভাঙে না', () => {
        expect(reprice(row({ rate: '100', markup: '-' }), 'markup')).toEqual({ anchor: 'markup' })
        expect(reprice(row({ rate: '.', sales_price: '150' }), 'sales_price')).toEqual({ anchor: 'sales_price' })
    })
})

describe('ক্ষতিতে বেচা', () => {
    it('ক্রয়দরের নিচে দাম দিলে দুইটাই ঋণাত্মক দেখায়', () => {
        const patch = reprice(row({ rate: '100', sales_price: '80' }), 'sales_price')

        expect(patch.markup).toBe('-20.00')
        expect(patch.margin).toBe('-25.00')
    })
})
