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

/*
 * ── লট — কেবল লট ধরা সারিতে, আর আগে থেকে বসানো ─────────────────────
 *
 * ⛔ ২৭ সেপ্টেম্বর ২০২৬ পর্যন্ত এই পর্দায় লটের কোনো ঘরই ছিল না, অথচ
 * নতুন পণ্য লট ধরে চলে — তাই কাউন্টার দিয়ে ঐ পণ্য কেনাই যেত না।
 */
describe('লট — কেবল লট ধরা সারিতে', () => {
    const tracked = (over = {}) => product({
        id: 7,
        name: 'ওষুধ',
        track_batch: true,
        ...over,
    })

    const withLots = (over = {}) => {
        const c = counter({
            catalogue: [product({ track_batch: false }), tracked()],
            texts: { paidMoreConfirm: 'বেশি দিচ্ছেন?' },
            lotErrors: {},
            lotHint: '051026/01-LOT',
            ...over,
        })
        c.$nextTick = fn => fn()
        c.$root = { querySelector: () => null }
        c.$refs = {}

        return c
    }

    const add = (c, p) => {
        c.pick(p)
        c.entry.rate = '60'
        c.entry.sales_price = '72.0'
        c.addToCart()

        return c.lines[c.lines.length - 1]
    }

    it('লট না-ধরা পণ্যের সারি লট চায় না, লট ধরাটা চায়', () => {
        const c = withLots()

        expect(c.tracksLot(add(c, c.catalogue[0]))).toBe(false)
        expect(c.tracksLot(add(c, c.catalogue[1]))).toBe(true)
    })

    /* ⚠️ পুরনো খসড়ার সারিতে চিহ্ন নেই — তবু তালিকা দেখে লট চায় */
    it('চিহ্নটা তালিকা থেকে পড়া, সারির কপি থেকে নয়', () => {
        const c = withLots()

        expect(c.tracksLot({ id: 7 })).toBe(true)
        expect(c.tracksLot({ id: 1, track_batch: true })).toBe(false)
    })

    it('কার্টে এই পণ্যের আগের সারি থাকলে তার লট বসে', () => {
        const c = withLots()
        const first = add(c, c.catalogue[1])
        first.batch_no = 'L-TODAY'
        first.expiry_date = '2028-01-31'

        const second = add(c, c.catalogue[1])

        expect(second.batch_no).toBe('L-TODAY')
        expect(second.expiry_date).toBe('2028-01-31')
        expect(second.lot_from).toBe('line')
    })

    /* ⭐ ৫ অক্টোবর ২০২৬ — "গতবারের লট" নয়: ঘর খালি, প্রস্তাব কেবল দেখায়, নম্বর বসে সংরক্ষণে (`PurchaseLots`) */
    it('আগের সারি না থাকলে খালি — গতবারের লট নয়, ঘরে পরের লট নম্বরের প্রস্তাব', () => {
        /* ⓘ পুরনো সার্ভার `last_lot` পাঠালেও সেটা আর বসে না */
        const c = withLots({ catalogue: [tracked({ last_lot: { batch_no: 'L-LAST', expiry_date: '2027-03-31', mrp: '95' } })] })
        const line = add(c, c.catalogue[0])

        expect(line.batch_no).toBe('')
        expect(line.expiry_date).toBe('')
        expect(line.mrp).toBe('')
        expect(line.lot_from).toBe('')
        expect(c.lotHint).toBe('051026/01-LOT')
    })

    it('লট না-ধরা পণ্যে কিছুই বসে না', () => {
        const c = withLots()
        const line = add(c, c.catalogue[0])

        expect(line.batch_no).toBe('')
        expect(c.lotProblem(line, 0)).toBe('')
    })

    /* ⭐ ৫ অক্টোবর ২০২৬ থেকে খালি লট পাঠানো থামায় না — সংরক্ষণে কাগজের লট নম্বর নিজে বসে */
    it('লট ছাড়া পাঠালে পাঠানো থামে না, সারির নিচে লাল বার্তাও নয়', () => {
        const c = withLots({ catalogue: [tracked()] })
        const line = add(c, c.catalogue[0])

        let stopped = false
        c.guard({ preventDefault: () => { stopped = true } })

        expect(stopped).toBe(false)
        expect(c.stopped).toBe('')
        expect(c.lotProblem(line, 0)).toBe('')
    })

    it('সার্ভারের সারি-ধরা বার্তাটা ঐ সারির নিচেই, লট লিখলে সরে যায়', () => {
        const c = withLots({ catalogue: [tracked()], lotErrors: { 0: 'সারি ১ — লট লাগবে' } })
        const line = add(c, c.catalogue[0])

        expect(c.lotProblem(line, 0)).toBe('সারি ১ — লট লাগবে')

        line.batch_no = 'L-1'
        expect(c.lotProblem(line, 0)).toBe('')
    })

    it('সারি মুছলে পুরনো সারি-ধরা বার্তাগুলো ভুল সারিতে বসে না', () => {
        const c = withLots({ catalogue: [tracked()], lotErrors: { 1: 'সারি ২ — লট লাগবে' } })
        add(c, c.catalogue[0])
        const second = add(c, c.catalogue[0])

        c.dropLine(0)

        expect(c.lotProblem(second, 0)).toBe('')
    })

    /* ⓘ Enter — লট → মেয়াদ → ছাপা দাম → পরের পণ্য খোঁজা */
    it('Enter পরের লট-ঘরে যায়, শেষ ঘরের পর খোঁজার ঘরে', () => {
        const c = withLots()
        const focused = []
        const box = name => ({ name, focus: () => focused.push(name) })
        const lot = box('lot')
        const expiry = box('expiry')
        const mrp = box('mrp')
        const search = box('search')
        const group = { querySelectorAll: () => [lot, expiry, mrp] }
        lot.closest = expiry.closest = mrp.closest = () => group
        c.$root = { querySelector: () => search }

        c.lotNext({ target: lot })
        c.lotNext({ target: expiry })
        c.lotNext({ target: mrp })

        expect(focused).toEqual(['expiry', 'mrp', 'search'])
    })
})

