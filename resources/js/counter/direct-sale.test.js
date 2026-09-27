import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import directSale from './direct-sale.js'
import { magics } from '../components/index.js'

/*
 * কাউন্টারে বিক্রয়ের অঙ্ক।
 *
 * ── ⭐ এই ফাইলটা কেন আছে — ১৮ সেপ্টেম্বর ২০২৬ ────────────────────────
 * নিরীক্ষার ধাপ ৪.১: *"সরানো প্রতিটি টুকরার জন্য vitest পরীক্ষা লিখুন
 * (দর গণনা, ছাড়, খসড়া সংরক্ষণ, বারকোড)।"*
 *
 * ⛔ এই অঙ্কগুলো **১,৪৫৩ লাইন ধরে ব্লেডের ভিতরে** ছিল, আর সেখানে একটাও
 * পরীক্ষা লেখা যেত না। ⚠️ এখানকার ভুল কোনো ত্রুটিবার্তা দেয় না — শুধু
 * প্রতিটা বিলে অঙ্কটা একটু এদিক-ওদিক হয়, আর মাসশেষে কেউ ধরতে পারে না
 * কেন হিসাব মিলছে না।
 *
 * ⓘ ঠিক এই কথাটাই পাশের [[pricing.test.js]]-এ লেখা আছে, আর ওটার জন্যই
 * ঐ ফাইলটা ব্লেডের বাইরে।
 */

/** ন্যূনতম একটা পণ্য — পরীক্ষাগুলো যা যা ছোঁয়। */
const product = (over = {}) => ({
    id: 1,
    name: 'চাল',
    rate: '50',
    vatRate: 0,
    vatInclusive: false,
    ...over,
})

/*
 * ⭐ Alpine যে জিনিসগুলো নিজে বসায় — `$num`, `$nextTick`, `$refs`।
 *
 * ⚠️ এগুলো কম্পোনেন্টের নিজের নয়, তাই পরীক্ষায় হাতে বসাতে হয়। ⛔ নইলে
 * `addToCart()` ডাকলেই `this.$num is not a function`, আর এই ফাইলের অঙ্কের
 * পরীক্ষাগুলো কখনো `addToCart()` ডাকে না বলে ফাঁকটা এতদিন চোখে পড়েনি।
 *
 * ⓘ `$num` **আসল জায়গা থেকেই** নেওয়া ([[components/index.js]]-এর
 * `magics`), হাতে লেখা নয় — ⛔ হাতে লিখলে পরীক্ষা ঐ নকলটাই মাপত, আর
 * আসলটা বদলে গেলেও সবুজ থাকত।
 *
 * ⓘ `$refs.search` একটা সত্যিকারের ডাকযোগ্য ঘর, খালি বস্তু নয় —
 * `addToCart()` ওকে `?.` ছাড়াই ডাকে, তাই খালি রাখলে পরীক্ষা ভাঙত
 * এমন এক জায়গায় যার সাথে দাবিটার সম্পর্ক নেই।
 */
const withMagics = (c) => Object.assign(c, {
    $num: magics.num,
    $nextTick: (fn) => fn(),
    $refs: { search: { focus: () => {} } },
})

/** ফাঁকা একটা কাউন্টার — ব্লেড যা যা দেয় তার ন্যূনতম রূপ। */
const counter = (over = {}) => withMagics(directSale({
    catalogue: [product()],
    customers: [],
    walkinId: 1,
    vatEnabled: false,
    packs: [],
    paymentTermDefault: 'cash',
    carriers: [],
    depositMethods: [],
    moneyAccounts: [],
    draftKey: 'test.counter',
    hasErrors: false,
    texts: {
        notForSales: 'বিক্রয়ের জন্য নয়',
        creditBeyondLimit: 'আর ৳:left বাকি দেওয়া যাবে',
        creditWall: 'অবশিষ্ট সীমা ৳:left, এই বিল ৳:short বেশি। টাকা জমা দিন, নয়তো বিল কমান।',
    },
    ...over,
}))

describe('লাইনের ভিত্তি', () => {
    let c

    beforeEach(() => {
        c = counter()
        c.picked = product()
    })

    it('পরিমাণ × দর', () => {
        c.entry.qty = '3'
        c.entry.rate = '50'

        expect(c.entryBase).toBe(150)
    })

    /*
     * ⚠️ খালি ঘর মানে শূন্য, `NaN` নয়।
     *
     * ⛔ `NaN` একবার ঢুকলে সে পুরো কাগজে ছড়ায়: মোট `NaN`, ভ্যাট `NaN`,
     * আর পর্দায় "NaN" লেখা একটা বিল। ⓘ ব্যবহারকারী তখন বোঝেনই না
     * কোন ঘরটা ভরতে হবে।
     */
    it('খালি ঘরে শূন্য, NaN নয়', () => {
        c.entry.qty = ''
        c.entry.rate = ''

        expect(c.entryBase).toBe(0)
        expect(Number.isNaN(c.entryBase)).toBe(false)
    })
})

describe('ছাড় — টাকায় বা শতাংশে, একটাই ঘর', () => {
    let c

    beforeEach(() => {
        c = counter()
        c.picked = product()
        c.entry.qty = '2'
        c.entry.rate = '100'
    })

    it('সংখ্যা লিখলে সেটা টাকা', () => {
        c.entry.discountInput = '30'

        expect(c.entryDiscount).toBe(30)
    })

    it('শেষে % থাকলে সেটা শতাংশ', () => {
        c.entry.discountInput = '10%'

        expect(c.entryDiscount).toBe(20)
    })

    /*
     * ⛔ এটাই এই ফাইলের সবচেয়ে জরুরি দাবি।
     *
     * ⚠️ ছাড় লাইনের মোটের চেয়ে বড় হলে লাইনটা **ঋণাত্মক** হত — অর্থাৎ
     * বিক্রির কাগজে একটা সারি গ্রাহককে টাকা **ফেরত** দিত। ⓘ কেউ ভুল
     * করে একটা শূন্য বেশি টাইপ করলেই সেটা ঘটত।
     */
    it('ছাড় লাইনের মোটের চেয়ে বড় হতে পারে না', () => {
        c.entry.discountInput = '5000'

        expect(c.entryDiscount).toBe(200)
        expect(c.entryAfterDiscount).toBe(0)
    })

    it('ঋণাত্মক বা আজেবাজে লেখায় ছাড় নেই', () => {
        c.entry.discountInput = '-50'
        expect(c.entryDiscount).toBe(0)

        c.entry.discountInput = 'অনেক'
        expect(c.entryDiscount).toBe(0)
    })

    /*
     * ⓘ সার্ভার ছাড়টা শতাংশে নেয়, তাই টাকায় লেখা হলে ফিরিয়ে দিতে হয়।
     * ⚠️ ভিত্তি শূন্য হলে শতাংশ বের করা যায় না — তখন খালি।
     */
    it('টাকার ছাড় শতাংশে ফিরে যায়', () => {
        c.entry.discountInput = '50'

        expect(c.entryDiscountPercent).toBe('25')
    })

    it('ভিত্তি শূন্য হলে শতাংশ খালি', () => {
        c.entry.qty = ''
        c.entry.discountInput = '50'

        expect(c.entryDiscountPercent).toBe('')
    })
})

describe('ভ্যাট — সহ না বাদে', () => {
    it('ভ্যাট বন্ধ থাকলে শূন্য', () => {
        const c = counter({ vatEnabled: false })
        c.picked = product({ vatRate: 15 })
        c.entry.qty = '1'
        c.entry.rate = '100'

        expect(c.entryVat).toBe(0)
    })

    it('ভ্যাট বাদে দরে ভ্যাট যোগ হয়', () => {
        const c = counter({ vatEnabled: true })
        c.picked = product({ vatRate: 15, vatInclusive: false })
        c.entry.qty = '1'
        c.entry.rate = '100'

        expect(c.entryVat).toBe(15)
        expect(c.entryNet).toBe(115)
    })

    /*
     * ⚠️ ভ্যাট-সহ দরে ভ্যাট **ভিতর থেকে** বের করা হয়, উপরে যোগ নয়।
     *
     * ⛔ ভুল করে যোগ করলে ১১৫ টাকার পণ্য ১৩২.২৫ হয়ে যেত — আর সেটা
     * প্রতিটা বিলে, প্রতিদিন।
     */
    it('ভ্যাট সহ দরে ভ্যাট ভিতর থেকে বেরোয়', () => {
        const c = counter({ vatEnabled: true })
        c.picked = product({ vatRate: 15, vatInclusive: true })
        c.entry.qty = '1'
        c.entry.rate = '115'

        expect(c.entryVat).toBeCloseTo(15, 6)
        expect(c.entryNet).toBe(115)
    })

    /*
     * ⭐ পুরো কাগজের বদল পণ্যের নিজের নিয়মকে ঢেকে দেয় — হার **ও** ধরন,
     * দুইটাই। ⓘ নইলে ভ্যাট গোনা হত নতুন নিয়মে আর মোট গোনা হত পুরনো
     * নিয়মে, আর দুইটা সংখ্যা মিলত না।
     */
    it('কাগজের বদল পণ্যের নিজের নিয়ম ঢেকে দেয়', () => {
        const c = counter({ vatEnabled: true })
        c.picked = product({ vatRate: 5, vatInclusive: false })
        c.entry.qty = '1'
        c.entry.rate = '115'
        c.vatMode = 'inclusive'
        c.vatRate = 15

        expect(c.isInclusive(c.picked)).toBe(true)
        expect(c.entryVat).toBeCloseTo(15, 6)
    })

    it('অব্যাহতি দিলে ভ্যাট শূন্য, আর দর ভ্যাট-সহ নয়', () => {
        const c = counter({ vatEnabled: true })
        c.picked = product({ vatRate: 15, vatInclusive: true })
        c.entry.qty = '1'
        c.entry.rate = '115'
        c.vatMode = 'exempt'

        expect(c.entryVat).toBe(0)
        expect(c.isInclusive(c.picked)).toBe(false)
    })
})

