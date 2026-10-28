/*
 * পর্দার নিজস্ব স্ক্রিপ্টগুলো — আগে ব্লেডের `<script @nonce>`-এ ছিল।
 *
 * ── ⛔ কেন ওখানে থাকা আর চলে না, ১৯ সেপ্টেম্বর ২০২৬ ────────────────────
 * ব্লেড একটা বৈশ্বিক ফাংশন লিখত (`function journalForm()`), আর পর্দা
 * ডাকত `x-data="journalForm()"`। ⓘ সাধারণ Alpine `with`-এর ভিতরে চালায়,
 * তাই বৈশ্বিক নামও পেত। ⚠️ CSP-Alpine কেবল কম্পোনেন্টের নাম দেখে —
 * `journalForm` তার কাছে "Undefined variable"। তাই প্রতিটা এখন
 * `Alpine.data()`-এ নিবন্ধিত, আর ব্লেডের যা লাগত (অনুবাদ, ঠিকানা) সেটা
 * আসে প্যারামিটারে।
 *
 * ⓘ ভিতরের যুক্তি হুবহু সরানো — মন্তব্যসহ।
 */

import { taka } from './money.js'


/*
 * নোট গোনার হিসাব।
 *
 * ── আগে এটা কাজ করত না, আর নীরবেই করত না ────────────────────
 * প্রতিটা ঘরে @input="recount()" ছিল, আর recount() ভেতরে
 * this.$el.querySelectorAll('input[data-note]') দিয়ে ঘরগুলো
 * খুঁজত। কিন্তু হ্যান্ডলারের ভেতরে $el মানে **যে ঘরে টাইপ করা
 * হয়েছে সেই ঘরটা**, কম্পোনেন্টের গোড়া নয় — আর একটা input-এর
 * ভেতরে কোনো input থাকে না। তাই তালিকাটা সবসময় খালি আসত,
 * total সবসময় ০ থাকত, আর Save বোতামটা (total <= 0 হলে
 * নিষ্ক্রিয়) কখনো সক্রিয় হত না।
 *
 * কনসোলে কোনো এরর ছিল না — তাই টেস্টও কিছু বলত না, আর
 * "দিনশেষে গণনা" ও "নগদ মিলকরণ" দুইটা ফিচারই ব্যবহারের
 * অযোগ্য ছিল, অথচ পর্দাটা দেখতে ঠিকই লাগত।
 *
 * এখন DOM ঘাঁটা হয় না। ঘরগুলো x-model দিয়ে counts-এ বাঁধা,
 * আর যোগফল counts থেকেই গোনা — তাই একই ভুল আর ঘটতে পারে না।
 */
export function cashCount({ zeroConfirm = '' } = {}) {
    return {
        busy: false,
        counts: {},
        lineOf(note) {
            return note * (this.counts[note] || 0);
        },
        get total() {
            return Object.entries(this.counts).reduce(
                (sum, [note, qty]) => sum + Number(note) * (Number(qty) || 0), 0,
            );
        },
        get pieces() {
            return Object.values(this.counts).reduce(
                (sum, qty) => sum + (Number(qty) || 0), 0,
            );
        },
        format(n) {
            return taka(n);
        },
        /*
         * খালি ড্রয়ারও গোনা যায় — কিন্তু জিজ্ঞেস করে।
         *
         * শূন্য গণনা একটা সত্যিকারের ঘটনা (ড্রয়ার খালি), আর
         * সেটা আটকে দিলে ওই দিনটার মিলকরণই করা যেত না। কিন্তু
         * ফাঁকা ফর্ম ভুল করে পাঠালে বইয়ের পুরো টাকাটা
         * "ঘাটতি" হয়ে সমন্বয়ে বসে যেত — তাই একবার জিজ্ঞেস।
         */
        guard(event) {
            if (this.busy) { event.preventDefault(); return; }

            if (this.total <= 0 && ! window.confirm(zeroConfirm)) {
                event.preventDefault();
                return;
            }

            this.busy = true;
        },
        /*
         * ভুল সংশোধনের পর ফর্মটা ফিরে এলে (old input) ঘরগুলোয়
         * সংখ্যা লেখা থাকে; x-model খালি counts দেখে সেগুলো
         * মুছে দিত, তাই আগে একবার পড়ে নেওয়া হয়।
         */
        init() {
            this.$root.querySelectorAll('input[data-note]').forEach((input) => {
                this.counts[input.dataset.note] = parseInt(input.value, 10) || 0;
            });
        },
    };
}

