import { beforeEach, describe, expect, it } from 'vitest'
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

        c.picked = product()
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
     */
    it('সীমা ছাড়ালে সারিটা কার্টে যায় না', async () => {
        const c = onCredit()
        entry(c, 2500)

        expect(await c.addToCart()).toBe(false)
        expect(c.lines).toHaveLength(0)
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
        expect(await c.addToCart()).toBe(false)
        expect(c.lines).toHaveLength(1)
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
    it('পুরনো "পার হতে দাও" বা চাবির ঘর এলেও আটকায়', async () => {
        const c = onCredit({
            creditRules: { enabled: true, blocks: false, zeroBlocks: false, canOverride: true },
        })
        entry(c, 50000)

        expect(await c.addToCart()).toBe(false)
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

        const shut = onCredit({
            customers: { 7: { limit: 0, due: 0, days: 30, name: 'নতুন' } },
            creditRules: { enabled: true, zeroBlocks: true },
        })
        entry(shut, 500)
        expect(await shut.addToCart()).toBe(false)
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

        expect(await c.addToCart()).toBe(false)
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