/*
 * ⭐ কার্টের সারি উপরে ফিরিয়ে আনা — মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ যে ফাঁকটা এটা বন্ধ করে ─────────────────────────────────────────
 * কার্টের ঘরগুলো লেখার ছিল, তাই সারিটা কার্টে ওঠার **পরে** দর বা পরিমাণ
 * বদলানো যেত — আর তখন এন্ট্রির একটা নিয়মও চলত না: নির্ধারিত দামের নিচে
 * বিক্রি, ফ্রি-র অনুপাত, মজুদ, কিছুই না।
 *
 * ⓘ মালিকের কথা: *"upore za atkay ta niche edite atkay na"*।
 */
describe('কার্টের সারি উপরে ফিরিয়ে আনা', () => {
    const filled = () => {
        const c = counter()
        c.picked = product()
        c.entry.qty = '3'
        c.entry.rate = '50'

        return c
    }

    it('সারিটা এন্ট্রির ঘরে ফেরে', async () => {
        const c = filled()
        await c.addToCart()

        expect(c.lines).toHaveLength(1)
        expect(c.picked).toBe(null)

        await c.editLine(0)

        expect(c.picked?.id).toBe(1)
        expect(c.entry.qty).toBe('3')
        expect(c.entry.rate).toBe('50')
    })

    /*
     * ⛔ আর এটাই আসল দাবি: সারিটা **সরে আসে**, কপি হয় না।
     *
     * ⚠️ কপি হলে "কার্টে যোগ করুন" চাপার পর একই পণ্য দুইবার বসত, আর
     * ব্যবহারকারী ভাবতেন তিনি কেবল বদলেছেন — ⓘ আর ভুলটা ধরা পড়ত
     * বিলের মোটে, যেখানে কেউ সারি গোনে না।
     */
    it('কার্ট থেকে সরে আসে, কপি হয় না', async () => {
        const c = filled()
        await c.addToCart()
        await c.editLine(0)

        expect(c.lines).toHaveLength(0)

        await c.addToCart()

        expect(c.lines).toHaveLength(1)
    })

    /*
     * ⚠️ হাতে কিছু লেখা থাকলে সেটা আগে কার্টে তোলা হয়।
     *
     * ⛔ নাহলে ঐ এন্ট্রিটা নীরবে হারাত — আর হারানো এন্ট্রি সবচেয়ে
     * বিরক্তিকর ভুল, কারণ কেউ জানেই না কী হারাল।
     */
    it('হাতের এন্ট্রি হারায় না', async () => {
        const c = filled()
        await c.addToCart()

        // ⓘ অন্য পণ্য — ২৬ সেপ্টেম্বর থেকে একই পণ্য দুই সারিতে বসে না
        c.picked = product({ id: 2, name: 'ডাল' })
        c.entry.qty = '7'
        c.entry.rate = '50'

        await c.editLine(0)

        expect(c.lines).toHaveLength(1)
        expect(c.lines[0].qty).toBe('7')
        expect(c.entry.qty).toBe('3')
    })

    /* ⓘ ছাড় শতাংশেই ফেরে — কার্টে ওটাই রাখা হয়। */
    it('ছাড় শতাংশসহ ফেরে', async () => {
        const c = filled()
        c.entry.discountInput = '10%'
        await c.addToCart()
        await c.editLine(0)

        expect(c.entry.discountInput).toBe('10%')
    })

    /* ⛔ পাল্টা-দাবি: নেই এমন সারিতে কিছুই ঘটে না। */
    it('অচেনা সূচকে কিছুই ঘটে না', async () => {
        const c = filled()
        await c.addToCart()
        await c.editLine(9)

        expect(c.lines).toHaveLength(1)
        expect(c.picked).toBe(null)
    })
})

/*
 * ⭐ বাকির সীমা কার্টেই আটকায় — মালিকের নির্দেশ, ২৫ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ দেয়ালটা নতুন নয়, খবরটা দেরিতে আসত ────────────────────────────
 * আসল দেয়াল সেবায় ([[SalesInvoiceService::assertWithinCreditLimit()]]),
 * কিন্তু সে কথা বলে **সংরক্ষণের সময়** — ত্রিশটা সারি তোলার পরে, আর তখন
 * কোনটা বাদ দিলে চলবে তা কেউ বলে না।
 *
 * ── ⚠️ তাই প্রতিটা দাবি সেবার নিয়মটাই মাপে ───────────────────────────
 * ⛔ পর্দা একটু কড়া হলে সে এমন বিক্রি আটকাত যা সেবা মেনে নিত, আর
 * বিক্রেতা কারণ খুঁজে পেতেন না। একটু ঢিলা হলে মিথ্যা আশা দিত।
 */