export function journalForm() {
    return {
        busy: false,
        debit: 0,
        credit: 0,
        // শূন্য-শূন্যও "মিলছে" নয়: একটা খালি জাবেদা সেভ করতে
        // দিলে লেজারে কিছুই বসত না অথচ নম্বরটা খরচ হয়ে যেত
        get balanced() {
            return this.debit > 0 && Math.abs(this.debit - this.credit) < 0.005;
        },
        // কিছু টাইপ হয়েছে কি না — ভুলের বার্তা তার আগে নয়
        get touched() {
            return this.debit > 0 || this.credit > 0;
        },
        format(n) {
            return taka(n);
        },
        /*
         * $root, $el নয় — আর এই এক অক্ষরেই ফিচারটা মরে ছিল।
         *
         * @input="recount()" থেকে ডাকা হলে Alpine-এর $el মানে
         * **যে ঘরে টাইপ করা হয়েছে সেই input-টা**, কম্পোনেন্টের
         * গোড়া নয়। একটা input-এর ভেতরে আর কোনো input থাকে না,
         * তাই তালিকাটা সবসময় খালি আসত, debit ও credit দুইটাই
         * ০ থাকত, balanced কখনো true হত না — আর "Save and post"
         * বোতামটা balanced দেখে নিষ্ক্রিয় থাকে।
         *
         * ফল: **কোনো জাবেদা কখনো সেভ করা যেত না।** কনসোলে এরর
         * নেই, পর্দা দেখতে নিখুঁত, শুধু বোতামে ক্লিক করা যায় না।
         * নগদ গণনার পর্দায় হুবহু একই ভুল ছিল — একই দিনে ধরা
         * পড়েছে দুইটাই।
         *
         * $root কম্পোনেন্টের গোড়া, যেখান থেকেই ডাকা হোক।
         */
        recount() {
            const sum = (sel) => [...this.$root.querySelectorAll(sel)]
                .reduce((t, i) => t + (parseFloat(i.value) || 0), 0);
            this.debit = sum('input[name$="[debit]"]');
            this.credit = sum('input[name$="[credit]"]');
        },
        init() { this.recount(); },
    };
}

export function anchorNav() {
    return {
        items: [],
        active: null,

        /* পাতার অংশগুলো — যাদের নিজের একটা <h2> আছে। */
        collect() {
            const main = document.querySelector('main') || document.body;
            const found = [];

            main.querySelectorAll('section').forEach((section, i) => {
                const heading = section.querySelector('h2');
                if (!heading) return;

                const label = heading.textContent.trim();
                if (label === '') return;

                /* নিজের id না থাকলে একটা বসানো হয়। ক্রমটাও
                   নামের সাথে রাখা হয়, কারণ দুইটা অংশের নাম
                   এক হলে দুইটা এক id পেত আর দ্বিতীয়টায়
                   কোনোদিন যাওয়া যেত না। */
                if (!section.id) section.id = 'sec-' + i;

                found.push({ id: section.id, label });
            });

            this.items = found;
            this.active = found.length ? found[0].id : null;

            if (found.length > 1) this.watch(found);
        },

        /* কোন অংশটা এখন চোখের সামনে। */
        watch(found) {
            if (!('IntersectionObserver' in window)) return;

            const seen = new IntersectionObserver((entries) => {
                entries
                    .filter((e) => e.isIntersecting)
                    .forEach((e) => { this.active = e.target.id; });
            }, { rootMargin: '-20% 0px -70% 0px' });

            found.forEach((f) => {
                const el = document.getElementById(f.id);
                if (el) seen.observe(el);
            });
        },

        go(id) {
            const el = document.getElementById(id);
            if (!el) return;

            /* `scrollIntoView` পটিটার নিচে অংশটাকে লুকিয়ে
               ফেলত — পটিটা sticky, তাই তার উচ্চতাটা বাদ
               দিয়ে নামতে হয়। */
            const bar = document.querySelector('[data-anchor-nav]');
            const gap = (bar ? bar.getBoundingClientRect().height : 0) + 8;

            window.scrollTo({
                top: el.getBoundingClientRect().top + window.scrollY - gap,
                behavior: 'smooth',
            });

            this.active = id;
        },
    };
}

