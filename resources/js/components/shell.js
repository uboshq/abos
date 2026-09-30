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

/**
 * যেকোনো কিছু খুঁজুন — টপবারের প্যালেট।
 *
 * ── ⭐ Ctrl+K আর তীর, ২৭ সেপ্টেম্বর ২০২৬ (A-06, A-07) ──────────────────
 * ⛔ টপবারের বোতামের `title`-এ লেখা ছিল "(Ctrl K)", অথচ গোটা অ্যাপে ঐ
 * চাবির কোনো শ্রোতা ছিল না — চাপলে ব্রাউজার নিজের ঠিকানা-বারে খুঁজত।
 * ⚠️ আর প্যালেট খুললেও ফলগুলোয় যাওয়া যেত কেবল ইঁদুরে: হাত কীবোর্ড
 * থেকে সরাতেই হত, অথচ প্যালেটের পুরো অর্থটাই ওটা না সরানো।
 *
 * ⓘ এখন: Ctrl+K / ⌘K যেকোনো পাতায় খোলে (`hotkey()`), ↓ ↑ বাছাই সরায়,
 * ↵ বাছা ফলটা খোলে, Esc বন্ধ করে। বাছা সারিটা `aria-selected`, আর ঘরটা
 * `aria-activedescendant` দিয়ে স্ক্রিন-রিডারকে বলে কোনটা বাছা।
 */
