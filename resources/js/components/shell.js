/*
 * শেলের ছোট কম্পোনেন্টগুলো — খোঁজা, মেনু, ফুল-স্ক্রিন, ভাগ করা।
 *
 * ── ⭐ কেন এগুলো ব্লেড থেকে এখানে এল, ১৯ সেপ্টেম্বর ২০২৬ ────────────────
 * নিরীক্ষার ধাপ ৩.১: CSP থেকে `unsafe-eval` তোলা। ⓘ Alpine-এর সাধারণ
 * সংস্করণ অ্যাট্রিবিউটের লেখাটা `new Function()` দিয়ে চালায় — ঠিক যেটা
 * `unsafe-eval` ছাড়া নিষেধ। CSP সংস্করণের নিজের পার্সার আছে, কিন্তু সে
 * কেবল **এক্সপ্রেশন** বোঝে: `a = 1; b = 2`, `() => …`, `?.`, টেমপ্লেট
 * লিটারাল, `Math` — কোনোটাই না। অ্যাট্রিবিউটের ভিতরে পদ্ধতি লেখা তো নয়ই।
 *
 * ⓘ তাই প্রতিটা "একটু বেশি" অ্যাট্রিবিউট একটা নামওয়ালা পদ্ধতি হয়েছে,
 * আর পদ্ধতিটা এখানে — যেখানে সাধারণ JavaScript চলে।
 */

/** যেকোনো কিছু খুঁজুন — টপবারের প্যালেট */
export function commandCenter ({ url }) {
    return {
        open: false,
        q: '',
        hits: [],
        busy: false,
        timer: null,

        show () {
            this.open = true
            this.$nextTick(() => this.$refs.box?.focus())
        },

        get tooShort () {
            return this.q.trim().length < 2
        },

        get nothingFound () {
            return ! this.tooShort && ! this.busy && this.hits.length === 0
        },

        /*
         * প্রতিটা অক্ষরে অনুরোধ নয় — থেমে যাওয়ার পর।
         *
         * ⓘ ২০০ মিলিসেকেন্ড: টাইপ করার স্বাভাবিক বিরতির চেয়ে বড়, আর
         * মানুষের কাছে তাৎক্ষণিকই মনে হয়। ⚠️ না দিলে 'invoice' লিখতে
         * সাতটা অনুরোধ যেত, আর শেষেরটা আগে ফিরলে তালিকায় ভুল ফল বসত।
         */
        ask () {
            clearTimeout(this.timer)

            if (this.tooShort) {
                this.hits = []
                this.busy = false

                return
            }

            this.busy = true

            this.timer = setTimeout(async () => {
                try {
                    const res = await fetch(url + '?q=' + encodeURIComponent(this.q))
                    const data = await res.json()
                    this.hits = data.hits ?? []
                } catch (e) {
                    // ⓘ নীরবে খালি — খোঁজা ব্যর্থ হলে পাতা ভাঙার কারণ নেই
                    this.hits = []
                }

                this.busy = false
            }, 200)
        },
    }
}

/*
 * ফুল-স্ক্রিন — অবস্থাটা document থেকেই পড়া হয়, নিজে মনে রাখা হয় না।
 * ⓘ Esc বা F11 দিয়েও ছাড়া যায়; নিজের boolean রাখলে বোতাম ভুল আইকন দেখাত।
 */
export function fullscreenToggle () {
    return {
        full: false,

        sync () {
            this.full = Boolean(document.fullscreenElement)
        },

        toggle () {
            if (document.fullscreenElement) {
                document.exitFullscreen()
            } else {
                // ⓘ ক্লিক ছাড়া বা policy আটকালে প্রত্যাখ্যাত — পাতা যেমন আছে থাকে
                document.documentElement.requestFullscreen().catch(() => {})
            }
        },
    }
}