/*
 * ⭐ পরিশোধের চার্জ — ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ⛔ ঘরটা না থাকায় বিকাশে ৯০০ দিলে অ্যাপে কাটত ৯০৫, খাতায় কমত ৯০০।
 * ⓘ চার্জ যায় কেবল ব্যাংক/বিকাশের সারিতে, আর সারির **নিজের** উপায় দেখে।
 */
describe('পরিশোধের চার্জ — ব্যাংক আর বিকাশে', () => {
    const methods = [
        { id: 1, label: 'নগদ', kind: 'cash', accountId: 10 },
        { id: 2, label: 'বিকাশ', kind: 'mfs', accountId: 30 },
        { id: 3, label: 'ব্যাংক', kind: 'bank', accountId: 20 },
    ]

    it('বিকাশের সারির চার্জ সার্ভারে যায়, নগদের সারির যায় না', () => {
        const c = counter({ depositMethods: methods })

        expect(c.chargeOf({ methodId: 2, chargeAmount: '5' })).toBe('5')
        expect(c.chargeOf({ methodId: 3, chargeAmount: '11.50' })).toBe('11.50')
        expect(c.chargeOf({ methodId: 1, chargeAmount: '5' })).toBe('')
        expect(c.chargeOf({ methodId: 2, chargeAmount: '' })).toBe('')
    })

    it('চার্জ অঙ্কের সমান বা বেশি হলে যোগ করা যায় না', () => {
        const c = counter({ depositMethods: methods })
        c.depositDraft.methodId = '2'
        c.depositDraft.accountId = '30'
        c.depositDraft.amount = '900'

        c.depositDraft.chargeAmount = '900'
        expect(c.depositReady).toBe(false)

        c.depositDraft.chargeAmount = '5'
        expect(c.depositReady).toBe(true)
    })

    it('যোগ করার পরে খসড়ার চার্জ খালি — পরের সারিতে চলে যায় না', () => {
        const c = counter({ depositMethods: methods })
        Object.assign(c.depositDraft, { methodId: '2', accountId: '30', amount: '900', chargeAmount: '5' })

        c.addDeposit()

        expect(c.deposits[0].chargeAmount).toBe('5')
        expect(c.depositDraft.chargeAmount).toBe('')
    })
})

/*
 * গোটা বিলের ছাড় — মালিকের সিদ্ধান্ত (ক), ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ⭐ ভাগের নিয়ম সার্ভারের হুবহু ([[DirectPurchaseService::spreadBillDiscount()]]):
 * সারির মালের অনুপাতে, পয়সায় গোল, শেষ সারি বাকিটা। ⚠️ না মিললে সারির
 * ভ্যাট পর্দায় এক আর খাতায় আরেক — তাই অঙ্কগুলো সার্ভারের পরীক্ষার সাথে এক।
 */
