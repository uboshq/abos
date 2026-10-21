/*
 * টাকার ঘরগুলো — কোন খাত, কীভাবে, কত।
 *
 * ⓘ ব্লেডের অ্যাট্রিবিউট থেকে এখানে এসেছে ১৯ সেপ্টেম্বর ২০২৬-এ (নিরীক্ষার
 * ধাপ ৩.১): CSP-Alpine অ্যাট্রিবিউটের ভিতরে getter, `?.`, `Math` বা
 * `Object` পড়তে পারে না। কারণের বিস্তার [[shell.js]]-এর মাথায়।
 */

/**
 * খাত বাছাই — বাছা খাতটা কোন ধরনের, আর নম্বরের ঘর লাগবে কি না।
 *
 * ⚠️ ধরনটা প্রতিটা option-এ `data-kind`-এ বসে, তালিকার আলাদা কোনো নকলে
 * নয়। দুই জায়গায় রাখলে একটা তালিকা ছাঁকা হত আর অন্যটা নয়, আর তখন
 * নম্বরের ঘরটা ভুল খাতে ভেসে উঠত।
 */
export function moneyAccount ({ chosen = '' } = {}) {
    return {
        chosen,

        /*
         * ⛔ ধরনটা `chosen` ধরে — ২৭ সেপ্টেম্বর ২০২৬, লাইভে TCL-এ ধরা।
         *
         * আগে এখানে `$refs.picker.selectedOptions` পড়া হত। ⚠️ Alpine ওটা
         * নজরে রাখে না, তাই `x-show="needsReference"` একবার মেপে আর কখনো
         * মাপত না: ব্যাংক বাছার পরেও লেনদেন-নম্বরের ঘর আসত না — হাতধার,
         * আমানত, মূলধন, উত্তোলন আর ভাড়ার প্রতিটা পর্দায়। ⓘ `chosen` নজরে
         * থাকা মান (`x-model`), তাই এখন বাছাই বদলালেই ঘরটা আসে-যায়।
         */
        get kind () {
            const value = String(this.chosen ?? '')
            const option = [...(this.$refs.picker?.options ?? [])].find(o => o.value === value)

            return option?.dataset?.kind ?? ''
        },

        get needsReference () {
            return this.kind === 'bank' || this.kind === 'mfs'
        },
    }
}

/**
 * টাকার অঙ্ক লেখার একটাই নিয়ম — লাখ-কোটির কমা।
 *
 * ── ⛔ কেন এক জায়গায়, ২১ সেপ্টেম্বর ২০২৬ ────────────────────
 * পাঁচটা ফাইলে সাতবার আলাদা করে `toLocaleString('en-US')` লেখা ছিল।
 * ⚠️ তাতে সার্ভারের লেখা ([[Money::format]]) আর পর্দার লেখা একই
 * সংখ্যা দুই রকম দেখাত — কাউন্টারে `1,234,567`, আর ছাপা কাগজে
 * `12,34,567`।
 *
 * ⓘ `en-IN` ইংরেজি অঙ্কেই লেখে, কেবল কমার জায়গা বাংলাদেশ-
 * ভারতের নিয়মে — সার্ভারের সাথে হুবহু এক।
 */