describe('বাকির সীমা — কার্টেই আটকায়', () => {
    /** সীমা আছে এমন একজন ক্রেতা, আর সব সুইচ চালু। */
    const onCredit = (over = {}) => {
        const c = counter({
            customers: { 7: { limit: 10000, due: 8000, days: 30, name: 'রহিম' } },
            creditRules: { enabled: true, zeroBlocks: false },
            ...over,
        })

        c.customerId = '7'
        c.creditTerm = 'credit:30'

        return c
    }

    /** ঐ ক্রেতার ঘরে একটা সারি বসানোর আয়োজন। */
    const entry = (c, rate) => {
        c.picked = product()
        c.entry.qty = '1'
        c.entry.rate = String(rate)
    }

    it('সীমার ভিতরে থাকলে সারিটা যায়', async () => {
        const c = onCredit()
        entry(c, 1500)

        expect(await c.addToCart()).toBe(true)
        expect(c.lines).toHaveLength(1)
        expect(c.creditWarning).toBe('')
    })

    /*
     * ⛔ এটাই আসল দাবি। ⚠️ বকেয়া ৮,০০০, সীমা ১০,০০০ — খোলা ২,০০০।
     * ২,৫০০ টাকার সারিটা ওটা ছাড়ায়, তাই কার্টেই থামে।
     * ⭐ ২৬ সেপ্টেম্বর ২০২৬ (রাতে) থেকে সতর্কতা কেবল **জানায়**, সারি আটকায় না — মালিক: *"jast warning but atkabena"*। ⛔ দেয়াল "নিশ্চিত করুন"-এ (পপ-আপ) আর সেবায়।
     */
    it('[২৬ সেপ্টেম্বর থেকে: সারি যায়, সতর্কতা থাকে] সীমা ছাড়ালে সারিটা কার্টে যায় না', async () => {
        const c = onCredit()
        entry(c, 2500)

        expect(await c.addToCart()).toBe(true)
        expect(c.lines).toHaveLength(1)
        expect(c.creditWarning).not.toBe('')
    })

    /*
     * ⭐ **গোটা ঝুড়ি ধরে, সারি ধরে নয়** — মালিকের নির্দেশ।
     *
     * ⛔ সারি ধরে দেখলে ১,২০০-র দুইটা সারি আলাদাভাবে নিরীহ দেখাত
     * (দুইটাই ২,০০০-এর কম), অথচ মিলে ২,৪০০ — সীমা পার।
     */
    it('সারি ধরে নয়, পুরো ঝুড়ি ধরে গোনে', async () => {
        const c = onCredit()

        entry(c, 1200)
        expect(await c.addToCart()).toBe(true)

        entry(c, 1200)
        c.picked = product({ id: 2, name: 'ডাল' })
        expect(await c.addToCart()).toBe(true)
        expect(c.creditWarning).not.toBe('')
        expect(c.lines).toHaveLength(2)
    })

    /*
     * ⚠️ বার্তায় **যতটুকু খোলা আছে** থাকে, যতটুকু ছাড়িয়েছে তা নয়।
     * ⛔ নাহলে বিক্রেতা কমাতে কমাতে চেষ্টা করতেন।
     */
    it('বার্তাটা যতটুকু খোলা আছে তা বলে', async () => {
        const c = onCredit()
        entry(c, 2500)
        await c.addToCart()

        expect(c.creditWarning).toContain('2,000')
    })

    /*
     * ⛔ নগদে সীমার প্রশ্নই ওঠে না — সেবাও `unpaid <= 0` দেখে ফিরে যায়।
     *
     * ⚠️ আর এটাই সবচেয়ে জরুরি পাল্টা-দাবি: কার্ট গড়ার সময় জমার ঘর
     * এখনো খালি, তাই টাকার অঙ্ক দেখে বিচার করলে **প্রতিটা নগদ বিক্রিই**
     * মাঝপথে আটকে যেত।
     */
    it('নগদে কিছুই আটকায় না', async () => {
        const c = onCredit()
        c.creditTerm = 'cash'
        entry(c, 50000)

        expect(await c.addToCart()).toBe(true)
        expect(c.creditWarning).toBe('')
    })

    /* ⛔ পাল্টা-দাবি: সুইচ বন্ধ থাকলে পর্দাও আটকায় না — সেবা যেমন আটকায় না। */
    it('সীমার সুইচ বন্ধ থাকলে আটকায় না', async () => {
        const c = onCredit({
            creditRules: { enabled: false, zeroBlocks: false },
        })
        entry(c, 50000)

        expect(await c.addToCart()).toBe(true)
    })

    /*
     * ⛔ পুরনো দুই দরজা বন্ধ — মালিকের নির্দেশ, ২৬ সেপ্টেম্বর ২০২৬।
     *
     * ⚠️ বিপজ্জনক ইনপুট: পুরনো পাতা বা পুরনো খসড়া থেকে `blocks: false`
     * আর `canOverride: true` এলেও পর্দা আটকাবেই — সীমা কারও চাবিতে পার
     * হয় না, আর "পার হতে দাও" সুইচও আর নেই।
     */
    it('[সতর্ক করে, আটকায় নিশ্চিত-এ] পুরনো "পার হতে দাও" বা চাবির ঘর এলেও আটকায়', async () => {
        const c = onCredit({
            creditRules: { enabled: true, blocks: false, zeroBlocks: false, canOverride: true },
        })
        entry(c, 50000)

        expect(await c.addToCart()).toBe(true)
        expect(c.creditWarning).not.toBe('')
    })

    /*
     * ⓘ সীমা শূন্য মানে "বাকি বন্ধ", আর সেটা সুইচ দিয়ে ঠিক হয়।
     * ⚠️ `zeroBlocks` বন্ধ থাকলে শূন্য সীমা কিছুই আটকায় না — নাহলে
     * নতুন গ্রাহকের প্রথম বিলটাই আটকে যেত।
     */
    it('সীমা শূন্য — সুইচ বন্ধ থাকলে যায়, চালু থাকলে যায় না', async () => {
        const open = onCredit({ customers: { 7: { limit: 0, due: 0, days: 30, name: 'নতুন' } } })
        entry(open, 500)
        expect(await open.addToCart()).toBe(true)
        expect(open.creditWarning).toBe('')

        const shut = onCredit({
            customers: { 7: { limit: 0, due: 0, days: 30, name: 'নতুন' } },
            creditRules: { enabled: true, zeroBlocks: true },
        })
        entry(shut, 500)
        expect(await shut.addToCart()).toBe(true)
        expect(shut.creditWarning).not.toBe('')
    })

    /*
     * ⚠️ জমা দিলে বাকিটা কমে, তাই সীমাও খোলে — সেবার
     * `unpaid = total − payingNow`-এর আয়না।
     */
    it('জমা দিলে সীমা খুলে যায়', async () => {
        const c = onCredit()
        c.deposits = [{ amount: '1000' }]
        entry(c, 2500)

        expect(await c.addToCart()).toBe(true)
    })

    /* ⛔ ক্রেতা না বাছলে সীমার প্রশ্নই নেই — নগদ খদ্দের। */
    it('ক্রেতা না বাছলে আটকায় না', async () => {
        const c = onCredit()
        c.customerId = ''
        entry(c, 50000)

        expect(await c.addToCart()).toBe(true)
    })
})

/*
 * ⛔ সীমার কড়া দেয়াল — আটকে থাকা টাকা, আর নিশ্চিত করার মুহূর্তের পপ-আপ।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৫–২৬ সেপ্টেম্বর ২০২৬ ────────────────────────
 * *"limit mane limit 100%"* · বিল না হওয়া ডিও আর খসড়া বিলও সীমা আটকায় ·
 * পার হলে বড় পপ-আপ আর ধ্বনি, আর একটাই বোতাম।
 *
 * ⚠️ পর্দার সংখ্যা সেবার আয়না ([[CreditExposure::assertRoom()]]) — পর্দা
 * বেশি দেখালে বিক্রেতা পুরো কার্ট তুলতেন আর সেবা শেষে আটকাত।
 */
describe('সীমার কড়া দেয়াল — আটকে থাকা টাকা ও পপ-আপ', () => {
    /** সীমা ১০,০০০ · বকেয়া ৫,০০০ · আটকে আছে ৪,০০০ → খোলা ১,০০০ */
    const held = (over = {}) => {
        const c = counter({
            customers: { 7: { limit: 10000, due: 5000, held: 4000, days: 30, name: 'রহিম' } },
            creditRules: { enabled: true, zeroBlocks: false },
            ...over,
        })

        c.customerId = '7'
        c.creditTerm = 'credit:30'

        return c
    }

    const line = (c, rate) => {
        c.picked = product()
        c.entry.qty = '1'
        c.entry.rate = String(rate)
    }

    /** কার্টে একটা তৈরি সারি — দর যা বলা হয়। */
    const cartOf = (c, rate) => {
        c.lines = [{ key: 1, id: 1, qty: '1', rate: String(rate), freeQty: '', discountPercent: '', vatRate: 0, gifts: [] }]
    }

    /** ফর্ম পাঠানোর ঘটনা — `preventDefault` ডাকা হলো কি না ধরে রাখে। */
    const submitEvent = () => {
        const e = { stopped: false }
        e.preventDefault = () => { e.stopped = true }

        return e
    }

    /*
     * ⛔ আসল দাবি: ডিও আর খসড়ার ৪,০০০ বাদ দিলে খোলা থাকে মাত্র ১,০০০।
     * ⚠️ `held` না গুনলে পর্দা ৫,০০০ দেখাত, আর ১,৫০০-র সারিটা যেত।
     */
    it('বিল না হওয়া ডিও ও খসড়া সীমা আটকায়', async () => {
        const c = held()
        line(c, 1500)

        expect(await c.addToCart()).toBe(true)
        expect(c.creditWarning).toContain('1,000')
    })

    /* ⭐ পাল্টা-দাবি: খোলা অংশের ভিতরে থাকলে সারিটা যায় */
    it('আটকে থাকা বাদ দিয়ে যা খোলা, তার ভিতরে সারি যায়', async () => {
        const c = held()
        line(c, 900)

        expect(await c.addToCart()).toBe(true)
    })

    /* ⓘ "সীমা পার — ৳…" সারির সংখ্যা: বাকি ১,৫০০ − খোলা ১,০০০ = ৫০০ */
    it('সীমা কত পার হচ্ছে তা বলে', () => {
        const c = held()
        cartOf(c, 1500)

        expect(c.creditOver).toBe(500)
    })

    /*
     * ⛔ নিশ্চিত করলে ফর্ম যায় না, পপ-আপ আসে, ধ্বনি বাজে — আর খসড়ার
     * আগাম সংরক্ষণও মোছে না (কার্টটা হারায় না)।
     */
    it('সীমা পার হলে ফর্ম থামে, পপ-আপ ও ধ্বনি', () => {
        const c = held()
        cartOf(c, 1500)

        let rang = 0
        c.soundTheAlarm = () => { rang++ }
        let parked = 0
        c.parkDraft = () => { parked++ }

        const e = submitEvent()
        c.guardSubmit(e)

        expect(e.stopped).toBe(true)
        expect(c.creditBlocked).toBe(true)
        expect(rang).toBe(1)
        expect(parked).toBe(0)
    })

    /* ⭐ পাল্টা-দাবি: সীমার ভিতরে ফর্ম স্বাভাবিকভাবে যায় */
    it('সীমার ভিতরে ফর্ম যায়', () => {
        const c = held()
        cartOf(c, 900)

        let parked = 0
        c.parkDraft = () => { parked++ }

        const e = submitEvent()
        c.guardSubmit(e)

        expect(e.stopped).toBe(false)
        expect(c.creditBlocked).toBe(false)
        expect(parked).toBe(1)
    })

    /*
     * ⚠️ বিপজ্জনক ইনপুট: শর্ত "নগদ", অথচ জমা নেই। ⛔ সেবা শর্ত দেখে না —
     * দেখে `বাকি = মোট − জমা`। পর্দা শর্ত দেখে ছেড়ে দিলে পপ-আপটা আসত না,
     * আর কার্টটা সার্ভারে গিয়ে হারাত।
     */
    it('"নগদ" বেছে জমা না দিলেও নিশ্চিত করার মুহূর্তে থামে', () => {
        const c = held()
        c.creditTerm = 'cash'
        cartOf(c, 1500)
        c.soundTheAlarm = () => {}

        const e = submitEvent()
        c.guardSubmit(e)

        expect(e.stopped).toBe(true)
    })

    /* ⭐ পুরো টাকা গুনলে থামে না — সেবার `unpaid <= 0` */
    it('পুরো টাকা জমা দিলে ফর্ম যায়', () => {
        const c = held()
        cartOf(c, 1500)
        c.deposits = [{ amount: '1500' }]
        c.parkDraft = () => {}

        const e = submitEvent()
        c.guardSubmit(e)

        expect(e.stopped).toBe(false)
    })

    /* ⓘ পপ-আপের লেখা — খোলা ১,০০০, বেশি ৫০০ */
    it('পপ-আপ বলে কত খোলা আর কত বেশি', () => {
        const c = held()
        cartOf(c, 1500)

        expect(c.creditBlockText).toContain('1,000')
        expect(c.creditBlockText).toContain('500')
        expect(c.creditBlockText).not.toContain(':left')
        expect(c.creditBlockText).not.toContain(':short')
    })

    /* ⓘ "বুঝেছি" পপ-আপ বন্ধ করে — আর কিছু নয় */
    it('বুঝেছি চাপলে পপ-আপ বন্ধ', () => {
        const c = held()
        c.creditBlocked = true
        c.closeCreditBlock()

        expect(c.creditBlocked).toBe(false)
    })

    /* ⚠️ শব্দযন্ত্র না থাকলে ধ্বনিটা চুপচাপ ফেরে — পপ-আপ ভাঙে না */
    it('শব্দযন্ত্র না থাকলেও ধ্বনির ডাক ভাঙে না', () => {
        const c = held()

        expect(() => c.soundTheAlarm()).not.toThrow()
    })
})