describe('গোটা বিলের ছাড়', () => {
    const line = (qty, rate, over = {}) => ({ qty, rate, discount: '0', vat_mode: 'none', tax: 0, ...over })

    it('মালের অনুপাতে ভাগ হয়, আর যোগফল হুবহু লেখা ছাড়', () => {
        const c = counter()
        c.lines = [line('3', '100'), line('7', '100')]
        c.billDiscount = '100'

        expect(c.billShares).toEqual([30, 70])
        expect(c.netPayable).toBe(900)
    })

    it('তিন ভাগে না মিললে শেষ সারি বাকি পয়সাটা নেয়', () => {
        const c = counter()
        c.lines = [line('1', '100'), line('1', '100'), line('1', '100')]
        c.billDiscount = '100'

        expect(c.billShares).toEqual([33.33, 33.33, 33.34])
    })

    it('শতাংশে লিখলে মালের মোট থেকে কষা হয়', () => {
        const c = counter()
        c.lines = [line('4', '250', { discount: '100' })]
        c.billDiscountMode = 'percent'
        c.billDiscount = '10'

        // ⓘ সারির নিজের ছাড়ের পরে: ১০০০ − ১০০ = ৯০০, তার ১০% = ৯০
        expect(c.billDiscountAmount).toBe(90)
        expect(c.netPayable).toBe(810)
    })

    it('মোটের চেয়ে বড় ছাড় ভাগ হয় না, আর পর্দা সেটা বলে', () => {
        const c = counter()
        c.lines = [line('1', '100')]
        c.billDiscount = '100.01'

        expect(c.billDiscountTooBig).toBe(true)
        expect(c.billShares).toEqual([0])
        expect(c.netPayable).toBe(100)
    })

    it('"পণ্য অনুযায়ী" ভ্যাট ছাড়ের পরের দামে কষা হয়', () => {
        const c = counter({ vatEnabled: true })
        c.lines = [line('10', '100', { vat_mode: 'product', tax_rate: 15, tax_inclusive: false })]
        c.billDiscount = '200'

        // ⓘ (১০০০ − ২০০) × ১৫% = ১২০, ১৫০ নয়
        expect(c.taxTotal).toBeCloseTo(120, 6)
        expect(c.netPayable).toBeCloseTo(920, 6)
    })

    it('সব মুছলে ছাড়ও মোছে — পরের ক্রয়ে আগেরটা বসে থাকে না', () => {
        const c = counter()
        c.$refs = {}
        c.$nextTick = (fn) => fn()
        c.lines = [line('1', '100')]
        c.billDiscount = '10'
        c.billDiscountMode = 'percent'

        c.clearAll()

        expect(c.billDiscount).toBe('')
        expect(c.billDiscountMode).toBe('amount')
    })
})

/*
 * ⛔ পাঠানো থেমে যেত, আর পর্দা কিছুই বলত না — ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ── ⚠️ কী ভাঙা ছিল ───────────────────────────────────────────────────
 * `guard()`-এ **তিনটা** জায়গায় `preventDefault()` ডাকা হত আর চুপ করে
 * ফিরে আসা হত: কার্ট খালি; লট-ধরা পণ্যে লট নম্বর নেই; ভাড়া লেখা আছে
 * অথচ কে আনল বলা নেই।
 *
 * ⛔ কাউন্টারে দাঁড়ানো মানুষটার কাছে এর মানে একটাই — **বোতাম চাপলে
 * কিছুই হয় না**। ⓘ লট-এরটায় সারির নিচে একটা ছোট বার্তা বসত, কিন্তু
 * সারিটা স্ক্রলের বাইরে থাকলে সেটাও চোখে পড়ত না; বাকি দুইটায় কোথাও
 * কিছু লেখা হত না।
 *
 * ⭐ সার্ভারও আটকায় (`lines` required·min:1, `carrier_id`-এর
 * `Rule::requiredIf`) — অর্থাৎ পর্দার বার্তাটা নিরাপত্তা নয়, **ভদ্রতা**:
 * সার্ভার ফিরিয়ে দেওয়ার আগেই বলা। ⓘ লটের থামা ৫ অক্টোবর ২০২৬-এ উঠে
 * গেছে — খালি লট সংরক্ষণে নিজে বসে (`PurchaseLots`)।
 */