export function taka (value, { decimals = 2 } = {}) {
    return Number(value || 0).toLocaleString('en-IN', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
}

/** নোট গুনে মোট — বড় নোট থেকে ছোট, যা বসানো হয়েছে */
export function countNotes (notes) {
    return Object.entries(notes)
        .reduce((sum, [note, qty]) => sum + (Number(note) * (Number(qty) || 0)), 0)
}

/**
 * টাকা কীভাবে এল বা গেল — মাধ্যম, চার্জ, নোট গোনা, চেকের তারিখ।
 *
 * ⓘ `amountField`: ফর্মের কোন ঘরে টাকার অঙ্কটা থাকে — শুরুতে সেখান
 * থেকেই পড়া হয়, যাতে নোট গোনার তুলনাটা প্রথম থেকেই ঠিক থাকে।
 */
/**
 * খাতের ধরন দেখে মাধ্যম — নগদ খাতে নগদ, MFS-এ MFS, ব্যাংকে ব্যাংকের কোনো পথ।
 *
 * ⓘ ব্যাংকে ট্রান্সফার, চেক আর কার্ড তিনটাই চলে, তাই আগে থেকে ওর একটা বাছা
 * থাকলে সেটাই থাকে; নইলে ট্রান্সফার। ⚠️ সার্ভারও ঠিক এই মিল চায়
 * ([[VoucherService::assertTheWayMatchesTheAccount()]]) — দুই জায়গা এক কথা বলে।
 */
export function wayForKind (kind, current) {
    if (kind === 'cash') return 'cash'
    if (kind === 'mfs') return 'mfs'
    if (kind === 'bank') return ['transfer', 'cheque', 'card'].includes(current) ? current : 'transfer'

    return current
}

/**
 * ⭐ [[components.money.method]]-এর মগজ — পদ্ধতি, চার্জের লাইন, আগাম চেক, নোট গোনা (মালিক, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ ব্যাংকের খাতে চারটা পথই চলে (ট্রান্সফার, নগদ জমা, চেক, পুরনো কার্ড), তাই আগে থেকে ওর একটা বাছা থাকলে সেটাই থাকে;
 * নইলে ট্রান্সফার — [[wayForKind]]-এর একই নিয়ম, নগদ জমা যোগ করে।
 */
export function methodForKind (kind, current) {
    if (kind === 'cash') return 'cash'
    if (kind === 'mfs') return 'mfs'
    if (kind === 'bank') return ['transfer', 'deposit', 'cheque', 'card'].includes(current) ? current : 'transfer'

    return current
}

/** চার্জের লাইন — লেখার ছাঁচে অঙ্ক দুইটা বসানো (`:charge`, `:amount`, `:head`) */
export function chargeLine (template, { charge, amount, head }) {
    return String(template ?? '')
        .replace(':charge', taka(charge))
        .replace(':amount', taka(amount))
        .replace(':head', head ?? '')
}

export function moneyMethod ({
    method = 'cash',
    charge = '',
    chequeDate = '',
    amountField = 'amount',
    chargeField = 'charge_amount',
    moneyField = '',
    direction = 'in',
    notes = {},
} = {}) {
    return {
        method: method || 'cash',
        charge: charge ?? '',
        chequeDate: chequeDate || '',
        amount: 0,
        notes: (notes && typeof notes === 'object') ? { ...notes } : {},

        readAmount () {
            const field = this.$el.closest('form')?.querySelector(`[name="${amountField}"]`)
            const raw = String(field?.value ?? '').replace(/[^0-9.]/g, '')

            this.amount = raw === '' ? 0 : Number(raw)
        },

        /*
         * ⓘ বাইরের অংশের জন্য (আদায়ের সারাংশ, a5): পদ্ধতি বা চার্জ বদলালেই `money-method` ঘটনা, `detail = { method, charge }` —
         * চার্জ কেবল যে পদ্ধতিতে চার্জের ঘর আছে, নইলে ০ ([[chargeNumber]])।
         */
        announce () {
            this.$dispatch('money-method', { method: this.method, charge: this.chargeNumber })
        },

        init () {
            this.readAmount()
            this.$watch('method', () => this.announce())
            this.$watch('charge', () => this.announce())
            this.$nextTick(() => this.announce())

            const form = this.$el.closest('form')

            form?.addEventListener('input', (event) => {
                if (event.target?.name === amountField) {
                    this.readAmount()
                }
            })

            if (moneyField) {
                form?.addEventListener('change', (event) => {
                    if (event.target?.name === moneyField) {
                        this.followAccount(event.target)
                    }
                })
            }
        },

        followAccount (select) {
            const option = [...(select?.options ?? [])].find(o => o.value === select.value)

            this.method = methodForKind(option?.dataset?.kind ?? '', this.method)
        },

        /* ⓘ চার্জ কেবল যে পদ্ধতিতে চার্জের ঘর আছে — নগদে বা চেকে পুরনো সংখ্যা থেকে গেলে লাইনটা মিথ্যা বলত */
        get chargeNumber () {
            return ['mfs', 'transfer', 'deposit'].includes(this.method) ? (Number(this.charge) || 0) : 0
        },

        get hasCharge () {
            return this.chargeNumber > 0
        },

        /* ⓘ `$root`, `$el` নয় — getter-এ `$el` হল যে ঘর লাইনটা আঁকে, আর ছাঁচগুলো বাক্সের মাথায় */
        get chargeLine () {
            const data = this.$root.dataset

            return chargeLine(direction === 'out' ? data.lineOut : data.lineIn, {
                charge: this.chargeNumber,
                amount: this.amount,
                head: this.method === 'mfs' ? data.headMfs : data.headBank,
            })
        },

        get postDated () {
            return this.method === 'cheque' && this.chequeDate !== ''
                && this.chequeDate > new Date().toISOString().slice(0, 10)
        },

        noteTotal (note) {
            const sum = (Number(this.notes[note]) || 0) * note

            return sum ? taka(sum, { decimals: 0 }) : '—'
        },

        get counted () {
            return countNotes(this.notes)
        },

        get countMatches () {
            return Math.abs(this.counted - Number(this.amount || 0)) < 0.005
        },

        get countClass () {
            return this.countMatches
                ? 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                : 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)'
        },

        get countText () {
            const line = this.$root.querySelector('[data-agrees]')

            return this.countMatches ? (line?.dataset?.agrees ?? '') : (line?.dataset?.differs ?? '')
        },
    }
}

