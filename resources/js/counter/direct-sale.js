/**
 * কাউন্টারে সরাসরি বিক্রয় — পর্দার পুরো যুক্তি।
 *
 * ── ⭐ কেন ফাইলে, ব্লেডের ভিতরে নয় — ১৮ সেপ্টেম্বর ২০২৬ ───────────────
 * নিরীক্ষার ধাপ ৪.১: *"ইনলাইন `<script>`-এর যুক্তি `resources/js/counter/`
 * ফোল্ডারে মডিউল হিসেবে সরান"*। এই ফাইলটা ব্লেডের ভিতরে **১,৪৫৩ লাইন**
 * ছিল, আর ব্লেড ফাইলটা মোট ৪,৪১৩।
 *
 * ── ⛔ ইনলাইন থাকার তিনটা দাম ────────────────────────────────────────
 * ⓵ কোনো পরীক্ষা লেখা যেত না। ⚠️ দরের হিসাব, ছাড়, খসড়া সংরক্ষণ —
 *   প্রতিটাই টাকার অঙ্ক, আর একটাও মাপা হত না।
 *
 * ⓶ অনুবাদে একটা অ্যাপস্ট্রফি থাকলেই স্ট্রিং শেষ হয়ে যেত, আর Blade
 *   মন্তব্যের ভিতরের `{{ }}`-ও পার্স হত ([[party-voucher.js]]-এ একই
 *   কারণ লেখা আছে — এই ফাঁদে আগে পড়া হয়েছে)।
 *
 * ⓷ ক্রয়ের কাউন্টারে প্রায় একই যুক্তি আলাদা করে লেখা, আর নিরীক্ষায়
 *   দেখা গেছে **একই বাগ চার জায়গায়** পাওয়া গেছে (কমিট 9197153)।
 *   ⓘ এটাই নকলের সরাসরি দাম।
 *
 * ── ⚠️ সই বদলেছে: অবস্থান নয়, নামধারী ঘর ─────────────────────────────
 * আগে `directSale(catalogue, customers, walkinId, vatEnabled, packs)` —
 * আর বাকি আটটা মান ব্লেড থেকে **ফাংশনের ভিতরে** `@js(...)` দিয়ে ঢুকত।
 * ⛔ অর্থাৎ ফাংশনটা তার নিজের ইনপুট কোথা থেকে আসছে তা জানত না।
 *
 * ⭐ এখন সবগুলোই একটা অবজেক্টে, তাই ফাংশনটা বিশুদ্ধ — যা লাগে তা সে
 * চায়, আর ব্লেড কেবল দেয়।
 */

import { taka } from '../components/money.js'

