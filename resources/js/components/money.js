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

        get kind () {
            const option = this.$refs.picker?.selectedOptions?.[0]

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
export function moneyMovement ({ amountField } = {}) {
    return {
        method: 'cash',
        charge: 0,
        chargeBy: 'us',
        amount: 0,
        notes: {},
        chequeDate: '',

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
