import { describe, expect, it, vi } from 'vitest'
import openingCart, { makeRow, matchProducts, MORE_ROWS, rowValue } from './opening-cart.js'

/*
 * ⭐ খোলা মজুদের কার্ট — মালিক, ৬ অক্টোবর ২০২৬: সার্চ বার, ফ্রি, দর-markup-margin-বিক্রয়মূল্য, Enter = পরের ঘর, মোট।
 *
 * ⓘ কম্পোনেন্টটা সরাসরি গড়া হয়, Alpine ছাড়া; `$root`-এ নকল ঘরের তালিকা, যাতে দেখা যায় ফোকাস সত্যিই পরের ঘরে গেল।
 */
const products = [
    { id: 1, code: 'NAPA-500', name: 'Napa 500mg', barcode: '8901234567890', rate: '1.8000', sales_price: '2.0000', pricing_anchor: '', pricing_pct: null },
    { id: 2, code: 'NAPA-EXT', name: 'Napa Extra', barcode: '', rate: '2.5000', sales_price: '0.0000', pricing_anchor: 'markup', pricing_pct: '20.0000' },
    { id: 3, code: 'ACE', name: 'Ace Plus', barcode: 'NAPA', rate: '', sales_price: '', pricing_anchor: '', pricing_pct: null },
]

function make(config = {}) {
    const cart = openingCart({ products, rows: {}, count: 3, ...config })
    cart.$nextTick = (fn) => fn()

    return cart
}

function cells(n) {
    return Array.from({ length: n }, (_, i) => ({ focus: vi.fn(), dataset: { cell: i % 2 === 0 ? 'product' : 'qty' } }))
}

describe('openingCart — খোঁজা', () => {
    it('কোড, নাম বা বারকোডের যেকোনো অংশে মেলে, আর প্রতিটা শব্দ মিলতে হয়', () => {
        expect(matchProducts(make().products, 'napa').map((p) => p.id).sort()).toEqual([1, 2, 3])
        expect(matchProducts(make().products, 'ace').map((p) => p.id)).toEqual([3])
        expect(matchProducts(make().products, 'napa extra').map((p) => p.id)).toEqual([2])
        expect(matchProducts(make().products, '8901234').map((p) => p.id)).toEqual([1])
        expect(matchProducts(make().products, '   ')).toEqual([])
    })

    it('কোড বা বারকোড হুবহু মিললে সেটা সবার আগে — স্ক্যানারের জন্য', () => {
        // ⓘ "napa" তিনটাতেই আছে, কিন্তু ৩ নম্বরের বারকোড হুবহু "NAPA"
        expect(matchProducts(make().products, 'NAPA')[0].id).toBe(3)
    })

    it('লেখা বদলালে আগের বাছা id মুছে যায় — লেখা এক পণ্য, যায় আরেক id, এমন হয় না', () => {
        const cart = make()
        const row = cart.rows[0]
        cart.pick(row, cart.products[0])
        expect(row.product_id).toBe('1')

        row.search = 'Ace'
        cart.typed(row)
        expect(row.product_id).toBe('')
        expect(row.open).toBe(true)
    })

    it('তীর দিয়ে নামা-ওঠা তালিকার বাইরে যায় না', () => {
        const cart = make()
        const row = cart.rows[0]
        row.search = 'napa'
        cart.typed(row)
        cart.down(row)
        cart.down(row)
        cart.down(row)
        cart.down(row)
        expect(row.cursor).toBe(2)
        cart.up(row)
        cart.up(row)
        cart.up(row)
        expect(row.cursor).toBe(0)
    })
})

describe('openingCart — দাম', () => {
    it('পণ্য বাছলে দর আর বিক্রয়মূল্য আসে, দাম ধরে markup ও margin দেখায়', () => {
        const cart = make()
        const row = cart.rows[0]
        cart.pick(row, cart.products[0])

        // ⓘ সার্ভার পাঠায় `1.8000` — পর্দায় ছাঁটা
        expect(row.rate).toBe('1.8')
        expect(row.sales_price).toBe('2')
        expect(row.markup).toBe('11.1111')
        expect(row.margin).toBe('10')
        expect(row.anchor).toBe('')
    })

    it('পণ্যের নীতি markup হলে সেটাই নোঙর, আর দাম তার থেকে বসে', () => {
        const cart = make()
        const row = cart.rows[0]
        cart.pick(row, cart.products[1])

        expect(row.anchor).toBe('markup')
        expect(row.markup).toBe('20')
        expect(row.sales_price).toBe('3.00')
        expect(cart.pctOf(row)).toBe('20')
    })

    it('আগে লেখা দর টেকে — বাছা পণ্যের দর সেটা মোছে না', () => {
        const cart = make()
        const row = cart.rows[0]
        row.rate = '1.50'
        cart.pick(row, cart.products[0])

        expect(row.rate).toBe('1.50')
    })

    it('তিন ঘরের যেকোনোটায় লিখলে বাকি দুইটা বসে', () => {
        const cart = make()
        const row = cart.rows[0]
        row.rate = '100'

        row.markup = '50'
        cart.priced(row, 'markup')
        expect(row.sales_price).toBe('150.00')
        expect(row.margin).toBe('33.3333')
        expect(cart.pctOf(row)).toBe('50')

        row.margin = '20'
        cart.priced(row, 'margin')
        expect(row.sales_price).toBe('125.00')
        expect(row.markup).toBe('25')
        expect(cart.pctOf(row)).toBe('20')

        row.sales_price = '200'
        cart.priced(row, 'sales_price')
        expect(row.markup).toBe('100')
        expect(row.margin).toBe('50')
        expect(cart.pctOf(row)).toBe('')

        // ⓘ দর বদলালে দাম নোঙরে থাকে — markup ও margin বদলায়
        row.rate = '160'
        cart.priced(row, 'rate')
        expect(row.sales_price).toBe('200')
        expect(row.markup).toBe('25')
    })
})