describe('পাঠানো থামলে পর্দা তার কারণ বলে', () => {
    /** নকল `$refs` — ফোকাস সত্যিই নড়ল কি না, প্রতিনিধি চিহ্ন দিয়ে নয়। */
    const withRefs = (c) => {
        const focused = []

        c.$nextTick = (fn) => fn()
        /* ⓘ `$root` লাগে কারণ `focusLot()` সারির ঘরটা ডিওম থেকে খোঁজে */
        c.$root = { querySelector: () => null }
        c.$refs = {
            search: { focus: () => focused.push('search') },
            carrier: { focus: () => focused.push('carrier') },
            carrierName: { focus: () => focused.push('carrierName') },
        }
        c.focused = focused

        return c
    }

    const texts = {
        paidMoreConfirm: 'বেশি দিচ্ছেন?',
        needALine: 'আগে অন্তত একটা পণ্য কার্টে দিন।',
        needACarrier: 'ভাড়া লেখা আছে — কে আনল সেটাও লিখুন।',
    }

    const tracked = (over = {}) => product({ track_batch: true, ...over })

    it('কার্ট খালি — পাঠানো থামে, আর কেন থামল তা লেখা থাকে', () => {
        const c = withRefs(counter({ texts }))

        let stopped = false
        c.guard({ preventDefault: () => { stopped = true } })

        expect(stopped).toBe(true)
        expect(c.stopped).toBe(texts.needALine)

        /* ⭐ আর কার্সর ঐ ঘরেই যায় যেখান থেকে কাজটা শুরু হয় */
        expect(c.focused).toContain('search')
    })

    /* ⓘ ৫ অক্টোবর ২০২৬ থেকে — খালি লট সংরক্ষণে নিজে বসে, তাই থামার কারণ নয় */
    it('লট নম্বর নেই — পাঠানো থামে না', () => {
        const c = withRefs(counter({ texts, catalogue: [tracked()], lots: true }))

        c.pick(c.catalogue[0])
        c.entry.qty = '1'
        c.entry.rate = '10'
        c.entry.sales_price = '12.0'
        c.addToCart()

        let stopped = false
        c.guard({ preventDefault: () => { stopped = true } })

        expect(stopped).toBe(false)
        expect(c.stopped).toBe('')
        expect(c.lotProblem(c.lines[0], 0)).toBe('')
    })

    it('ভাড়া আছে কিন্তু বাহক নেই — বার্তা, আর কার্সর বাহকের ঘরে', () => {
        /* ⓘ তালিকায় বাহক আছে — তাই কার্সর তালিকাটাতেই যাওয়ার কথা */
        const c = withRefs(counter({ texts, carriers: [{ id: 3, label: 'করিম' }] }))

        c.pick(c.catalogue[0])
        c.entry.qty = '1'
        c.entry.rate = '10'
        c.entry.sales_price = '12.0'
        c.addToCart()

        c.transportCost = '500'

        let stopped = false
        c.guard({ preventDefault: () => { stopped = true } })

        expect(stopped).toBe(true)
        expect(c.stopped).toBe(texts.needACarrier)
        expect(c.focused).toContain('carrier')

        /*
         * ⛔ আর পরিবহনের প্যানেলটা খোলা — নাহলে বার্তাটা মিথ্যা বলত।
         *
         * ⚠️ এটা ব্রাউজারে ধরা পড়েছে, এই ফাইলে নয়: নকল `$refs` নিয়ে
         * ফোকাস "নড়েছিল", অথচ আসল পর্দায় ঘরটা গুটানো বলে ব্রাউজার
         * ওখানে ফোকাস নেয়নি, আর কার্সর বোতামেই রয়ে গিয়েছিল।
         * ⓘ দাবিটা এখানে বসল যাতে পরের বার কেউ লাইনটা তুলে দিলে লাল হয়।
         */
        expect(c.transportOpen).toBe(true)
    })

    it('বাহকের তালিকা খালি হলে কার্সর হাতে-নাম লেখার ঘরে', () => {
        /*
         * ⛔ এটাই আজকের **স্বাভাবিক** দশা, ব্যতিক্রম নয়: কোনো সরবরাহকারী
         * TRANSPORT ধরনে নেই, তাই তালিকাটা `x-show`-এ লুকানো থাকে আর
         * হাতে নাম লেখার ঘরটাই একমাত্র পথ।
         *
         * ⚠️ আগে কার্সর ঐ লুকানো তালিকাতেই পাঠানো হত — ব্রাউজার কিছুই
         * করত না, কার্সর বোতামে পড়ে থাকত, আর বার্তাটা "বাহক বাছুন" বলে
         * এমন কিছু দেখাত না যা বাছা যায়। ⓘ ধরা পড়েছে পাতা খুলে, এই
         * ফাইলে নয় — নকল `$refs`-এ সবই "কাজ করছিল"।
         */
        const c = withRefs(counter({ texts, carriers: [] }))

        c.pick(c.catalogue[0])
        c.entry.qty = '1'
        c.entry.rate = '10'
        c.entry.sales_price = '12.0'
        c.addToCart()

        c.transportCost = '500'
        c.guard({ preventDefault: () => {} })

        expect(c.stopped).toBe(texts.needACarrier)
        expect(c.transportOpen).toBe(true)
        expect(c.focused).toContain('carrierName')
        expect(c.focused).not.toContain('carrier')
    })

    it('বাধা সরে গেলে বার্তাটাও সরে, আর পাঠানো এগোয়', () => {
        /*
         * ⛔ বার্তাটা মুছে না গেলে মানুষ ঠিক করার পরেও পুরনো অভিযোগটা
         * পড়তেন, আর ভাবতেন কাজ হয়নি।
         */
        const c = withRefs(counter({ texts }))

        c.guard({ preventDefault: () => {} })
        expect(c.stopped).toBe(texts.needALine)

        c.pick(c.catalogue[0])
        c.entry.qty = '1'
        c.entry.rate = '10'
        c.entry.sales_price = '12.0'
        c.addToCart()

        let stopped = false
        c.guard({ preventDefault: () => { stopped = true } })

        expect(stopped).toBe(false)
        expect(c.stopped).toBe('')
        expect(c.busy).toBe(true)
    })
})

