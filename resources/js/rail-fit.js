/*
 * ⭐ বাঁ মডিউল-রেল — যা ধরে না তা "⋯ আরও"-তে (মালিক, ২৪ অক্টোবর ২০২৬: "বাঁ মডিউল বারে ১০০% জুমে সব মডিউলের আইকন আসে না")।
 *
 * ── কী ছিল ─────────────────────────────────────────────────────────────
 * টাইল ৪০px আর ফাঁক ৫px, সঙ্গে দলের রেখা আর মাথার চিহ্ন: ~২০টা মডিউলে প্রায় ৯৫০px। ল্যাপটপের ৭৬৮px পর্দায় ১২–১৪টা ধরত, আর রেল
 * `overflow-visible`, তাই নিচেরগুলো চুপচাপ পর্দার বাইরে কাটা যেত — কোনো মডিউল হারানো মানে তার সব পাতার পথ হারানো।
 *
 * ── এখন ────────────────────────────────────────────────────────────────
 * রেল নিজে মাপে (ResizeObserver — জুম, জানালা, পর্দা বদলালে আবার): যতক্ষণ শেষ টাইল রেলের নিচের কিনারার ভিতরে না আসে, শেষ থেকে
 * মডিউল "আরও"-তে সরে। আগে সরে না-পিন করাগুলো, পিন করা থাকে শেষ পর্যন্ত। ⛔ কোনো মডিউল কখনো হারায় না: রেল + "আরও" = মেনুর
 * সব মডিউল। "আরও" একটা button + menu (ARIA): ↑ ↓ Home End চলে, Enter খোলে, Esc বন্ধ করে আর ফোকাস বোতামে ফেরায়; খোঁজার ঘর আছে।
 */

/**
 * কোন ক্রমে "আরও"-তে সরবে — না-পিন করাগুলো শেষ থেকে, তারপর (একেবারেই না ধরলে) পিন করাগুলো শেষ থেকে।
 *
 * @param {Array<{code: string, pinned: boolean}>} items  রেলের ক্রমে
 * @returns {string[]}
 */
export function hideOrder (items) {
    const unpinned = items.filter(i => ! i.pinned).reverse()
    const pinned = items.filter(i => i.pinned).reverse()

    return [...unpinned, ...pinned].map(i => i.code)
}

/**
 * কোনগুলো "আরও"-তে — `fits(hidden, withMore)` বলে ঐ অবস্থায় রেলে সব ধরে কি না (পাতায় আসল মাপ, পরীক্ষায় নকল)।
 *
 * @param {Array<{code: string, pinned: boolean}>} items
 * @param {(hidden: Set<string>, withMore: boolean) => boolean} fits
 * @returns {string[]} রেলের ক্রমে
 */
export function fitRail (items, fits, { alwaysMore = false } = {}) {
    const hidden = new Set()

    // ⓘ `alwaysMore` — "⋯" বোতাম সবসময় থাকে (তাতে "সাজান" আর "আগের মতো করুন"), তাই তার জায়গা ধরে মাপা
    if (fits(hidden, alwaysMore)) {
        return []
    }

    for (const code of hideOrder(items)) {
        hidden.add(code)
        if (fits(hidden, true)) {
            break
        }
    }

    return items.filter(i => hidden.has(i.code)).map(i => i.code)
}

/** তালিকায় একটা সারি এক ঘর সরানো — নতুন তালিকা ফেরে, পুরনোটা অটুট */
export function moved (rows, from, to) {
    if (to < 0 || to >= rows.length || from === to) {
        return rows.slice()
    }
    const out = rows.slice()
    const [row] = out.splice(from, 1)
    out.splice(to, 0, row)

    return out
}

/** রাখার জিনিস — ক্রম (সব কোড, দেখানো ক্রমে) আর পিন করা কোড */
export function layoutOf (rows) {
    return { order: rows.map(r => r.code), pinned: rows.filter(r => r.pinned).map(r => r.code) }
}

/** "আরও" তালিকার খোঁজা — নামের যেকোনো অংশ, বড়-ছোট হাতের অক্ষর আর বাংলা দুটোতেই */
export function matches (label, query) {
    const q = String(query ?? '').trim().toLocaleLowerCase()

    return q === '' || String(label).toLocaleLowerCase().includes(q)
}