export default function directSale({
    catalogue, customers, walkinId, vatEnabled, packs,
    paymentTermDefault, carriers, depositMethods, moneyAccounts,
    draftKey, hasErrors, texts, freeAllowedUrl, warehouseId,
}) {
    return {
        catalogue,

        /*
         * ফ্রি-র সীমা জানার ঠিকানা আর গুদামটা।
         *
         * ⓘ গুদাম ছাড়া অনুপাতের প্রশ্নেরই উত্তর নেই — ফ্রি কোন গুদামের
         * কোন লটে এসেছিল, সেটাই তো হিসাব।
         */
        freeAllowedUrl,
        warehouseId,

        /*
         * ⚠️ সীমা ছাড়ানোর বার্তা — এন্ট্রির ঘরের নিচেই, উপরে নয়।
         *
         * ⓘ পর্দার উপরে দেখালে মানুষ যেখানে টাইপ করছেন সেখান থেকে চোখ
         * সরাতে হত। ⛔ আর সারিটা কার্টে যায়নি বলে নিচে কিছুই বদলায় না,
         * তাই বার্তাটাই একমাত্র চিহ্ন।
         */
        freeWarning: '',
        customers,
        vatEnabled,
        term: '',
        pickerOpen: false,
        picked: null,
        showCosting: false,
        /*
         * ⚠️ `gifts` এন্ট্রির **ভেতরে**, আলাদা তালিকা নয়।
         *
         * ── কেন (৩ সেপ্টেম্বর ২০২৬) ──────────────────────────
         * আগে উপহারগুলো একটা আলাদা `gifts: []` তালিকায় থাকত,
         * আর প্রতিটা উপহারে একটা "কোন পণ্যের জন্য" ড্রপডাউন
         * ছিল — অর্থাৎ **উপহার আর পণ্যের সম্পর্কটা ছিল একটা
         * বাছাই, যেটা মানুষ ভুল করতে পারত বা খালি রেখে দিত।**
         *
         * আর কোডে ওটা আগে থেকে বসানোর চেষ্টাও ছিল
         * (`againstProductId: this.picked?.id`), কিন্তু সেটা
         * **কোনোদিন কাজ করত না**: কার্টে যোগ করার আগে চাপলে
         * পণ্যটা তালিকায় নেই, আর পরে চাপলে `picked` তখন null।
         *
         * এখন উপহারটা লাইনের ভেতরেই থাকে। **সম্পর্কটা আর
         * বাছাই নয়, অবস্থান** — লাইন মুছলে উপহারও যায়, আর
         * ভুল লাইনে বসার কোনো পথই নেই।
         */
        entry: { qty: '', freeQty: '', rate: '', discountInput: '', unitId: '', gifts: [] },

        // পণ্যপ্রতি প্যাকের তালিকা — সার্ভার থেকে একবারেই
        packs,
        lines: [],

        // একবারে একটাই উপহারের ঘর খোলা থাকে
        giftDraft: null,

        /*
         * F1-এর সাহায্যের পর্দা।
         *
         * ⚠️ শর্টকাট আছে অথচ কোথাও লেখা নেই — এটাই সবচেয়ে
         * অকেজো ধরনের সুবিধা: যিনি জানেন কেবল তিনিই পান।
         * POS-এ F1 এই তালিকাটা খোলে, তাই এখানেও।
         */
        helping: false,
        /*
         * শুরুতে কে বাছা থাকে।
         *
         * ── কেন এই লাইনটা লাগল (২ সেপ্টেম্বর ২০২৬) ──────────
         * আগে এটা ছিল একটা `<select>`, আর ব্রাউজার নিজে থেকেই
         * প্রথম option-টা বেছে রাখত — অর্থাৎ ক্রেতা কখনো
         * "কেউ না" হত না, যদিও কোডে কোথাও তা লেখা ছিল না।
         *
         * `<select>`-টা চিহ্ন হয়ে যাওয়ায় ওই নীরব ডিফল্টটাও
         * চলে গেছে। যে কোম্পানিতে `sales.walkin_customer_id`
         * বসানো নেই, সেখানে `customerId` খালি থেকে যেত আর
         * চালান যেত ক্রেতা ছাড়াই। ব্রাউজারে ক্লিক করে দেখতে
         * গিয়ে ধরা পড়েছে, কোড পড়ে নয়।
         *
         * তাই ক্রমটা স্পষ্ট করে লেখা: **নগদ ক্রেতা, নাহলে
         * তালিকার প্রথমজন, নাহলে সত্যিই কেউ না** — শেষেরটা
         * তখনই, যখন কোম্পানিতে একটাও ক্রেতা নেই।
         */
        /*
         * ⚠️ কেউ আগে থেকে বাছা থাকে না — ইচ্ছাকৃতভাবে।
         *
         * ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ──────────────
         * *"Walk-in Customer default bose thakte parbe na …
         * eta POS na, eta depot/wholesale counter. Obosoi
         * dekhe nishchit hoye party select korte hobe."*
         *
         * আগে এখানে `walkinId` বসত, নাহলে **তালিকার প্রথম
         * গ্রাহকটাই** — অর্থাৎ পর্দা খুললেই একজনের নাম বসানো
         * থাকত, আর সেটা কার নাম তা কেউ বেছে দেয়নি।
         *
         * ⚠️ ফাঁকা রাখা মানে "নিশ্চিত করুন" বোতামও বন্ধ থাকে
         * ([[canConfirm]]), আর সার্ভারেও ঘরটা এখন
         * `required` — **তিন জায়গাতেই**, কারণ একটাতে ফাঁক
         * থাকলে বাকি দুইটা অর্থহীন।
         */
        customerId: '',
        customerPickerOpen: false,
        customerTerm: '',
        /* ⓘ ডিফল্ট নগদ — কাউন্টারের স্বাভাবিক অবস্থা, আর
           বাকি দেওয়াটা ব্যতিক্রম। ⚠️ ব্যতিক্রমটা সিস্টেম নিজেই
           জানে: ক্রেতা বাছলে তাঁর নিজের মেয়াদ বসে যায়। */
        creditTerm: paymentTermDefault,
        dueOn: '',
        discountInput: '',

        // পুরো কাগজের ভ্যাট-বদল — খালি মানে পণ্য অনুযায়ী
        vatMode: '',
        vatRate: '',
        expenseInput: '',
        roundingInput: '',
        roundingSign: '+',
        /*
         * ── জমা — এখন একটা নয়, একটা তালিকা ─────────────────
         *
         * মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"Add deposit-এ
         * Ref Date, Payment Method, into, Amount, Narration,
         * Add to Cart … একাধিক payment add করতে পারবে"*।
         *
         * ⚠️ `deposit` আর একটা ঘর নয়, **যোগফল** — নিচে getter।
         * ঘর রাখলে দুই জায়গায় একই সংখ্যা থাকত (তালিকা আর ঘর),
         * আর একটায় লিখলে অন্যটা জানত না।
         */
        deposits: [],

        depositDraft: {
            methodId: '', accountId: '', amount: '',
            reference: '', refDate: '', narration: '',
        },
        panel: '',
        carriers,
        carrierId: '',
        depositMethods,
        moneyAccounts,

        nextKey: 1,

        /*
         * ══ অসমাপ্ত চালান — রিফ্রেশ বা বিদ্যুৎ গেলেও থাকে ══════
         *
         * ── কোন প্রশ্ন থেকে এটা এলো (৩ সেপ্টেম্বর ২০২৬) ─────────
         * মালিক: *"ধরো আমি একটা সেল করছি, এই মুহূর্তে হঠাৎ
         * ভুলেই রিফ্রেশ চাপ পড়ে গেছে — অর্ধেক অর্ডার নেওয়ার পরে।
         * তাহলে তো পুরা কাজই শেষ।"*
         *
         * ⚠️ **তিনি ঠিক**: এতদিন সবকিছু কেবল ব্রাউজারের স্মৃতিতে
         * ছিল। ভুল করে F5, বিদ্যুৎ চলে যাওয়া, ট্যাব বন্ধ — বিশটা
         * লাইন এক মুহূর্তে শেষ, আর ক্রেতা সামনে দাঁড়িয়ে।
         *
         * ── কেন সার্ভারে নয়, ব্রাউজারে ────────────────────────
         * অসমাপ্ত চালান কোনো **নথি নয়** — ওটার নম্বর নেই, খাতায়
         * কিছু বসে না, আর কেউ ওটা রিপোর্টে খুঁজবে না। সার্ভারে
         * রাখলে একটা টেবিল, একটা নম্বর-সিরিজ আর "এই খসড়াগুলো
         * কে মুছবে" — একটা আস্ত নতুন প্রশ্ন তৈরি হত।
         *
         * ⓘ আর ব্রাউজারে রাখাটা **তাৎক্ষণিক**: প্রতিটা কীস্ট্রোকে
         * সার্ভারে গেলে কাউন্টারে দেরি হত, আর ইন্টারনেট গেলে
         * সুরক্ষাটাই কাজ করত না — অথচ ঠিক তখনই ওটা সবচেয়ে দরকার।
         *
         * ⚠️ **চাবিতে কোম্পানি আর ব্যবহারকারী দুইটাই** — এক
         * ব্রাউজারে দুইজন কর্মী পালা করে বসেন, আর একজনের অসমাপ্ত
         * চালান অন্যজনের পর্দায় ফিরে এলে **ভুল পার্টির নামে বিল**
         * হয়ে যেত।
         */
        draftKey,

        /** ফিরিয়ে আনার প্রস্তাব — খসড়া পাওয়া গেলে উপরে বার দেখায়। */
        draftFound: false,
        draftAt: '',

        /*
         * খসড়া লেখা — যা যা টাইপ করা হয়েছে, সব।
         *
         * ⚠️ **কার্ট খালি হলে খসড়া মুছে যায়**, রাখা হয় না। খালি
         * খসড়া ফিরিয়ে আনার প্রস্তাব দেওয়া মানে প্রতিদিন সকালে
         * একটা অর্থহীন প্রশ্ন করা।
         */
        saveDraft() {
            /*
             * ⚠️ প্রস্তাব পর্দায় থাকা অবস্থায় কিছু লেখা বা মোছা নয়।
             *
             * এই লাইনটা ছাড়া বাগটা নীরব ও মারাত্মক হত: পাতা খোলার
             * সাথে সাথেই `x-effect` একবার চলে, আর তখন কার্ট খালি —
             * অর্থাৎ **খসড়াটা মুছে যেত ঠিক সেই মুহূর্তে যখন
             * ব্যবহারকারীকে ফেরানোর প্রস্তাব দেখানো হচ্ছে**।
             * বোতামটা থাকত, চাপলে কিছুই ফিরত না।
             */
            if (this.draftFound) return;

            try {
                if (this.lines.length === 0) {
                    localStorage.removeItem(this.draftKey);

                    return;
                }

                localStorage.setItem(this.draftKey, JSON.stringify({
                    at: new Date().toISOString(),
                    customerId: this.customerId,
                    creditTerm: this.creditTerm,
                    dueOn: this.dueOn,
                    lines: this.lines,
                    discountInput: this.discountInput,
                    vatMode: this.vatMode,
                    vatRate: this.vatRate,
                    expenseInput: this.expenseInput,
                    roundingInput: this.roundingInput,
                    roundingSign: this.roundingSign,
                    deposits: this.deposits,
                    nextKey: this.nextKey,
                }));
            } catch (e) {
                /*
                 * ⚠️ চুপ করে থাকা ইচ্ছাকৃত।
                 *
                 * localStorage বন্ধ থাকতে পারে (ব্যক্তিগত উইন্ডো,
                 * সাইট-ডেটা বন্ধ করা ব্রাউজার), আর তখন লেখা
                 * ব্যতিক্রম ছোঁড়ে। **কিন্তু ওটা বিক্রি থামানোর
                 * কারণ নয়** — সুরক্ষাটা না পেলেও কাউন্টার চলবে।
                 */
            }
        },

        /** পাতা খোলার সময় — আছে কিনা দেখা, নিজে থেকে ফেরানো নয়। */
        lookForDraft() {
            try {
                const parked = localStorage.getItem(this.draftKey + '.pending');

                if (parked) {
                    localStorage.removeItem(this.draftKey + '.pending');

                    /*
                     * ⚠️ সার্ভার ফিরিয়ে দিয়েছে — তাই প্রশ্ন নয়,
                     * সরাসরি ফিরিয়ে আনা। ব্যবহারকারী "নতুন
                     * চালান" চাননি, তিনি **এইটাই** পাঠিয়েছিলেন।
                     */
                    if (hasErrors) {
                        this.applyDraft(parked);

                        return;
                    }
                }

                const raw = localStorage.getItem(this.draftKey);

                if (! raw) return;

                const d = JSON.parse(raw);

                if (! d || ! Array.isArray(d.lines) || d.lines.length === 0) return;

                this.draftFound = true;
                this.draftAt = d.at ? new Date(d.at).toLocaleString() : '';
            } catch (e) {
                localStorage.removeItem(this.draftKey);
            }
        },

        /*
         * ⚠️ ফিরিয়ে আনা **কেবল চাপ দিলে** — নিজে থেকে নয়।
         *
         * নিজে থেকে ফিরিয়ে আনলে সবচেয়ে বিপজ্জনক জিনিসটা ঘটত:
         * কেউ নতুন বিক্রি শুরু করতে এসে **আগের অসমাপ্ত চালানটা
         * পেয়ে যেতেন, না বুঝে** — আর তার উপরেই নতুন লাইন যোগ
         * করে নিশ্চিত করে ফেলতেন। **ভুল ক্রেতার নামে ভুল মাল।**
         */
        restoreDraft() {
            this.applyDraft(localStorage.getItem(this.draftKey));
            this.draftFound = false;
        },

        /**
         * খসড়াটা পর্দায় বসানো — কোথা থেকে এল তা জানার দরকার নেই।
         */
        applyDraft(raw) {
            try {
                const d = JSON.parse(raw || '{}');

                this.customerId = d.customerId ?? '';
                this.creditTerm = d.creditTerm ?? paymentTermDefault;
                this.dueOn = d.dueOn ?? '';
                this.lines = d.lines ?? [];
                this.discountInput = d.discountInput ?? '';
                this.vatMode = d.vatMode ?? '';
                this.vatRate = d.vatRate ?? '';
                this.expenseInput = d.expenseInput ?? d.expenseAmount ?? '';
                this.roundingInput = d.roundingInput ?? '';
                this.roundingSign = d.roundingSign ?? '+';
                this.deposits = Array.isArray(d.deposits) ? d.deposits : [];
                this.nextKey = d.nextKey ?? (this.lines.length + 1);
            } catch (e) {
                // ভাঙা খসড়া — ফেরানোর চেয়ে বাদ দেওয়াই নিরাপদ
            }
        },

        /*
         * ── সাবমিটের সময় খসড়া মোছা হয় না, সরিয়ে রাখা হয় ────
         *
         * ⚠️ মালিকের কথা (৪ সেপ্টেম্বর ২০২৬): *"এই warning-এ
         * আমার সব entry হারিয়ে গেল, এটা তো বক্সে বসার কথা"*।
         *
         * ── যা ঘটত ──────────────────────────────────────────
         * `@submit` খসড়াটা **মুছে দিত**, আর তার পরেই সার্ভার
         * চালানটা ফিরিয়ে দিলে (মজুদ কম, নম্বর নেওয়া, যা-ই হোক)
         * পাতাটা খালি হয়ে ফিরত — **বিশ লাইনের কার্ট, ক্রেতা,
         * জমা, সব শেষ**। ভুলটা এক লাইনের, দামটা এক ঘণ্টার কাজ।
         *
         * ── এখন ────────────────────────────────────────────
         * খসড়াটা `.pending`-এ সরে যায়। পাতাটা আবার **ভুলের
         * বার্তাসহ** খুললে ওটা নিজে থেকেই ফিরে আসে — প্রশ্ন
         * ছাড়াই, কারণ ব্যবহারকারী কিছু হারাতে চাননি।
         *
         * ⓘ চালানটা পাকা হলে পাতাটা আর ফেরে না (ছাপায় চলে
         * যায়), তাই পরেরবার এখানে এলে `.pending` চুপচাপ মুছে
         * যায় — পুরনো একটা বিক্রি ফিরিয়ে আনার প্রশ্নই ওঠে না।
         */
        parkDraft() {
            try {
                const raw = localStorage.getItem(this.draftKey);

                if (raw) localStorage.setItem(this.draftKey + '.pending', raw);

                localStorage.removeItem(this.draftKey);
            } catch (e) {
                // localStorage বন্ধ — তখন আগের মতোই আচরণ
            }

            this.draftFound = false;
        },

        /** প্রস্তাব ফিরিয়ে দেওয়া — খসড়াটাও সাথে যায়। */
        dropDraft() {
            try {
                localStorage.removeItem(this.draftKey);
            } catch (e) {
                // উপরের একই কারণ
            }

            this.draftFound = false;
        },

        /*
         * খোলা মাত্রই তালিকা — ক্রেতার চিহ্নের মতোই।
         *
         * ── মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬) ──────────────
         * *"customer icon e click korle zemon search button
         * open hoy, products search icon-eও zate emon hoy"*।
         *
         * ── পার্থক্যটা কোথায় ছিল ──────────────────────────────
         * দুইটাই চিহ্নে চাপলে খুলত, কিন্তু:
         *
         *     ক্রেতা  খোলা মাত্রই তালিকা — না লিখেও বাছা যায়
         *     পণ্য    টাইপ না করা পর্যন্ত **কিছুই নেই**
         *
         * ⚠️ ফলে পণ্যের চিহ্নটা চেপে মনে হত কিছুই হয়নি — ঘরটা
         * এসেছে ঠিকই, কিন্তু **নিচে শূন্য**। যিনি পণ্যের নাম
         * জানেন না, তাঁর পক্ষে শুরু করারই উপায় ছিল না।
         *
         * ⓘ ৩০-এর সীমাটা দুই জায়গাতেই এক। পুরো তালিকা আঁকলে দুই
         * হাজার পণ্যের গুদামে প্রতিটা কীস্ট্রোকে পাতা কাঁপত।
         */
        get visible() {
            if (! this.pickerOpen) return [];

            /*
             * ── ⛔ পক্ষ আগে, পণ্য পরে — মালিকের নিয়ম, ৬ সেপ্টেম্বর ২০২৬ ──
             *
             * *"ক্রেতা আগে সিলেক্ট করলে পরে প্রোডাক্ট সার্চ হবে,
             * ক্রেতা না দিলে প্রোডাক্ট শো করবে না।"*
             *
             * ── কেন এটা কেবল ক্রম নয় ─────────────────────────
             * ⚠️ **দর ক্রেতাভেদে আলাদা** — একজনের দর তালিকা আরেকজনের চেয়ে আলাদা, আর বকেয়ার সীমাও। পণ্য আগে বাছলে
             * পর্দা এমন একটা দর বসাত যেটা এখনো জানা যায়নি কার
             * জন্য, আর ক্রেতা বাছার পর সেটা **নীরবে ভুল** হয়ে থাকত।
             *
             * ⓘ কার্টে লাইন বসানোর পর ক্রেতা বদলালে ঐ দরগুলো আর
             * নিজে থেকে ঠিক হয় না — অর্থাৎ ভুলটা কাগজ পর্যন্ত যেত।
             *
             * ⭐ তাই তালিকাটা খালি থাকে, আর নিচের বার্তাটা বলে দেয়
             * **কী করতে হবে** — শুধু "পারবেন না" নয় (নিয়ম ১)।
             */
            if (! this.customerId) return [];


            const t = this.term.trim().toLowerCase();

            if (t === '') return this.catalogue.slice(0, 30);

            // ⓘ দুইটা নামই — গ্রাহকের ঘরের একই কারণে (৭ সেপ্টেম্বর ২০২৬)
            return this.catalogue.filter(p =>
                p.name.toLowerCase().includes(t)
                || (p.name_en || '').toLowerCase().includes(t)
                || (p.name_bn || '').toLowerCase().includes(t)
                || p.code.toLowerCase().includes(t)
                || (p.barcode || '').toLowerCase().includes(t)
            ).slice(0, 30);
        },

        get customer() {
            return this.customers[this.customerId]
                || { limit: 0, due: 0, days: 0, name: '', phone: '', address: '', location: '' };
        },

        /*
         * চালান নিশ্চিত করা যাবে কখন।
         *
         * ── কেন দুইটা শর্ত, একটা নয় ─────────────────────────
         * আগে কেবল **কার্ট খালি কিনা** দেখা হত, কারণ ক্রেতা
         * সবসময় আগে থেকেই বসানো থাকত। ডিফল্ট তুলে দেওয়ায়
         * শর্তটা অসম্পূর্ণ হয়ে গেছে: **মাল আছে অথচ কার নামে
         * তা কেউ বলেনি** — এমন চালান আর সম্ভব নয়।
         *
         * ⚠️ সার্ভারও `required` দেখে, আর সেটাই আসল পাহারা।
         * এই লাইনটা কেবল **বোতামটা মিথ্যা না বলার জন্য** —
         * চাপা যায় অথচ কিছু হয় না, ওটাই সবচেয়ে বিরক্তিকর।
         */
        /*
         * বাছা পণ্যটার প্যাকগুলো।
         *
         * ⚠️ খালি অ্যারে মানে দুইটা আলাদা কথা, আর দুইটাতেই ঘরটা
         * পড়ার-জন্য থাকে — তাই আলাদা করার দরকার নেই:
         *     • কন্ট্রোল প্যানেলের সুইচ বন্ধ
         *     • পণ্যটার একটাই একক
         */
        get entryUnits() {
            if (! this.picked) return [];

            return this.packs[this.picked.id] ?? [];
        },

        /*
         * কাজের প্যানেল খোলা ও বন্ধ — একবারে একটাই।
         *
         * ── কেন খোলার সাথে পর্দা নামে (৩ সেপ্টেম্বর ২০২৬) ─────
         * বোতামগুলো **ডান পাশে**, আর প্যানেলটা খোলে **কার্টের
         * নিচে** — অর্থাৎ চাপ এক জায়গায়, ফল আরেক জায়গায়।
         *
         * ⚠️ বিশ লাইনের কার্টে ওটা পর্দার বাইরে পড়ত, আর চেপে
         * মনে হত **কিছুই হয়নি** — তারপর আবার চাপলে প্যানেলটা
         * বন্ধ হয়ে যেত। ঠিক এই ফাঁদেই আজ Action মেনুটা পড়েছিল।
         *
         * ⓘ `block: 'nearest'` — ইতিমধ্যেই দেখা যাচ্ছে এমন হলে
         * পর্দা নড়ে না। অকারণ লাফানো নিজেই একটা বিরক্তি।
         */
        openPanel(name) {
            this.panel = this.panel === name ? '' : name;

            if (! this.panel) return;

            this.$nextTick(() => this.$refs.actionPanel?.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
            }));
        },

        get canConfirm() {
            return this.lines.length > 0 && this.customerId !== '';
        },

        /*
         * ক্রেতা খোঁজা — নাম, কোড আর ফোন, তিনটাতেই।
         *
         * ফোনটা ইচ্ছাকৃত: কাউন্টারে অনেক সময় দোকানের নাম
         * মনে থাকে না, নম্বরটা থাকে — ফোনেই তো অর্ডারটা
         * এসেছিল।
         *
         * ⚠️ খালি লেখায় **পুরো তালিকা** দেখানো হয়, প্রথম
         * ত্রিশটা। চিহ্নে চাপ দিয়ে কেউ যদি কিছু না লেখেন,
         * একটা ফাঁকা প্যানেল দেখে তিনি ভাববেন কোনো গ্রাহকই
         * নেই — অথচ তিনি কেবল এখনো কিছু টাইপ করেননি।
         */
        get customerMatches() {
            const t = this.customerTerm.trim().toLowerCase();

            const rows = Object.entries(this.customers)
                .map(([id, c]) => ({ id, ...c }));

            /*
             * ⓘ দুইটা নামই দেখা হয় — ৭ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ শুধু `name` (চলতি ভাষার নাম) দেখলে ইংরেজিতে
             * টাইপ করা ক্যাশিয়ার নিজের গ্রাহককেই খুঁজে পেতেন
             * না, আর পর্দা বলত "ওই নামে কোনো গ্রাহক নেই"।
             */
            return (t === '' ? rows : rows.filter(c =>
                (c.name || '').toLowerCase().includes(t)
                || (c.name_en || '').toLowerCase().includes(t)
                || (c.name_bn || '').toLowerCase().includes(t)
                || (c.code || '').toLowerCase().includes(t)
                || (c.phone || '').toLowerCase().includes(t)
            )).slice(0, 30);
        },

        /*
         * শর্ত বদলালে তারিখের ঘরটা মেলানো।
         *
         * "নির্দিষ্ট তারিখ" ছাড়া বাকি সব বিকল্পে তারিখটা দিন
         * থেকে গোনা হয়, আর সার্ভারেও তাই — তাই ঘরটা খালি করে
         * দেওয়া হয়, নাহলে আগের বাছাইয়ের একটা বাসি তারিখ
         * ফর্মের সাথে চলে যেত।
         *
         * ⚠️ গোনা শুরু হয় **বিলের তারিখ থেকে**, আজ থেকে নয় —
         * ব্যাক-ডেটেড বিলে দুইটা আলাদা, আর আজ থেকে গুনলে
         * মেয়াদটা ভুল দিনে পড়ত।
         */
        /* ⓘ ঘরটার একটাই মান: `cash` · `cod` · `credit:30` ·
           `month_end` · `fixed`। ⚠️ কোলনের পরের সংখ্যাটা কেবল
           `credit`-এ, আর সার্ভারে যায় কোলনের **আগের** অংশটা
           ধরন হিসেবে, পরেরটা দিনসংখ্যা হিসেবে। */
        get termKind() {
            return String(this.creditTerm || '').split(':')[0];
        },

        get termDays() {
            return String(this.creditTerm || '').split(':')[1] || '';
        },

        termChanged() {
            if (this.termKind === 'fixed') {
                /* ⚠️ আগের হিসাব করা তারিখটা মুছে দেওয়া হয়,
                   নাহলে মানুষটা তারিখের ঘরে একটা **আগের
                   হিসাবের** তারিখ বসা দেখতেন আর ভাবতেন
                   তিনিই বসিয়েছেন। */
                this.dueOn = '';
                this.pushDueDate();

                return;
            }

            const el = this.$root.querySelector('input[name=trx_date]');
            const from = el && el.value
                ? new Date(el.value + 'T00:00:00')
                : new Date();

            if (Number.isNaN(from.getTime())) {
                this.dueOn = '';
                this.pushDueDate();

                return;
            }

            if (this.termKind === 'month_end') {
                /*
                 * ⭐ চালানের **মাসের** শেষ দিন।
                 *
                 * ⚠️ `new Date(y, m + 1, 0)` মানে "পরের মাসের
                 * শূন্যতম দিন", অর্থাৎ চলতি মাসের শেষ দিন।
                 * ⛔ `+30 দিন` গুনলে ফেব্রুয়ারিতে মার্চে গিয়ে
                 * পড়ত, আর লিপ ইয়ারে আরও একদিন।
                 */
                this.dueOn = this.isoDay(
                    new Date(from.getFullYear(), from.getMonth() + 1, 0)
                );
                this.pushDueDate();

                return;
            }

            /*
             * ⭐ নগদ আর COD — দুইটার তারিখই চালানের দিন।
             *
             * ⚠️ তবু দুইটা এক নয়, আর পার্থক্যটা টাকার ঘরে:
             * নগদে টাকা ড্রয়ারে ঢুকেছে, COD-তে মাল ভ্যানে
             * গেছে আর টাকা ফিরবে ডেলিভারিম্যানের সাথে।
             * ⓘ তাই ধরনটা আলাদা করে সার্ভারে যায়।
             */
            const days = this.termKind === 'credit'
                ? Number(this.termDays || 0)
                : 0;

            from.setDate(from.getDate() + days);
            this.dueOn = this.isoDay(from);
            this.pushDueDate();
        },

        /**
         * তারিখটা `YYYY-MM-DD` হয়ে।
         *
         * ⚠️ `toISOString()` নয় — ওটা UTC-তে নামায়, আর
         * বাংলাদেশে সন্ধ্যার পর তারিখটা একদিন পিছিয়ে যেত।
         * ⓘ পুরনো কোডে ঠিক ওটাই ছিল।
         */
        isoDay(d) {
            return [
                d.getFullYear(),
                String(d.getMonth() + 1).padStart(2, '0'),
                String(d.getDate()).padStart(2, '0'),
            ].join('-');
        },

        /*
         * তারিখটা কম্পোনেন্টের ভেতরে বসানো।
         *
         * ── কেন সরাসরি `x-model` নয় ─────────────────────────
         * ঘরটা আর ব্রাউজারের নিজের তারিখের ঘর নয় — সে
         * ওটার লেখা নিজের লোকেলে আঁকত, আর `05/06` তখন ৫ জুন
         * না ৬ মে বলার উপায় থাকত না (`x-ui.date` ঠিক এই
         * কারণেই আছে)। কম্পোনেন্টটার নিজের Alpine স্কোপ আছে,
         * তাই বাইরের `x-model` ওখানে পৌঁছায় না।
         *
         * ⓘ `$refs.dueDate` লেখার ঘরটাকে ধরে, আর সেটা
         * কম্পোনেন্টের স্কোপের **ভিতরে** — তাই `Alpine.$data()`
         * ওই স্কোপটাই ফেরত দেয়। দুইটা ঘর বসাতে হয়: `iso`
         * সার্ভারের জন্য, `text` চোখের জন্য।
         */
        pushDueDate() {
            const el = this.dateBox('due_on');

            if (! el || ! window.Alpine) return;

            const box = window.Alpine.$data(el);

            box.iso = this.dueOn;
            box.text = this.dueOn
                ? this.dueOn.split('-').reverse().join('-')
                : '';
        },

        chooseCustomer(id) {
            this.customerId = String(id);

            /*
             * ক্রেতার নিজের মেয়াদ থাকলে সেটাই বসে।
             *
             * ডিফল্ট "আজ" কাউন্টারের স্বাভাবিক অবস্থা, কিন্তু
             * যে দোকানের সাথে ৩০ দিনের কথা আছে তাঁর বেলায় ওটা
             * ব্যতিক্রম — আর ব্যতিক্রমটা সিস্টেম নিজেই জানে।
             * প্রতিবার হাতে বাছতে দেওয়া মানে একদিন কেউ ভুলে
             * যাবেন, আর নগদ বলে বসে থাকা একটা বাকি বিল
             * কাউকে তাগাদা দেওয়া হবে না।
             *
             * তালিকায় ওই দিনসংখ্যা না থাকলে বদলানো হয় না —
             * নাহলে ড্রপডাউনটা এমন একটা মান দেখাত যা তার
             * নিজের বিকল্পে নেই, আর ঘরটা ফাঁকা দেখাত।
             */
            /*
             * ⚠️ মানটা এখন `credit:30`, শুধু `30` নয় — ৫
             * সেপ্টেম্বর ২০২৬ থেকে ঘরটা ধরন বোঝে।
             *
             * ⛔ পুরনো লেখাটা রয়ে গেলে ড্রপডাউনে এমন একটা মান
             * বসত যা তার নিজের কোনো বিকল্পে নেই, আর ঘরটা
             * **ফাঁকা** দেখাত — ক্রেতা বাছার ঠিক পরমুহূর্তে।
             */
            const days = Number(this.customers[this.customerId]?.days || 0);
            const want = 'credit:' + days;
            const has = [...this.$root.querySelectorAll('select option')]
                .some(o => o.value === want);

            if (days > 0 && has) {
                this.creditTerm = want;
                this.dueOn = '';
                this.termChanged();
            }
            this.customerTerm = '';
            this.customerPickerOpen = false;
        },

        pickFirstCustomer() {
            const first = this.customerMatches[0];
            if (first) this.chooseCustomer(first.id);
        },

        /*
         * চিহ্নে চাপলে ঘরটা খোলে, আর সাথে সাথেই ফোকাস।
         *
         * `$nextTick` ছাড়া চলত না: `x-show` ঘরটাকে ওই মুহূর্তে
         * এখনো `display:none`-এ রাখে, আর লুকানো ঘরে ফোকাস দিলে
         * ব্রাউজার নীরবে কিছুই করে না — বোতামে চাপ দিয়ে মানুষ
         * টাইপ শুরু করতেন আর কোথাও কিছু বসত না।
         */
        openPicker() {
            this.pickerOpen = true;
            this.term = '';
            this.$nextTick(() => this.$refs.search?.focus());
        },

        /*
         * ── মজুদ নেই এমন পণ্য বাছা যায় না ────────────────────
         *
         * মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬): *"kono product
         * stock na thakle poriborton hobe na, ekta warning sound
         * dibe, color lal hobe, screen-er majkhane boro kore
         * notice dibe"*।
         *
         * ── কেন থামানোই ঠিক, সতর্ক করে এগোনো নয় ─────────────
         * পণ্যটা বসতে দিলে কাউন্টারের লোক পরিমাণ-দর টাইপ করে
         * কার্টে ফেলতেন, আর ভুলটা ধরা পড়ত **সবার শেষে, নিশ্চিত
         * করার সময়** — তখন লাইনটা মুছে আবার শুরু।
         *
         * ⚠️ আর ওই মুহূর্তে ক্রেতা সামনে দাঁড়িয়ে। **যত আগে "না"
         * বলা যায়, তত কম কাজ নষ্ট হয়।**
         */
        outOfStock: null,

        pick(product) {
            if (! (Number(product.available) > 0)) {
                this.refuse(product);

                return;
            }

            this.picked = product;
            this.entry.rate = product.rate;
            this.entry.qty = this.entry.qty || '1';
            this.term = '';
            // বাছা হয়ে গেছে — তালিকাটা আর কিছু বলার নেই
            this.pickerOpen = false;
        },

        refuse(product) {
            this.outOfStock = product;
            this.beep();
        },

        /*
         * সতর্কতার শব্দ — কোনো ফাইল নয়, ব্রাউজারই বাজায়।
         *
         * ── কেন Web Audio, কেন mp3 নয় ───────────────────────
         * একটা শব্দের ফাইল মানে একটা সম্পদ: লাইসেন্স, আকার, আর
         * ডিপ্লয়ে ওটা সত্যিই গেল কিনা তার একটা নতুন প্রশ্ন।
         * ⭐ **মালিকের নিয়ম — কোনো paid tool নয়** — আর এখানে
         * ফাইলই লাগে না: ব্রাউজার নিজেই সুর বানাতে পারে।
         *
         * ⚠️ ব্রাউজার ব্যবহারকারীর প্রথম ক্লিকের আগে শব্দ বাজাতে
         * দেয় না। কাউন্টারে পণ্য বাছতে গেলে ক্লিক হয়েই যায়, তাই
         * বাস্তবে সমস্যা নেই — তবু `try` দিয়ে ঘেরা, কারণ **শব্দ
         * না বাজলেও বিক্রি থামা চলবে না।**
         */
        beep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();

                osc.type = 'square';
                osc.frequency.value = 440;
                gain.gain.value = 0.08;

                osc.connect(gain).connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.18);
                osc.onended = () => ctx.close();
            } catch (e) {
                // শব্দ বাজেনি — লাল বাক্স আর নোটিশ তবু আছে
            }
        },

        pickFirst() {
            const first = this.visible[0];
            if (first) this.pick(first);
        },

        // ── চলতি লাইনের অঙ্ক ────────────────────────────────
        get entryBase() {
            return (Number(this.entry.qty) || 0) * (Number(this.entry.rate) || 0);
        },

        get entryAfterDiscount() {
            return this.entryBase
                - this.entryDiscount;
        },

        /*
         * লাইনের ছাড় — টাকায় বা শতাংশে, একটাই ঘর।
         *
         * ── কেন একটাই (৩ সেপ্টেম্বর ২০২৬) ────────────────────
         * আগে ছাড়ের ঘরটা বাঁয়ের ফর্মে ছিল ("ছাড় %"), আর অঙ্কটা
         * দেখা যেত না। মালিক ঘরগুলো এক সারিতে নামিয়ে বাকিগুলো
         * তুলে দিতে বললেন — *"this line e egulo ache, dubar
         * dorkar nai"* — তাই ছাড়টা এখন কেবল "এই লাইন" প্যানেলে,
         * আর **লেখা ও ফল পাশাপাশি**।
         *
         * শেষে `%` থাকলে শতাংশ, নাহলে সোজা টাকা।
         *
         * ⚠️ ছাড় লাইনের মোটের চেয়ে বড় হতে দেওয়া হয় না — দিলে
         * লাইনটা ঋণাত্মক হত, আর তখন **বিক্রির কাগজে একটা সারি
         * গ্রাহককে টাকা ফেরত দিত**।
         */
        get entryDiscount() {
            const raw = String(this.entry.discountInput || '').trim();

            if (raw === '') return 0;

            const isPercent = raw.endsWith('%');
            const n = Number(raw.replace('%', '').trim());

            if (! isFinite(n) || n <= 0) return 0;

            const value = isPercent ? this.entryBase * n / 100 : n;

            return Math.min(value, this.entryBase);
        },

        /*
         * সার্ভার লাইনের ছাড় **শতাংশে** নেয়, তাই টাকায় লেখা হলে
         * এখানে ফিরিয়ে দেওয়া হয়।
         *
         * ⚠️ ভিত্তি শূন্য হলে শতাংশ বের করা যায় না (কোনো দর বা
         * পরিমাণ বসানো হয়নি) — তখন ০, কারণ ছাড় বলে কিছু নেই।
         */
        get entryDiscountPercent() {
            if (this.entryBase <= 0) return '';

            return String(this.entryDiscount * 100 / this.entryBase);
        },

        get entryVat() {
            if (! this.vatEnabled || ! this.picked) return 0;
            return this.vatOn(this.entryAfterDiscount, this.picked);
        },

        get entryNet() {
            return this.isInclusive(this.picked)
                ? this.entryAfterDiscount
                : this.entryAfterDiscount + this.entryVat;
        },

        /* বিক্রয় + ফ্রি — গুদাম থেকে মোট যতটা বেরোবে।
           নমুনায় ঘরটা নিজে থেকেই ভরে, হাতে লেখা যায় না। */
        get entryTotalQty() {
            return (Number(this.entry.qty) || 0) + (Number(this.entry.freeQty) || 0);
        },

        /*
         * শিট থেকে আসা সারিগুলো কার্টে।
         *
         * ── কেন ইভেন্ট, সরাসরি ডাকা নয় ────────────────────
         * শিটটা জানে না কে শুনছে — তাই একই শিট চালানে,
         * সরাসরি বিক্রয়ে আর ক্রয়েও বসে। তিনটা কপি থাকলে
         * একদিন একটার অঙ্ক বদলাত, বাকি দুইটা থাকত।
         *
         * ── একই পণ্য দুইবার এলে সারি বাড়ে না ──────────────
         * পরিমাণ বাড়ে। নাহলে এক পণ্যে দুই সারি, আর কাগজে
         * একই নাম দুইবার।
         */
        absorbBulk(rows) {
            (rows || []).forEach(row => {
                const p = this.catalogue.find(c => String(c.id) === String(row.product_id));
                if (! p) { return; }

                const already = this.lines.find(l => l.id === p.id);

                if (already) {
                    already.qty = String((parseFloat(already.qty) || 0)
                        + (parseFloat(row.qty) || 0));
                    already.freeQty = String((parseFloat(already.freeQty) || 0)
                        + (parseFloat(row.free_qty) || 0)) || '';

                    return;
                }

                this.lines.push({
                    key: this.nextKey++,
                    id: p.id,
                    name: p.name,
                    unit: p.unit,
                    vatRate: p.vatRate || 0,
                    vatInclusive: !! p.vatInclusive,
                    qty: String(row.qty || '0'),
                    freeQty: row.free_qty || '',
                    rate: String(row.rate || p.rate || 0),
                    discountPercent: '',
                    gifts: [],
                });
            });

            this.panel = '';
        },

        /*
         * সারিটা কার্টে যাওয়ার আগে ফ্রি-র হিসাব মিলিয়ে নেওয়া।
         *
         * ── ⭐ মালিকের নির্দেশ, ২৪ সেপ্টেম্বর ২০২৬ ────────────────────
         * *"অনুপাতের বেশি ফ্রি দিলে বিল প্রডাক্ট এন্টিতেই আটকে যাবে,
         * কার্টে যোগ হবে না আর ওয়ার্নিং দিবে ফ্রি এতটা দেওয়া যাবে"*।
         *
         * ── ⚠️ কেন বিল শেষ হওয়ার পর নয় ─────────────────────────────
         * ⓘ আগে ভুলটা ধরা পড়ত সেভ করার সময়, অর্থাৎ ত্রিশটা সারি তোলার
         * পরে। ⛔ তখন কোন সারিটা দোষী তা খুঁজতে হত, আর সংখ্যাটা কত
         * হলে চলত সেটা কেউ বলত না।
         *
         * ── ⛔ এটা দেয়াল নয়, দেয়ালটা কোথায় তা আগে বলা ────────────────
         * ⚠️ আসল দেয়াল সেবায় ([[DirectSaleService]])। ⓘ শুধু পর্দায়
         * আটকালে অন্য পথে আসা বিল — কাউন্টার, আদেশ, কালকের নতুন পর্দা —
         * প্রতিটাই একটা করে ফাঁক হত।
         */
        async addToCart() {
            if (! this.picked) return;

            if (! await this.freeFitsTheRatio()) return;

            this.lines.push({
                key: this.nextKey++,
                id: this.picked.id,
                name: this.picked.name,
                unit: this.picked.unit,
                vatRate: this.picked.vatRate || 0,
                vatInclusive: !! this.picked.vatInclusive,
                qty: this.entry.qty || '1',
                freeQty: this.entry.freeQty || '',
                rate: this.entry.rate || '0',
                discountPercent: this.entryDiscountPercent,
                unitId: this.entry.unitId || '',
                gifts: this.entry.gifts,
            });

            this.clearEntry();
            this.$nextTick(() => this.$refs.search.focus());
        },

        /**
         * এই সারির ফ্রি-টা অনুপাতে ধরে কি না।
         *
         * ⓘ ফ্রি না দিলে প্রশ্নটাই ওঠে না — তখন সার্ভারকে ডাকা হয় না,
         * আর রোজকার বিক্রিতে একটাও বাড়তি অনুরোধ যায় না।
         *
         * ⚠️ উত্তর না পেলে সারিটা **আটকানো হয় না**। ⓘ নেটওয়ার্ক পড়ে
         * গেলে কাউন্টার বন্ধ হয়ে যাওয়ার চেয়ে সারিটা যাওয়া ভালো —
         * ⛔ আসল দেয়াল সেবায়, আর সে ঠিকই ধরবে।
         */
        async freeFitsTheRatio() {
            this.freeWarning = '';

            const free = this.$num(this.entry.freeQty);
            const qty = this.$num(this.entry.qty || '1');

            if (! (free > 0) || ! (qty > 0)) return true;

            try {
                const url = new URL(this.freeAllowedUrl, window.location.origin);
                url.searchParams.set('product_id', this.picked.id);
                url.searchParams.set('qty', qty);

                if (this.warehouseId) url.searchParams.set('warehouse_id', this.warehouseId);

                const answer = await fetch(url, { headers: { Accept: 'application/json' } });

                if (! answer.ok) return true;

                const { data } = await answer.json();

                if (! data || ! data.known) return true;

                const allowed = this.$num(data.allowed);

                if (free <= allowed) return true;

                /*
                 * ⓘ বার্তাটা সংখ্যাটাই বলে — "বেশি হয়ে গেছে" নয়।
                 *
                 * ⛔ সীমাটা না বললে মানুষ কমাতে কমাতে চেষ্টা করতেন, আর
                 * প্রতিবার একটা করে অনুরোধ যেত।
                 */
                this.freeWarning = texts.freeBeyondRatio.replace(':allowed', this.qty(allowed));

                return false;
            } catch (e) {
                return true;
            }
        },

        clearEntry() {
            this.picked = null;
            this.entry = { qty: '', freeQty: '', rate: '', discountInput: '', unitId: '', gifts: [] };
            this.giftDraft = null;
            this.term = '';
            this.showCosting = false;
        },

        clearAll() {
            this.lines = [];
            this.discountInput = '';
            this.expenseInput = '';
            this.roundingInput = '';
            this.deposits = [];
            this.depositDraft = {
                methodId: '', accountId: '', amount: '',
                reference: '', refDate: '', narration: '',
            };
            this.clearEntry();

            /*
             * ⚠️ সংরক্ষিত খসড়াও যায় — মালিকের নির্দেশ:
             * *"এরপর না লাগলে ক্লিয়ার ডাটা দিলে যেন মুছে যায়"*।
             *
             * না মুছলে "সব মুছুন" একটা মিথ্যা হয়ে যেত: পর্দা
             * খালি দেখাত, অথচ পরের বার পাতা খুললেই **মুছে ফেলা
             * চালানটা ফিরে আসার প্রস্তাব** আসত।
             */
            this.dropDraft();

            /*
             * ── ⛔ ক্রেতা আর কাগজের ঘরগুলোও যায় ─────────────────
             *
             * মালিক, ৬ সেপ্টেম্বর ২০২৬: *"ei buton gulo duti ache,
             * ekto kaj kore na"*।
             *
             * ⚠️ চালিয়ে দেখা গেল দুইটাই চলে — কিন্তু "সব মুছুন"
             * চাপার পর **ক্রেতা রয়ে যেত**, আর সাথে শর্ত ও DO।
             * ⓘ পর্দাটা তখনো ভরা দেখাত, তাই বোতামটা "কাজ করেনি"
             * মনে হত — আর মালিকের পাঠটাই সঠিক পাঠ: বোতামের নাম
             * "সব মুছুন", তাই সব মুছতে হবে।
             *
             * ⛔ আর ক্ষতিটা কেবল চোখের নয়: পরের ক্রেতার মাল
             * আগের ক্রেতার নামে বসে যেত, কারণ কেউ খেয়াল করতেন
             * না যে নামটা বদলায়নি।
             *
             * ⓘ তারিখ আর বিল নম্বর **থাকে** — ওদুটো কাগজের
             * পরিচয়, চালানের বিষয়বস্তু নয়। ⚠️ নম্বরটা মুছলে
             * সিরিজ থেকে আরেকটা তুলতে হত, আর সেটা ফাঁক তৈরি করত।
             */
            this.customerId = '';
            this.customerTerm = '';
            this.customerPickerOpen = false;
            this.creditTerm = paymentTermDefault;
            this.dueOn = '';
            this.termChanged();

            const doNo = this.$root.querySelector('[name=do_no]');
            if (doNo) doNo.value = '';
        },

        /*
         * ── উপহার — যে পণ্যটা এখন হাতে, তার সাথেই ─────────────
         *
         * বোতামটা পণ্য না বাছা পর্যন্ত নিষ্ক্রিয়। কারণ উপহার
         * সবসময় **কোনো একটা পণ্যের সাথে** যায়, আর কোনটার সাথে
         * সেটা এখানে জিজ্ঞেস করা হয় না — যেটা হাতে আছে, সেটাই।
         */
        openGift() {
            if (! this.picked) return;

            this.giftDraft = this.giftDraft
                ? null
                : { productId: '', qty: '1', remarks: texts.notForSales };
        },

        commitGift() {
            const g = this.giftDraft;

            if (! g || ! g.productId || ! (Number(g.qty) > 0)) return;

            this.entry.gifts.push({ key: this.nextKey++, ...g });
            this.giftDraft = null;
        },

        removeGift(lineIndex, giftIndex) {
            this.lines[lineIndex].gifts.splice(giftIndex, 1);
        },

        /*
         * সব লাইনের উপহার একসাথে — গোনার জন্য।
         *
         * ⚠️ এন্ট্রির উপহারগুলো এখানে নেই, ইচ্ছে করে: ওগুলো
         * এখনো কার্টে যায়নি, তাই যোগফলেও যাওয়ার কথা নয়।
         */
        get allGifts() {
            return this.lines.flatMap((line) => line.gifts || []);
        },

        /*
         * সার্ভারে যা যায়।
         *
         * ⭐ `against_product_id` কোনো ড্রপডাউন থেকে আসে না —
         * **উপহারটা যে লাইনের ভেতরে বসে আছে, সেই লাইনের পণ্য**।
         * তাই ওটা খালি থাকতে পারে না, আর ভুলও হতে পারে না।
         */
        get payloadGifts() {
            return this.lines.flatMap((line) =>
                (line.gifts || [])
                    .filter((g) => g.productId && Number(g.qty) > 0)
                    .map((g) => ({
                        productId: g.productId,
                        againstProductId: line.id,
                        qty: g.qty,
                        remarks: g.remarks,
                    })),
            );
        },

        // ── কার্টের অঙ্ক ────────────────────────────────────
        lineBase(line) {
            return (Number(line.qty) || 0) * (Number(line.rate) || 0);
        },

        lineAfterDiscount(line) {
            return this.lineBase(line)
                - this.lineBase(line) * (Number(line.discountPercent) || 0) / 100;
        },

        /*
         * ভ্যাট — সার্ভারের নিয়মেই, দুই রকম।
         *
         * বাইরের ভ্যাট দরের উপরে বসে; ভেতরের ভ্যাট দরের ভেতরেই
         * আছে, তাই ওটা মোট বাড়ায় না — কেবল কতটুকু কর তা আলাদা
         * করে দেখায়। আগে দুইটাই যোগ করা হত, আর ভেতরের বেলায়
         * পর্দার সংখ্যা বিলের চেয়ে বেশি দেখাত।
         *
         * অঙ্কটা এখানে কেবল **দেখানোর** জন্য; খাতায় যেটা বসে
         * সেটা সার্ভার নিজে গোনে (CalculatesSalesLines)।
         */
        vatOn(net, item) {
            if (! this.vatEnabled || ! item) return 0;

            /*
             * পুরো কাগজের জন্য বদল — থাকলে পণ্যের নিজের হার ও
             * ধরন দুইটাই এটাই ঢেকে দেয় (৩ সেপ্টেম্বর ২০২৬)।
             *
             * ⚠️ **ধরনটাও বদলায়, শুধু হার নয়।** কেউ "ভ্যাট সহ"
             * বাছলে দরটা এখন ভ্যাট-সহ ধরা হবে — পণ্যটা নিজে
             * "ভ্যাট বাদে" বলা থাকলেও। ওটাই তো বদলের মানে।
             */
            if (this.vatMode === 'exempt') return 0;

            const override = this.vatMode === 'exclusive' || this.vatMode === 'inclusive';
            const rate = (override ? Number(this.vatRate || 0) : Number(item.vatRate || 0)) / 100;

            if (! rate) return 0;

            const inclusive = override ? this.vatMode === 'inclusive' : !! item.vatInclusive;

            return inclusive
                ? net - (net / (1 + rate))
                : net * rate;
        },

        /*
         * দরটা ভ্যাট-সহ কিনা — লাইনের মোট গুনতে লাগে।
         *
         * ⚠️ আগে সরাসরি `line.vatInclusive` পড়া হত। পুরো কাগজের
         * বদল এলে সেটা মিথ্যা বলত: ভ্যাট গোনা হত নতুন নিয়মে,
         * আর মোট গোনা হত পুরনো নিয়মে — **দুইটা সংখ্যা মিলত না**।
         */
        isInclusive(item) {
            if (this.vatMode === 'exempt') return false;

            if (this.vatMode === 'exclusive') return false;
            if (this.vatMode === 'inclusive') return true;

            return !! (item && item.vatInclusive);
        },

        lineVat(line) {
            return this.vatOn(this.lineAfterDiscount(line), line);
        },

        lineNet(line) {
            return this.isInclusive(line)
                ? this.lineAfterDiscount(line)
                : this.lineAfterDiscount(line) + this.lineVat(line);
        },

        get subTotal() {
            return this.lines.reduce((s, l) => s + this.lineAfterDiscount(l), 0);
        },

        get vatTotal() {
            return this.lines.reduce((s, l) => s + this.lineVat(l), 0);
        },

        /*
         * সারির নিট যোগ করা, subTotal + vatTotal নয়।
         *
         * ভেতরের ভ্যাটে দ্বিতীয় হিসাবটা দুইবার কর যোগ করত।
         * সারি ধরে গুনলে দুই ধরনের ভ্যাট একসাথে থাকা বিলেও
         * সংখ্যাটা ঠিক থাকে।
         */
        get grossTotal() {
            return this.lines.reduce((s, l) => s + this.lineNet(l), 0);
        },

        /*
         * ছাড় — টাকা, নাকি শতাংশ?
         *
         * ── নিয়ম ────────────────────────────────────────────
         * শেষে `%` থাকলে শতাংশ, নাহলে সোজা টাকা। **এই একটাই
         * getter সবখানে** — পর্দার সংখ্যা, দিতে হবে, আর
         * সার্ভারে যাওয়া লুকানো ঘর, তিনটাই এখান থেকে।
         *
         * ⚠️ শতাংশটা **মোট বিলের উপরে** বসে, কোনো একটা লাইনের
         * উপরে নয় — লাইনের নিজের ছাড় আলাদা ঘরে, কার্টে।
         *
         * ⚠️ ছাড় বিলের চেয়ে বড় হতে দেওয়া হয় না। দিলে "দিতে
         * হবে" ঋণাত্মক হত, আর তখন চালানটা **গ্রাহককে টাকা
         * ফেরত দেওয়ার কাগজ** হয়ে যেত — যেটা সম্পূর্ণ আলাদা
         * জিনিস (বিক্রয় ফেরত), আর তার নিজের পর্দা আছে।
         */
        get discountValue() {
            const raw = String(this.discountInput || '').trim();

            if (raw === '') return 0;

            const isPercent = raw.endsWith('%');
            const n = Number(raw.replace('%', '').trim());

            if (! isFinite(n) || n <= 0) return 0;

            const value = isPercent ? this.grossTotal * n / 100 : n;

            return Math.min(value, this.grossTotal);
        },

        /*
         * খরচ — টাকা, নাকি চালানের শতাংশ।
         *
         * ছাড়ের সাথে হুবহু এক নিয়ম, ইচ্ছে করেই: পাশাপাশি বসা
         * দুইটা ঘর আলাদা নিয়মে চললে **সেটাই পরের ভুলের কারণ**।
         *
         * ⚠️ একটা জায়গায় ছাড়ের সাথে মেলে না — খরচে উপরের
         * সীমা নেই। ছাড় বিলের চেয়ে বড় হলে কাগজটা উল্টে যায়
         * (ফেরতের কাগজ), কিন্তু খরচ বড় হওয়া অস্বাভাবিক হলেও
         * **অসম্ভব নয়** — সামান্য মালের দূরের ডেলিভারিতে ভাড়াই
         * বেশি পড়তে পারে, আর ওটা সত্যি ঘটনা, ভুল নয়।
         */
        get expenseValue() {
            const raw = String(this.expenseInput || '').trim();

            if (raw === '') return 0;

            const isPercent = raw.endsWith('%');
            const n = Number(raw.replace('%', '').trim());

            if (! isFinite(n) || n <= 0) return 0;

            return isPercent ? this.grossTotal * n / 100 : n;
        },

        /*
         * রাউন্ডিং — চিহ্নসহ একটাই সংখ্যা।
         *
         * ⚠️ অঙ্কটা সবসময় ধনাত্মক টাইপ হয়, দিকটা আলাদা বাছাই —
         * তাই ভুল করে `-৪৩০০` বসে যাওয়ার পথ নেই।
         *
         * ⓘ সার্ভারে এই সংখ্যাটাই যায়, আর সেখানে রাউন্ডিং
         * ঋণাত্মক হতে দেওয়া হয়েছে (কেবল রাউন্ডিং, ছাড় বা
         * খরচ নয় — ওদের ঋণাত্মক হওয়ার কোনো মানে নেই)।
         */
        get roundingValue() {
            const n = Number(this.roundingInput);

            if (! isFinite(n) || n <= 0) return 0;

            return this.roundingSign === '-' ? -n : n;
        },

        get netPayable() {
            return this.grossTotal
                - this.discountValue
                + this.expenseValue
                + this.roundingValue;
        },

        /*
         * সব জমার যোগফল।
         *
         * ⓘ সার্ভারও ঠিক এই যোগটাই আবার করে (`depositTotal()`)
         * — পর্দার সংখ্যা বিশ্বাস করে খাতায় বসানো হয় না।
         */
        get deposit() {
            return this.deposits.reduce(
                (sum, row) => sum + (Number(row.amount) || 0), 0
            );
        },

        /*
         * ── কোন খাতগুলো বাছা যাবে ────────────────────────────
         *
         * মালিকের নির্দেশ (৪ সেপ্টেম্বর ২০২৬): *"Method Cash হলে
         * Received into-তে cash account গুলো, Bank হলে bank
         * account, MFS হলে Mobile bank — আর method না select
         * করলে Received into-তে কিছু দেখাবে না"*।
         *
         * ⚠️ **তিনটা আলাদা অবস্থা, তিনটাই আলাদা উত্তর:**
         *
         *   উপায় বাছা হয়নি      →  একটাও খাত নয়
         *   উপায় বাছা, ধরন জানা  →  কেবল ওই মায়ের সন্তানরা
         *   উপায় বাছা, ধরন নেই   →  সব টাকার খাত
         *
         * ⭐ প্রথমটা কেন খালি: খাত বাছার আগে **টাকাটা কীভাবে
         * এল** সেটা জানা দরকার, নইলে বিকাশের টাকা নগদ ড্রয়ারে
         * বসে যেত — আজ সকালে ঠিক ওই ভুলটাই সারানো হয়েছে।
         *
         * ⓘ তৃতীয়টা নিরাপদ ছাড়: কোনো কোম্পানি যদি নিজের একটা
         * উপায় বানিয়ে ধরন না দেন, তাঁর কাজ আটকে যাবে না।
         */
        /*
         * ⚠️ চেক ব্যাংক নয় — ১১০৪ "হাতে চেক"।
         *
         * ── কেন, আর ভুলটা কত সহজ ─────────────────────────────
         * আমি প্রথমে চেককে ব্যাংকের ঘরে ফেলেছিলাম, কারণ চেকের
         * টাকা তো ব্যাংকেই যায়। ⚠️ **কিন্তু আজ যায় না** — হাতে
         * পাওয়া চেক এখনো টাকা নয়, ওটা পাশ হতে পারে, ফেরতও
         * আসতে পারে।
         *
         * ⭐ রিপোতে এই ভুলটা একবার হয়েছিল আর সারানোও হয়েছে —
         * চেকের মাইগ্রেশনের নামটাই সেটা: *"হাতে চেক ইতিমধ্যেই
         * ব্যাংকের টাকা ছিল"*। `ChequeService` চেক নিলে
         * **Dr ১১০৪** করে, ব্যাংক নয়; পাশ হলে তবে ব্যাংকে যায়।
         *
         * ⓘ তাই কাউন্টারে চেক বাছলে খাতের তালিকায় ব্যাংক হিসাব
         * দেখানো **ভুল হত** — আর ওটা নীরব ভুল, কারণ সংখ্যাটা
         * ঠিকই বসত, কেবল ভুল খাতে, আর ব্যাংকের ব্যালেন্স
         * বাস্তবের চেয়ে বেশি দেখাত।
         */
        depositKindParents: { cash: '1101', bank: '1102', mfs: '1105', cheque: '1104' },

        get depositAccounts() {
            const method = this.depositMethodRow;

            if (! method) return [];

            const parent = this.depositKindParents[method.kind];

            if (! parent) return this.moneyAccounts;

            return this.moneyAccounts.filter(a => a.parent === parent);
        },

        /** বাছা উপায়ের সারিটা — কোড, খাত, নম্বর লাগবে কিনা। */
        get depositMethodRow() {
            return this.depositMethods.find(
                m => String(m.id) === String(this.depositDraft.methodId)
            ) || null;
        },

        /*
         * ⚠️ নম্বরের ঘরটা কেবল যে উপায়ে দরকার।
         *
         * নগদে TrxID নেই, তাই ঘরটা সবসময় দেখালে প্রতিটা নগদ
         * জমায় একটা খালি ঘর পার হতে হত — আর যেদিন সত্যিই
         * দরকার সেদিনও চোখে পড়ত না।
         */
        get depositNeedsReference() {
            return this.depositMethodRow?.needsReference === true;
        },

        /*
         * ⚠️ শর্তটা **খাত**, উপায় নয় — আর কারণটা মাপা।
         *
         * খাতা যেটা সত্যিই দেখে সেটা খাত; উপায় কেবল বলে
         * টাকাটা কীভাবে এসেছিল। তাছাড়া `mdm_payment_methods`
         * একটা **সেটিংসের তালিকা**, আর নতুন কোম্পানিতে ওটা
         * আজ **খালি** (মেপে দেখা, ৩ সেপ্টেম্বর ২০২৬)।
         *
         * উপায় বাধ্যতামূলক করলে যে ব্যবসা তালিকাটা এখনো
         * সাজায়নি, **তাদের কাউন্টারে জমাই নেওয়া যেত না** —
         * একটা সেটআপের ফাঁক দাঁড়িয়ে যেত রোজকার কাজের পথে।
         */
        get depositReady() {
            return Number(this.depositDraft.amount) > 0
                && this.depositDraft.accountId !== ''
                && (! this.depositNeedsReference
                    || String(this.depositDraft.reference || '').trim() !== '');
        },

        /*
         * উপায় বাছলে খাতটা নিজে থেকে বসে — কিন্তু বদলানো যায়।
         *
         * ⚠️ এক উপায়ের একাধিক খাত থাকতে পারে ("ব্যাংক" উপায়ে
         * তিনটা ব্যাংক হিসাব), তাই ঘরটা তালাবদ্ধ নয়।
         */
        pickDepositMethod() {
            this.depositDraft.accountId = this.depositMethodRow?.accountId || '';

            /*
             * ⚠️ উপায় বদলালে আগের খাতটা আর মানানসই না-ও হতে পারে।
             *
             * নগদ বেছে নগদের খাত বসানোর পর কেউ ব্যাংক বাছলে ঘরটা
             * **নগদের খাত ধরে বসে থাকত**, অথচ তালিকায় ওটা আর নেই।
             * পর্দায় দেখাত খালি, কিন্তু সার্ভারে যেত পুরনো মানটাই —
             * নীরবে, আর টাকা ভুল খাতে।
             */
            if (! this.depositAccounts.some(a => a.id === this.depositDraft.accountId)) {
                this.depositDraft.accountId = '';
            }

            if (! this.depositNeedsReference) {
                this.depositDraft.reference = '';
            }
        },

        /*
         * ⚠️ `$refs` এখানে কাজ করে না, আর কারণটা সহজে মিস হয়।
         *
         * `x-ref` যে স্কোপে **থাকে** সেখানেই নিবন্ধিত হয়, আর
         * ঘরটা `x-ui.date` কম্পোনেন্টের নিজের `x-data="abosDate(...)"`
         * এর ভিতরে। তাই ফর্মের স্কোপ থেকে `$refs.depositDate`
         * **সবসময় undefined** — আর নীরবে: তারিখটা খালি যেত,
         * কোনো ত্রুটি ছাড়াই। মালিক তালিকায় ফাঁকা "Ref. Date"
         * দেখে ধরেছেন (৪ সেপ্টেম্বর ২০২৬)।
         *
         * ⓘ লুকানো ঘরটার `name` আছে, তাই ওটাই ধরার হাতল —
         * আর সেটাও কম্পোনেন্টের স্কোপের ভিতরে, তাই
         * `Alpine.$data()` ওই স্কোপটাই ফেরত দেয়।
         */
        dateBox(name) {
            return this.$root.querySelector(`input[name="${name}"]`);
        },

        get depositRefDate() {
            const el = this.dateBox('deposit_ref_date');

            return el && window.Alpine ? (window.Alpine.$data(el).iso || '') : '';
        },

        clearDepositDate() {
            const el = this.dateBox('deposit_ref_date');

            if (! el || ! window.Alpine) return;

            const box = window.Alpine.$data(el);

            box.iso = '';
            box.text = '';
        },

        addDeposit() {
            if (! this.depositReady) return;

            this.deposits.push({
                ...this.depositDraft,
                refDate: this.depositRefDate,
            });

            this.depositDraft = {
                methodId: '', accountId: '', amount: '',
                reference: '', refDate: '', narration: '',
            };

            this.clearDepositDate();
        },

        dropDeposit(index) {
            this.deposits.splice(index, 1);
        },

        /** তালিকায় দেখানোর জন্য নাম — id নয়। */
        depositMethodName(id) {
            return this.depositMethods.find(
                m => String(m.id) === String(id)
            )?.label || '—';
        },

        depositAccountName(id) {
            return this.moneyAccounts.find(
                a => String(a.id) === String(id)
            )?.label || '—';
        },

        get invoiceDue() {
            const due = this.netPayable - (Number(this.deposit) || 0);
            return due > 0 ? due : 0;
        },

        /*
         * বিলের চেয়ে বেশি জমা — যেটুকু বেশি, সেটুকু অগ্রিম।
         *
         * ⚠️ মালিক ধরেছেন (৩ সেপ্টেম্বর ২০২৬): ৬০,৫৬৫ টাকার
         * বিলে ৫৬ লাখ জমা লিখলে পর্দা বলত **"বকেয়া ০"**, আর
         * বাকি টাকাটা নিয়ে **একটা শব্দও বলত না**। ক্যাশিয়ার
         * হাতে টাকা নিয়েছেন, অথচ পর্দা ভুলে গেছে।
         */
        get depositExcess() {
            const extra = this.deposit - this.netPayable;

            return extra > 0 ? extra : 0;
        },

        /*
         * ⚠️ `invoiceDue` নয়, না-কাটা সংখ্যাটা।
         *
         * `invoiceDue` শূন্যে থেমে যায় (একটা বিলে ঋণাত্মক
         * বকেয়ার মানে নেই)। কিন্তু **পক্ষের** হিসাবে উদ্বৃত্তটা
         * সত্যি — ওটা তাঁর অগ্রিম, আর পরের চালানে কাটা হবে।
         * তাই এখানে বাদ দিলে নিচের লাল/সবুজ বড়িটা মিথ্যা বলত।
         */
        get outstanding() {
            return (Number(this.customer.due) || 0)
                + this.netPayable
                - this.deposit;
        },

        /*
         * আর কত বাকিতে দেওয়া যাবে।
         *
         * ── কেন সীমাটা একা যথেষ্ট নয় ────────────────────────
         * "বাকির সীমা ৭৫,০০০" একটা **চুক্তির** সংখ্যা, কাউন্টারের
         * নয়। ⚠️ যাঁর ৭০,০০০ আগেই বাকি, তাঁর জন্য খোলা আছে
         * মাত্র ৫,০০০ — অথচ পর্দায় ৭৫,০০০ দেখলে বিক্রেতা
         * নির্দ্বিধায় পুরো ট্রাক তুলে দেবেন।
         *
         * ⓘ `outstanding` ব্যবহার করা হলো, কেবল `customer.due`
         * নয় — কারণ **এই চালানটাও বাকির অংশ**। কার্টে মাল
         * ওঠার সাথে সাথে সংখ্যাটা কমে, আর সীমা ছোঁয়ার মুহূর্তটা
         * চালান নিশ্চিত করার *আগেই* চোখে পড়ে।
         *
         * ⚠️ সীমা ০ হলে এই সংখ্যাটা দেখানোই হয় না — শূন্য মানে
         * "মাল বন্ধ" নয়, "বাকি বন্ধ"; ওটা আলাদা কথা, আর সেটা
         * লেখাই থাকে।
         */
        get availableCredit() {
            return (Number(this.customer.limit) || 0) - this.outstanding;
        },

        /*
         * ⛔ পর্দার শর্তগুলো এখানে, ব্লেডে নয় — ২৫ সেপ্টেম্বর ২০২৬।
         *
         * ── ⚠️ কেন সারিটা কোনোদিন আসেনি ─────────────────────────────
         * ব্লেডে লেখা ছিল `x-if="(Number(customer.limit) || 0) > 0"`।
         * ⓘ প্রকল্পটা **`@alpinejs/csp`** ব্যবহার করে, আর সেখানে
         * অভিব্যক্তির ভিতরে **বাইরের ফাংশন ডাকা যায় না** — `Number`
         * ঐ সীমিত পরিসরে নেই।
         *
         * ⛔ ফাঁদটা নিখুঁতভাবে নীরব: ব্লেড কম্পাইল হয়, পাতা ২০০ দেয়,
         * কোনো JS ত্রুটি নেই — Alpine চুপচাপ ঐ অংশটা আঁকা বাদ দেয়।
         * ⚠️ মালিক বারবার বলেছেন *"bose ni"*, আর আমি প্রতিবার ভুল
         * জায়গায় খুঁজেছি (সার্ভারের পেলোড, সেটিংস, ডিপ্লয়)।
         *
         * ⓘ মেপে দেখা: গোটা `views/`-এ যে `x-if`-গুলো কাজ করে
         * (`giftDraft`, `depositExcess > 0`, `entryUnits.length === 0`,
         * `vatMode === 'exclusive' || …`) — একটাও বাইরের ফাংশন ডাকে না।
         *
         * ⭐ তাই হিসাবটা এখানে, আর পর্দায় কেবল নাম — ঠিক `giftDraft`-এর
         * মতো। ⚠️ নতুন কোনো শর্ত ব্লেডে লিখলে এই ফাঁদে আবার পড়বেন।
         */
        get hasCustomer() {
            return this.customerId !== '';
        },

        get hasCreditLimit() {
            return this.hasCustomer && (Number(this.customer.limit) || 0) > 0;
        },

        /* ⓘ ক্রেতা বাছা, কিন্তু সীমা শূন্য — *"বাকি বন্ধ"*, আর সেটা
         * *"ক্রেতা বাছা হয়নি"* থেকে আলাদা কথা। */
        get creditIsClosed() {
            return this.hasCustomer && ! this.hasCreditLimit;
        },

        get counts() {
            const sales = this.lines.reduce((s, l) => s + (Number(l.qty) || 0), 0);
            const free = this.lines.reduce((s, l) => s + (Number(l.freeQty) || 0), 0)
                + this.allGifts.reduce((s, g) => s + (Number(g.qty) || 0), 0);

            return {
                totalItem: this.lines.length,
                totalSalesQty: this.qty(sales),
                totalFreeQty: this.qty(free),
                totalQty: this.qty(sales + free),
            };
        },

        /* যে বোতামের কাজটা এই পাতাতেই আছে, সেটা ওই ঘরে নিয়ে
           যায় — নতুন কোনো পপ-আপ নয়। খরচ বসাতে গিয়ে একটা জানালা
           খুলে আবার বন্ধ করা কাউন্টারে দুইটা বাড়তি চাপ। */
        /* $root, $el নয়: বোতাম থেকে ডাকা হলে $el হয় ওই
           বোতামটাই, আর বোতামের ভেতরে ঘরটা থাকে না — তখন
           কিছুই ফোকাস হত না, নীরবে। জাবেদা ও নগদ গণনার
           পর্দায় এই একই ভুল দুইটা ফিচার মেরে রেখেছিল। */
        focusField(name) {
            const el = this.$root.querySelector(`[name="${name}"]`);
            if (el) { el.focus(); el.select?.(); }
        },

        money(v) {
            return taka(v);
        },

        qty(v) {
            return String(Number(v || 0));
        },

        /*
         * ⓘ নিচের তিনটা আগে ব্লেডের অ্যাট্রিবিউটে লেখা ছিল। CSP-Alpine
         * `=>`, `?.` আর কমা-দিয়ে-জোড়া এক্সপ্রেশন পড়ে না, তাই এখানে
         * (১৯ সেপ্টেম্বর ২০২৬, নিরীক্ষার ধাপ ৩.১)।
         */

        /** আইডি থেকে পণ্যের নাম — উপহারের সারিতে */
        productName(id) {
            return (this.catalogue.find(p => String(p.id) === String(id)) || {}).name || '';
        },

        /*
         * Esc — সবচেয়ে ভিতরের জিনিসটা আগে বন্ধ: খোলা প্যানেল, তারপর
         * তালিকা, শেষে সাহায্যের পাতা।
         */
        escape() {
            if (this.panel) {
                this.panel = '';
            } else if (this.pickerOpen || this.customerPickerOpen) {
                this.pickerOpen = false;
                this.customerPickerOpen = false;
            } else {
                this.helping = false;
            }
        },

        /** F6 — হিসাবের খাত থেকে পণ্য বাছার বোতাম */
        openChartEntry() {
            this.$refs.chartEntry?.querySelector('button')?.click();
        },
    };
}