/*
 * ⭐ লট বাছাই — মালিকের সিদ্ধান্ত, ২৫ সেপ্টেম্বর ২০২৬।
 *
 * ── ⓘ তাঁকে দুইটা বিকল্প দেওয়া হয়েছিল ───────────────────────────────
 * না বাছলে FEFO চলবে, নাকি বাছা **বাধ্যতামূলক**। ⚠️ সুপারিশ ছিল
 * প্রথমটার (পুরনো মাল আপনা থেকে আগে যায়), আর তিনি দ্বিতীয়টা বেছেছেন।
 *
 * ⛔ তাঁর সিদ্ধান্তের যে ঝুঁকিটা বলা হয়েছিল — তাড়াহুড়োয় উপরেরটাই বাছা
 * হবে — সেটা কমাতে তালিকা মেয়াদের ক্রমে আসে, যারটা আগে ফুরাবে সে উপরে।
 */
describe('লট বাছাই — বাধ্যতামূলক, আর একই লট দুইবার নয়', () => {
    const LOTS = {
        1: [
            { id: '11', productId: '1', no: 'B-A', expiry: '2027-01-01', qty: '50' },
            { id: '12', productId: '1', no: 'B-B', expiry: '2027-06-01', qty: '80' },
        ],
    }

    /** লট ধরা একটা পণ্য, আর তার দুইটা লট। */
    const tracked = (over = {}) => {
        const c = counter({
            lots: LOTS,
            texts: {
                notForSales: 'বিক্রয়ের জন্য নয়',
                creditBeyondLimit: 'আর ৳:left বাকি দেওয়া যাবে',
                lotIsRequired: 'লট বাছতে হবে',
                lotAlreadyInCart: 'এই লট কার্টে আগেই আছে',
            },
            ...over,
        })

        c.picked = product({ trackBatch: true })
        c.entry.qty = '2'
        c.entry.rate = '100'

        return c
    }

    it('লট ধরা পণ্যে ঘরটা দেখা যায়, আর লটগুলো পাওয়া যায়', () => {
        const c = tracked()

        expect(c.needsLot).toBe(true)
        expect(c.entryLots).toHaveLength(2)
    })

    /*
     * ⛔ পাল্টা-দাবি, আর এটা ছাড়া উপরেরটার কোনো মানে নেই: ⚠️ "সব পণ্যে
     * ঘর দেখাও" লিখলেও ওটা সবুজ থাকত, আর তখন ডিপোর চাল-ডাল-সাবানের
     * প্রতিটা সারিতে একটা বাড়তি বাছাই বসত।
     */
    it('লট ধরা নয় এমন পণ্যে ঘরটাই নেই', () => {
        const c = tracked()
        c.picked = product()

        expect(c.needsLot).toBe(false)
    })

    it('লট না বাছলে সারিটা কার্টে যায় না', async () => {
        const c = tracked()

        expect(await c.addToCart()).toBe(false)
        expect(c.lines).toHaveLength(0)
        expect(c.lotWarning).not.toBe('')
    })

    it('লট বাছলে যায়, আর সারিতে লটটা থাকে', async () => {
        const c = tracked()
        c.entry.batchId = '11'

        expect(await c.addToCart()).toBe(true)
        expect(c.lines[0].batchId).toBe('11')
        expect(c.lines[0].batchNo).toBe('B-A')
    })

    /*
     * ⭐ মালিকের নিয়ম: **প্রতি লটে এক সারি**। ⓘ আলাদা লট হলে দুইটা
     * সারি ঠিকই থাকে — সেটাই তো নিয়মের মানে।
     */
    it('আলাদা লট হলে দুইটা সারি হয়', async () => {
        const c = tracked()

        c.entry.batchId = '11'
        expect(await c.addToCart()).toBe(true)

        c.picked = product({ trackBatch: true })
        c.entry.qty = '3'
        c.entry.rate = '100'
        c.entry.batchId = '12'
        expect(await c.addToCart()).toBe(true)

        expect(c.lines).toHaveLength(2)
    })

    /* ⛔ কিন্তু একই লট দুইবার নয়। */
    it('একই লট দুইবার দিলে আটকায়', async () => {
        const c = tracked()

        c.entry.batchId = '11'
        expect(await c.addToCart()).toBe(true)

        c.picked = product({ trackBatch: true })
        c.entry.qty = '3'
        c.entry.rate = '100'
        c.entry.batchId = '11'

        expect(await c.addToCart()).toBe(false)
        expect(c.lines).toHaveLength(1)
        expect(c.lotWarning).toBe('এই লট কার্টে আগেই আছে')
    })

    /*
     * ⛔ লট ধরা নয় এমন পণ্য — একই পণ্য দুই সারিতে নয়। মালিকের নিয়ম
     * (২৬ সেপ্টেম্বর ২০২৬, আবার ২৭ তারিখের ছবিতে): *"ekoi products ekbareer
     * odik entry nibe na"* — পুরনো লাইভ পর্দায় কসমস ৪০ গ্রাম তিন সারিতে।
     */
    it('লট ধরা নয় এমন একই পণ্য দুইবার দিলে আটকায়, বার্তাসহ', async () => {
        const c = tracked({
            texts: {
                notForSales: 'বিক্রয়ের জন্য নয়',
                creditBeyondLimit: 'আর ৳:left বাকি দেওয়া যাবে',
                itemAlreadyInCart: 'এই পণ্যটা কার্টে আগে থেকেই আছে',
            },
        })
        c.picked = product()

        expect(await c.addToCart()).toBe(true)

        c.picked = product()
        c.entry.qty = '3'
        c.entry.rate = '100'

        expect(await c.addToCart()).toBe(false)
        expect(c.lines).toHaveLength(1)
        expect(c.lotWarning).toBe('এই পণ্যটা কার্টে আগে থেকেই আছে')

        // ⓘ তৃতীয়বারও একই — "কসমস তিন সারিতে" আর হয় না
        c.picked = product()
        c.entry.qty = '1'
        c.entry.rate = '100'
        expect(await c.addToCart()).toBe(false)
        expect(c.lines).toHaveLength(1)
    })

    /*
     * ⓘ পাল্টা-দাবি: সারিটা উপরে তুলে বদলালে সেটা "দ্বিতীয় সারি" নয় —
     * ⚠️ নিজের সাথে ধাক্কা লাগলে সারি বদলানোই যেত না।
     */
    it('উপরে তোলা সারি বদলে আবার বসানো যায় — নিজের সাথে ধাক্কা নয়', async () => {
        const c = tracked({
            texts: {
                notForSales: 'বিক্রয়ের জন্য নয়',
                creditBeyondLimit: 'আর ৳:left বাকি দেওয়া যাবে',
                itemAlreadyInCart: 'এই পণ্যটা কার্টে আগে থেকেই আছে',
            },
        })
        c.picked = product()
        expect(await c.addToCart()).toBe(true)

        await c.editLine(0)
        c.entry.qty = '5'

        expect(await c.addToCart()).toBe(true)
        expect(c.lines).toHaveLength(1)
    })

    /*
     * ⚠️ দুইটা বার্তা আলাদা, আর সেটাই জরুরি — ⛔ এক বার্তা দিলে
     * বিক্রেতা বুঝতেন না লট **বাছতে** হবে না **বদলাতে** হবে।
     */
    it('দুইটা কারণের বার্তা দুইটা আলাদা', async () => {
        const c = tracked()
        await c.addToCart()
        const missing = c.lotWarning

        c.entry.batchId = '11'
        await c.addToCart()

        c.picked = product({ trackBatch: true })
        c.entry.qty = '1'
        c.entry.rate = '100'
        c.entry.batchId = '11'
        await c.addToCart()

        expect(c.lotWarning).not.toBe(missing)
    })

    /* ⓘ সারিটা উপরে ফিরলে লটটাও ফেরে — নাহলে আবার বাছতে হত। */
    it('সারি উপরে ফিরলে লটটাও ফেরে', async () => {
        const c = tracked()
        c.entry.batchId = '12'
        await c.addToCart()

        await c.editLine(0)

        expect(c.entry.batchId).toBe('12')
    })
})

