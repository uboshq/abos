/*
 * ⭐ টাকা নেওয়ার পর্দার মগজ — মালিকের নকশা ১০০ (২১ অক্টোবর ২০২৬; মালিকের-ফাইল/টাকা-নেওয়া-দেওয়া-চূড়ান্ত-নকশা-১০০-২১-অক্টোবর.pdf, পাতা ২)।
 *
 * ⓘ খোলা বিল ও ক্রেডিট পুরনো আগে নিজে বসে (দফা ৩), সারাংশ "মিলেছে" বলে কেবল যখন পাওয়া + কাটা + ছাড় = প্রয়োগ + অপ্রয়োগকৃত
 * (দফা ৪), মাথার তিন অঙ্ক (দফা ১), আর "খাতায় যা যাবে" সার্ভারের প্রিভিউ থেকে — পোস্ট যে লাইন খাতায় পাঠায় ঠিক সেগুলো (দফা ১০)।
 * ⛔ CSP-Alpine: হিসাব সব এখানে, পর্দার এক্সপ্রেশনে কেবল ঘরের নাম আর মেথড ডাকা ([[shell.js]]-এর মাথার কারণ)। ঘরে getter নেই —
 * প্রতিটা বদলে [[recalc]] সাধারণ ঘরগুলো নতুন করে লেখে।
 * ⓘ টাকা ইংরেজি অঙ্কে, লাখ-কোটির কমায় ([[taka]] — সার্ভারের Money::format-এর একই লেখা)।
 */
import { amountOf, balanceOf, cents, fillOldestFirst, ownerSignNeeded, roundingNeedsOwner, rowOf, signedApply } from './collection-math.js'
import { taka } from './money.js'

