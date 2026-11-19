/*
 * ⭐ "ABOS" রূপের মডিউল-তালিকা — মেনুর নিচে, উপরে খোলে (মালিকের অনুমোদিত নমুনা, ১৯ নভেম্বর ২০২৬; [[chrome/rose.blade.php]])।
 *
 * ⓘ খোঁজা: বাংলা আর ইংরেজি দুই নামেই, কোডসহ (`data-keys`, লঞ্চারের একই চাবি)। ↵ খোঁজার ঘরে থাকলে প্রথম মেলা মডিউল খোলে।
 * ⓘ সাজানো: "⇅ সাজান" চাপলে প্রতিটা সারিতে ▲ ▼; "শেষ" চাপলে ক্রমটা নিজের সারিতে রাখা হয় — রেলের একই ঘর আর একই দরজা
 *   (`users.nav_layout`, POST `rail.layout`; [[MenuBuilder::arranged()]]), তাই সব যন্ত্রে একই। "আগের ক্রম" নিজের ক্রম মুছে
 *   শুরুর ক্রমে ফেরে; পিন করা মডিউল (রেলের) যেমন ছিল তেমনই থাকে।
 * ⓘ CSP-Alpine: অ্যাট্রিবিউটে কেবল সরল ডাক, তাই যুক্তি এখানে।
 */

import { matches } from './rail-fit.js'

/** খোঁজার মিল — চাবির যেকোনো অংশ, ছোট হাতে */
export function pickerMatches (keys, query) {
    return matches(keys, query)
}

/**
 * রাখার জিনিস — দেখানো ক্রমে সব কোড, আর আগে থেকে পিন করা কোড (রেলের পিন হারায় না)
 *
 * @param {Array<{code: string, pinned: boolean}>} rows
 */
export function pickerLayout (rows) {
    return { order: rows.map(r => r.code), pinned: rows.filter(r => r.pinned).map(r => r.code) }
}

/** Alpine কম্পোনেন্ট — <div x-data="modulePicker"> */
export function modulePicker () {
    return {
        open: false,
        arranging: false,
        q: '',
        none: false,
        changed: false,
        openedAt: 0,
        root: null,
        startOrder: [],

        init () {
            this.root = this.$el
            this.startOrder = this.rows().map(r => r.code)
            this.$watch('q', () => this.filter())
        },

        get openFlag () {
            return this.open ? 'true' : 'false'
        },

        get listClass () {
            return this.arranging ? 'arranging' : ''
        },

        get arrangeClass () {
            return this.arranging ? 'done' : ''
        },

        get arrangeLabel () {
            return this.arranging ? (this.root?.dataset.doneLabel ?? '') : (this.root?.dataset.arrangeLabel ?? '')
        },

        get note () {
            return this.arranging ? (this.root?.dataset.noteArranging ?? '') : (this.root?.dataset.note ?? '')
        },

        /** সারিগুলো, পর্দার ক্রমে */
        rows () {
            return [...(this.root?.querySelectorAll('[data-picker-row]') ?? [])].map(el => ({
                el,
                code: el.dataset.pickerRow,
                pinned: el.dataset.pinned === '1',
            }))
        },

        toggle () {
            this.open ? this.close() : this.show()
        },

        show () {
            this.open = true
            this.openedAt = Date.now()
            this.$nextTick(() => this.$refs.find?.focus())
        },

        /** বাইরে চাপ — বন্ধ; ⛔ কিন্তু ⊞ চেপে খোলার ঠিক সেই চাপটাই নয় (সেটাও "বাইরে", আর খুলেই বন্ধ হয়ে যেত) */
        away () {
            if (Date.now() - this.openedAt < 300) return

            this.close()
        },

        async close () {
            if (! this.open) return

            if (this.arranging) {
                await this.finish()
            }

            this.open = false
            this.q = ''
        },

        filter () {
            let shown = 0

            for (const row of this.rows()) {
                const hit = pickerMatches(row.el.dataset.keys ?? '', this.q)

                row.el.hidden = ! hit
                shown += hit ? 1 : 0
            }

            this.none = shown === 0
        },

        openFirst () {
            const first = this.rows().find(r => ! r.el.hidden)?.el.querySelector('[data-picker-link]')

            if (first && ! this.arranging) {
                first.click()
            }
        },

        /** সাজানোর সময় সারির লিংক কাজ করে না — ▲ ▼ চাপতে গিয়ে মডিউল খুলে যেত */
        follow (event) {
            if (this.arranging) {
                event.preventDefault()
            }
        },

        up (event) {
            this.step(event, -1)
        },

        down (event) {
            this.step(event, 1)
        },

        step (event, by) {
            event.preventDefault()
            event.stopPropagation()

            const row = event.target.closest('[data-picker-row]')
            const list = row?.parentElement

            if (! row || ! list) return

            if (by < 0 && row.previousElementSibling) {
                list.insertBefore(row, row.previousElementSibling)
                this.changed = true
            }

            if (by > 0 && row.nextElementSibling) {
                list.insertBefore(row.nextElementSibling, row)
                this.changed = true
            }

            event.target.focus()
        },

        async arrange () {
            if (this.arranging) {
                await this.finish()

                return
            }

            this.q = ''
            this.arranging = true
        },

        /** "শেষ" — বদল থাকলে রাখা */
        async finish () {
            this.arranging = false

            if (this.changed) {
                this.changed = false
                await this.send(pickerLayout(this.rows()))
            }
        },

        /** "আগের ক্রম" — নিজের ক্রম মোছা; পাতা নতুন করে, সার্ভারের শুরুর ক্রমে */
        async reset () {
            const ok = await this.send({ order: [], pinned: this.rows().filter(r => r.pinned).map(r => r.code) })

            if (ok) {
                window.location.reload()
            }
        },

        async send (body) {
            const token = this.root.querySelector('[data-picker-token]')?.value ?? ''

            try {
                const response = await fetch(this.root.dataset.save, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body),
                })

                return response.ok
            } catch {
                return false
            }
        },
    }
}
