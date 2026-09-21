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

        init () {
            const field = this.$el.closest('form')?.querySelector(`[name="${amountField}"]`)
            const raw = String(Number(field?.value || 0)).replace(/[^0-9.]/g, '')

            this.amount = raw || 0
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