/** Alpine কম্পোনেন্ট — রেলের <nav x-data="railFit"> */
export function railFit () {
    return {
        rows: [],
        hiddenCodes: [],
        moreOpen: false,
        query: '',
        arrangeRows: [],
        dragFrom: null,
        // ⛔ রেলের <nav> — পদ্ধতির ভিতরে `this.$el` মানে যে ঘরের নির্দেশ চলছে (যেমন "রাখুন" বোতাম), রেল নয়; তাই init-এ ধরে রাখা
        root: null,

        init () {
            this.root = this.$el
            this.rows = [...this.root.querySelectorAll('[data-rail-module]')].map(el => ({
                code: el.dataset.railModule,
                label: el.dataset.label ?? '',
                href: el.dataset.href ?? '',
                pinned: el.dataset.pinned === '1',
                dotStyle: { background: `var(--color-module-${el.dataset.railModule}, var(--color-brand-600))` },
            }))
            this.measure()
            if (typeof ResizeObserver === 'function') {
                const watch = new ResizeObserver(() => this.measure())
                watch.observe(this.root)
            }
            window.addEventListener('resize', () => this.measure())
        },

        get hasMore () {
            return this.hiddenCodes.length > 0
        },

        /** "আরও"-র সারি — রেলের ক্রমে, খোঁজা মিলিয়ে */
        get shownMore () {
            return this.rows.filter(r => this.hiddenCodes.includes(r.code) && matches(r.label, this.query))
        },

        get noneFound () {
            return this.hasMore && this.shownMore.length === 0
        },

        measure () {
            const nav = this.root
            const tile = code => nav.querySelector(`[data-rail-module="${code}"]`)
            const more = nav.querySelector('[data-rail-more]')
            const apply = (hidden, withMore) => {
                for (const r of this.rows) {
                    const el = tile(r.code)
                    if (el) el.style.display = hidden.has(r.code) ? 'none' : ''
                }
                // ⓘ পুরো দল সরে গেলে তার রেখাও — নইলে একা একটা সোনালি দাগ ঝুলে থাকত
                for (const sep of nav.querySelectorAll('[data-rail-sep]')) {
                    const any = this.rows.some(r => tile(r.code)?.dataset.section === sep.dataset.railSep && ! hidden.has(r.code))
                    sep.style.display = any ? '' : 'none'
                }
                if (more) more.style.display = withMore ? '' : 'none'
            }
            const fits = (hidden, withMore) => {
                apply(hidden, withMore)
                /*
                 * ⛔ রেলের নিজের নিচের কিনারা নয়, চোখে-দেখা শেষ বিন্দু: সাইডবার টপবারের নিচে শুরু হয় অথচ মাপ ধরে পর্দার মাথা থেকে,
                 * তাই ৭৬৮-এর পর্দায় রেল ৭৯৬px পর্যন্ত যায়; আর নিচের স্থির বার শেষ ২৮px ঢাকে (আসল ব্রাউজারে মাপা, ২৪ অক্টোবর ২০২৬)।
                 */
                const bar = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--spacing-status-bar')) || 0
                const limit = Math.min(nav.getBoundingClientRect().bottom - parseFloat(getComputedStyle(nav).paddingBottom || '0'),
                    window.innerHeight - bar)
                const last = [...nav.children].filter(c => c.offsetParent !== null && ! c.hasAttribute('data-rail-flyout')).pop()

                return ! last || last.getBoundingClientRect().bottom <= limit + 0.5
            }

            const hidden = fitRail(this.rows, fits, { alwaysMore: true })
            apply(new Set(hidden), true)
            this.hiddenCodes = hidden
        },

        toggleMore () {
            this.moreOpen ? this.closeMore() : this.openMore()
        },

        openMore () {
            this.moreOpen = true
            this.query = ''
            this.$nextTick(() => this.$refs.moreSearch?.focus())
        },

        closeMore (refocus = true) {
            if (! this.moreOpen) return
            this.moreOpen = false
            if (refocus) this.$refs.moreButton?.focus()
        },

        /** বাইরে চাপ — বন্ধ, ফোকাস যেখানে চাপা হলো সেখানেই থাকে */
        clickAway () {
            this.closeMore(false)
        },

        items () {
            return [...this.root.querySelectorAll('[data-more-item]')]
        },

        // ── রেল সাজানো — পিন আর ক্রম (মালিক, ২৪ অক্টোবর ২০২৬) ─────────────────────────────

        openArrange () {
            this.closeMore(false)
            this.arrangeRows = this.rows.map(r => ({ ...r }))
            this.$refs.arrange?.showModal?.()
        },

        closeArrange () {
            this.$refs.arrange?.close?.()
            this.$refs.moreButton?.focus()
        },

        togglePin (i) {
            this.arrangeRows[i].pinned = ! this.arrangeRows[i].pinned
        },

        moveUp (i) {
            this.arrangeRows = moved(this.arrangeRows, i, i - 1)
        },

        moveDown (i) {
            this.arrangeRows = moved(this.arrangeRows, i, i + 1)
        },

        moveUpLabel (row) {
            return `${this.root?.dataset.upLabel ?? ''} ${row.label}`.trim()
        },

        moveDownLabel (row) {
            return `${this.root?.dataset.downLabel ?? ''} ${row.label}`.trim()
        },

        dragStart (i) {
            this.dragFrom = i
        },

        dragOver (i) {
            if (this.dragFrom === null || this.dragFrom === i) return
            this.arrangeRows = moved(this.arrangeRows, this.dragFrom, i)
            this.dragFrom = i
        },

        dragDrop () {
            this.dragFrom = null
        },

        /** রাখা — নিজের সারিতে, তারপর পাতা নতুন করে (সার্ভারের ক্রমে রেল আবার আঁকা) */
        async saveArrange () {
            await this.send(layoutOf(this.arrangeRows))
        },

        /** "আগের মতো করুন" — নিজের সাজ মোছা, শুরুর ক্রমে ফেরা */
        async resetLayout () {
            await this.send({ reset: true })
        },

        async send (body) {
            const token = this.root.querySelector('[data-rail-token]')?.value ?? ''
            const response = await fetch(this.root.dataset.save, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body),
            })
            if (response.ok) {
                window.location.reload()
            }
        },

        /** বোতামে ↓ বা Enter/Space — তালিকা খোলে (WAI-ARIA menu button) */
        buttonKey (event) {
            if (['ArrowDown', 'Enter', ' '].includes(event.key)) {
                event.preventDefault()
                this.openMore()
            }
        },

        /** তালিকার ভিতরে — ↑ ↓ Home End চলা, Esc বন্ধ */
        menuKey (event) {
            const all = [this.$refs.moreSearch, ...this.items()].filter(Boolean)
            const at = all.indexOf(document.activeElement)
            const go = i => { all[(i + all.length) % all.length]?.focus(); event.preventDefault() }

            if (event.key === 'Escape') { event.preventDefault(); this.closeMore() }
            else if (event.key === 'ArrowDown') go(at + 1)
            else if (event.key === 'ArrowUp') go(at - 1)
            else if (event.key === 'Home') go(0)
            else if (event.key === 'End') go(all.length - 1)
        },
    }
}
