// @vitest-environment happy-dom
/*
 * ⭐ নোট আর স্টক ছাড়া ভাউচারের সারির ছক — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬ ([[trade-lines.js]])।
 *
 * ⓘ অঙ্ক সার্ভারের [[LineMath]]-এর হুবহু, খাতার ছবি [[NotePosting]] আর [[TradeVoucherService::postingLines()]]-এর হুবহু —
 *   এখানে হাতে গোনা অঙ্কে মাপা।
 */
import { describe, expect, it } from 'vitest'

import { addDays, figures, money, plain, toNumber, tradeLines } from './trade-lines.js'

const taxes = [{ id: 1, code: 'V15', name: 'মূসক 15%', rate: '15' }, { id: 2, code: 'V5', name: 'মূসক 5%', rate: '5' }]
const accounts = [
    { id: 41, code: '4100', name: 'বিক্রয়' },
    { id: 43, code: '4360', name: 'পরিবহন ভাড়া আদায়' },
    { id: 52, code: '5206', name: 'মেরামত' },
    { id: 53, code: '5216', name: 'জ্বালানি' },
]
const products = [
    { id: 7, name: 'A4 কাগজ', unit: 'রিম', sale_price: '0', purchase_price: '450.0000', tax_id: 1, vat_rate: '15' },
    { id: 9, name: 'টোনার', unit: 'পিস', sale_price: '3500.0000', purchase_price: '0', tax_id: 1, vat_rate: '15' },
]
const event = (value) => ({ target: { value: String(value), checked: Boolean(value), name: '' } })

const make = (config) => {
    const c = tradeLines({ taxes, accounts, products, partyId: 5, labels: {}, text: {}, ...config })

    c.init()

    return c
}

// ⓘ CN-এর বিল: Cosmos 200 কেস @ 242.50 (15%), Lexus 30 কার্টন @ 1150 (15%)
const saleBill = {
    no: 'INV-2381',
    issued_at: '12 Nov 2026 · 10:15',
    room: '12000.0000',
    lines: [
        { line_id: 11, product_id: 1, name: 'Cosmos Biscuit 40gm', unit: 'কেস', qty: '200.0000', rate: '242.5000', vat_rate: '15', in_stock: null },
        { line_id: 12, product_id: 2, name: 'Lexus Vegetable 240g', unit: 'কার্টন', qty: '30.0000', rate: '1150.0000', vat_rate: '15', in_stock: null },
    ],
}

describe('অঙ্ক — LineMath-এর হুবহু', () => {
    it('অঙ্ক = পরিমাণ × দর, দুই দশমিকে শূন্য থেকে দূরে গোল', () => {
        const f = figures('3', '0.335', null, '15')

        expect(plain(f.amount)).toBe('1.01')
        expect(plain(f.vat)).toBe('0.15')
        expect(plain(f.total)).toBe('1.16')
    })

    it('float-এর ফাঁদে পড়ে না — 1.005 × 1 = 1.01, 1.00 নয়', () => {
        expect(plain(figures('1', '1.005', null, '0').amount)).toBe('1.01')
    })

    it('কমা আর অচেনা লেখা — কমা বাদ, অচেনা = 0', () => {
        expect(money('1,150.50')).toBe(11505000n)
        expect(money('abc')).toBe(0n)
        expect(money('2.123456')).toBe(21234n)
    })

    it('তারিখ + দিন — ইংরেজি অঙ্কে "23 Dec 2026"', () => {
        expect(addDays('2026-11-23', 30)).toBe('23 Dec 2026')
    })
})

