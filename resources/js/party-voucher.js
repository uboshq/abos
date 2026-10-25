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

            // ⓘ উল্টো দিকের স্থিতি — "অগ্রিম" নয়, পক্ষের নিজের খাতে (Cr/Dr); :party পক্ষের ধরনে (মালিকের নিয়ম, ২৫ অক্টোবর ২০২৬)
            const party = this.texts?.partyOf?.[this.partyType] ?? this.texts?.partyOf?.[''] ?? '';

            return String(this.texts['due' + side + (this.dueIsOwed ? 'Owed' : 'Advance')] ?? '').replace(':party', party);
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

        /*
         * ⭐ এক টাকা, বহু বিল — কোন বিলে কত (`allocs`: বিলের public_id → অঙ্ক; cb-ma-4, ২৫ অক্টোবর ২০২৬; পরিশোধে-মোট-টাকা-চুক্তি §৩)।
         * ⓘ টিক দিলে পুরো বকেয়া বসে, আর টাকার ঘরে বসে টিক-দেওয়া বিলগুলোর যোগফল — যতক্ষণ লেখক নিজে টাকার ঘর বদলাননি (`lastFill`:
         * শেষবার এই বাক্স যা বসিয়েছিল; ঘরের মান তার থেকে আলাদা হলে আর ছোঁয়া নয়)। `grossSeen` ফর্মের মোট (পয়সায়, [[voucherMoney]]-এর
         * `money-gross` ঘটনা): যোগফলের বাইরে যা থাকে তা অগ্রিম, বেশি হলে লাল। সার্ভার একই নিয়মে আবার মাপে ([[VoucherAllocations]])।
         */
        allocs: {},
        lastFill: null,
        grossSeen: null,

        /*
         * ⭐ পক্ষের মালিক মডিউলের তিন উত্তর — বকেয়ার একই উত্তরে আসে (Core চুক্তি PartyCreditLimit, PartyTaxIds, PayeeBankView; cb-ma-4):
         * বাকির সীমা (গ্রাহক), BIN/TIN (সরবরাহকারী), প্রাপকের ব্যাংক {state, line}। `method` — বাছা পদ্ধতি; ব্যাংকে যায় এমন পদ্ধতিতে
         * (ট্রান্সফার, নগদ জমা) সই-না-পাওয়া ব্যাংক লালে, পোস্টের পাহারার একই কথা ([[PayeeBankGuard]])।
         */
        credit: null,
        taxIds: null,
        payeeBank: null,
        method: '',
        // ⓘ পক্ষের নিজের শাখা — কেবল তথ্য, মাথায় "শাখা: গ্রাহকের শাখা (…)"; টাকা বসে বাক্সের শাখায়
        partyBranch: '',

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
            this.clearAllocs();
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
            // ⓘ পরিশোধে ঘরটা মোট (`gross_amount`, ২৫ অক্টোবর ২০২৬) — রসিদে `amount`
            const amount = Number(String(this.$root.closest('form')?.querySelector('[name="amount"], [name="gross_amount"]')?.value || 0).replace(/,/g, ''));

            return bill !== null && amount - Number(bill.outstanding) > 0.0001;
        },

        init() {
            // ⓘ শুরুর পদ্ধতি — সার্ভারের বাছা চিপ (পুরনো কাগজ, ভুল নিয়ে ফেরা)
            this.method = String(this.$root?.querySelector?.('input[name="instrument"]:checked')?.value ?? '');

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
            // ⭐ বিলে প্রয়োগের যোগফল সারাংশকে — নকশার "বিলে প্রয়োগ · অপ্রয়োগকৃত" ([[voucherMoney]] `heardApplied`; cb-ma-5)
            this.$watch('allocSumPaisa', () => this.tellApplied());
        },

        tellApplied() {
            this.$dispatch('bills-applied', { paisa: this.allocSumPaisa });
        },

        tellDue() {
            // ⓘ ধরনও যায় — মাথার অঙ্কের নাম তাতে ("গ্রাহকের বকেয়া", "সরবরাহকারীর পাওনা"; [[voucherMoney]] `headOwedLabel`)
            this.$dispatch('party-due', { due: this.due, hasParty: this.partyType !== '', partyType: this.partyType, partyBranch: this.partyBranch });
        },

        /** ⭐ পক্ষের ঘরের নাম ধরনে — "গ্রাহক", "সরবরাহকারী" (নকশা পাতা ২ আর ৪); অজানা ধরনে সাধারণ "পক্ষ" */
        get partyLabel() {
            return this.texts?.typeLabels?.[this.partyType] ?? this.texts?.partyWord ?? '';
        },

        /**
         * ⭐ পক্ষের ঘরের নিচে "সবচেয়ে পুরনো বাকি N দিন" (নকশা পাতা ২) — খোলা বিলের বয়স থেকে, যে বিলে এখনো বাকি আছে।
         * ⓘ বিল না থাকলে কিছুই নয় (ফাঁকা লেখা, ঘরটা লুকানো)।
         */
        get oldestDueText() {
            const ages = this.bills.filter((b) => this.isBill(b) && Number(b.outstanding) > 0.005).map((b) => Number(b.age)).filter((n) => Number.isFinite(n));

            if (ages.length === 0 || !this.texts?.oldestDue) {
                return '';
            }

            return this.texts.oldestDue.replace(':days', String(Math.max(...ages)));
        },

        /** ⭐ "পুরো বকেয়া" — কেবল রসিদের দিকে সত্যিকারের বকেয়া থাকলে (অগ্রিম বা শূন্যে নয়) */
        get canFillDue() {
            return this.dueIsOwed && this.dueSign !== 0;
        },

        /*
         * ⭐ নকশার "পুরো বকেয়া · নির্বাচিত বিল" — এক চাপে অঙ্ক বসে। ⓘ অঙ্কটা ফর্মের ([[voucherMoney]]), এই বাক্সের নয় — তাই ঘটনা
         * (`fill-amount`), দুই x-data একে অপরের অবস্থা দেখে না। অঙ্ক কাঁচা, দুই দশমিকে ("45000.00")।
         */
        fillWholeDue() {
            if (this.canFillDue) {
                this.$dispatch('fill-amount', Math.abs(Number(this.due)).toFixed(2));
            }
        },

        /** "নির্বাচিত বিল" — টিক-দেওয়া বিলগুলোর যোগফল; টিক না থাকলে অন্য মডিউলের আগাম-বাছা একক বিলের বাকি */
        fillPickedBill() {
            if (this.hasTicked) {
                this.$dispatch('fill-amount', (this.allocSumPaisa / 100).toFixed(2));

                return;
            }

            const bill = this.pickedBill;

            if (bill !== null) {
                this.$dispatch('fill-amount', Number(bill.outstanding).toFixed(2));
            }
        },

        get hasTicked() {
            return this.pickedRows.length > 0 || this.pickedBill !== null;
        },

        // ── বহু বিল ─────────────────────────────────────────────────────

        /** ⓘ বিল না নোট — a5-এর চুক্তির `kind`; পুরনো সারিতে (ঘর নেই) বিল */
        isBill(b) {
            return String(b?.kind ?? 'invoice') === 'invoice';
        },

        kindText(b) {
            return this.isBill(b) ? '' : String(this.texts?.kinds?.[b.kind] ?? b.kind ?? '');
        },

        get hasNotes() {
            return this.bills.some((b) => !this.isBill(b));
        },

        billKey(b) {
            return String(b.against_type) + '-' + String(b.id);
        },

        isTicked(b) {
            return Object.prototype.hasOwnProperty.call(this.allocs, String(b.public_id));
        },

        /** টিক — পুরো বকেয়া বসে; টিক তুললে সারিটা যায় */
        toggle(b) {
            // ⛔ নোট টিক হয় না — এই কাগজে প্রয়োগ নয়
            if (!this.isBill(b)) {
                return;
            }

            const key = String(b.public_id);
            const next = { ...this.allocs };

            if (Object.prototype.hasOwnProperty.call(next, key)) {
                delete next[key];
            } else {
                next[key] = Number(b.outstanding).toFixed(2);
            }

            this.allocs = next;
            this.refill();
        },

        rowAmount(b) {
            return this.allocs[String(b.public_id)] ?? '';
        },

        /** সারির অঙ্ক বদলানো — লাখ-কমা ছেঁটে; অ-সংখ্যা হলে ফাঁকা (সার্ভার থামাবে, চুপচাপ ০ নয়) */
        setRow(b, value) {
            const clean = String(value ?? '').replace(/,/g, '').trim();

            this.allocs = { ...this.allocs, [String(b.public_id)]: /^\d+(\.\d+)?$/.test(clean) ? clean : '' };
            this.refill();
        },

        /** পাঠানোর সারি — বিলের তালিকার ক্রমে (পুরনো আগে) */
        get pickedRows() {
            return this.bills.filter((b) => this.isTicked(b)).map((b) => ({ id: String(b.public_id), amount: this.allocs[String(b.public_id)] }));
        },

        billField(i, key) {
            return 'bills[' + i + '][' + key + ']';
        },

        get allocSumPaisa() {
            return this.pickedRows.reduce((sum, row) => sum + Math.round(Number(row.amount || 0) * 100), 0);
        },

        get allocSumText() {
            return '৳' + taka(this.allocSumPaisa / 100);
        },

        /** টাকার ঘরে যোগফল — কেবল লেখক নিজে ঘরটা না বদলালে (ফাঁকা, বা শেষবার এই বাক্সের বসানো অঙ্কই আছে) */
        refill() {
            const sum = this.allocSumPaisa;

            if (this.grossSeen === null || this.grossSeen === 0 || this.grossSeen === this.lastFill) {
                this.lastFill = sum;
                this.$dispatch('fill-amount', (sum / 100).toFixed(2));
            }
        },

        /** ⓘ ফর্মের মোট — পয়সায় ([[voucherMoney]] `money-gross`) */
        heardGross(paisa) {
            const value = Number(paisa);

            this.grossSeen = Number.isFinite(value) ? value : null;
        },

        /**
         * যোগফলের বাইরে যা থাকে — বিলে প্রয়োগ হয় না, পক্ষের নিজের খাতায় থাকে: পরিশোধে Dr, রসিদে Cr (চুক্তি §৭.৬)।
         * ⛔ "অগ্রিম" নয় — সরবরাহকারীর আলাদা অগ্রিম খাত নেই (১১৩০ দল, ১১৩১ কেবল কর্মীর); খাতায় টাকা পক্ষের নিজের খাতেই বসে।
         */
        get unappliedText() {
            const sum = this.allocSumPaisa;

            if (sum <= 0 || this.grossSeen === null || this.grossSeen <= sum || !this.texts?.unapplied) {
                return '';
            }

            const party = this.texts?.partyOf?.[this.partyType] ?? this.texts?.partyOf?.[''] ?? '';

            return this.texts.unapplied
                .replace(':sum', '৳' + taka(sum / 100))
                .replace(':amount', taka((this.grossSeen - sum) / 100))
                .replace(':party', party)
                .replace(':side', this.out ? 'Dr' : 'Cr');
        },

        /** যোগফল টাকার বেশি — লালে (সার্ভারও থামায়, `bills_over_gross`) */
        get overText() {
            const sum = this.allocSumPaisa;

            if (sum <= 0 || this.grossSeen === null || this.grossSeen >= sum || !this.texts?.over) {
                return '';
            }

            return this.texts.over.replace(':sum', taka(sum / 100)).replace(':gross', taka(this.grossSeen / 100));
        },

        /** দেরি — শেষ দিন পেরোলে "N দিন" (লালে), নইলে "—" */
        lateText(b) {
            const days = Number(b.days_late ?? 0);

            return days > 0 && this.texts?.lateDays ? this.texts.lateDays.replace(':n', String(days)) : '—';
        },

        lateClass(b) {
            return Number(b.days_late ?? 0) > 0 ? 'font-medium text-(--color-danger)' : 'text-(--color-ink-muted)';
        },

        /** টাকা লাখ-কমায় দুই দশমিকে — নকশার "30,000.00" */
        lakh(value) {
            return taka(Number(value ?? 0));
        },

        /** "2026-10-17" → "17-10" (নকশার মতো) */
        dayMonth(value) {
            const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? ''));

            return m ? m[3] + '-' + m[2] : String(value ?? '');
        },

        clearAllocs() {
            this.allocs = {};
            this.lastFill = null;
            this.credit = null;
            this.taxIds = null;
            this.payeeBank = null;
            this.partyBranch = '';
        },

        /** পদ্ধতির চিপ বদলালে — এই বাক্সের ভিতরের `change` (চিপ "সাধারণ তথ্য" কার্ডে) */
        heardChange(event) {
            if (event?.target?.name === 'instrument') {
                this.method = String(event.target.value ?? '');
            }
        },

        /** বাকির সীমা — সুইচ বন্ধ হলে কিছুই নয়; ০.০০ মানে বাকি নেই (নগদে) */
        get creditText() {
            if (this.credit === null || this.credit === undefined) {
                return '';
            }

            return Number(this.credit.limit ?? 0) > 0.005
                ? (this.texts?.creditLimit ?? '').replace(':limit', taka(Number(this.credit.limit)))
                : (this.texts?.creditNone ?? '');
        },

        /** BIN · TIN — যা আছে তাই, নেই তো কিছুই নয় */
        get taxText() {
            const ids = this.taxIds ?? {};

            return [ids.bin ? 'BIN ' + ids.bin : '', ids.tin ? 'TIN ' + ids.tin : ''].filter((x) => x !== '').join(' · ');
        },

        get bankLineText() {
            if (this.payeeBank === null || this.payeeBank === undefined) {
                return '';
            }

            return this.payeeBank.line ? String(this.payeeBank.line) : (this.texts?.bankNone ?? '');
        },

        get bankSigned() {
            return this.payeeBank?.state === 'signed';
        },

        /** সই-না-পাওয়া ব্যাংক আর ব্যাংকে যায় এমন পদ্ধতি — পরিশোধে লালে (পোস্টও থামবে) */
        get bankBlocks() {
            return this.out && this.payeeBank !== null && this.payeeBank !== undefined && this.payeeBank.state !== 'signed'
                && (this.method === 'transfer' || this.method === 'deposit');
        },

        /**
         * ⓘ অন্য মডিউলের আগাম-বাছা একক "বিপরীতে" (যেমন অর্থের খাতার "টাকা নিন") যদি এই পক্ষের খোলা বিল হয় — সেটাকে বিলের সারিতে টিক,
         * আর একক ঘর খালি: একই বিল দুই পথে দুবার বসত না।
         */
        adoptPicked() {
            if (this.pickedId === '') {
                return;
            }

            const bill = this.bills.find((b) => String(b.id) === this.pickedId && b.against_type === this.pickedType);

            if (bill && bill.public_id) {
                this.allocs = { ...this.allocs, [String(bill.public_id)]: Number(bill.outstanding).toFixed(2) };
                this.unpick();
            }
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
            this.clearAllocs();
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
                this.credit = data.credit ?? null;
                this.taxIds = data.tax_ids ?? null;
                this.payeeBank = data.payee_bank ?? null;
                this.partyBranch = String(data.party_branch ?? '');
                this.tellDue();
                this.adoptPicked();
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
            const sent = form?.querySelector('[name="amount"], [name="gross_amount"]');
            const amount = form?.querySelector('[data-money-shown="amount"], [data-money-shown="gross_amount"]') ?? sent;

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