export function collectionForm (config = {}) {
    const words = config.words ?? {}

    return {
        customerId: String(config.customerId ?? ''),
        urls: config.urls ?? {},
        items: [],
        due: 0,
        facts: '',
        // ⓘ মাথার "শাখা: গ্রাহকের শাখা (…)" — গ্রাহক বদলালে তাঁর শাখা (দফা ১৪)
        branchText: config.branch ? ' (' + String(config.branch) + ')' : '',
        monthOpen: config.monthOpen !== false,

        amount: String(config.amount ?? ''),
        vds: String(config.vds ?? ''),
        ait: String(config.ait ?? ''),
        discount: String(config.discount ?? ''),
        rounding: String(config.rounding ?? ''),
        method: String(config.method ?? 'cash'),
        charge: 0,

        // ⓘ হাতে বদলালে নিজে থেকে বসানো থামে — মানুষের সিদ্ধান্ত মুছে যায় না
        manual: (config.lines ?? []).length > 0,

        dueText: taka(0),
        thisText: taka(0),
        leftText: taka(0),
        receivedText: taka(0),
        appliedText: taka(0),
        unappliedText: taka(0),
        openTotalText: taka(0),
        balanced: false,
        both: false,
        // ⓘ রাউন্ডিং ৳0.50-এর বেশি — মালিকের সই লাগবে (সার্ভারের CollectionService::differenceNeedsOwner()-এর একই সীমা)
        roundingSigned: false,
        // ⓘ পোস্ট মালিকের সইয়ে যাবে (যেকোনো ছাড়, বা ৳0.50-এর বেশি রাউন্ডিং) — বোতাম আর নিয়ন্ত্রণের কথা বদলায়; CSP-র জন্য উল্টোটাও আলাদা ঘরে
        ownerSigns: false,
        postsNow: true,
        short: false,
        over: false,
        diffKind: '',
        diffAmount: '',

        ledger: [],
        ledgerDr: '',
        ledgerCr: '',
        stops: [],
        referenceFree: false,
        previewFailed: false,
        timer: null,

        init () {
            this.load(config.open ?? null, config.lines ?? [])
        },

        /** সার্ভারের খোলা-কাগজের উত্তর ([[CollectionOpenItems]]) পর্দায় */
        load (open, chosen = []) {
            const items = open && Array.isArray(open.items) ? open.items : []

            this.items = items.map(item => rowOf(item, words))
            this.due = open ? amountOf(open.due) : 0
            this.monthOpen = open ? open.month_open !== false : this.monthOpen
            this.facts = open ? this.factsOf(open.customer ?? {}) : ''
            this.branchText = open && (open.customer ?? {}).branch ? ' (' + String(open.customer.branch) + ')' : ''

            for (const line of chosen) {
                const row = this.items.find(r => r.id === String(line.id))

                if (row) {
                    row.on = true
                    row.apply = (row.credit ? -Math.abs(amountOf(line.amount)) : amountOf(line.amount)).toFixed(2)
                }
            }

            if (!this.manual) {
                fillOldestFirst(this.items, this.grossNow())
            }

            this.recalc()
        },

        /** "বাকির সীমা ৳1,00,000 · সবচেয়ে পুরনো বাকি 19 দিন" */
        factsOf (customer) {
            const limit = customer.credit_limit === null || customer.credit_limit === undefined
                ? (words.noLimit ?? '')
                : String(words.limit ?? '').replace(':limit', taka(amountOf(customer.credit_limit), { decimals: 0 }))
            const oldest = customer.oldest_overdue_days
                ? String(words.oldest ?? '').replace(':days', String(customer.oldest_overdue_days))
                : ''

            return [limit, oldest].filter(Boolean).join(' · ')
        },

        grossNow () {
            return balanceOf(this, []).gross
        },

        /** গ্রাহক বদলালে তাঁর খোলা কাগজ — আগের গ্রাহকের সারি মুছে (অন্যের বিলে টাকা বসবে না) */
        async pickCustomer (id) {
            this.customerId = String(id ?? '')
            this.items = []
            this.manual = false
            this.recalc()

            if (this.customerId === '' || !this.urls.openItems) {
                return
            }

            try {
                const response = await fetch(this.urls.openItems + '?customer=' + encodeURIComponent(this.customerId), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })

                if (response.ok) {
                    this.load(await response.json())
                }
            } catch (e) {
                this.previewFailed = true
            }
        },

        /** টাকা, কাটা বা ছাড় বদলাল — হাতে না ছুঁয়ে থাকলে পুরনো আগে আবার বসে */
        changed () {
            if (!this.manual) {
                fillOldestFirst(this.items, this.grossNow())
            }

            this.recalc()
        },

        /** টিক হাতে বদলাল — টিক দিলে সারির পুরো বাকি বসে, তুললে খালি */
        touched (row) {
            this.manual = true

            if (row && !row.on) {
                row.apply = ''
            } else if (row && row.on && amountOf(row.apply) === 0) {
                row.apply = row.open.toFixed(2)
            }

            this.recalc()
        },

        /** সারির "এখন প্রয়োগ" হাতে লেখা — লেখার মাঝপথে ঘরটা ফেরানো হয় না, কেবল হিসাব নতুন */
        typed () {
            this.manual = true
            this.recalc()
        },

        /** সার্ভারে যা যায় — ক্রেডিটের সারি সবসময় ঋণাত্মক, বিল ধনাত্মক (সার্ভার চিহ্ন না মিললে ফেরায়; হাতে "2000" লিখলেও) */
        sent (row) {
            return signedApply(row)
        },

        /** "পুরো বকেয়া" — বকেয়াটাই টাকার ঘরে, তারপর পুরনো আগে */
        fillDue () {
            this.amount = cents(Math.max(0, this.due)).toFixed(2)
            this.manual = false
            this.changed()
        },

        /** "নির্বাচিত বিল" — বাছা সারির যোগ টাকার ঘরে */
        fillChosen () {
            const sum = cents(this.items.filter(r => r.on).reduce((s, r) => s + (r.credit ? -Math.abs(amountOf(r.apply)) : amountOf(r.apply)), 0))

            this.amount = Math.max(0, sum).toFixed(2)
            this.manual = true
            this.recalc()
        },

        /** ⓘ cb-র পদ্ধতির ব্লক জানায় ([[moneyMethod]]-এর `money-method` ঘটনা) — চার্জ টাকার ভিতরেই, তাই কেবল প্রিভিউ নতুন */
        moneyChanged (detail) {
            this.method = String((detail ?? {}).method ?? this.method)
            this.charge = amountOf((detail ?? {}).charge)
            this.later()
        },

        recalc () {
            const b = balanceOf(this, this.items)

            this.dueText = taka(this.due)
            this.thisText = taka(b.gross)
            this.leftText = taka(this.due - b.gross)
            this.receivedText = taka(amountOf(this.amount))
            this.appliedText = taka(b.applied)
            this.unappliedText = taka(b.unapplied)
            this.openTotalText = taka(b.openTotal)
            this.balanced = b.balanced
            this.both = b.both
            this.roundingSigned = roundingNeedsOwner(this.rounding)
            this.ownerSigns = ownerSignNeeded(this.discount, this.rounding)
            this.postsNow = !this.ownerSigns
            this.short = b.unapplied < -0.004
            this.over = this.customerId !== '' && b.gross > this.due + 0.004

            // ⓘ সার্ভার একটাই পার্থক্য নেয় — ছাড় থাকলে ছাড়, নইলে রাউন্ডিং
            this.diffKind = amountOf(this.discount) > 0 ? 'discount' : (amountOf(this.rounding) > 0 ? 'rounding' : '')
            this.diffAmount = this.diffKind === 'discount' ? this.discount : (this.diffKind === 'rounding' ? this.rounding : '')

            this.later()
        },

        later () {
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.preview(), 300)
        },

        /** ⭐ খাতায় যা যাবে — সার্ভারের প্রিভিউ (দফা ১০); কিছুই লেখে না */
        async preview () {
            const form = this.$root.tagName === 'FORM' ? this.$root : this.$root.closest('form')

            if (!form || !this.urls.preview || this.customerId === '' || amountOf(this.amount) <= 0) {
                this.ledger = []
                this.stops = []
                return
            }

            const data = new FormData(form)
            data.delete('slip')
            data.delete('_once')

            try {
                const response = await fetch(this.urls.preview, {
                    method: 'POST',
                    body: data,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': String(data.get('_token') ?? ''),
                    },
                    credentials: 'same-origin',
                })
                const body = await response.json()

                this.previewFailed = false

                if (!response.ok) {
                    this.ledger = []
                    this.stops = Object.values(body.errors ?? {}).flat().map(String)
                    this.referenceFree = false
                    return
                }

                this.ledger = (body.lines ?? []).map(line => ({
                    account: String(line.account),
                    branch: String(line.branch),
                    dr: amountOf(line.dr) > 0 ? taka(amountOf(line.dr)) : '',
                    cr: amountOf(line.cr) > 0 ? taka(amountOf(line.cr)) : '',
                }))
                this.ledgerDr = taka(amountOf(body.total_dr))
                this.ledgerCr = taka(amountOf(body.total_cr))
                this.stops = (body.stops ?? []).map(String)
                this.referenceFree = body.reference_free === true
                // ⓘ সার্ভারের কথাই শেষ — একই নিয়ম, তবে পার্থক্যের ধরন সার্ভার ঠিক করে
                this.ownerSigns = body.needs_signature === true
                this.postsNow = !this.ownerSigns
            } catch (e) {
                this.previewFailed = true
            }
        },
    }
}
