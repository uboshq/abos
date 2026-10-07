import { describe, expect, it } from 'vitest'
import { invoiceCollection } from './documents.js'

/*
 * ⭐ আদায়ের পর্দায় "পুরনো বিল আগে" — Accounts-Finance অডিট ম১, ৫ অক্টোবর ২০২৬।
 * ⓘ এক টাকায় বহু বিল এখন কেবল এই পর্দায় (রসিদে একটাই বিল); টাকা বয়সের ক্রমে, প্রতিটা বিলে যতটুকু বাকি ততটুকুই।
 */
function make(amount, open) {
    const box = invoiceCollection({ customerId: '5', rows: [], open })
    box.$root = { closest: () => ({ querySelector: () => ({ value: amount }) }) }
    box.init()

    return box
}

const open = [
    { id: '30', customer_id: '5', due: '100.0000', date: '2026-09-20' },
    { id: '10', customer_id: '5', due: '50.0000', date: '2026-08-01' },
    { id: '20', customer_id: '5', due: '0.0000', date: '2026-07-01' },
    { id: '40', customer_id: '9', due: '500.0000', date: '2026-01-01' },
]

describe('invoiceCollection — পুরনো বিল আগে', () => {
    it('পুরনোটা আগে, বাকির বেশি নয়, টাকা ফুরালে থামে', () => {
        const box = make('120', open)

        box.oldestFirst()

        expect(box.rows).toEqual([
            { sales_invoice_id: '10', amount: '50.00' },
            { sales_invoice_id: '30', amount: '70.00' },
        ])
    })

    it('অন্য গ্রাহকের বিল আর শোধ হওয়া বিল আসে না', () => {
        const box = make('1000', open)

        box.oldestFirst()

        expect(box.rows.map((r) => r.sales_invoice_id)).toEqual(['10', '30'])
    })

    it('টাকা না লিখলে একটা খালি সারি থাকে', () => {
        const box = make('', open)

        box.oldestFirst()

        expect(box.rows).toEqual([{ sales_invoice_id: '', amount: '' }])
    })
})
