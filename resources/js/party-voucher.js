/**
 * রসিদ ও পরিশোধের পর্দার যুক্তি — পক্ষ, বকেয়া, আর কোন বিলের বিপরীতে।
 *
 * ── ⭐ কেন ফাইলে, ব্লেডের ভিতরে নয় ───────────────────────────────────
 * আগে পুরো যুক্তিটা `x-data="{ … }"` অ্যাট্রিবিউটের ভিতরে লেখা ছিল, আর
 * সেখানে দুইটা জিনিস বারবার ভেঙেছে: অনুবাদে একটা অ্যাপস্ট্রফি থাকলেই
 * স্ট্রিং শেষ হয়ে যেত, আর Blade মন্তব্যের ভিতরের `{{ }}`-ও পার্স করত।
 *
 * ⓘ এখানে লেখাগুলো বাইরে থেকে আসে, তাই ঐ দুইটা ফাঁদই বন্ধ।
 *
 * ── ⭐ নাম বাছা এখন ভাগ করা পিকারে (২৪ অক্টোবর ২০২৬) ──────────────────
 * খোঁজা, ↑ ↓ Enter, "+ নতুন নাম" আর মোবাইলের ঘর — সব [[x-ui.party-search]]-এ (`stacked addable`, a6 d8005fa00), আদায় আর
 * সরবরাহকারী-পরিশোধের একই পিকার। ⓘ এই বাক্স কেবল শোনে: পিকার নিজের লুকানো `party_pick`-এ `change` ছাড়ে (জমা যায় পাতার একটাই `party_id`, মান `partyId`) — বাছলে id, হাতে লেখা নতুন নামে
 * ফাঁকা — আর এখানে বকেয়া আর খোলা বিল আসে বা মোছে ([[partyPicked()]])। ⛔ আগের নিজের তালিকা (খোঁজা, কার্সর, "+") এখান থেকে
 * সরানো — দুই জায়গায় থাকলে একদিন একটা আরেকটার থেকে আলাদা আচরণ করত।
 */

/*
 * ⓘ খোঁজার নিয়ম [[party-search.js]]-এ; এখান থেকেও রপ্তানি হয়, যাতে পুরনো `import` ভাঙে না।
 */
import { matchParties, SHOWN_AT_ONCE } from './party-search.js'
import { taka } from './components/money.js'

export { matchParties, SHOWN_AT_ONCE }

