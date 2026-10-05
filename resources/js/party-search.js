/**
 * খোঁজা যায় এমন পক্ষের তালিকা — একটাই কম্পোনেন্ট, সব লম্বা পক্ষ-পিকারের জন্য।
 *
 * ── ⭐ কেন, ৩ অক্টোবর ২০২৬ ───────────────────────────────────────────
 * মালিক: *"ডেবিট নোট পার্টি সার্চ দেয়ার অপশন নাই"*। ক্রেডিট / ডেবিট নোটের
 * "কাকে" ঘরটা একটা লম্বা সাধারণ `<select>` — ইউবি-তে ~৪১৪ জন গ্রাহক, আর
 * একই নামের দুইটা দোকান ("M/S. Bismillah Store") নাম দেখে আলাদা করা যায় না।
 *
 * ⓘ একই অভিযোগ আগে রসিদ / পরিশোধে মেটানো হয়েছিল (b41d5036,
 * [[party-voucher.js]])। খোঁজার যুক্তিটা এখানে তুলে আনা হলো, যাতে প্রতিটা
 * পর্দা নিজের কপি না রাখে — দুইটা কপি একদিন আলাদা হয়ে যায়।
 *
 * ── ⚠️ যা বদলায় না ──────────────────────────────────────────────────
 * ফর্ম আগের নামেই আগের মানটা পাঠায় — এখন লুকানো ঘরে
 * ([[x-ui.party-search]])। খোঁজার ঘরের কোনো `name` নেই।
 */

/**
 * একবারে কতগুলো নাম আঁকা হয়।
 *
 * ⓘ ইউবি-তে ৪১৪ জন গ্রাহক — সব আঁকা যায়, কিন্তু যে কোম্পানির হাজার
 * পাঁচেক, তার পর্দা প্রতিটা অক্ষরে থমকাত। ⚠️ বাকিরা হারায় না: তালিকার
 * নিচে লেখা থাকে "আরও আছে — লিখে খুঁজুন"।
 */
export const SHOWN_AT_ONCE = 100

/**
 * ⭐ খোঁজা — নাম, কোড, মোবাইল বা পয়েন্টের যেকোনো অংশ, ৩ অক্টোবর ২০২৬।
 *
 * ⓘ লেখাটা শব্দে ভাঙা হয়, আর **প্রতিটা** শব্দ মিলতে হয় — "bismillah
 * kawran" লিখলে কেবল কারওয়ান বাজারের বিসমিল্লাহ, সব বিসমিল্লাহ নয়।
 * ⚠️ খোঁজা হয় `find`-এ (সার্ভার গড়ে দেয়: দুই ভাষার নাম, কোড, পয়েন্ট,
 * মোবাইল — [[PartyRegistry::pickerFind()]]); না থাকলে নাম আর ছোট লাইনটাই।
 */
export function matchParties(options, term) {
    const words = String(term || '').toLowerCase().split(/\s+/).filter(Boolean)

    if (words.length === 0) {
        return options
    }

    return options.filter((p) => {
        const haystack = (p.find || ((p.label || '') + ' ' + (p.hint || ''))).toLowerCase()

        return words.every((w) => haystack.includes(w))
    })
}

/**
 * Alpine কম্পোনেন্ট — `x-data="partySearch({ value, options, uid })"`।
 *
 * ⓘ `uid` প্রতিটা ঘরের নিজের — একই পাতায় কয়েকটা পিকার (জার্নালের সারি)
 * থাকলেও সারির `id` একে অন্যের সাথে মেশে না।
 */
