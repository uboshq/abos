import { describe, expect, it } from 'vitest'
import { voucherMoney } from './money.js'
import partyVoucher from '../party-voucher.js'

/*
 * ⭐ ভাঁজের মাথার ছোট লেখা — মালিক, ৮ নভেম্বর ২০২৬ ("egulo vaj kore rako zokon lagbe tokhon kule nibe"; নমুনা "ok")।
 * ভাঁজ বন্ধ থাকলেও মাথায় দেখা যায় ভিতরে কী আছে: কাটা (VDS · AIT · কম-বেশি), খোলা বিল (সংখ্যা · বাকি · প্রয়োগ),
 * সংযুক্তি (ফাইল · কার মাধ্যমে)। ⓘ গেটারগুলো কারখানার সাধারণ বস্তুতেই চলে — Alpine লাগে না।
 */

const peekTexts = {
    cut: 'VDS :vds · AIT :ait · :diff',
    diffNone: 'কম-বেশি নেই',
    diff: 'কম-বেশি :amount',
    noFile: 'কোনো ফাইল নেই',
    file: 'ফাইল: :name',
    carrier: 'কার মাধ্যমে: :name',
    direct: 'সরাসরি',
}

describe('কাটার ভাঁজের মাথা', () => {
    it('ফাঁকা ঘরে শূন্য আর "কম-বেশি নেই"; লেখা অঙ্ক লাখ-কমায়; কম-বেশির ধরন বাছলে তার অঙ্ক', () => {
        const money = voucherMoney({ peekTexts })
        expect(money.cutPeek, '⛔ ফাঁকা কাটায় মাথার লেখা নমুনার নয়').toBe('VDS 0.00 · AIT 0.00 · কম-বেশি নেই')

        money.vds = '1,20,000'
        money.ait = '45.5'
        expect(money.cutPeek, '⛔ লেখা VDS/AIT মাথায় ওঠেনি — বা ভুল ক্রমে').toBe('VDS 1,20,000.00 · AIT 45.50 · কম-বেশি নেই')

        money.differenceKind = 'discount'
        money.difference = '12'
        expect(money.cutPeek, '⛔ কম-বেশির ধরন বাছার পরও মাথায় "নেই"').toBe('VDS 1,20,000.00 · AIT 45.50 · কম-বেশি 12.00')
    })
})

describe('সংযুক্তির ভাঁজের মাথা', () => {
    it('শুরুতে সার্ভারের বাছা নাম; ফাইল বাছলে তার নাম; "কার মাধ্যমে" ফাঁকা করলে "সরাসরি"', () => {
        const money = voucherMoney({ peekTexts, carrierLabel: 'রহিম উদ্দিন' })
        expect(money.attachPeek, '⛔ সম্পাদনায় আগের "কার মাধ্যমে" মাথায় নেই').toBe('কোনো ফাইল নেই · কার মাধ্যমে: রহিম উদ্দিন')

        money.heardAttach({ target: { type: 'file', files: [{ name: 'slip.jpg' }] } })
        expect(money.attachPeek, '⛔ বাছা ফাইলের নাম মাথায় ওঠেনি').toBe('ফাইল: slip.jpg · কার মাধ্যমে: রহিম উদ্দিন')

        money.heardAttach({ target: { type: 'select-one', name: 'carried_by', value: '', selectedOptions: [{ textContent: 'সরাসরি — কেউ যায়নি' }] } })
        expect(money.attachPeek, '⛔ "কার মাধ্যমে" ফাঁকা করার পরও আগের নাম').toBe('ফাইল: slip.jpg · কার মাধ্যমে: সরাসরি')

        money.heardAttach({ target: { type: 'select-one', name: 'carried_by', value: '7', selectedOptions: [{ textContent: ' Abu Kawser ' }] } })
        expect(money.attachPeek).toBe('ফাইল: slip.jpg · কার মাধ্যমে: Abu Kawser')

        money.heardAttach({ target: { type: 'file', files: [] } })
        expect(money.attachPeek, '⛔ ফাইল সরানোর পরও পুরনো নাম').toBe('কোনো ফাইল নেই · কার মাধ্যমে: Abu Kawser')
    })
})

describe('খোলা বিলের ভাঁজের মাথা', () => {
    it('কেবল বিল গোনে (নোট নয়); বাকির যোগফল আর এই কাগজে প্রয়োগ', () => {
        const party = partyVoucher({ partyType: 'customer', partyId: '', dueUrl: '', texts: { billsPeek: ':nটা বিল · বাকি ৳:due · এই রসিদে প্রয়োগ :applied' } })
        party.bills = [
            { id: 1, public_id: 'a', kind: 'invoice', outstanding: '50893.87' },
            { id: 2, public_id: 'b', outstanding: '1000' },
            { id: 3, public_id: 'c', kind: 'credit_note', outstanding: '-500' },
        ]
        expect(party.billsPeek, '⛔ মাথায় বিলের সংখ্যা বা বাকি ভুল — নোটও গোনা হল?').toBe('2টা বিল · বাকি ৳51,893.87 · এই রসিদে প্রয়োগ ৳0.00')

        party.allocs = { a: '50893.87' }
        expect(party.billsPeek, '⛔ টিক দেওয়া বিলের প্রয়োগ মাথায় নেই').toBe('2টা বিল · বাকি ৳51,893.87 · এই রসিদে প্রয়োগ ৳50,893.87')
    })
})
