import { describe, expect, it } from 'vitest'
import directSale from './direct-sale.js'
import { magics } from '../components/index.js'

/*
 * ⭐ এক সারিতে লটের মালের বেশি নয় — মালিক, ৪ অক্টোবর ২০২৬ (বিকল্প "ক")।
 *
 * *"এক সারিতে লটে যতটুকু আছে তার বেশি নয়; বাকিটার জন্য আরেকটা লট বেছে নতুন সারি"* — পর্দা নিজে লট ভাগ
 * করে না। ⓘ সংখ্যাগুলো লাইভের Milk Marie Premium-এর তিন লট (২৩, ৮১, ৮২)।
 *
 * ⛔ আগে: পর্দা কিছু না বলে সারি তুলত; বিল সংরক্ষণে সার্ভার "লট …-এ আছে কেবল …" বলে পুরো বিল ফেরাত,
 * আর কোন সারিটা দোষী তা খুঁজতে হত ([[StockService::issue()]])।
 */

const LOTS = {
    300: [
        { id: '2569', productId: '300', no: 'OM-10178', expiry: '', qty: '23' },
        { id: '2588', productId: '300', no: 'OM-238', expiry: '', qty: '81' },
        { id: '2643', productId: '300', no: 'OM-619', expiry: '', qty: '82' },
    ],
}

const marie = { id: 300, name: 'Milk Marie Premium', rate: '50', vatRate: 0, vatInclusive: false, trackBatch: true }

const counter = (over = {}) => {
    const c = Object.assign(directSale({
        catalogue: [marie],
        customers: [],
        walkinId: 1,
        vatEnabled: false,
        packs: {},
        paymentTermDefault: 'cash',
        carriers: [],
        depositMethods: [],
        moneyAccounts: [],
        draftKey: 'test.lot-limit',
        hasErrors: false,
        lots: LOTS,
        texts: {
            lotIsRequired: 'লট বাছতে হবে',
            lotAlreadyInCart: 'এই লট কার্টে আগেই আছে',
            lotHoldsLess: 'লট :lot-এ আছে :have — বাকি :rest নতুন সারিতে',
        },
        ...over,
    }), {
        $num: magics.num,
        $nextTick: (fn) => fn(),
        $refs: { search: { focus: () => {} } },
    })

    c.picked = marie
    c.entry.rate = '50'

    return c
}

describe('এক সারিতে লটের মালের বেশি নয়', () => {
    it('৮১-র লটে ৫০ — সারি ওঠে', async () => {
        const c = counter()
        c.entry.batchId = '2588'
        c.entry.qty = '50'

        expect(await c.addToCart()).toBe(true)
        expect(c.lines).toHaveLength(1)
    })

    it('ঠিক লটের সমান (৮১) — সারি ওঠে', async () => {
        const c = counter()
        c.entry.batchId = '2588'
        c.entry.qty = '81'

        expect(await c.addToCart()).toBe(true)
    })

    it('২৩-এর লটে ৫০ — থামে, আর বলে লটে ২৩, বাকি ২৭; পর্দা নিজে কোনো সারি বসায় না', async () => {
        const c = counter()
        c.entry.batchId = '2569'
        c.entry.qty = '50'

        expect(await c.addToCart()).toBe(false)
        expect(c.lines).toHaveLength(0)
        expect(c.lotWarning).toBe('লট OM-10178-এ আছে 23 — বাকি 27 নতুন সারিতে')
    })

    it('বাকিটা মানুষ নিজে আরেক লটে দিলে দুই সারি — ২৩ আর ২৭', async () => {
        const c = counter()
        c.entry.batchId = '2569'
        c.entry.qty = '23'
        expect(await c.addToCart()).toBe(true)

        c.picked = marie
        c.entry.rate = '50'
        c.entry.batchId = '2588'
        c.entry.qty = '27'
        expect(await c.addToCart()).toBe(true)

        expect(c.lines.map(l => [l.batchId, l.qty])).toEqual([['2569', '23'], ['2588', '27']])
    })

    /* ⓘ কার্টনে লিখলে পিসে গুনে মেলানো — ১ কার্টন = ২৪ পিস; ২৩-এর লটে ১ কার্টনও বেশি */
    it('কার্টনে লেখা পরিমাণ পিসে গুনে মেলে', async () => {
        const c = counter({
            packs: { 300: [{ id: 1, label: 'পিস', factor: '1' }, { id: 2, label: 'কার্টন', factor: '24' }] },
        })
        c.entry.batchId = '2569'
        c.entry.qty = '1'
        c.entry.unitId = '2'

        expect(await c.addToCart()).toBe(false)
        expect(c.lotWarning).toBe('লট OM-10178-এ আছে 23 — বাকি 1 নতুন সারিতে')

        c.entry.batchId = '2588'
        expect(await c.addToCart()).toBe(true)
    })
})
