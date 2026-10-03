/**
 * রসিদ ও পরিশোধের পর্দার যুক্তি — পক্ষ, বকেয়া, আর বিলের ভাগ।
 *
 * ── ⭐ কেন ফাইলে, ব্লেডের ভিতরে নয় ───────────────────────────────────
 * আগে পুরো যুক্তিটা `x-data="{ … }"` অ্যাট্রিবিউটের ভিতরে লেখা ছিল, আর
 * সেখানে দুইটা জিনিস বারবার ভেঙেছে: অনুবাদে একটা অ্যাপস্ট্রফি থাকলেই
 * স্ট্রিং শেষ হয়ে যেত, আর Blade মন্তব্যের ভিতরের `{{ }}`-ও পার্স করত।
 *
 * ⓘ এখানে লেখাগুলো বাইরে থেকে আসে, তাই ঐ দুইটা ফাঁদই বন্ধ।
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
 * মালিকের অভিযোগ: রসিদের "ডিপোজিটরের নাম" আর পরিশোধের "প্রাপকের নাম"
 * একটা লম্বা সাধারণ তালিকা, খোঁজার ঘর নেই — ইউবি-র ৪১৪ জন গ্রাহকের
 * ভিতরে *নাম খুঁজে পাওয়া যায় না*।
 *
 * ⓘ লেখাটা শব্দে ভাঙা হয়, আর **প্রতিটা** শব্দ মিলতে হয় — "bismillah
 * bazar" লিখলে কেবল বাজারের বিসমিল্লাহ, সব বিসমিল্লাহ নয়। ⚠️ খোঁজা হয়
 * `find`-এ (সার্ভার গড়ে দেয়: দুই ভাষার নাম, কোড, পয়েন্ট, মোবাইল —
 * [[PartyRegistry::pickerFind()]]); না থাকলে নাম আর ছোট লাইনটাই।
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

export default function partyVoucher({ partyType, partyId, parties, dueUrl, picked, texts, adding = false, newName = '' }) {
    return {
        partyType,
        partyId,
        parties,
        texts,

        /** "+" খোলা কি না — নতুন নাম আর মোবাইলের ঘর। */
        adding,

        /** তালিকায় না থাকা নাম — `party_new` ঘরে যা বসে। */
        newName,

        /** খোঁজার তালিকা খোলা কি না, কী লেখা, আর কোন সারিতে আলো। */
        listOpen: false,
        search: '',
        cursor: 0,

        /** এই পক্ষের মোট বকেয়া — নামের পাশে দেখানো হয়। */
        due: null,

        /** এই পক্ষের যে বিলগুলো এখনো পুরো শোধ হয়নি। */
        bills: [],

        /**
         * কোন বিলে কত বসল — চাবি বিলের id, মান টাকার অঙ্ক।
         *
         * ⚠️ অ্যারে নয়, বস্তু: সারিগুলো বাছাই-অনুযায়ী আসে-যায়, আর
         * অ্যারের সূচক ধরে রাখলে একটা টিক তুললে বাকিগুলোর অঙ্ক এক ঘর
         * সরে যেত।
         */
        alloc: {},

        get partyOptions() {
            return this.parties.filter((p) => p.type === this.partyType);
        },

        /** লেখার সাথে মেলা সব নাম — ধরনের ভিতরেই। */
        get matches() {
            return matchParties(this.partyOptions, this.search);
        },

        /** যতগুলো আঁকা হয় ([[SHOWN_AT_ONCE]])। */
        get shown() {
            return this.matches.slice(0, SHOWN_AT_ONCE);
        },

        get moreHidden() {
            return this.matches.length > SHOWN_AT_ONCE;
        },

        get noMatch() {
            return this.matches.length === 0;
        },

        /** কিছু লেখা আছে কি না — "নতুন নাম হিসেবে যোগ করুন" তখনই দেখা যায়। */
        get hasSearch() {
            return String(this.search || '').trim() !== '';
        },

        /** বাছা পক্ষটা — বোতামে তার নাম আর নিচে কোড · পয়েন্ট। */
        get pickedParty() {
            return this.partyOptions.find((p) => String(p.id) === String(this.partyId)) || null;
        },

        /**
         * বোতামে কী লেখা।
         *
         * ⭐ তালিকায় না থাকা নামও এখানে দেখা যায় — সমন্বয়কের মাধ্যমে মালিকের
         * চাওয়া, ৩ অক্টোবর ২০২৬: "+" দিয়ে যোগ করা নামটা তালিকা থেকেই বাছা
         * দেখাবে। ⓘ সার্ভারে সেটা জমার সময় ব্যক্তি হয়ে বসে ([[VoucherRequest]]),
         * আর পরের বার তালিকাতেই থাকে।
         */
        get pickedLabel() {
            if (this.pickedParty) {
                return this.pickedParty.label;
            }

            const typed = String(this.newName || '').trim();

            return this.adding && typed !== '' ? '+ ' + typed : '—';
        },

        get pickedHint() {
            return this.pickedParty ? (this.pickedParty.hint || '') : '';
        },

        /** স্ক্রিন-রিডারের জন্য — আলো-পড়া সারির id। */
        get activeOption() {
            return this.listOpen && this.shown.length > 0 ? 'party-opt-' + this.cursor : '';
        },

        isCursor(i) {
            return i === this.cursor;
        },

        isPicked(p) {
            return String(p.id) === String(this.partyId);
        },

        /**
         * তালিকা খোলা — খোঁজার ঘরে সাথে সাথে ফোকাস।
         *
         * ⓘ বোতামে একটা অক্ষর টাইপ করলে সেটাই প্রথম অক্ষর হয়ে বসে — কাউন্টারের
         * লোক ঘরে এসে সরাসরি নাম লিখতে শুরু করেন, আগে ক্লিক করতে হয় না।
         */
        openList(first = '') {
            this.search = first;
            this.listOpen = true;

            const at = this.shown.findIndex((p) => this.isPicked(p));

            this.cursor = first === '' && at >= 0 ? at : 0;

            this.$nextTick(() => {
                this.$refs.search?.focus();
                this.revealCursor();
            });
        },

        toggleList() {
            if (this.listOpen) {
                this.closeList();
            } else {
                this.openList();
            }
        },

        closeList() {
            this.listOpen = false;
            this.search = '';
        },

        /** Esc — তালিকা বন্ধ, আর ফোকাস বোতামে ফেরে, যাতে Tab আগের মতোই চলে। */
        escape() {
            this.closeList();
            this.$nextTick(() => this.$refs.trigger?.focus());
        },

        /** বন্ধ বোতামে ↓ বা কোনো অক্ষর — তালিকা খোলে। */
        triggerKey(e) {
            if (e.ctrlKey || e.metaKey || e.altKey) {
                return;
            }

            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                this.openList();
            } else if (typeof e.key === 'string' && e.key.length === 1 && e.key !== ' ') {
                e.preventDefault();
                this.openList(e.key);
            }
        },

        /** লেখা বদলালে আলো প্রথম সারিতে — নাহলে Enter পুরনো জায়গার নাম বাছত। */
        searched() {
            this.cursor = 0;
        },

        moveDown() {
            if (this.shown.length > 0) {
                this.cursor = Math.min(this.cursor + 1, this.shown.length - 1);
                this.revealCursor();
            }
        },

        moveUp() {
            this.cursor = Math.max(this.cursor - 1, 0);
            this.revealCursor();
        },

        hover(i) {
            this.cursor = i;
        },

        /** আলো-পড়া সারিটা তালিকার দৃশ্যমান অংশে টেনে আনা। */
        revealCursor() {
            this.$nextTick(() => {
                const row = this.$refs.list?.querySelector('#party-opt-' + this.cursor);

                if (row && typeof row.scrollIntoView === 'function') {
                    row.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        /**
         * Enter — আলো-পড়া নামটা বাছা।
         *
         * ⓘ কিছুই না মিললে Enter লেখাটাকে নতুন নাম বানায় — "তালিকায় নেই? নাম
         * লিখুন"-এর একই পথ ([[addTyped()]])।
         */
        pickCursor() {
            const row = this.shown[this.cursor];

            if (row) {
                this.pickParty(row.id);
            } else if (this.search.trim() !== '') {
                this.addTyped();
            }
        },

        /**
         * নাম বাছা — আগের `<select>`-এর মতোই: `partyId` বসে আর পাওনা আসে।
         *
         * ⚠️ নতুন নামের ঘর বন্ধ ও খালি হয়: দুইটা একসাথে থাকলে সার্ভার বাছা
         * নামটাই নেয় ([[VoucherRequest]]), আর হাতে লেখাটা চুপচাপ হারাত।
         */
        pickParty(id) {
            this.partyId = String(id);
            this.adding = false;
            this.newName = '';
            this.closeList();
            this.loadDue();
            this.$nextTick(() => this.$refs.trigger?.focus());
        },

        /**
         * তালিকায় নেই — লেখাটাই নতুন নাম হয়ে "+" ঘরে বসে।
         *
         * ⚠️ বাছা নামটা মুছে যায়: সার্ভার হাতে লেখা নাম কেবল তখনই নেয় যখন
         * `party_id` খালি। ⓘ মোবাইলের ঘরে ফোকাস, কারণ নামটা তো লেখাই হয়ে গেছে।
         */
        addTyped() {
            this.newName = this.search.trim();
            this.partyId = '';
            this.due = null;
            this.bills = [];
            this.adding = true;
            this.closeList();
            this.$nextTick(() => this.$refs.newMobile?.focus());
        },

        toggleAdding() {
            this.adding = ! this.adding;
        },

        /** নতুন নামের ঘরে লেখা — তালিকার বাছাই তখন ছেড়ে দেওয়া হয়, একই কারণে। */
        newNameTyped() {
            if (String(this.newName || '').trim() !== '' && this.partyId !== '') {
                this.partyId = '';
                this.due = null;
                this.bills = [];
            }
        },

        get allocatedTotal() {
            return Object.values(this.alloc).reduce((sum, v) => sum + (Number(v) || 0), 0);
        },

        /**
         * ভাগ করা টাকা গৃহীত টাকার চেয়ে বেশি কি না।
         *
         * ⓘ অঙ্কটা পর্দার নিচের "গৃহীত টাকা" ঘর থেকে পড়া হয় — দুইটা
         * সংখ্যা এক জায়গায় রাখলে একটা বদলালে অন্যটা বাসি হয়ে যেত।
         */
        get overAllocated() {
            const amount = Number(this.$root.closest('form')?.querySelector('[name="amount"]')?.value || 0);

            return amount > 0 && this.allocatedTotal - amount > 0.0001;
        },

        init() {
            for (const row of picked || []) {
                if (row && row.invoice_id) {
                    this.alloc[row.invoice_id] = Number(row.amount) || 0;
                }
            }

            if (this.partyId) {
                this.loadDue();
            }
        },

        resetParty() {
            this.closeList();
            this.partyId = '';
            this.due = null;
            this.bills = [];
            this.alloc = {};
        },

        async loadDue() {
            if (!this.partyType || !this.partyId) {
                this.due = null;
                this.bills = [];

                return;
            }

            try {
                const url = `${dueUrl}?party_type=${encodeURIComponent(this.partyType)}&party_id=${encodeURIComponent(this.partyId)}`;
                const res = await fetch(url, { headers: { Accept: 'application/json' } });

                if (!res.ok) {
                    return;
                }

                const data = await res.json();

                this.due = data.known ? Number(data.amount).toFixed(2) : null;
                this.bills = data.bills || [];
            } catch (e) {
                /*
                 * ⚠️ নেট গেলে পর্দাটা অচল হয় না — বকেয়া আর বিলের তালিকা
                 * সুবিধা, শর্ত নয়। ⓘ ব্যবহারকারী হাতে অঙ্ক লিখে রসিদটা
                 * ঠিকই সংরক্ষণ করতে পারেন।
                 */
                this.due = null;
            }
        },

        toggle(bill, on) {
            if (on) {
                this.alloc[bill.id] = Number(bill.outstanding);
            } else {
                delete this.alloc[bill.id];
            }
        },

        /**
         * পুরনো বিল আগে — মালিকের নিজের চাওয়া নিয়ম।
         *
         * ⓘ গৃহীত টাকাটা বয়সের ক্রমে বসে, আর প্রতিটা বিলে **যতটুকু
         * বাকি ততটুকুই** — বেশি নয়। ⚠️ টাকা ফুরালে বাকি বিলগুলো খালি
         * থাকে, শূন্য বসে না: শূন্য বসালে সেগুলো "ভাগ করা হয়েছে"
         * দেখাত অথচ কিছুই বসেনি।
         */
        fifo() {
            const form = this.$root.closest('form');
            let left = Number(form?.querySelector('[name="amount"]')?.value || 0);

            this.alloc = {};

            for (const b of [...this.bills].sort((a, b) => b.age - a.age)) {
                if (left <= 0.0001) {
                    break;
                }

                const take = Math.min(left, Number(b.outstanding));

                this.alloc[b.id] = Number(take.toFixed(2));
                left -= take;
            }
        },

        clearAlloc() {
            this.alloc = {};
        },
    };
}
