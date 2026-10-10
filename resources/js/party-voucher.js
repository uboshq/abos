/**
 * রসিদ ও পরিশোধের পর্দার যুক্তি — পক্ষ, বকেয়া, আর কোন বিলের বিপরীতে।
 *
 * ── ⭐ কেন ফাইলে, ব্লেডের ভিতরে নয় ───────────────────────────────────
 * আগে পুরো যুক্তিটা `x-data="{ … }"` অ্যাট্রিবিউটের ভিতরে লেখা ছিল, আর
 * সেখানে দুইটা জিনিস বারবার ভেঙেছে: অনুবাদে একটা অ্যাপস্ট্রফি থাকলেই
 * স্ট্রিং শেষ হয়ে যেত, আর Blade মন্তব্যের ভিতরের `{{ }}`-ও পার্স করত।
 *
 * ⓘ এখানে লেখাগুলো বাইরে থেকে আসে, তাই ঐ দুইটা ফাঁদই বন্ধ।
 */

/*
 * ⓘ খোঁজার নিয়ম আর একবারে কতগুলো আঁকা হয় — এখন [[party-search.js]]-এ, ৩ অক্টোবর
 * ২০২৬: ক্রেডিট / ডেবিট নোটেও একই খোঁজা লাগল (মালিক: *"ডেবিট নোট পার্টি সার্চ
 * দেয়ার অপশন নাই"*), আর দুইটা কপি একদিন আলাদা হয়ে যেত। ⚠️ এখান থেকেও রপ্তানি
 * হয়, যাতে পুরনো `import` ভাঙে না।
 */
import { matchParties, SHOWN_AT_ONCE } from './party-search.js'

export { matchParties, SHOWN_AT_ONCE }

export default function partyVoucher({ partyType, partyId, parties, dueUrl, texts, pickedType = '', pickedId = '', adding = false, newName = '' }) {
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
         * ⭐ রসিদ **একটা** বিলের বিপরীতে — `against_type` / `against_id` (Accounts-Finance অডিট ম১, ৪ অক্টোবর ২০২৬)।
         *
         * ⛔ আগে এখানে বহু বিলে ভাগের ঘর ছিল (`bill_allocs`), কিন্তু সার্ভার সেটা কোথাও রাখত না — ভাগ করলেও কিছুই হত না।
         * ⓘ এখন বাছা বিলটাই রসিদের "বিপরীতে" ঘরে যায়, আর পোস্টের মুহূর্তে অঙ্ক, পক্ষ আর খোলা থাকা মাপা হয়। এক টাকায় বহু বিল
         * আর "পুরনো বিল আগে" বিক্রয়ের "আদায়" পর্দায় — হিসাবের উৎস একটাই।
         */
        pickedType: pickedType ? String(pickedType) : '',
        pickedId: pickedId ? String(pickedId) : '',

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
            this.unpick();
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
                this.unpick();
            }
        },

        /** বাছা বিলটা — তালিকায় থাকলে। */
        get pickedBill() {
            return this.bills.find((b) => String(b.id) === this.pickedId && b.against_type === this.pickedType) || null;
        },

        /**
         * গৃহীত টাকা বাছা বিলের বাকির চেয়ে বেশি কি না।
         *
         * ⓘ অঙ্কটা পর্দার নিচের "গৃহীত টাকা" ঘর থেকে পড়া হয় — দুইটা সংখ্যা এক জায়গায় রাখলে একটা বদলালে অন্যটা বাসি
         * হত। ⚠️ এটা কেবল আগাম সতর্কতা; আসল পাহারা পোস্টের মুহূর্তে সার্ভারে।
         */
        get overDue() {
            const bill = this.pickedBill;
            const amount = Number(this.$root.closest('form')?.querySelector('[name="amount"]')?.value || 0);

            return bill !== null && amount - Number(bill.outstanding) > 0.0001;
        },

        init() {
            if (this.partyId) {
                this.loadDue();
            }
        },

        resetParty() {
            this.closeList();
            this.partyId = '';
            this.due = null;
            this.bills = [];
            this.unpick();
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

        /**
         * এই বিলের বিপরীতে — আর টাকার ঘর খালি থাকলে বিলের বাকিটাই বসে।
         *
         * ⚠️ ঘরে আগে থেকে অঙ্ক থাকলে সেটা বদলানো হয় না: গ্রাহক প্রায়ই বাকির একটা অংশ দেন।
         */
        pick(bill) {
            this.pickedType = String(bill.against_type);
            this.pickedId = String(bill.id);

            const amount = this.$root.closest('form')?.querySelector('[name="amount"]');

            if (amount && String(amount.value || '').trim() === '') {
                amount.value = Number(bill.outstanding).toFixed(2);
                amount.dispatchEvent(new Event('input', { bubbles: true }));
            }
        },

        unpick() {
            this.pickedType = '';
            this.pickedId = '';
        },

        /*
         * ⭐ এই বিলটাই কি বাছা — রেডিওর `:checked`-এর জন্য (১০ অক্টোবর ২০২৬)। ⚠️ নাম `isPicked` নয় — ওটা পক্ষের তালিকার
         * নিজের ([[isPicked()]]); একই নামে লিখলে বস্তুর শেষ চাবিটাই টিকত আর পক্ষের দাগ ভাঙত (vitest ধরেছে)।
         *
         * ⛔ আগে ব্লেডে লেখা ছিল `String(b.id) === pickedId && …` — আর `@alpinejs/csp` বৈশ্বিক নাম (`String`) চেনে না,
         * তাই এক্সপ্রেশনটা চুপচাপ ভাঙত: বাছা বিলের রেডিওতে দাগ পড়ত না (csp-expressions.test.js)। তুলনাটা এখন এখানে।
         */
        isBillPicked(bill) {
            return String(bill.id) === this.pickedId && bill.against_type === this.pickedType;
        },
    };
}