describe('দর ০ বা বিক্রয়দর ছাড়া কার্টে নয় — মালিক, ৩ অক্টোবর ২০২৬', () => {
    const priced = () => {
        const c = counter({
            texts: { paidMoreConfirm: 'বেশি দিচ্ছেন?', needRate: 'ক্রয়দর লিখুন', needSalesPrice: 'বিক্রয়দর লিখুন', needPricedLines: 'সারিতে দর নেই' },
        })
        c.$nextTick = fn => fn()
        c.$root = { querySelector: () => null }
        c.$refs = {}
        c.pick(c.catalogue[0])

        return c
    }

    it('দর ০ হলে সারি যোগ হয় না, বার্তা আসে', () => {
        const c = priced()
        c.entry.rate = '0'
        c.entry.sales_price = '50'
        c.addToCart()

        expect(c.lines).toHaveLength(0)
        expect(c.entryError).toBe('ক্রয়দর লিখুন')
    })

    it('বিক্রয়দর না থাকলে সারি যোগ হয় না', () => {
        const c = priced()
        c.entry.rate = '40'
        c.entry.sales_price = ''
        c.addToCart()

        expect(c.lines).toHaveLength(0)
        expect(c.entryError).toBe('বিক্রয়দর লিখুন')
    })

    it('দুটো থাকলে যোগ হয়, আর বার্তা মুছে যায়', () => {
        const c = priced()
        c.entry.rate = '40'
        c.entry.sales_price = ''
        c.addToCart()
        c.entry.sales_price = '48'
        c.addToCart()

        expect(c.lines).toHaveLength(1)
        expect(c.entryError).toBe('')
    })

    it('কার্টে বসার পরে সারির দর ০ করলে পাঠানো থামে', () => {
        const c = priced()
        c.entry.rate = '40'
        c.entry.sales_price = '48'
        c.addToCart()
        c.lines[0].rate = '0'

        let stopped = false
        c.guard({ preventDefault: () => { stopped = true } })

        expect(stopped).toBe(true)
        expect(c.stopped).toBe('সারিতে দর নেই')
    })
})