/*
 * টপবারের একটা মডিউলের তালিকা — বোতামের ঠিক নিচে, fixed।
 * ⓘ ২৬৪ = তালিকার চওড়া (w-64) + ৮px ফাঁক — ডান প্রান্তের মডিউলটা
 * নাহলে পর্দার বাইরে খুলত।
 */
export function topnavMenu () {
    return {
        open: false,
        x: 0,
        y: 0,

        place () {
            const r = this.$refs.btn.getBoundingClientRect()
            this.x = Math.max(8, Math.min(r.left, window.innerWidth - 264))
            this.y = r.bottom + 4
        },

        toggle () {
            this.open = ! this.open

            if (this.open) {
                this.place()
            }
        },

        close () {
            this.open = false
        },

        get position () {
            return { left: this.x + 'px', top: this.y + 'px' }
        },
    }
}

/*
 * অ্যাপের পাতা — খোঁজার সাথে শিরোনামও মিলিয়ে রাখা।
 * ⓘ দলের একটা নামও না মিললে শিরোনামটা নিজেও সরে যায়।
 */
export function launcher () {
    return {
        open: false,
        q: '',

        show () {
            this.open = true
            this.$nextTick(() => this.$refs.q?.focus())
        },

        shows (name) {
            return ! this.q || name.includes(this.q.toLowerCase())
        },

        showsAny (names) {
            return ! this.q || names.some(n => n.includes(this.q.toLowerCase()))
        },
    }
}

/** সাইডবারের মেনু ছাঁকা — কিছু না মিললে সেটাও বলা হয় */
export function sidebarFilter () {
    return {
        filter: '',

        get term () {
            return this.filter.toLowerCase().trim()
        },

        shows (label) {
            return this.filter === '' || label.includes(this.term)
        },

        noneMatch (labels) {
            return this.filter !== '' && ! labels.some(l => l.includes(this.term))
        },
    }
}

/*
 * সারির কাজের মেনু — খোলার সময় fixed-এ বসে।
 *
 * ⓘ বোতামটা `.table-responsive`-এর ভেতরে, আর তার `overflow-x: auto` দুই
 * দিকেই কাটে। `absolute` মেনু শেষ সারিতে পুরোপুরি অদৃশ্য হত। নিচে না
 * কুলালে উপরে — পর্দার কোন প্রান্তেই কাটা যায় না।
 */
export function rowActions () {
    return {
        open: false,

        place () {
            const r = this.$refs.button.getBoundingClientRect()
            const m = this.$refs.menu
            const rtl = getComputedStyle(document.documentElement).direction === 'rtl'

            m.style.insetInlineEnd = (rtl ? r.left : window.innerWidth - r.right) + 'px'

            if (window.innerHeight - r.bottom < m.offsetHeight + 8) {
                m.style.top = 'auto'
                m.style.bottom = (window.innerHeight - r.top + 4) + 'px'
            } else {
                m.style.bottom = 'auto'
                m.style.top = (r.bottom + 4) + 'px'
            }
        },

        toggle () {
            this.open = ! this.open

            if (this.open) {
                this.$nextTick(() => this.place())
            }
        },
    }
}

/** সংরক্ষিত দৃশ্যের মেনু — নামের ঘরটা মেনুর ভেতরেই */
export function viewMenu () {
    return {
        open: false,
        naming: false,

        close () {
            this.open = false
            this.naming = false
        },

        startNaming () {
            this.naming = true
            this.$nextTick(() => this.$refs.viewName?.focus())
        },
    }
}

/** এই পাতার লিংক ভাগ করা */
export function shareMenu ({ url }) {
    return {
        open: false,
        copied: false,

        copy () {
            navigator.clipboard.writeText(url)
            this.copied = true
            setTimeout(() => { this.copied = false }, 2000)
        },
    }
}

/** গ্রাহককে পাঠানোর লিংক — কপি করার বোতামসহ */
export function sharedLink ({ url }) {
    return {
        copied: false,

        copy () {
            navigator.clipboard.writeText(url)
            this.copied = true
            setTimeout(() => { this.copied = false }, 2000)
        },
    }
}