describe('সারির ঘর', () => {
    it('অঙ্ক লিখলে দর নিজে বদলায় — অঙ্ক ÷ পরিমাণ', () => {
        const c = make({ doc: 'puv' })
        const line = c.lines[0]

        c.setQty(line, event('3'))
        c.setRate(line, event('100'))
        c.setAmount(line, event('1000'))

        expect(line.rate).toBe('333.3333')
        expect(c.amountShown(line)).toBe('1000')
        expect(plain(c.lineFigures(line).amount)).toBe('1000.00')
        // ⓘ পরিমাণ বা দর আবার বদলালে অঙ্ক = পরিমাণ × দর
        c.setRate(line, event('10'))
        expect(c.amountShown(line)).toBe('30.00')
    })

    it('পরিমাণ বিলের পরিমাণের বেশি নয়', () => {
        const c = make({ doc: 'cn', kind: 'customer' })

        c.applyBill(saleBill)
        const input = event('250')

        c.setQty(c.lines[0], input)

        expect(c.lines[0].qty).toBe('200.00')
        expect(input.target.value).toBe('200.00')
        expect(c.billedShown(c.lines[0])).toBe('200.00')
    })

    it('বিলের সারিতে ভ্যাট বিলের হারে তালাবদ্ধ — ভ্যাটের কোড বাছলেও না', () => {
        const c = make({ doc: 'cn', kind: 'customer' })

        c.applyBill(saleBill)
        const line = c.lines[0]

        line.tax_id = '2'
        c.setRate(line, event('20'))

        expect(c.vatLocked(line)).toBe(true)
        expect(c.vatPickable(line)).toBe(false)
        expect(c.vatLockedText(line)).toBe('15%')
        expect(plain(c.lineFigures(line).vat)).toBe('600.00')
        expect(c.serialise()[0].tax_id).toBeNull()
    })

    it('বিল ছাড়া নোটে ভ্যাট ০ — "সমন্বয় নয়"', () => {
        const c = make({ doc: 'cn', kind: 'customer' })
        const line = c.lines[0]

        expect(line.type).toBe('account')
        line.account_id = '41'
        line.tax_id = '1'
        c.setAmount(line, event('100000'))

        expect(c.noVat()).toBe(true)
        expect(c.vatPickable(line)).toBe(false)
        expect(plain(c.sums().vat)).toBe('0.00')
        expect(c.sumGrossText).toBe('1,00,000.00')
    })

    it('সরবরাহকারীর DN-এ মূসক-৬.৭ নম্বর আর তারিখ দুটো না থাকলে ভ্যাট ০', () => {
        const c = make({ doc: 'dn', kind: 'supplier' })

        c.applyBill({ ...saleBill, room: null })
        expect(c.vatAllowed).toBe(false)
        c.supNo = 'SL-CN-881'
        expect(c.vatAllowed).toBe(false)
        c.supDate = '2026-11-22'
        expect(c.vatAllowed).toBe(true)
    })
})

describe('খাতায় যা উঠবে', () => {
    it('মাল ফেরতের সারি নোটের দাখিলায় নেই — আলাদা, আর দুই দিকই সমান', () => {
        const c = make({ doc: 'cn', kind: 'customer' })

        c.applyBill(saleBill)
        const [price, returned] = c.lines

        c.setRate(price, event('20'))                // 200 × 20 = 4,000 + 600 ভ্যাট
        c.setQty(returned, event('6'))
        c.setGoods(returned, { target: { checked: true } })   // 6 × 1150 = 6,900 + 1,035 ভ্যাট

        const ledger = c.ledger()

        expect(ledger.rows.map((r) => [r.code, toNumber(r.debit), toNumber(r.credit)])).toEqual([
            ['4100', 4000, 0],
            ['2120', 600, 0],
            ['1110', 0, 4600],
        ])
        expect(ledger.dr).toBe(ledger.cr)
        expect(c.balanced).toBe(true)
        expect(ledger.returns.map((r) => [r.code, toNumber(r.debit), toNumber(r.credit)])).toEqual([
            ['4110', 6900, 0],
            ['2120', 1035, 0],
            ['1110', 0, 7935],
        ])
        // ⓘ সারাংশে সব সারি — নকশার মতো (গ্রাহকের বাকি কমবে 12,535)
        expect(c.bottomText).toBe('12,535.00')
    })

    it('মাল ফেরতের সারির দর বিলের দর — হাতে বদলায় না', () => {
        const c = make({ doc: 'cn', kind: 'customer' })

        c.applyBill(saleBill)
        const line = c.lines[1]

        c.setRate(line, event('5'))
        c.setGoods(line, { target: { checked: true } })

        expect(c.rateLocked(line)).toBe(true)
        expect(c.serialise()[1].rate).toBe('1150.0000')
        expect(c.serialise()[1].goods_moved).toBe(true)
    })

    it('সরবরাহকারীর DN — মজুদে থাকা ভাগ 1120, বিক্রি হয়ে যাওয়া ভাগ 5150', () => {
        const c = make({ doc: 'dn', kind: 'supplier', supplierNoteNo: 'SL-CN-881', supplierNoteDate: '2026-11-22' })

        c.applyBill({
            no: 'PBL-0412',
            issued_at: null,
            room: null,
            lines: [{ line_id: 21, product_id: 1, name: 'Cosmos', unit: 'কার্টন', qty: '200', rate: '1200', vat_rate: '15', in_stock: '125' }],
        })
        c.setRate(c.lines[0], event('40'))          // 200 × 40 = 8,000; মজুদে 125/200 = 5,000, বিক্রি 3,000

        const rows = c.ledger().rows.map((r) => [r.code, toNumber(r.debit), toNumber(r.credit)])

        expect(rows).toEqual([
            ['2111', 9200, 0],
            ['1120', 0, 5000],
            ['5150', 0, 3000],
            ['2120', 0, 1200],
        ])
        expect(c.balanced).toBe(true)
    })

    it('ক্রয় ভাউচারে উৎসে কর পাওনা কমায় — Cr 2118 মোট − TDS, Cr 2121 TDS', () => {
        const c = make({ doc: 'puv', productAccount: '5100 · বিক্রীত পণ্যের ব্যয়' })
        const line = c.lines[0]

        line.product_id = '7'
        c.productPicked(line)
        c.setQty(line, event('40'))                  // 40 × 450 = 18,000 + 2,700
        c.tds = '600'

        expect(c.bottomText).toBe('20,100.00')
        expect(c.ledger().rows.map((r) => [r.code, toNumber(r.debit), toNumber(r.credit)])).toEqual([
            ['5100', 18000, 0],
            ['1139', 2700, 0],
            ['2118', 0, 20100],
            ['2121', 0, 600],
        ])
        expect(c.balanced).toBe(true)
    })

    it('বিক্রয় ভাউচারে উৎসে কর নেই — Dr 1110 মোট', () => {
        const c = make({ doc: 'sv', productAccount: '4100 · বিক্রয়', tds: '500' })
        const line = c.lines[0]

        line.product_id = '9'
        c.productPicked(line)
        c.setQty(line, event('2'))

        expect(line.rate).toBe('3500.00')
        expect(c.bottomText).toBe('8,050.00')
        expect(c.ledger().rows[0]).toMatchObject({ code: '1110', debit: 80500000n })
    })
})