export function recipeForm({ lines = [] } = {}) {
    return {
        lines,

        init() {
            /* প্রতিটা সারির একটা স্থায়ী চাবি — নাহলে একটা
               সারি মুছলে Alpine বাকিগুলো নতুন করে আঁকত আর
               বাছাই করা পণ্যগুলো এক ঘর সরে যেত। */
            this.lines = this.lines.map((l) => ({ ...l, key: this.nextKey() }));

            if (this.lines.length === 0) this.addLine();
        },

        nextKey() {
            this._key = (this._key || 0) + 1;
            return this._key;
        },

        addLine() {
            this.lines.push({ product_id: '', qty: '', waste_pct: '0', key: this.nextKey() });
        },

        /* গুদাম থেকে যতটা বেরোবে — ভাগ, গুণ নয়। */
        gross(line) {
            const qty = parseFloat(line.qty);
            const waste = parseFloat(line.waste_pct);

            if (!isFinite(qty) || qty <= 0) return '—';
            if (!isFinite(waste) || waste <= 0 || waste >= 100) return qty.toFixed(4);

            return (qty / ((100 - waste) / 100)).toFixed(4);
        },
    };
}

/*
 * ⭐ নিজে থেকে নতুন — ডেলিভারি ট্র্যাকিং, মালিকের আদেশ, ২ অক্টোবর ২০২৬ ("প্রতি ৩০ সেকেন্ডে")।
 *
 *   <div x-data="liveRefresh({ url: '…', seconds: 30 })" data-live> … </div>
 *
 * ⓘ পুরো পাতা নয়, কেবল এই ঘরটা — একই ঠিকানা আবার এনে তার `[data-live]`-এর ভেতরটা বসায়। তাই খোঁজার ঘরে
 * লেখা থাকে, পপ-আপ খোলাই থাকে। ⚠️ ঠিকানাটা হাতে দেওয়া, `location` নয়: পপ-আপের ভেতরে পাতার ঠিকানা
 * তালিকার, সময়রেখার নয়।
 * ⛔ ট্যাব লুকানো থাকলে বা কেউ এই ঘরের ভেতরে লিখছেন — আনা হয় না; ভুল হলে চুপ, পরের বার আবার।
 */
export function liveRefresh({ url = '', seconds = 30 } = {}) {
    return {
        timer: null,
        init() {
            this.timer = setInterval(() => this.pull(), Math.max(5, seconds) * 1000)
        },
        destroy() {
            clearInterval(this.timer)
        },
        async pull() {
            if (document.hidden || !url) return
            const active = document.activeElement
            if (active && this.$el.contains(active) && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) return
            try {
                const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                if (!response.ok) return
                const fresh = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('[data-live]')
                if (fresh) this.$el.innerHTML = fresh.innerHTML
            } catch (e) {
                // সংযোগ নেই — পুরনোটা থাকুক, পরের বার আবার
            }
        },
    }
}

/*
 * ⭐ অনুমোদনের নিয়ম — খোলা নিয়মের কার্ড, "পরখ করুন" আর শর্তের ঘরের তালিকা (অনুমোদন পর্দা ভাগ ৩, দফা ১৭)।
 *
 * ⓘ ঘরের তালিকা আর লেখাগুলো গোড়ার `data-fields` / `data-words`-এ (JSON) — ⛔ CSP-Alpine এক্সপ্রেশনে `JSON`, `Object` চেনে না।
 *
 * ⭐ "এই কাগজ যাবে" — ইঞ্জিনের একই নিয়ম ([[ApprovalFlow::stepsAt()]]): নিয়মের সীমার নিচে হলে নিয়মই ধরে না; নইলে প্রতিটা স্তর থাকে,
 * কেবল যে স্তরের "কত টাকা থেকে" অঙ্কের চেয়ে বড় সেটা বাদ (ধূসর, আর প্রথমটার কারণ লেখা)। কার্ড থেকে পড়া, সংরক্ষণের আগেই।
 */