export function commandCenter ({ url }) {
    return {
        open: false,
        q: '',
        hits: [],
        busy: false,
        timer: null,

        // ⓘ বাছা ফলের ক্রম — ফল না থাকলেও ০, পড়ার সময় `hits.length` দেখা হয়
        active: 0,

        show () {
            this.open = true
            this.$nextTick(() => {
                this.$refs.box?.focus()
                this.$refs.box?.select?.()
            })
        },

        /*
         * Ctrl+K / ⌘K — পাতার যেখানেই থাকুন।
         *
         * ── ⚠️ রোলের পাতায় রোল খোঁজা আগে ──────────────────────────────
         * মালিকের স্পেক §২.৯: অনুমতির পর্দায় Ctrl+K মানে **রোল খোঁজা**
         * (`actions.js`)। ⓘ ওই শ্রোতা `document`-এ বসে, এটা `window`-এ —
         * তাই ঘটনা আগে ওর কাছে যায়, আর ঘর পেলে সে `preventDefault()` করে।
         * ⭐ এখানে সেটা দেখে সরে দাঁড়ানো হয়: কোন পাতার কোন চাবি, সেই
         * তালিকা এই ফাইলে লিখতে হয় না, আর নতুন কোনো পাতা নিজের Ctrl+K
         * চাইলে একই পথে পায়।
         *
         * ⛔ Shift বা Alt চাপা থাকলে নয় — Ctrl+Shift+K ফায়ারফক্সের কনসোল,
         * আর কিছু লেআউটে Ctrl+Alt মানে AltGr (অক্ষর টাইপ করা)।
         *
         * ⚠️ `code === 'KeyK'`-ও দেখা হয়: বাংলা লেআউটে `key` একটা বাংলা
         * অক্ষর, কিন্তু চাবিটা একই জায়গায়।
         */
        hotkey (event) {
            if (event.defaultPrevented) return

            if (! (event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) return

            const key = typeof event.key === 'string' ? event.key.toLowerCase() : ''

            if (key !== 'k' && event.code !== 'KeyK') return

            event.preventDefault()
            this.show()
        },

        close () {
            this.open = false
        },

        /** ↓ ↑ — দুই প্রান্তে থামে, ঘুরে অন্য মাথায় যায় না */
        step (by) {
            if (this.hits.length === 0) return

            this.active = Math.min(Math.max(this.active + by, 0), this.hits.length - 1)

            this.$nextTick(() => {
                // ⚠️ `nearest` — নাহলে প্রতিটা চাপে তালিকা লাফাত
                document.getElementById(this.hitId(this.active))?.scrollIntoView?.({ block: 'nearest' })
            })
        },

        next () {
            this.step(1)
        },

        prev () {
            this.step(-1)
        },

        /*
         * ↵ — বাছা ফলটা খোলা।
         *
         * ⓘ লিংকটাই চাপা হয়, ঠিকানা হাতে বসানো হয় না: ইঁদুরের ক্লিক আর
         * ↵ তখন হুবহু একই পথে যায় — পাতার অন্য কোনো লিংক-শ্রোতাও দুইটায়
         * একই রকম চলে।
         *
         * ⚠️ বাংলা IME-তে অক্ষর গড়ার মাঝখানের ↵ (`isComposing`) অক্ষরটারই —
         * তখন ফল খুললে অর্ধেক লেখা শব্দে পাতা চলে যেত।
         */
        choose (event) {
            if (event && event.isComposing) return

            if (! this.hits[this.active]) return

            document.getElementById(this.hitId(this.active))?.click()
        },

        /** ইঁদুর যে সারিতে, বাছাইও সেখানে — দুইটা আলাদা হাইলাইট নয় */
        point (i) {
            this.active = i
        },

        isActive (i) {
            return i === this.active
        },

        hitId (i) {
            return 'command-hit-' + i
        },

        /** ⓘ `null` হলে Alpine অ্যাট্রিবিউটটাই সরায় — খালি id নয় */
        get activeId () {
            return this.hits[this.active] ? this.hitId(this.active) : null
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
                this.active = 0
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

                // ⚠️ নতুন ফল মানে নতুন তালিকা — পুরনো ক্রমটা অন্য জিনিস দেখাত
                this.active = 0
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

/**
 * বিজ্ঞপ্তির সেটিংসের একটা সারি — ঘণ্টা আর চিঠি, পাশাপাশি।
 *
 * ── ⭐ কেন `x-model` নয়, DOM থেকে পড়া ───────────────────────────────
 * সার্ভার প্রতিটা টিকের অবস্থা `@checked` দিয়ে বসিয়ে দেয়। ⛔ `x-model`
 * বসালে Alpine চালু হওয়ার মুহূর্তে **নিজের শুরুর মান দিয়ে ওটা মুছে
 * দিত** — পর্দা খুললেই সব টিক উল্টে যেত, অথচ কেউ কিছু ছোঁয়নি, আর
 * সংরক্ষণে চাপ দিলে ঐ উল্টো অবস্থাটাই সত্যি হয়ে যেত।
 *
 * ⓘ তাই HTML-ই সত্যের একমাত্র উৎস, আর এই কম্পোনেন্ট কেবল একটা নিয়ম
 * মানায়: ঘণ্টা বন্ধ থাকলে চিঠিও বন্ধ।
 *
 * ── ⚠️ কেন টিকটা নিভিয়ে দেওয়াই যথেষ্ট নয় ───────────────────────────
 * নিষ্ক্রিয় (`disabled`) ঘর ফর্মের সাথে পাঠানোই হয় না, তাই সার্ভারে
 * ওটা "চাই না" হিসেবে পৌঁছায় — আর কন্ট্রোলারেও একই নিয়ম আলাদা করে
 * লেখা আছে। ⓘ দুই জায়গায় এক কথা, কারণ পর্দার নিয়ম কখনো পাহারা নয়:
 * JS বন্ধ থাকলেও উত্তরটা একই হতে হবে।
 */
export function notifyRow () {
    return {
        init () {
            const bell = this.$el.querySelector('[data-notify-bell]')
            const mail = this.$el.querySelector('[data-notify-mail]')
            const label = this.$el.querySelector('[data-notify-mail-label]')

            if (! bell || ! mail) return

            const follow = () => {
                mail.disabled = ! bell.checked
                label?.classList.toggle('opacity-40', ! bell.checked)
            }

            bell.addEventListener('change', follow)
            follow()
        },
    }
}

/*
 * ⭐ পিক — তালিকার লিংকে চাপলে ডকুমেন্টটা উপরেই খোলে।
 *
 * ── ⭐ মালিকের নিয়ম, ২৮ সেপ্টেম্বর ২০২৬ ───────────────────────────────
 * *"হাইপার লিংকে চাপলে পপআপ এলেই ভালো, নাহলে মডিউল/মেনু পরিবর্তন হয়ে
 * যায়, সেটা অত্যন্ত বিরক্তিকর।"*
 *
 * ── ⓘ একটাই শ্রোতা, প্রতিটা লিংকে নয় ────────────────────────────────
 * ⚠️ জরিপ: `doc-link` মোট ১৭৫টা লিংক-জায়গার মাত্র ২২টা, আর সেগুলো কেবল
 * বিক্রয় ও ক্রয়ে। বাকিগুলো কাঁচা `<a>` (বেশিরভাগ `partials/number.blade.php`
 * ধরনের এক-লাইনের পার্শিয়ালে) আর তিনটা ভাগাভাগি করা উপাদানে।
 * ⛔ উপাদান ধরে ছড়ালে বেশিরভাগ তালিকা বাদ পড়ত — নীরবে, কারণ বাদ পড়া
 * তালিকা কোনো পরীক্ষা লাল করে না।
 *
 * ── ⭐ ভুল আন্দাজ হলে যা হয়, সেটাই নকশার আসল কথা ────────────────────
 * ⓘ নিচের বাদের তালিকাটা নির্ভুলতার শর্ত **নয়**, কেবল ছাঁকনি: যেটা
 * ছাঁকনি পেরিয়ে গেল অথচ পিক-যোগ্য নয়, সে সাধারণ নেভিগেশনে গড়িয়ে পড়ে
 * (`fallback()`)। ⚠️ তাই সবচেয়ে খারাপ ফল "আগের মতোই পাতা বদলাল",
 * কখনোই "একটা ফাঁকা বাক্স"।
 */

/*
 * ⛔ যেসব লিংক কখনো পিকে যায় না।
 *
 * ⓘ ফর্মের পাতা (`/create`, `/edit`) — পপআপে ফর্ম ভরে সংরক্ষণ করলে
 * মানুষ কোথায় ফিরতেন সেটা অস্পষ্ট, আর অস্পষ্ট জায়গায় টাকার ফর্ম নয়।
 * ⓘ ছাপা ও নামানো — ওগুলো পাতা নয়, ফাইল।
 */
export const NEVER_PEEK = [
    '/create',
    '/edit',
    '/print',
    '/export',
    '/download',
    '/pdf',
]

export function peek () {
    return {
        open: false,
        busy: false,
        failed: false,
        url: '',
        title: '',

        /* ⓘ `document`-এ বসানো শ্রোতা — সরানোর জন্য ধরে রাখতে হয় */
        listener: null,

        /* ⓘ ফেরার সময় ফোকাসটা যে লিংক থেকে এসেছিল সেখানেই ফেরত যায় */
        cameFrom: null,

        /*
         * ⓘ শ্রোতাটা `document`-এ, কারণ তালিকার লিংকগুলো শত শত জায়গায়
         * আঁকা হয় আর একটাও বদলানো হয়নি ([[peek.blade.php]])।
         *
         * ⛔ আর সে আবার সরেও যায়। ⚠️ না সরলে দুইটা শ্রোতা একসাথে
         * দাঁড়াতে পারত, আর তখন **একটা ক্লিকে দুইটা অনুরোধ** যেত —
         * অ্যাপে লেআউট একবারই বসায় বলে ওটা চোখে পড়ত না, কিন্তু ভুলটা
         * তাতে কম হয় না।
         */
        init () {
            this.listener = (event) => this.maybe(event)

            document.addEventListener('click', this.listener)
        },

        destroy () {
            document.removeEventListener('click', this.listener)
        },

        /*
         * ⛔ বাঁ-ক্লিক ছাড়া কিছুই নয়। ⚠️ Ctrl/⌘ নতুন ট্যাব, Shift নতুন
         * জানালা, মাঝের বোতামও নতুন ট্যাব — ঐ তিনটা কেড়ে নিলে মানুষ
         * ডকুমেন্ট পাশাপাশি খোলার ক্ষমতাটাই হারাতেন।
         */
        maybe (event) {
            if (event.defaultPrevented) return
            if (event.button !== 0) return
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return

            const link = event.target.closest('a')

            if (! this.peekable(link)) return

            event.preventDefault()
            this.cameFrom = link
            this.show(link.href)
        },

        peekable (link) {
            if (link === null) return false
            if (link.hasAttribute('download')) return false
            if (link.hasAttribute('data-no-peek')) return false

            /* ⛔ `target` বসানো মানে লেখক নিজেই অন্য জায়গা চেয়েছেন */
            if (link.target !== '' && link.target !== '_self') return false

            /* ⛔ ফর্মের ভিতরের লিংক — বাতিল/ফিরে যাওয়ার লিংক হয় */
            if (link.closest('form') !== null) return false

            /*
             * ⛔ কেবল পাতার ভিতরের লিংক — মালিকের অভিযোগ, ৩০ সেপ্টেম্বর ২০২৬।
             * ⚠️ বাঁ পাশের মেনুতে মডিউলে চাপলে পাতা না খুলে পপআপ আসছিল।
             * মেনু, মডিউল-পটি, মাথার সারি — ওগুলো পথ বদলানোর জন্যই, আর
             * নিয়মটা ছিল কেবল **তালিকার ডকুমেন্ট-লিংকের** জন্য।
             */
            if (link.closest('main') === null) return false
            if (link.closest('nav, header, aside, [role="navigation"]') !== null) return false

            if (link.origin !== window.location.origin) return false

            const href = link.getAttribute('href') || ''

            if (href === '' || href.startsWith('#')) return false

            /* ⓘ একই পাতার নোঙর — পিক করলে পাতাটা নিজের ভিতরে খুলত */
            if (link.pathname === window.location.pathname) return false

            /*
             * ⛔ কেবল একটা ডকুমেন্ট — ঠিকানার শেষ অংশটা তার নম্বর
             * (`/sales/invoice/7`)। ⓘ তালিকা, মডিউল আর রিপোর্টের পাতা
             * (`/sales/invoice`, `/backup/dashboard`) আগের মতোই পুরো পাতায় খোলে।
             */
            if (! /\/\d+\/?$/.test(link.pathname)) return false

            for (const part of NEVER_PEEK) {
                if (link.pathname.endsWith(part) || link.pathname.includes(part + '/')) {
                    return false
                }
            }

            return true
        },

        async show (url) {
            this.url = url
            this.open = true
            this.busy = true
            this.failed = false
            this.$refs.body.innerHTML = ''

            let html = null

            try {
                const res = await fetch(url, {
                    headers: { 'X-Peek': '1' },
                    credentials: 'same-origin',
                    redirect: 'follow',
                })

                /*
                 * ⛔ ৪০৩ বা ৪০৪ হলে ফাঁকা বাক্স নয় — সার্ভারের নিজের
                 * উত্তরটাই দেখানো হয়। ⚠️ দরজাটা পিকে বদলায় না, তাই
                 * ঐ পাতাটাই আসল উত্তর।
                 */
                if (res.ok || res.status === 403 || res.status === 404) {
                    html = await res.text()
                }
            } catch (e) {
                html = null
            }

            this.busy = false

            /* ⭐ যা আন্দাজে ধরা পড়েনি, তার জন্য পুরনো আচরণটাই রয়ে গেছে */
            if (html === null) {
                this.fallback()

                return
            }

            this.$refs.body.innerHTML = html
            this.title = this.$refs.body.querySelector('h1, h2')?.textContent?.trim() || ''
            this.$refs.panel.focus()
        },

        /*
         * ⓘ পিক না পারলে ব্রাউজার যা করত, সেটাই — পাতাটা খোলা।
         *
         * ⛔ `location.assign()`, `location.href =` নয়। ⓘ দুইটা একই কাজ
         * করে, কিন্তু একটা **ডাকা যায় এমন পদ্ধতি** — তাই পরীক্ষা সত্যিই
         * দেখতে পারে সে ঐ ঠিকানাতেই গেল কি না। ⚠️ `href` বসালে দাবিটা
         * বেশিরভাগ "জানালা খোলা রইল না" পর্যন্তই থামত, আর গড়িয়ে পড়ার
         * আসল অংশটা কোথাও প্রমাণিত হত না।
         */
        fallback () {
            this.open = false
            window.location.assign(this.url)
        },

        close () {
            this.open = false
            this.busy = false
            this.failed = false
            this.$refs.body.innerHTML = ''

            this.cameFrom?.focus()
            this.cameFrom = null
        },
    }
}