export function moneyMovement ({
    amountField,
    method = 'cash',
    charge = 0,
    chequeDate = '',
    notes = {},
    moneyField = '',
} = {}) {
    return {
        /*
         * ⛔ শুরুর মান সার্ভার থেকে — ২৭ সেপ্টেম্বর ২০২৬।
         *
         * আগে এখানে `method: 'cash'`, `charge: 0`, `notes: {}` আর
         * `chequeDate: ''` লেখা ছিল। ⚠️ `x-model` তখন সার্ভারের পুরনো মানের
         * উপরে এগুলো বসাত, তাই ভুল নিয়ে ফর্ম ফিরলে বা সম্পাদনায় মাধ্যম আবার
         * "নগদ" হত, আর লেনদেন-নম্বর, চার্জ, নোট গোনা, চেকের তারিখ হারাত।
         */
        method: method || 'cash',
        charge: Number(charge) || 0,
        chargeBy: 'us',
        amount: 0,
        notes: (notes && typeof notes === 'object') ? { ...notes } : {},
        chequeDate: chequeDate || '',

        /*
         * ⛔ অঙ্কটা একবার নয়, প্রতিবার — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ── ⚠️ মালিক যা দেখেছেন ────────────────────────────────────
         * নোট গুনে ১০,০০০, ঘরে লেখা ১০০০০, তবু পর্দা বলত *"গোনা টাকা
         * আর লেখা টাকা মিলছে না"*।
         *
         * ⓘ কারণ এই ব্লকটা আগে কেবল `init()`-এ চলত — পাতা খোলার সময়,
         * যখন ঘরটা **খালি**। ⛔ তাই `amount` চিরকাল ০ হয়ে বসে থাকত,
         * আর পরে যত টাকাই লেখা হোক কম্পোনেন্ট জানতই না।
         *
         * ⚠️ ফল সবচেয়ে খারাপ ধরনের: সংখ্যা দুইটা চোখের সামনে সমান,
         * অথচ ব্যবস্থা "মিলছে না" বলে আটকে দেয় — মানুষ ভাবেন নিজে ভুল
         * করেছেন আর বারবার গোনেন।
         *
         * ⭐ এখন ঘরটা শোনা হয় (`input`), তাই টাইপ করার সাথে সাথেই
         * সংখ্যাটা মেলে। ⓘ `init()`-এর প্রথম পড়াটাও থাকে — সম্পাদনার
         * সময় ঘরটা আগে থেকেই ভরা থাকতে পারে।
         */
        readAmount () {
            const field = this.$el.closest('form')?.querySelector(`[name="${amountField}"]`)
            const raw = String(field?.value ?? '').replace(/[^0-9.]/g, '')

            this.amount = raw === '' ? 0 : raw
        },

        init () {
            this.readAmount()

            /*
             * ⓘ শ্রোতাটা ফর্মে, ঘরে নয়: টাকার ঘরটা Alpine-এর `x-model`
             * দিয়ে চলে আর পর্দার অংশ পরে বসতেও পারে। ⚠️ ঘরে সরাসরি
             * বসালে ঐ দেরিতে আসা ঘরগুলো বাদ পড়ত।
             */
            this.$el.closest('form')?.addEventListener('input', (event) => {
                if (event.target?.name === amountField) {
                    this.readAmount()
                }
            })

            /*
             * ⭐ টাকার খাত বদলালে মাধ্যমও — ২৭ সেপ্টেম্বর ২০২৬।
             *
             * ⛔ লাইভে: ব্যাংক বাছার পরেও চিপ "নগদ"-এ থাকত, তাই লেনদেন-নম্বরের
             * ঘরটা আসতই না, অথচ সার্ভার সেটা চাইত। ⓘ এখন খাতের ধরন
             * (`data-kind`) দেখে চিপটা নিজে সরে, আর চিপের সাথে ঘরগুলোও আসে।
             */
            if (moneyField) {
                this.$el.closest('form')?.addEventListener('change', (event) => {
                    if (event.target?.name === moneyField) {
                        this.followAccount(event.target)
                    }
                })
            }
        },

        followAccount (select) {
            /*
             * ⓘ ধরনটা মান ধরে খোঁজা option থেকে, `selectedOptions` থেকে নয় —
             * দ্বিতীয়বার বদলালে কিছু পরিবেশে (happy-dom) ওটা পুরনো সারিই দিত,
             * আর মাধ্যম আগের খাতেই আটকে থাকত।
             */
            const option = [...(select?.options ?? [])].find(o => o.value === select.value)
            const kind = option?.dataset?.kind ?? ''

            this.method = wayForKind(kind, this.method)
        },

        get counted () {
            return countNotes(this.notes)
        },

        get countMatches () {
            return Math.abs(this.counted - Number(this.amount || 0)) < 0.005
        },

        get postDated () {
            return this.method === 'cheque' && this.chequeDate
                && this.chequeDate > new Date().toISOString().slice(0, 10)
        },
    }
}