/*
 * ⭐ রাখা খসড়া — পেন্ডিং থেকে একই পর্দায় ফিরে আসা। মালিকের নকশা, ২৬
 * সেপ্টেম্বর ২০২৬: "খসড়া রাখুন" বিলটা রাখে, আর পাকা হয় এই পর্দাতেই ফিরে —
 * পেন্ডিং থেকে বাছলে পুরো পর্দা হুবহু ফেরে, আর খোলা খসড়া থাকলে নতুন বিল নয়।
 */
describe('রাখা খসড়া — পেন্ডিং থেকে ফেরা', () => {
    /* ⓘ localStorage-এর ছোট একটা নকল — node-এ ব্রাউজারের ভাণ্ডার নেই। */
    const memoryStore = () => {
        const box = new Map()

        return {
            getItem: (k) => (box.has(k) ? box.get(k) : null),
            setItem: (k, v) => box.set(k, String(v)),
            removeItem: (k) => box.delete(k),
        }
    }

    let store

    beforeEach(() => {
        store = memoryStore()
        vi.stubGlobal('localStorage', store)
    })

    afterEach(() => {
        vi.unstubAllGlobals()
    })

    const TEXTS = {
        notForSales: 'বিক্রয়ের জন্য নয়',
        creditBeyondLimit: 'আর ৳:left বাকি দেওয়া যাবে',
        openDraftBlocks: 'খসড়া :no খোলা আছে',
    }

    /** একটা ভরা পর্দা — সারি (লট, ফ্রি, উপহার, ছাড়), জমা, শর্ত। */
    const filled = (over = {}) => {
        const c = counter({ texts: TEXTS, ...over })

        c.customerId = '7'
        c.creditTerm = 'credit:30'
        c.dueOn = '2026-10-26'
        c.lines = [{
            key: 1, id: 1, name: 'চাল', qty: '4', freeQty: '1', rate: '50',
            discountPercent: 10, unitId: '', batchId: '11', batchNo: 'B-A',
            gifts: [{ productId: 2, qty: '1' }],
        }]
        c.discountInput = '5%'
        c.expenseInput = '20'
        c.roundingInput = '0.5'
        c.roundingSign = '-'
        c.deposits = [{ methodId: 'cash', accountId: '3', amount: '100' }]
        c.nextKey = 2

        return c
    }

    const resumeOf = (screen) => ({
        invoiceId: 42,
        invoiceNo: 'INV-0042',
        challanNo: 'DC-0042',
        customerId: 7,
        screen,
        fields: { carrier_id: 5, do_no: 'DO-1' },
    })

    const DRAFTS = {
        7: [{ id: 42, no: 'INV-0042', total: '1000.00', date: '26-09-2026' }],
        9: [{ id: 43, no: 'INV-0043', total: '50.00', date: '26-09-2026' }],
    }

    it('পর্দার ছবি applyDraft দিয়ে হুবহু ফেরে', () => {
        const a = filled()
        const b = counter({ texts: TEXTS })

        b.applyDraft(a.screenSnapshot)

        expect(b.customerId).toBe('7')
        expect(b.creditTerm).toBe('credit:30')
        expect(b.dueOn).toBe('2026-10-26')
        expect(b.lines).toEqual(a.lines)
        expect(b.deposits).toEqual(a.deposits)
        expect(b.discountInput).toBe('5%')
        expect(b.expenseInput).toBe('20')
        expect(b.roundingInput).toBe('0.5')
        expect(b.roundingSign).toBe('-')
        expect(b.nextKey).toBe(2)
    })

    /* ⚠️ ব্রাউজারে লেখা আর সার্ভারে পাঠানো — একই ছবি, দুইটা আলাদা নকল নয়। */
    it('saveDraft ব্রাউজারে ঠিক ঐ ছবিটাই লেখে', () => {
        const c = filled()

        c.saveDraft()

        const saved = JSON.parse(store.getItem('test.counter'))
        const snap = JSON.parse(c.screenSnapshot)
        delete saved.at
        delete snap.at

        expect(saved).toEqual(snap)
    })

    it('পেন্ডিং থেকে খুললে সারি, জমা, শর্ত আর ক্রেতা ফেরে', () => {
        const screen = JSON.parse(filled().screenSnapshot)
        const c = counter({ texts: TEXTS, resume: resumeOf(screen) })

        c.start()

        expect(c.customerId).toBe('7')
        expect(c.lines).toEqual(screen.lines)
        expect(c.deposits).toEqual(screen.deposits)
        expect(c.creditTerm).toBe('credit:30')
        expect(c.dueOn).toBe('2026-10-26')
        expect(c.resumeId).toBe('42')
        expect(c.carrierId).toBe('5')
    })

    /*
     * ⛔ পেন্ডিং থেকে এলে "আগের খসড়া ফেরাব?" প্রশ্ন ওঠে না — আর বিক্রেতার
     * অসমাপ্ত নতুন বিলটাও মোছে না (খোলা খসড়া আলাদা চাবিতে লেখে)।
     */
    it('পেন্ডিং থেকে খুললে ব্রাউজারের প্রস্তাব আসে না, অসমাপ্ত বিলও থাকে', () => {
        const unfinished = filled().screenSnapshot
        store.setItem('test.counter', unfinished)

        const c = counter({ texts: TEXTS, resume: resumeOf(JSON.parse(filled().screenSnapshot)) })

        c.start()
        c.saveDraft()

        expect(c.draftFound).toBe(false)
        expect(store.getItem('test.counter')).toBe(unfinished)
        expect(store.getItem('test.counter.resume')).not.toBeNull()
    })

    /* ⓘ পাল্টা-দাবি: সাধারণ অবস্থায় প্রস্তাবটা ঠিকই আসে — নাহলে উপরেরটা কিছুই মাপত না। */
    it('সাধারণ অবস্থায় প্রস্তাবটা আসে, নিজে থেকে ফেরে না', () => {
        store.setItem('test.counter', filled().screenSnapshot)

        const c = counter({ texts: TEXTS })
        c.start()

        expect(c.draftFound).toBe(true)
        expect(c.lines).toHaveLength(0)
    })

    it('ভুলসহ ফিরলে বিক্রেতার শেষ পাঠানো অবস্থাই ফেরে', () => {
        const edited = filled()
        edited.lines = [...edited.lines, { ...edited.lines[0], key: 2, batchId: '12' }]
        store.setItem('test.counter.resume.pending', edited.screenSnapshot)

        const c = counter({
            texts: TEXTS,
            hasErrors: true,
            resume: resumeOf(JSON.parse(filled().screenSnapshot)),
        })
        c.start()

        expect(c.lines).toHaveLength(2)
        expect(store.getItem('test.counter.resume.pending')).toBeNull()
    })

    it('পেন্ডিং তালিকা কেবল বাছা ক্রেতার', () => {
        const c = counter({ texts: TEXTS, pendingDrafts: DRAFTS })

        expect(c.pendingForCustomer).toEqual([])

        c.customerId = '7'
        expect(c.pendingForCustomer.map(d => d.id)).toEqual([42])

        c.customerId = '8'
        expect(c.pendingForCustomer).toEqual([])
    })

    /* ⚠️ সার্ভারে একটাও খসড়া না থাকলে তালিকাটা `[]` আসে, বস্তু নয়। */
    it('খালি তালিকা `[]` এলেও ভাঙে না', () => {
        const c = counter({ texts: TEXTS, pendingDrafts: [] })
        c.customerId = '7'

        expect(c.pendingForCustomer).toEqual([])
        expect(c.customerHasOpenDraft).toBe(false)
    })

    it('পেন্ডিং বাছলে পাতাটা ?draft=ID নিয়ে খোলে', () => {
        const assign = vi.fn()
        vi.stubGlobal('window', { location: { assign } })

        const c = counter({ texts: TEXTS, pendingUrl: '/sales/direct' })

        c.openPending({ target: { value: '' } })
        expect(assign).not.toHaveBeenCalled()

        c.openPending({ target: { value: '42' } })
        expect(assign).toHaveBeenCalledWith('/sales/direct?draft=42')
    })

    /*
     * ⛔ মালিকের নির্দেশ: খোলা খসড়া থাকলে নতুন বিল নয় — আগে নিশ্চিত, বাতিল
     * বা সম্পাদনা। ⓘ একই পর্দা, একই কার্ট — কেবল ক্রেতার খসড়া আছে কি না বদলায়।
     */
    it('খোলা খসড়া থাকলে দুইটা বোতামই বন্ধ', () => {
        const c = filled({ pendingDrafts: DRAFTS })

        expect(c.customerHasOpenDraft).toBe(true)
        expect(c.canConfirm).toBe(false)
        expect(c.openDraftText).toBe('খসড়া INV-0042 খোলা আছে')

        c.customerId = '8'
        expect(c.customerHasOpenDraft).toBe(false)
        expect(c.canConfirm).toBe(true)
    })

    it('খসড়াটাই খোলা থাকলে সে নিজেকে আটকায় না', () => {
        const screen = JSON.parse(filled().screenSnapshot)
        const c = counter({ texts: TEXTS, pendingDrafts: DRAFTS, resume: resumeOf(screen) })

        c.start()

        expect(c.customerHasOpenDraft).toBe(false)
        expect(c.canConfirm).toBe(true)
    })

    it('খোলা খসড়া থাকলে সারি কার্টে ওঠে না', async () => {
        const c = counter({ texts: TEXTS, pendingDrafts: DRAFTS })
        c.customerId = '7'
        c.picked = product()
        c.entry.qty = '1'
        c.entry.rate = '50'

        expect(await c.addToCart()).toBe(false)
        expect(c.lines).toHaveLength(0)
        expect(c.lotWarning).toBe('খসড়া INV-0042 খোলা আছে')

        /* ⓘ পাল্টা-দাবি: খসড়াহীন ক্রেতায় একই সারি ওঠে */
        c.customerId = '8'
        expect(await c.addToCart()).toBe(true)
        expect(c.lines).toHaveLength(1)
    })

    it('সব মুছলে খোলা খসড়াও ছাড়ে, আর নম্বরের ঘর দুইটা খালি হয়', () => {
        const screen = JSON.parse(filled().screenSnapshot)
        const c = counter({ texts: TEXTS, resume: resumeOf(screen) })
        const boxes = { invoice_no: { value: 'INV-0042' }, challan_no: { value: 'DC-0042' } }
        c.$root = { querySelector: (sel) => boxes[(sel.match(/name=(\w+)/) || [])[1]] ?? null }

        c.start()
        c.clearAll()

        expect(c.resumeId).toBe('')
        expect(c.lines).toHaveLength(0)
        expect(boxes.invoice_no.value).toBe('')
        expect(boxes.challan_no.value).toBe('')
    })
})