/*
 * ⭐ কার্টে লেখার ঘর নেই, সম্পাদনা উপরে — মালিক, ৩ অক্টোবর ২০২৬।
 *
 * ⛔ কার্টের ঘরে দর বা পরিমাণ বদলালে উপরের কোনো পাহারা চলত না — দর ০-র দেয়ালও না। ⓘ এখন ✎ চাপলে সারিটা
 * উপরের বাক্সে বসে, বোতাম হয় "হালনাগাদ করুন", আর চাপলে সারিটা **জায়গাতেই** বদলায়।
 */
describe('কার্টের সারি সম্পাদনা — উপরে বসে, জায়গায় বদলায়', () => {
    const ready = () => {
        const c = counter({
            catalogue: [product(), product({ id: 2, name: 'ডাল' })],
            texts: { paidMoreConfirm: 'বেশি দিচ্ছেন?', needRate: 'ক্রয়দর লিখুন', needSalesPrice: 'বিক্রয়দর লিখুন' },
        })
        const focused = []
        c.$nextTick = fn => fn()
        c.$root = { querySelector: () => null }
        c.$refs = {
            search: { focus: () => focused.push('search') },
            entryQty: { focus: () => focused.push('entryQty') },
        }
        c.focused = focused

        return c
    }

    const add = (c, p, figures) => {
        c.pick(p)
        Object.assign(c.entry, figures)
        return c.addToCart()
    }

    it('✎ চাপলে পণ্য, পরিমাণ, ফ্রি, দর, markup, margin আর বিক্রয়দর উপরে বসে', () => {
        const c = ready()
        add(c, c.catalogue[0], { qty: '10', free_qty: '2', rate: '100', sales_price: '125' })

        c.editLine(0)

        expect(c.picked?.id).toBe(1)
        expect(c.entry.qty).toBe('10')
        expect(c.entry.free_qty).toBe('2')
        expect(c.entry.rate).toBe('100')
        expect(c.entry.sales_price).toBe('125')
        expect(c.entry.markup).toBe('25')
        expect(c.entry.margin).toBe('20')
        expect(c.editingKey).toBe(c.lines[0].key)
        expect(c.focused).toContain('entryQty')
    })

    it('হালনাগাদে সারি বাড়ে না — একই সারি, একই জায়গায়, নতুন সংখ্যা', () => {
        const c = ready()
        add(c, c.catalogue[0], { qty: '10', rate: '100', sales_price: '125' })
        add(c, c.catalogue[1], { qty: '5', rate: '60', sales_price: '70' })
        const key = c.lines[0].key

        c.editLine(0)
        c.entry.qty = '12'
        c.entry.rate = '95'

        expect(c.addToCart()).toBe(true)
        expect(c.lines).toHaveLength(2)
        expect(c.lines[0].key).toBe(key)
        expect(c.lines[0].id).toBe(1)
        expect(c.lines[0].qty).toBe('12')
        expect(c.lines[0].rate).toBe('95')
        expect(c.lines[1].id).toBe(2)
        expect(c.editingKey).toBe(null)
    })

    it('সম্পাদনাতেও দর ০ চলে না — সারিটা আগের মতোই থাকে', () => {
        const c = ready()
        add(c, c.catalogue[0], { qty: '10', rate: '100', sales_price: '125' })

        c.editLine(0)
        c.entry.rate = '0'

        expect(c.addToCart()).toBe(false)
        expect(c.entryError).toBe('ক্রয়দর লিখুন')
        expect(c.lines[0].rate).toBe('100')
        expect(c.editingKey).toBe(c.lines[0].key)
    })

    it('খোলা সারিটা মুছলে বাক্সও খালি — হালনাগাদ নতুন সারি বানায় না', () => {
        const c = ready()
        add(c, c.catalogue[0], { qty: '10', rate: '100', sales_price: '125' })

        c.editLine(0)
        c.dropLine(0)

        expect(c.editingKey).toBe(null)
        expect(c.picked).toBe(null)
        expect(c.lines).toHaveLength(0)
    })

    /* ⛔ পাল্টা-দাবি: অন্য পণ্য খুঁজে বাছলে সম্পাদনা ছাড়ে, আর সেটা নতুন সারি — খোলা সারি অক্ষত */
    it('সম্পাদনার মাঝে অন্য পণ্য বাছলে নতুন সারি, আগেরটা অক্ষত', () => {
        const c = ready()
        add(c, c.catalogue[0], { qty: '10', rate: '100', sales_price: '125' })

        c.editLine(0)
        add(c, c.catalogue[1], { qty: '5', rate: '60', sales_price: '70' })

        expect(c.lines).toHaveLength(2)
        expect(c.lines[0].qty).toBe('10')
    })
})