export function flowRule({ pair = '', shown = 1, more = null } = {}) {
    return {
        pair,
        shown,
        // ⓘ কোন ধাপের "আরও" পট্টি খোলা — একবারে একটা; ভুল ফিরলে সেই ধাপেরটা খোলা আসে
        moreOf: more,
        toggleMore(index) {
            this.moreOf = this.moreOf === index ? null : index
            // ⓘ পট্টিটা নিয়মের বাক্সের নিচে (পরিকল্পনার মতো) — খুললে সেটা চোখের সামনে আনা। ⚠️ `document`, `$root` নয়: বোতামটা কার্ডের
            // নিজের x-data-র ভেতরে, সেখানে `$root` মানে কার্ড, আর পট্টি কার্ডের বাইরে
            if (this.moreOf !== null) {
                this.$nextTick(() => document.querySelector(`[data-step-panel="${index}"]`)?.scrollIntoView({ block: 'center' }))
            }
        },
        tryAmount: '',
        fields: {},
        words: {},
        // ⓘ কার্ডের ঘর Alpine-এর নয় — কিছু লিখলেই এটা বাড়ে, আর "এই কাগজ যাবে" আবার গোনে
        tick: 0,
        touch() {
            this.tick++
        },
        init() {
            this.fields = JSON.parse(this.$root.dataset.fields || '{}')
            this.words = JSON.parse(this.$root.dataset.words || '{}')
        },
        get module() {
            return this.pair.split('|')[0] || ''
        },
        get action() {
            return this.pair.split('|')[1] || ''
        },
        get currentFields() {
            return this.fields[this.pair] || {}
        },
        get hasFields() {
            return Object.keys(this.currentFields).length > 0
        },
        more() {
            this.shown++
        },
        chipClass(step) {
            return step.skipped
                ? 'bg-(--color-surface-hover) text-(--color-ink-muted) line-through'
                : 'bg-(--color-badge-info-bg) text-(--color-badge-info-ink)'
        },
        get chain() {
            return this.tick >= 0 ? chainOf(this.$root, this.tryAmount, this.words) : { steps: [], note: '' }
        },
    }
}

/*
 * ⓘ আলাদা ফাংশন, যাতে পরীক্ষা সরাসরি পায় — কার্ডগুলো থেকে স্তর, নাম আর "কত টাকা থেকে", তারপর অঙ্ক ধরে কে থাকে কে বাদ।
 * লুকানো (না-খোলা) কার্ড আর অনুমোদনকারী না বাছা কার্ড গোনা হয় না — সার্ভারও ওগুলো ছেঁকে ফেলে।
 */
export function chainOf(root, tryAmount, words = {}) {
    const amount = parseFloat(String(tryAmount).replace(/,/g, ''))
    if (Number.isNaN(amount)) return { steps: [], note: '' }

    const threshold = parseFloat(root.querySelector('[name="threshold_amount"]')?.value || '')
    if (!Number.isNaN(threshold) && amount < threshold) return { steps: [], note: words.notCaught || '' }

    const levels = {}
    root.querySelectorAll('[data-step-card]').forEach((card) => {
        if (card.style.display === 'none') return
        const who = card.querySelector('[data-step-who]')
        if (!who || who.value === '') return
        const level = parseInt(card.querySelector('[data-step-level]')?.value || '1', 10)
        const name = (card.querySelector('[data-step-name]')?.value || '').trim() || who.selectedOptions[0]?.text.trim() || ''
        const from = parseFloat(card.querySelector('[data-step-from]')?.value || '')
        levels[level] ??= { names: [], from: Number.isNaN(from) || level === 1 ? null : from }
        if (!levels[level].names.includes(name)) levels[level].names.push(name)
    })

    const steps = Object.keys(levels).map(Number).sort((a, b) => a - b).map((level) => ({
        level,
        who: levels[level].names.join(' / '),
        from: levels[level].from,
        skipped: levels[level].from !== null && amount < levels[level].from,
    }))

    const first = steps.find((s) => s.skipped)
    const lakh = (n) => new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(n)
    const note = first ? (words.skips || '').replace(':amount', lakh(first.from)).replace(':who', first.who) : ''

    return { steps, note }
}