/*
 * ⭐ ব্যাংক আর মোবাইল ব্যাংকিংয়ের জমা — মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬:
 * *"counter e bank e taka nile ei porda asena tik koro"*। ⓘ আদায় ভাউচারের
 * "ব্যাংক অনলাইন"-এর ঘরগুলো কাউন্টারের জমাতেও।
 */
describe('জমা — ব্যাংক ও মোবাইল ব্যাংকিংয়ের তথ্য', () => {
    const METHODS = [
        { id: 1, label: 'নগদ', kind: 'cash', accountId: 'c1' },
        { id: 2, label: 'ব্যাংক', kind: 'bank', accountId: 'b1', needsReference: true },
        { id: 3, label: 'বিকাশ', kind: 'mfs', accountId: 'm1', needsReference: true },
    ]
    const ACCOUNTS = [
        { id: 'c1', label: 'ক্যাশ', parent: '1101' },
        { id: 'b1', label: 'ডাচ-বাংলা', parent: '1102' },
        { id: 'm1', label: 'বিকাশ হিসাব', parent: '1105' },
    ]

    const till = () => {
        const c = counter({
            depositMethods: METHODS,
            moneyAccounts: ACCOUNTS,
            transferModes: [{ id: '4', label: 'NPSB' }],
        })

        /* ⓘ তারিখের ঘরটা পর্দায় নেই — `dateBox()` তখন কিছুই পায় না */
        c.$root = { querySelector: () => null }

        return c
    }

    /** ব্যাংকের একটা জমা, সব ঘর ভরা। */
    const bankDeposit = (c) => {
        c.depositDraft.methodId = '2'
        c.pickDepositMethod()
        Object.assign(c.depositDraft, {
            amount: '5000', reference: 'TRX-9',
            transferModeId: '4', fromBank: 'সোনালী', fromBranch: 'মতিঝিল',
            fromAccountName: 'রহিম স্টোর', fromAccountNo: '0012', depositSlipNo: 'SL-1',
            landsOn: '2026-09-28', chargeAmount: '25', chargeBorneBy: 'them',
        })
    }

    it('ব্যাংক বাছলে ব্যাংকের ঘর আসে, নগদে আসে না', () => {
        const c = till()

        c.depositDraft.methodId = '2'
        expect(c.depositIsBank).toBe(true)
        expect(c.depositHasCharge).toBe(true)
        expect(c.depositIsCash).toBe(false)

        c.depositDraft.methodId = '1'
        expect(c.depositIsBank).toBe(false)
        expect(c.depositIsMfs).toBe(false)
        expect(c.depositHasCharge).toBe(false)

        c.depositDraft.methodId = '3'
        expect(c.depositIsMfs).toBe(true)
        expect(c.depositIsBank).toBe(false)
        expect(c.depositHasCharge).toBe(true)
    })

    it('যোগ করা সারিতে ব্যাংকের তথ্য থাকে, আর সার্ভারের নামে যায়', () => {
        const c = till()
        bankDeposit(c)

        c.addDeposit()

        expect(c.deposits).toHaveLength(1)
        expect(c.deposits[0].fromBank).toBe('সোনালী')

        const sent = Object.fromEntries(c.depositDetailsOf(c.deposits[0]).map(d => [d.key, d.value]))
        expect(sent).toEqual({
            transfer_mode_id: '4', from_bank: 'সোনালী', from_branch: 'মতিঝিল',
            from_account_name: 'রহিম স্টোর', from_account_no: '0012',
            deposit_slip_no: 'SL-1', lands_on: '2026-09-28',
            charge_amount: '25', charge_borne_by: 'them',
        })
        expect(c.depositRefText(c.deposits[0])).toBe('NPSB · TRX-9')
    })

    /* ⛔ পরের জমায় আগের ব্যাংকের তথ্য চলে যায় না */
    it('যোগ করার পর খসড়া খালি হয়, চার্জ আবার "আমরা"', () => {
        const c = till()
        bankDeposit(c)

        c.addDeposit()

        expect(c.depositDraft.fromBank).toBe('')
        expect(c.depositDraft.transferModeId).toBe('')
        expect(c.depositDraft.landsOn).toBe('')
        expect(c.depositDraft.chargeAmount).toBe('')
        expect(c.depositDraft.chargeBorneBy).toBe('us')
    })

    /*
     * ⚠️ ব্যাংক বেছে ঘর ভরে পরে নগদে বদলালে মানগুলো খসড়ায় রয়ে যায় —
     * ⛔ তবু নগদের সারির সাথে ব্যাংকের কিছুই সার্ভারে যায় না।
     */
    it('নগদের সারিতে ব্যাংকের কিছুই যায় না, মান রয়ে গেলেও', () => {
        const c = till()
        bankDeposit(c)
        c.depositDraft.methodId = '1'
        c.pickDepositMethod()

        c.addDeposit()

        expect(c.deposits).toHaveLength(1)
        expect(c.depositDetailsOf(c.deposits[0])).toEqual([])
    })

    it('মোবাইল ব্যাংকিংয়ে কেবল তার নিজের ঘর যায়', () => {
        const c = till()
        c.depositDraft.methodId = '3'
        c.pickDepositMethod()
        Object.assign(c.depositDraft, {
            amount: '900', reference: 'BK1', wallet: 'bkash', walletMedium: 'send_money',
            counterpartyPhone: '01811000001', fromBank: 'বাসি মান',
        })

        c.addDeposit()

        const sent = Object.fromEntries(c.depositDetailsOf(c.deposits[0]).map(d => [d.key, d.value]))
        expect(sent).toEqual({
            wallet: 'bkash', wallet_medium: 'send_money', counterparty_phone: '01811000001',
            charge_borne_by: 'us',
        })
    })

    /* ⭐ খসড়া রাখলে ব্যাংকের তথ্যও পর্দার ছবিতে যায়, আর ফেরে */
    it('পর্দার ছবিতে ব্যাংকের তথ্য থাকে আর ফেরে', () => {
        const c = till()
        bankDeposit(c)
        c.addDeposit()

        const back = till()
        back.applyDraft(c.screenSnapshot)

        expect(back.deposits).toEqual(c.deposits)
        expect(back.depositDetailsOf(back.deposits[0])).toEqual(c.depositDetailsOf(c.deposits[0]))
    })
})