/*
 * ⛔ যোগের পরে তালিকা নিজে খুলে বসে থাকত — মালিক, ৩ অক্টোবর ২০২৬: *"ekta product add er por ro add er jonno
 * list bose auto, eta biroktikor"*। ⓘ কার্সর পণ্যের ঘরে থাকে, তালিকা খোলে কেবল লিখলে, ক্লিকে বা নিচের তীরে।
 */
describe('যোগের পরে তালিকা বন্ধ, কার্সর পণ্যের ঘরে', () => {
    const ready = () => {
        const c = counter({ catalogue: [product({ code: 'P-1' }), product({ id: 2, name: 'ডাল', code: 'P-2' })] })
        const focused = []
        c.$nextTick = fn => fn()
        c.$root = { querySelector: () => null }
        c.$refs = { search: { focus: () => focused.push('search') } }
        c.supplierId = '7'
        c.focused = focused

        return c
    }

    it('যোগের পরে তালিকা খালি, আর কার্সর খোঁজার ঘরে', () => {
        const c = ready()
        c.openBrowsing()
        c.pickFirst()
        Object.assign(c.entry, { qty: '1', rate: '10', sales_price: '12' })
        c.addToCart()

        expect(c.visible).toHaveLength(0)
        expect(c.focused.at(-1)).toBe('search')
    })

    it('একটা অক্ষর লিখলে তালিকা খোলে', () => {
        const c = ready()
        c.term = 'ড'

        expect(c.visible.map(p => p.id)).toEqual([2])
    })

    it('ক্লিক বা নিচের তীরে পুরো তালিকা', () => {
        const c = ready()
        c.openBrowsing()

        expect(c.visible).toHaveLength(2)
    })
})

/*
 * ⛔ কার্টের সারিতে পরিমাণ, দর, ফ্রি আর ছাড়ের লেখার ঘর নেই — কেবল লুকানো ঘর (সার্ভারে পাঠানোর জন্য)।
 * ⓘ ব্লেডটাই পড়া হয়: ঘরটা কম্পোনেন্টে নয়, পাতায় — আর একটা `x-model="line.rate"` ফিরলেই দেয়ালটা ফাঁকা।
 */
describe('কার্টের সারিতে লেখার ঘর নেই', () => {
    it('পরিমাণ, দর, ফ্রি, ছাড় — সবই লুকানো ঘর, x-model নেই', async () => {
        const { readFileSync } = await import('node:fs')
        const blade = readFileSync(new URL('../../../app/Modules/Purchase/Resources/views/direct/partials/cart.blade.php', import.meta.url), 'utf8')
        const start = blade.indexOf('x-for="(line, index) in lines"')
        const end = blade.indexOf('x-for="line in lines"')
        const row = blade.slice(start, end).replace(/\{\{--[\s\S]*?--\}\}/g, '')

        expect(start).toBeGreaterThan(0)
        expect(end).toBeGreaterThan(start)

        const inputs = row.match(/<input\b[^>]*>/g) || []
        const money = inputs.filter(i => /\]\[(qty|rate|free_qty|discount)\]/.test(i))

        expect(money).toHaveLength(4)
        money.forEach(i => expect(i).toMatch(/type="hidden"/))
        expect(row).not.toMatch(/x-model="line\.(qty|rate|free_qty|discount)"/)
        expect(row).toMatch(/@click="editLine\(index\)"/)
    })

    it('খোঁজার ঘর ফোকাসে তালিকা খোলে না — ক্লিক আর নিচের তীরে খোলে', async () => {
        const { readFileSync } = await import('node:fs')
        const blade = readFileSync(new URL('../../../app/Modules/Purchase/Resources/views/direct/partials/entry.blade.php', import.meta.url), 'utf8')
        const search = blade.match(/<input type="text" x-model="term" x-ref="search"[\s\S]*?>/)?.[0] || ''
        const tag = search.replace(/\{\{--[\s\S]*?--\}\}/g, '')

        expect(tag).not.toBe('')
        expect(tag).not.toMatch(/@focus=/)
        expect(tag).toMatch(/@click="openBrowsing\(\)"/)
        expect(tag).toMatch(/@keydown\.down="openBrowsing\(\)"/)
    })
})
