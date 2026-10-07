/*
 * তারিখের ঘরের আচরণ — দিন-মাস-বছর, সব কম্পিউটারে এক।
 *
 * ── কেন এটা আলাদা ফাইলে, ইনলাইন x-data নয় ────────────────────────────
 * এখানে দুইটা রূপান্তর আছে (দেখানোর ছাঁদ ⇄ ISO), আর দুইটাই ভুল হলে
 * খাতায় ভুল তারিখ বসে। ইনলাইন লিখলে ওটার কোনো পরীক্ষা লেখা যেত না —
 * ঠিক যে কারণে `pricing.js` আলাদা।
 *
 * ── কেন ব্রাউজারের নিজের ঘর নয় ───────────────────────────────────────
 * `<input type="date">`-এর প্রদর্শিত ছাঁদ বদলানোর কোনো API নেই। এই
 * কম্পিউটারে en-US থাকায় ১৯ আগস্ট দেখাত `08/19/2026`। `08/19` ভুল
 * পড়ার সুযোগ নেই (১৯ কোনো মাস নয়), কিন্তু **`05/06` পড়া যায় দুইভাবে**
 * — আর দুইটাই বৈধ তারিখ, তাই ভুলটা খাতা থেকে ধরাই যায় না।
 */

/** ISO (2026-08-17) → দেখানোর ছাঁদ (17-08-2026) */
export function toDisplay(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso ?? '')

    return m ? `${m[3]}-${m[2]}-${m[1]}` : ''
}

/**
 * দেখানোর ছাঁদ → ISO। না মিললে খালি।
 *
 * তারিখটা সত্যিই আছে কি না, সেটাও দেখা হয়: ৩১-০২-২০২৬ ছাঁদে ঠিক, কিন্তু
 * এমন কোনো দিন নেই। Date নিজে ওটাকে ৩ মার্চে গড়িয়ে দেয় — নীরবে, আর তখন
 * ব্যবহারকারী যা লিখেছেন আর যা জমা হলো তা আলাদা।
 */
export function toIso(text) {
    const m = /^(\d{2})-(\d{2})-(\d{4})$/.exec((text ?? '').trim())

    if (! m) {
        return ''
    }

    const [, d, mo, y] = m
    const dt = new Date(Date.UTC(+y, +mo - 1, +d))

    const real = dt.getUTCFullYear() === +y
        && dt.getUTCMonth() === +mo - 1
        && dt.getUTCDate() === +d

    return real ? `${y}-${mo}-${d}` : ''
}

/**
 * টাইপ করার সময় `-` নিজে থেকেই বসে।
 *
 * কেউ `17082026` লিখলেও চলে — আর এটাই বেশিরভাগ মানুষ করেন, কারণ
 * কীবোর্ডের সংখ্যা-প্যাডে হাইফেন নেই।
 */
export function mask(text) {
    const d = (text ?? '').replace(/\D/g, '').slice(0, 8)

    if (d.length <= 2) {
        return d
    }

    if (d.length <= 4) {
        return `${d.slice(0, 2)}-${d.slice(2)}`
    }

    return `${d.slice(0, 2)}-${d.slice(2, 4)}-${d.slice(4)}`
}

/** Alpine-এর জন্য মোড়ক */
export function abosDate(iso, submitOnChange = false) {
    return {
        iso: iso ?? '',
        text: toDisplay(iso),
        submitOnChange,

        /*
         * ছাঁকনির ঘরে তারিখ বসলেই তালিকা নতুন করে আসে।
         *
         * requestSubmit(), submit() নয় — submit() ভ্যালিডেশন ও
         * `submit` ইভেন্ট দুইটাই এড়িয়ে যায়, তাই অন্য ঘরের ভুল ধরা
         * পড়ত না আর কোনো JS হুকও চলত না।
         */
        resubmit() {
            if (! this.submitOnChange) {
                return
            }

            const form = this.$el.closest('form')

            if (form && typeof form.requestSubmit === 'function') {
                form.requestSubmit()
            }
        },

        mask() {
            this.text = mask(this.text)

            /*
             * পুরো তারিখ লেখা হয়ে গেলেই ISO বসে — blur-এর অপেক্ষা নয়।
             * নাহলে ফর্মটা Enter-এ জমা হলে লুকানো ঘরটা পুরনো মান নিয়ে
             * যেত, আর ব্যবহারকারী যা দেখছেন তার চেয়ে আলাদা তারিখ বসত।
             */
            if (this.text.length === 10) {
                this.iso = toIso(this.text)
            }
        },

        commit() {
            const iso = toIso(this.text)

            /*
             * আধা-লেখা তারিখ ফেলে দেওয়া হয় না, ফিরিয়ে আনা হয়।
             *
             * কেউ `17-0` পর্যন্ত লিখে অন্য ঘরে চলে গেলে ঘরটা খালি করে
             * দিলে তিনি ভাবতেন তারিখটা মুছে গেছে। আগের ভালো মানটাই
             * ফিরিয়ে দেখানো হয় — যা জমা হবে, তাই দেখা যায়।
             */
            if (iso) {
                const moved = iso !== this.iso
                this.iso = iso
                this.text = toDisplay(iso)

                if (moved) {
                    this.resubmit()
                }
            } else {
                this.text = toDisplay(this.iso)
            }
        },

        fromNative(value) {
            const moved = (value ?? '') !== this.iso
            this.iso = value ?? ''
            this.text = toDisplay(this.iso)

            if (moved) {
                this.resubmit()
            }
        },

        pick() {
            const n = this.$refs.native

            /*
             * showPicker() Chrome/Edge ৯৯+ এ আছে। না থাকলে কিছুই হয় না,
             * আর ঘরটায় হাতে লেখা যায় — তাই কোথাও আটকে যায় না।
             */
            if (n && typeof n.showPicker === 'function') {
                n.showPicker()
            }
        },
    }
}