/*
 * ⭐ মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬ (সন্ধ্যা) — দুইটা পপ-আপ, আর খসড়া সীমা পেরিয়েও।
 *
 * ⓵ বাকির সীমা ছাড়ালে পপ-আপ ও ধ্বনি — কিন্তু সারিটা কার্টে **যায়**।
 * ⓶ সীমা পেরোনো বিল "খসড়া রাখুন"-এ যায়; "নিশ্চিত করুন"-এ থামে (পপ-আপ, ধ্বনি)।
 * ⓷ সার্ভারের অনুমোদনের বার্তা পর্দা খুলেই পপ-আপে, ধ্বনিসহ।
 */
describe('পপ-আপ — সীমার সতর্কতা, অনুমোদন, আর খসড়া সীমা পেরিয়েও', () => {
    /** সীমা ১০,০০০ · বকেয়া ৮,০০০ → খোলা ২,০০০ */
    const onCredit = (over = {}) => {
        const c = counter({
            customers: { 7: { limit: 10000, due: 8000, days: 30, name: 'রহিম' } },
            creditRules: { enabled: true, zeroBlocks: false },
            ...over,
        })

        c.customerId = '7'
        c.creditTerm = 'credit:30'

        return c
    }

    const entry = (c, rate, id = 1) => {
        c.picked = product({ id, name: 'পণ্য ' + id })
        c.entry.qty = '1'
        c.entry.rate = String(rate)
    }

    const submitEvent = (value) => {
        const e = { stopped: false, submitter: { value } }
        e.preventDefault = () => { e.stopped = true }

        return e
    }

    it('সীমা ছাড়ানো সারি: পপ-আপ খোলে, ধ্বনি বাজে, আর সারিটা কার্টে যায়', async () => {
        const c = onCredit()
        let rang = 0
        c.soundTheAlarm = () => { rang++ }
        entry(c, 2500)

        expect(await c.addToCart()).toBe(true)
        expect(c.lines).toHaveLength(1)
        expect(c.creditWarningOpen).toBe(true)
        expect(c.creditWarning).toContain('2,000')
        expect(rang).toBe(1)
    })

    /* ⛔ পাল্টা-দাবি: সীমার ভিতরে পপ-আপ নেই, ধ্বনিও নেই */
    it('সীমার ভিতরের সারিতে পপ-আপ নেই', async () => {
        const c = onCredit()
        let rang = 0
        c.soundTheAlarm = () => { rang++ }
        entry(c, 1500)

        expect(await c.addToCart()).toBe(true)
        expect(c.creditWarningOpen).toBe(false)
        expect(rang).toBe(0)
    })

    it('"বুঝেছি" সতর্কতার পপ-আপ বন্ধ করে, সারি থাকে', async () => {
        const c = onCredit()
        c.soundTheAlarm = () => {}
        entry(c, 2500)
        await c.addToCart()

        c.closeCreditWarning()

        expect(c.creditWarningOpen).toBe(false)
        expect(c.lines).toHaveLength(1)
    })

    /*
     * ⭐ মালিক: সীমা পেরোনো বিল খসড়া রাখা যায়; পরে জমা যোগ করে সীমার ভিতরে এলে
     * নিশ্চিত। ⛔ খসড়ার বোতামে পর্দা থামালে বিলটা হারাত।
     */
    it('সীমা পেরোনো বিল "খসড়া রাখুন"-এ থামে না', async () => {
        const c = onCredit()
        c.soundTheAlarm = () => {}
        entry(c, 2500)
        await c.addToCart()
        let parked = 0
        c.parkDraft = () => { parked++ }

        const e = submitEvent('1')
        c.guardSubmit(e)

        expect(e.stopped).toBe(false)
        expect(c.creditBlocked).toBe(false)
        expect(parked).toBe(1)
    })

    /* ⛔ আর একই বিল "নিশ্চিত করুন"-এ থামে — পপ-আপ আর ধ্বনি */
    it('একই বিল "নিশ্চিত করুন"-এ থামে, পপ-আপ ও ধ্বনিসহ', async () => {
        const c = onCredit()
        c.soundTheAlarm = () => {}
        entry(c, 2500)
        await c.addToCart()
        let rang = 0
        c.soundTheAlarm = () => { rang++ }

        const e = submitEvent('0')
        c.guardSubmit(e)

        expect(e.stopped).toBe(true)
        expect(c.creditBlocked).toBe(true)
        expect(rang).toBe(1)
    })

    /* ⭐ জমা যোগ করে সীমার ভিতরে এলে নিশ্চিত যায় */
    it('জমা দিয়ে সীমার ভিতরে এলে "নিশ্চিত করুন" যায়', async () => {
        const c = onCredit()
        c.soundTheAlarm = () => {}
        entry(c, 2500)
        await c.addToCart()
        c.deposits = [{ amount: '1000' }]
        c.parkDraft = () => {}

        const e = submitEvent('0')
        c.guardSubmit(e)

        expect(e.stopped).toBe(false)
    })

    it('সার্ভারের অনুমোদনের বার্তা খুলেই পপ-আপে, ধ্বনিসহ', () => {
        const c = counter({ approvalNotice: 'অনুমোদনের জন্য পাঠানো হয়েছে — ডেলিভারি চালান · ৳৫০০' })
        let rang = 0
        c.soundTheAlarm = () => { rang++ }
        c.lookForDraft = () => {}

        c.start()

        expect(c.approvalNoticeShown).toBe(true)
        expect(c.approvalNotice).toContain('অনুমোদনের জন্য পাঠানো হয়েছে')
        expect(rang).toBe(1)

        c.closeApprovalNotice()
        expect(c.approvalNoticeShown).toBe(false)
    })

    /* ⛔ পাল্টা-দাবি: বার্তা না থাকলে পপ-আপও নেই, ধ্বনিও নেই */
    it('বার্তা না থাকলে পপ-আপ নেই', () => {
        const c = counter()
        let rang = 0
        c.soundTheAlarm = () => { rang++ }
        c.lookForDraft = () => {}

        c.start()

        expect(c.approvalNoticeShown).toBe(false)
        expect(rang).toBe(0)
    })
})

/*
 * ⭐ জমার প্যানেল — মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬ (সন্ধ্যা): *উপায় বাছার
 * আগেই নোটের ঘর কেন?* ⓘ উপায় বাছার পরেই তার নিজের ঘর: নগদে নোট, MFS-এ
 * MFS-এর, ব্যাংকে ব্যাংকের; কিছু না বাছলে কিছুই না। আর উপায় বদলালে অন্যের
 * ঘর মুছে যায় — নগদের সাথে পুরনো TrxID যায় না।
 */