export default function partyVoucher({ partyType, partyId, dueUrl, texts, pickedType = '', pickedId = '', out = false }) {
    return {
        partyType,
        partyId: partyId ? String(partyId) : '',
        texts,

        /** এই পক্ষের মোট বকেয়া — নামের পাশে দেখানো হয়। */
        due: null,

        /*
         * ⭐ পক্ষের পাশের অঙ্ক — চিহ্ন ধরে শব্দ, অঙ্ক সবসময় ধনাত্মক আর লাখ-কমায় (fe, ২৩ অক্টোবর ২০২৬)।
         * ⓘ সার্ভারের চিহ্ন ([[AccountsFacts::dueFrom()]]): ধনাত্মক = তিনি আমাদের দেবেন, ঋণাত্মক = আমরা তাঁকে দেব।
         *   রসিদ:   ধনাত্মক "বকেয়া" (সতর্ক রং) · ঋণাত্মক "অগ্রিম" (সবুজ) · শূন্য "বকেয়া নেই"
         *   পরিশোধ: ঋণাত্মক "দেনা" (সতর্ক রং) · ধনাত্মক "অগ্রিম দেওয়া" (সবুজ) · শূন্য "দেনা নেই"
         * ⛔ আগে "পাওনা -45,000.00" সবুজে — ঋণাত্মক চিহ্ন চোখ এড়ায়, আর মানুষ অগ্রিমকেও আদায়যোগ্য ভাবতেন।
         */
        out,

        get dueSign() {
            const n = Number(this.due ?? 0);
            if (Math.abs(n) < 0.005) return 0;

            return n > 0 ? 1 : -1;
        },

        /** এই কাগজ যে দিকটা মেটায় — রসিদে তিনি দেবেন (+), পরিশোধে আমরা দেব (−) */
        get dueIsOwed() {
            return this.dueSign === (this.out ? -1 : 1);
        },

        get dueWord() {
            const side = this.out ? 'Out' : 'In';
            if (this.dueSign === 0) return this.texts['due' + side + 'None'] ?? '';

            return this.texts['due' + side + (this.dueIsOwed ? 'Owed' : 'Advance')] ?? '';
        },

        get dueAmount() {
            return this.due === null || this.dueSign === 0 ? '' : '৳' + taka(Math.abs(Number(this.due)));
        },

        get dueClass() {
            if (this.dueSign === 0) return 'bg-(--color-surface-sunken) text-(--color-ink-muted)';

            return this.dueIsOwed
                ? 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)'
                : 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)';
        },

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

        /**
         * ⭐ ভাগ করা পিকারের খবর — পিকারের লুকানো `party_pick`-এর `change` ([[x-ui.party-search]]; জমা যায় পাতার `party_id`, এই বাক্সের `partyId`)। বাছলে বকেয়া আর বিল আসে; হাতে লেখা নতুন নামে
         * মান ফাঁকা — বকেয়া, বিল আর বাছা বিল মোছে, আর মাথার তিন অঙ্কও ([[tellDue()]])। ⓘ অন্য ঘরের `change` এখানে কিছু করে না।
         */
        partyPicked(event) {
            if (event?.target?.name !== 'party_pick') {
                return;
            }

            const id = String(event.target.value ?? '');

            if (id === this.partyId && id !== '') {
                return;
            }

            this.partyId = id;
            this.due = null;
            this.bills = [];
            this.unpick();
            this.loadDue();
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
            // ⓘ ঘরটা এখন লাখ-কমায় ("45,000.00") — কমা ছেঁটে পড়া, নাহলে NaN আর "বিলের বাকির বেশি" কখনো বলত না
            const amount = Number(String(this.$root.closest('form')?.querySelector('[name="amount"]')?.value || 0).replace(/,/g, ''));

            return bill !== null && amount - Number(bill.outstanding) > 0.0001;
        },

        init() {
            if (this.partyId) {
                this.loadDue();
            }

            /*
             * ⭐ বকেয়া আর "পক্ষ আছে কি না" ফর্মকে জানানো — মাথার তিন অঙ্ক ডানের কলামে, এই বাক্সের বাইরে ([[voucherMoney]] `heardParty`;
             * fe, ২৪ অক্টোবর ২০২৬)। ⓘ ঘটনা, ভাগ করা অবস্থা নয় — দুই x-data একে অপরের অবস্থা দেখে না।
             */
            this.$watch('due', () => this.tellDue());
            this.$watch('partyType', () => this.tellDue());
            this.$nextTick(() => this.tellDue());
        },

        tellDue() {
            this.$dispatch('party-due', { due: this.due, hasParty: this.partyType !== '' });
        },

        /** "অন্য (সরাসরি খাত)" — পক্ষ নেই, তাই বিলের তালিকা আর পাওনার অঙ্কও নেই */
        get noParty() {
            return this.partyType === '';
        },

        /*
         * ধরন বদলালে বাছাই, বকেয়া আর বিল মোছে — নতুন ধরনের পিকার খালি শুরু করে।
         * ⚠️ শুরুর ধরনে ফিরলে তার পিকার আবার শুরুর বাছাই নিয়ে জন্মায় (`x-if`, সার্ভারের `selected`) — তখন সেই নামই, আর তার বকেয়া
         * ও বিল; নাহলে ঘর নাম পাঠাত অথচ মাথার অঙ্ক ফাঁকা থাকত।
         */
        firstType: partyType,
        firstId: partyId ? String(partyId) : '',

        resetParty() {
            this.partyId = this.partyType === this.firstType ? this.firstId : '';
            this.due = null;
            this.bills = [];
            this.unpick();
            this.loadDue();
        },

        async loadDue() {
            if (!this.partyType || !this.partyId) {
                this.due = null;
                this.bills = [];

                return;
            }

            /*
             * ⚠️ উত্তর আসতে দেরি হলে মাঝে মানুষ নতুন নাম লিখে ফেলেন বা ধরন বদলান — তখন পুরনো পক্ষের বকেয়া এসে মোছা ঘর আবার ভরত
             * (ধীর সার্ভারে ব্রাউজারে মাপা, ২৪ অক্টোবর ২০২৬: নতুন নাম লেখার পরে "অগ্রিম ৳45,000")। তাই যে পক্ষের জন্য চাওয়া, উত্তর
             * কেবল তখনো সে থাকলেই বসে।
             */
            const asked = this.partyType + ':' + this.partyId;
            const stillAsked = () => asked === this.partyType + ':' + this.partyId;

            try {
                const url = `${dueUrl}?party_type=${encodeURIComponent(this.partyType)}&party_id=${encodeURIComponent(this.partyId)}`;
                const res = await fetch(url, { headers: { Accept: 'application/json' } });

                if (!res.ok || !stillAsked()) {
                    return;
                }

                const data = await res.json();

                if (!stillAsked()) {
                    return;
                }

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

            /*
             * ⓘ টাকার ঘর দুই ভাগে (x-ui.field type="money", ২৪ অক্টোবর ২০২৬) — লেখা যায় দেখানোর ঘরে, পাঠানোর ঘর লুকানো আর কেবল
             * মান পড়ে। ⛔ লুকানো ঘরে বসালে পর্দার অঙ্ক বদলাত না, আর পরের টিকে Alpine সেটা মুছে দিত। পুরনো পাতায় নামের ঘরটাই।
             */
            const form = this.$root.closest('form');
            const sent = form?.querySelector('[name="amount"]');
            const amount = form?.querySelector('[data-money-shown="amount"]') ?? sent;

            if (amount && String(sent?.value ?? amount.value ?? '').trim() === '') {
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
         * নিজের ছিল; একই নামে লিখলে বস্তুর শেষ চাবিটাই টিকত আর পক্ষের দাগ ভাঙত (vitest ধরেছে)।
         *
         * ⛔ আগে ব্লেডে লেখা ছিল `String(b.id) === pickedId && …` — আর `@alpinejs/csp` বৈশ্বিক নাম (`String`) চেনে না,
         * তাই এক্সপ্রেশনটা চুপচাপ ভাঙত: বাছা বিলের রেডিওতে দাগ পড়ত না (csp-expressions.test.js)। তুলনাটা এখন এখানে।
         */
        isBillPicked(bill) {
            return String(bill.id) === this.pickedId && bill.against_type === this.pickedType;
        },
    };
}