describe('জমা', () => {
    it('SV/PUV-তে টাকার কোনো ঘর নেই — মাধ্যম, ব্যাংক বা নগদ', () => {
        for (const doc of ['sv', 'puv']) {
            const c = make({ doc })
            const keys = Object.keys(c).join(' ')

            expect(keys).not.toMatch(/mode|bank|cash|method|payment/i)
            expect(Object.keys(c.serialise()[0])).not.toContain('payment_mode')
        }
    })

    it('lines_json-এর গড়ন — নোটে source_line_id আর goods_moved, ভাউচারে নয়', () => {
        const note = make({ doc: 'cn', kind: 'customer' })

        note.applyBill(saleBill)
        const sent = JSON.parse(note.linesJson)

        expect(sent[0]).toEqual({
            type: 'product',
            product_id: 1,
            item_name: null,
            account_id: null,
            description: null,
            qty: '200.00',
            rate: '242.50',
            amount: null,
            tax_id: null,
            unit: null,
            source_line_id: 11,
            goods_moved: false,
        })

        const voucher = make({ doc: 'sv' })
        const line = voucher.lines[0]

        line.type = 'account'
        voucher.typeChanged(line)
        line.account_id = '43'
        line.tax_id = '1'
        voucher.setAmount(line, event('2500'))

        expect(JSON.parse(voucher.linesJson)[0]).toEqual({
            type: 'account',
            product_id: null,
            item_name: null,
            account_id: 43,
            description: null,
            qty: '1',
            rate: '2500',
            amount: '2500',
            tax_id: 1,
            unit: null,
        })
    })

    it('ফেরানো জমার সারি ফিরে আসে — লেখা অঙ্কসহ', () => {
        const c = make({ doc: 'puv', lines: [{ type: 'service', item_name: 'এসি সার্ভিস', account_id: 52, qty: '4', rate: '3000', amount: null, tax_id: 1, unit: 'বার' }] })

        expect(c.lines).toHaveLength(1)
        expect(c.lines[0]).toMatchObject({ type: 'service', item_name: 'এসি সার্ভিস', account_id: '52', tax_id: '1', unit: 'বার' })
        expect(c.lineTotalText(c.lines[0])).toBe('13,800.00')
    })

    it('পক্ষ বদলালে আগের পক্ষের বিল সরে যায়', () => {
        const c = make({ doc: 'cn', kind: 'customer' })

        c.applyBill(saleBill)
        c.changed({ target: { name: 'party_id', value: '8' } })

        expect(c.partyId).toBe('8')
        expect(c.hasBill).toBe(false)
        expect(c.lines.every((l) => l.source_line_id === null)).toBe(true)
    })
})