export default function partySearch({ value = '', options = [], uid = 'party', clearable = false } = {}) {
    return {
        /** বাছা পক্ষের id — লুকানো ঘরে এটাই যায়। */
        value: value === null || value === undefined ? '' : String(value),

        /*
         * ⭐ ঐচ্ছিক ঘর — জাবেদার সারির পক্ষ বা খাত (৫ অক্টোবর ২০২৬): তালিকার মাথায় "—", বাছলে ঘর খালি, আগের
         * `<select>`-এর `<option value="">—</option>`-এর মতো। ⓘ খোঁজার শব্দ দিলে ওটা আর মেলে না, তাই সরে যায়।
         */
        options: clearable ? [{ id: '', label: '—', hint: '', find: '' }, ...options] : options,
        uid,

        listOpen: false,
        search: '',
        cursor: 0,

        get matches() {
            return matchParties(this.options, this.search)
        },

        /** যতগুলো আঁকা হয় ([[SHOWN_AT_ONCE]])। */
        get shown() {
            return this.matches.slice(0, SHOWN_AT_ONCE)
        },

        get moreHidden() {
            return this.matches.length > SHOWN_AT_ONCE
        },

        get noMatch() {
            return this.matches.length === 0
        },

        get picked() {
            return this.options.find((p) => String(p.id) === this.value) || null
        },

        get pickedLabel() {
            return this.picked ? this.picked.label : '—'
        },

        /** বোতামের নিচের ছোট লাইন — কোড · পয়েন্ট · মোবাইল, একই নামের দুই দোকান আলাদা করতে। */
        get pickedHint() {
            return this.picked ? (this.picked.hint || '') : ''
        },

        get hasPickedHint() {
            return this.pickedHint !== ''
        },

        /** স্ক্রিন-রিডারের জন্য — আলো-পড়া সারির id। */
        get activeOption() {
            return this.listOpen && this.shown.length > 0 ? this.optionId(this.cursor) : ''
        },

        optionId(i) {
            return this.uid + '-opt-' + i
        },

        isCursor(i) {
            return i === this.cursor
        },

        isPicked(p) {
            return String(p.id) === this.value
        },

        /**
         * ⭐ দলের নাম — দলের প্রথম সারির মাথায়, নাহলে খালি (গ্রাহক · সরবরাহকারী · ব্যক্তি · কর্মী, জাবেদার পক্ষ,
         * ৫ অক্টোবর ২০২৬)। ⓘ খোঁজার পরেও দল ধরে — যে সারিগুলো মেলে কেবল সেগুলোর দলের নাম।
         */
        groupLabel(i) {
            const row = this.shown[i]

            if (!row || !row.group) {
                return ''
            }

            return i === 0 || (this.shown[i - 1] && this.shown[i - 1].group !== row.group) ? row.group : ''
        },

        /**
         * তালিকা খোলা — খোঁজার ঘরে সাথে সাথে ফোকাস।
         *
         * ⓘ বোতামে একটা অক্ষর টাইপ করলে সেটাই প্রথম অক্ষর হয়ে বসে — হিসাবের
         * লোক ঘরে এসে সরাসরি নাম লিখতে শুরু করেন, আগে ক্লিক করতে হয় না।
         */
        openList(first = '') {
            this.search = first
            this.listOpen = true

            const at = this.shown.findIndex((p) => this.isPicked(p))

            this.cursor = first === '' && at >= 0 ? at : 0

            this.$nextTick(() => {
                this.$refs.search?.focus()
                this.revealCursor()
            })
        },

        toggleList() {
            if (this.listOpen) {
                this.closeList()
            } else {
                this.openList()
            }
        },

        closeList() {
            this.listOpen = false
            this.search = ''
        },

        /** Esc — তালিকা বন্ধ, আর ফোকাস বোতামে ফেরে, যাতে Tab আগের মতোই চলে। */
        escape() {
            this.closeList()
            this.$nextTick(() => this.$refs.trigger?.focus())
        },

        /** বন্ধ বোতামে ↓ বা কোনো অক্ষর — তালিকা খোলে। */
        triggerKey(e) {
            if (e.ctrlKey || e.metaKey || e.altKey) {
                return
            }

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault()
                this.openList()
            } else if (typeof e.key === 'string' && e.key.length === 1 && e.key !== ' ') {
                e.preventDefault()
                this.openList(e.key)
            }
        },

        /** লেখা বদলালে আলো প্রথম সারিতে — নাহলে Enter পুরনো জায়গার নাম বাছত। */
        searched() {
            this.cursor = 0
        },

        moveDown() {
            if (this.shown.length > 0) {
                this.cursor = Math.min(this.cursor + 1, this.shown.length - 1)
                this.revealCursor()
            }
        },

        moveUp() {
            this.cursor = Math.max(this.cursor - 1, 0)
            this.revealCursor()
        },

        hover(i) {
            this.cursor = i
        },

        /** আলো-পড়া সারিটা তালিকার দৃশ্যমান অংশে টেনে আনা। */
        revealCursor() {
            this.$nextTick(() => {
                const row = this.$refs.list?.querySelector('[id="' + this.optionId(this.cursor) + '"]')

                if (row && typeof row.scrollIntoView === 'function') {
                    row.scrollIntoView({ block: 'nearest' })
                }
            })
        },

        /** Enter — আলো-পড়া নামটা বাছা; কিছু না মিললে কিছুই হয় না। */
        pickCursor() {
            const row = this.shown[this.cursor]

            if (row) {
                this.pick(row.id)
            }
        },

        /**
         * নাম বাছা — আগের `<select>`-এর মতোই: লুকানো ঘরে id বসে।
         *
         * ⓘ বাছার পরে লুকানো ঘরে `change` ছোড়া হয়, যাতে পাতার অন্য কেউ
         * (পরে যে পর্দা চায়) আগের `<select>`-এর মতোই শুনতে পারে।
         */
        pick(id) {
            this.value = String(id)
            this.closeList()
            this.$nextTick(() => {
                this.$refs.input?.dispatchEvent(new Event('change', { bubbles: true }))
                this.$refs.trigger?.focus()
            })
        },
    }
}