describe('জমার প্যানেল — উপায় বাছার পরেই তার ঘর', () => {
    const METHODS = [
        { id: 1, label: 'নগদ', kind: 'cash', accountId: 'c1' },
        { id: 2, label: 'ব্যাংক', kind: 'bank', accountId: 'b1', needsReference: true },
        { id: 3, label: 'বিকাশ', kind: 'mfs', accountId: 'm1', needsReference: true },
    ]
    const ACCOUNTS = [
        { id: 'c1', label: 'ক্যাশ', parent: '1101' },
        { id: 'b1', label: 'ডাচ-বাংলা', parent: '1102' },
        { id: 'm1', label: 'বিকাশ হিসাব', parent: '1105' },
    ]

    const till = (methods = METHODS) => counter({ depositMethods: methods, moneyAccounts: ACCOUNTS })

    const choose = (c, id) => {
        c.depositDraft.methodId = String(id)
        c.pickDepositMethod()
    }

    it('কিছু না বাছলে নোট, MFS, ব্যাংক — কোনো ঘরই নেই', () => {
        const c = till()

        expect(c.depositIsCash).toBe(false)
        expect(c.depositIsMfs).toBe(false)
        expect(c.depositIsBank).toBe(false)
        expect(c.depositHasCharge).toBe(false)
    })

    it('নগদ বাছলে কেবল নোটের ঘর', () => {
        const c = till()
        choose(c, 1)

        expect(c.depositIsCash).toBe(true)
        expect(c.depositIsMfs).toBe(false)
        expect(c.depositIsBank).toBe(false)
    })

    it('MFS বাছলে MFS-এর ঘর (TrxID, প্রেরক, চার্জ) — নোট নয়', () => {
        const c = till()
        choose(c, 3)

        expect(c.depositIsMfs).toBe(true)
        expect(c.depositHasCharge).toBe(true)
        expect(c.depositIsCash).toBe(false)
        expect(c.depositIsBank).toBe(false)
    })

    it('ব্যাংক বাছলে ব্যাংকের ঘর — নোট নয়', () => {
        const c = till()
        choose(c, 2)

        expect(c.depositIsBank).toBe(true)
        expect(c.depositHasCharge).toBe(true)
        expect(c.depositIsCash).toBe(false)
    })

    /* ⚠️ উপায়ের তালিকাই না থাকলে জমা নগদ — নোটের ঘর থাকে (নতুন কোম্পানি) */
    it('উপায়ের তালিকা না থাকলে নোটের ঘর থাকে', () => {
        const c = till([])

        expect(c.depositIsCash).toBe(true)
    })

    /* ⛔ বিপজ্জনক ইনপুট: MFS-এ TrxID, ফোন, চার্জ লিখে নগদে বদলানো */
    it('MFS থেকে নগদে বদলালে TrxID, ফোন আর চার্জ মুছে যায়', () => {
        const c = till()
        choose(c, 3)
        Object.assign(c.depositDraft, {
            reference: 'TRX-1', counterpartyPhone: '01711000000', wallet: 'bkash', chargeAmount: '15',
        })

        choose(c, 1)

        expect(c.depositDraft.reference).toBe('')
        expect(c.depositDraft.counterpartyPhone).toBe('')
        expect(c.depositDraft.wallet).toBe('')
        expect(c.depositDraft.chargeAmount).toBe('')
    })

    it('নগদ থেকে ব্যাংকে বদলালে নোটের হিসাব মুছে যায়', () => {
        const c = till()
        choose(c, 1)
        c.depositDraft.noteCounts = { 1000: '2', 500: '1' }

        choose(c, 2)

        expect(c.depositDraft.noteCounts).toEqual({})
    })

    it('ব্যাংক থেকে MFS-এ বদলালে ব্যাংকের ঘর মুছে যায়', () => {
        const c = till()
        choose(c, 2)
        Object.assign(c.depositDraft, { fromBank: 'সোনালী', depositSlipNo: 'SL-1', transferModeId: '4' })

        choose(c, 3)

        expect(c.depositDraft.fromBank).toBe('')
        expect(c.depositDraft.depositSlipNo).toBe('')
        expect(c.depositDraft.transferModeId).toBe('')
    })
})

/*
 * ── বাহক আর চালকের নম্বর — মালিকের ছবি, ২৭ সেপ্টেম্বর ২০২৬ (রাত) ────────
 * *"বাহকের নাম Dropdown, tar pase mob. no … চালকের নাম & Mobile no ekbar
 * save korle porbortite sajest korbe"*।
 */
describe('বাহক আর চালকের নম্বর', () => {
    it('বাহক বাছলে তার নম্বর দেখায়, তালিকার বাইরে খালি', () => {
        const c = counter({ carriers: [{ id: '7', label: 'Sundarban', phone: '01711000000' }] })

        expect(c.carrierPhone).toBe('')
        c.carrierId = '7'
        expect(c.carrierPhone).toBe('01711000000')
        c.carrierId = ''
        expect(c.carrierPhone).toBe('')
    })

    it('আগে লেখা চালকের নাম বাছলে নম্বর নিজে বসে', () => {
        const c = counter({ drivers: [{ name: 'Karim', phone: '01811111111' }] })

        c.driverName = ' karim '
        c.pickDriver()
        expect(c.driverPhone).toBe('01811111111')
    })

    it('হাতে লেখা নতুন নম্বর মোছে না', () => {
        const c = counter({ drivers: [{ name: 'Karim', phone: '01811111111' }] })

        c.driverPhone = '01999999999'
        c.driverName = 'Karim'
        c.pickDriver()
        expect(c.driverPhone).toBe('01999999999')
    })

    it('এক চালক থেকে আরেকজনে গেলে বসানো নম্বরটাও বদলায়', () => {
        const c = counter({ drivers: [
            { name: 'Karim', phone: '01811111111' },
            { name: 'Rahim', phone: '01822222222' },
        ] })

        c.driverName = 'Karim'
        c.pickDriver()
        c.driverName = 'Rahim'
        c.pickDriver()
        expect(c.driverPhone).toBe('01822222222')
    })

    it('নতুন নাম হলে কিছুই বসে না', () => {
        const c = counter({ drivers: [{ name: 'Karim', phone: '01811111111' }] })

        c.driverName = 'Notun'
        c.pickDriver()
        expect(c.driverPhone).toBe('')
    })
})

/*
 * ── Pending ড্রপডাউন — ক্রেতা না বাছলেও সবার খসড়া ──────────────────────
 * মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"পেন্ডিং list drop dawne ekhantekei asar kotha"*।
 */
describe('Pending ড্রপডাউনের তালিকা', () => {
    const drafts = {
        5: [{ id: 11, no: 'INV-0011', customer: 'রহিম স্টোর', total: '100', date: '27-09-2026' }],
        9: [{ id: 14, no: 'INV-0014', customer: 'করিম ট্রেডার্স', total: '250', date: '28-09-2026' }],
    }

    it('ক্রেতা না বাছা থাকলে সবার খসড়া, নতুনটা আগে, নামসহ', () => {
        const c = counter({ pendingDrafts: drafts })

        expect(c.pendingShown.map(d => d.no)).toEqual(['INV-0014', 'INV-0011'])
        expect(c.pendingLabel(c.pendingShown[0])).toContain('করিম ট্রেডার্স')
    })

    it('ক্রেতা বাছলে কেবল তার খসড়া, নাম ছাড়া', () => {
        const c = counter({ pendingDrafts: drafts })

        c.customerId = '5'
        expect(c.pendingShown.map(d => d.no)).toEqual(['INV-0011'])
        expect(c.pendingLabel(c.pendingShown[0])).not.toContain('রহিম স্টোর')
    })
})

/*
 * ── এক ক্রেতার একটাই খসড়া — বাছার মুহূর্তে পপ-আপ ──────────────────────
 * মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"2ND BAR customer entry dile pop up warning dibe"*।
 */
describe('খোলা খসড়ার পপ-আপ', () => {
    const drafts = { 5: [{ id: 11, no: 'INV-0011', customer: 'রহিম স্টোর', total: '100', date: '27-09-2026' }] }

    it('খোলা খসড়াওয়ালা ক্রেতা বাছলে পপ-আপ খোলে', () => {
        const c = counter({ pendingDrafts: drafts, texts: { openDraftBlocks: 'খসড়া :no খোলা' } })
        c.soundTheAlarm = () => {}
        c.$root = { querySelectorAll: () => [] }  // ⓘ chooseCustomer শর্তের বিকল্প খোঁজে

        c.chooseCustomer(5)
        expect(c.openDraftPopup).toBe(true)
        expect(c.openDraftText).toContain('INV-0011')
    })

    it('খসড়া নেই এমন ক্রেতায় পপ-আপ আসে না', () => {
        const c = counter({ pendingDrafts: drafts })
        c.soundTheAlarm = () => {}
        c.$root = { querySelectorAll: () => [] }

        c.chooseCustomer(9)
        expect(c.openDraftPopup).toBe(false)
    })
})