describe('openingCart — ফ্রির সতর্কতা', () => {
    it('ফ্রি পরিমাণের সমান বা বেশি হলে সতর্কতা, কম বা খালি হলে নয়', () => {
        const cart = make()

        expect(cart.freeWarn({ qty: '8', free_qty: '8' })).toBe(true)
        expect(cart.freeWarn({ qty: '8', free_qty: '9' })).toBe(true)
        expect(cart.freeWarn({ qty: '8', free_qty: '7.99' })).toBe(false)
        expect(cart.freeWarn({ qty: '8', free_qty: '' })).toBe(false)
        expect(cart.freeWarn({ qty: '', free_qty: '3' })).toBe(false)
    })
})

describe('openingCart — মোট', () => {
    it('সারির মূল্য পরিমাণ × দর; ফ্রি গোনে না', () => {
        expect(rowValue({ qty: '10', rate: '2.5', free_qty: '5' })).toBe(25)
        expect(rowValue({ qty: '', rate: '2.5' })).toBe(0)
    })

    it('মোট পরিমাণ, ফ্রি আর মূল্য কেবল ভরা সারির', () => {
        const cart = make()
        Object.assign(cart.rows[0], { search: 'A', qty: '10', rate: '50', free_qty: '1' })
        Object.assign(cart.rows[2], { search: 'C', qty: '2', rate: '100' })

        expect(cart.filledCount).toBe(2)
        expect(cart.totalQty).toBe(12)
        expect(cart.totalFree).toBe(1)
        expect(cart.totalValue).toBe(700)
        expect(cart.totalValueText).toBe('700.00')
    })

    it('সার্ভার ফেরত দিলে আগের লেখা আর ভুলের বার্তা সারিতে ফেরে', () => {
        const cart = make({ rows: { 1: { product: 'NAPA-500', qty: '4', unit_cost: '9', pricing_anchor: 'margin', pricing_pct: '25', error: 'ভুল' } } })

        expect(cart.rows[1].search).toBe('NAPA-500')
        expect(cart.rows[1].rate).toBe('9')
        expect(cart.rows[1].margin).toBe('25')
        expect(cart.rows[1].error).toBe('ভুল')
        expect(cart.rows[0]).toEqual(makeRow(0))
    })
})

describe('openingCart — Enter', () => {
    it('Enter ফর্ম জমা দেয় না, পরের ঘরে যায়', () => {
        const cart = make()
        const list = cells(4)
        cart.$root = { querySelectorAll: () => list }
        const event = { preventDefault: vi.fn(), target: list[1] }

        cart.enter(event, cart.rows[0])

        expect(event.preventDefault).toHaveBeenCalled()
        expect(list[2].focus).toHaveBeenCalled()
    })

    it('খোঁজার তালিকা খোলা থাকলে Enter আগে পণ্যটা বাছে', () => {
        const cart = make()
        const list = cells(4)
        cart.$root = { querySelectorAll: () => list }
        const row = cart.rows[0]
        row.search = 'extra'
        cart.typed(row)

        cart.enter({ preventDefault: vi.fn(), target: list[0] }, row)

        expect(row.product_id).toBe('2')
        expect(list[1].focus).toHaveBeenCalled()
    })

    it('শেষ ঘরে Enter — নতুন সারি যোগ হয়ে পরের ঘরে যায়', () => {
        const cart = make()
        let list = cells(2)
        cart.$root = { querySelectorAll: () => list }
        const last = list[1]
        const grown = [...list, ...cells(2)]

        cart.$nextTick = (fn) => {
            list = grown
            fn()
        }
        cart.enter({ preventDefault: vi.fn(), target: last }, cart.rows[2])

        expect(cart.rows).toHaveLength(3 + MORE_ROWS)
        expect(new Set(cart.rows.map((r) => r.key)).size).toBe(3 + MORE_ROWS)
        expect(grown[2].focus).toHaveBeenCalled()
    })
})
