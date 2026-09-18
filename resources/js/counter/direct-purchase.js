/**
 * কাউন্টারে সরাসরি ক্রয় — পর্দার পুরো যুক্তি।
 *
 * ── ⭐ কেন ফাইলে, ব্লেডের ভিতরে নয় — ১৮ সেপ্টেম্বর ২০২৬ ───────────────
 * নিরীক্ষার ধাপ ৪.১। ব্লেড ফাইলটা ছিল ৩,৭৩৫ লাইন, তার ভিতরে ১,৩৪৭ লাইন JS।
 *
 * ⚠️ বিক্রয়ের কাউন্টারের সাথে এর যুক্তি প্রায় এক, আর নিরীক্ষায় দেখা
 * গেছে **একই বাগ চার জায়গায়** পাওয়া গেছে (কমিট 9197153)। ⓘ দুইটা এখন
 * পাশাপাশি ফাইলে ([[direct-sale.js]]), তাই মিলটা অন্তত **দেখা যায়** —
 * আগে দুই ব্লেডের দুই প্রান্তে লুকানো ছিল।
 *
 * ⛔ এখনো এক করা হয়নি, আর সেটা ইচ্ছাকৃত: দুইটা পর্দাই রোজ চলে, আর
 * এক ধাপে সব করলে ভাঙলে কারণ খুঁজে পাওয়া যেত না। ⭐ নিরীক্ষার নিজের
 * কথা: *"একবারে সব নয় — ছোট ছোট টুকরায় সরান।"*
 */
/*
 * সরাসরি ক্রয়ের পর্দা।
 *
 * দর নির্ধারণের অঙ্কটা এখানে লেখা নেই — window.abos.reprice
 * (resources/js/pricing.js) সেটা করে, আর ওটার নিজের ১৪টা
 * পরীক্ষা আছে। Blade-এর ভেতরে লিখলে ওই অঙ্কটার কোনো পরীক্ষা
 * লেখা যেত না, অথচ সেটাই প্রতিটা পণ্যের বিক্রয়মূল্য ঠিক করে।
 */
export default function directPurchase({
    catalogue, vatEnabled, lastRatesUrl,
    depositMethods, moneyAccounts, carriers, packs, suppliers,
    paymentTermDefault, draftKey, hasErrors, accountCodes, texts,
}) {
    return {
        catalogue,
        vatEnabled,
        lastRatesUrl,
        busy: false,
        term: '',
        picked: null,
        entry: {},
        lines: [],
        paidNow: '',
        nextKey: 1,

        /* সরবরাহকারীর আগের বকেয়া — সার্ভার থেকে আসে বাছাইয়ের
           মুহূর্তে ([[loadLastRates]])।

           ⓘ ঋণাত্মক মানে অগ্রিম, আর তখন পর্দার লেবেলটাই
           বদলে যায় — "আগের বকেয়া" নয়, "আগের অগ্রিম"। */
        previousDue: 0,

        /* ── জমা ─────────────────────────────────────────────
           এক বিলের টাকা এক পথে যায় না — কিছু নগদ, বাকিটা চেকে
           বা bKash-এ। প্রতিটা সারি নিজের উপায় ও নিজের খাত নিয়ে
           বসে, আর সার্ভারে আলাদা পরিশোধ হয়। */
        deposits: [],
        depositMethods,
        /* ⚠️ খাতের ধরনটা `is_cash`/`is_bank` থেকে নেওয়া যায় না।

           মেপে দেখা গেছে বিকাশের খাতটা দুইটার কোনোটাই নয়, তাই
           ওই হিসাবে MFS-এর ছাঁকনি **একটাও খাত পেত না** আর
           নীরবে সব খাত দেখাত — অর্থাৎ ছাঁকনিটা ছিল, কাজ করত না।

           ⭐ আসল উত্তর মায়ের কোডে: ১১০১ নগদ · ১১০২ ব্যাংক ·
           ১১০৫ মোবাইল মানি। বিক্রয়ের দিকেও এভাবেই করা। */
        moneyAccounts,
        depositDraft: { methodId: '', accountId: '', amount: '', reference: '', refDate: '', narration: '' },

        /* ── কে মালটা আনল ─────────────────────────────────
           তালিকাটা পক্ষের ধরন ধরে ছাঁকা (TRANSPORT), তাই এখানে
           কোনো প্রতিষ্ঠানের নাম লেখা নেই। */
        carriers,
        carrierId: '',
        carrierName: '',
        transportCost: '',
        vehicleNo: '',
        driverName: '',

        /* এই সরবরাহকারীর কাছ থেকে কোন পণ্য গতবার কত দরে —
           পণ্যের আইডি ধরে। সরবরাহকারী বাছার সাথে সাথে একবারে
           আসে; সারি ধরে ধরে নয়, নাহলে বিশ লাইনের কার্টে বিশটা
           রাউন্ড-ট্রিপ হত আর কাউন্টারে সেটা টের পাওয়া যেত। */
        lastRates: {},
        supplierChosen: false,

        /* ── প্যাকের তালিকা — পণ্যের আইডি ধরে ────────────────
           ⓘ উৎস `PackConversion::optionsFor`, আর সুইচটা
           `inventory.pack_entry_enabled`। ⚠️ লাইন-এডিটরও হুবহু
           এই ডাকটাই ব্যবহার করে; দুই পর্দায় দুই তালিকা হলে
           একই পণ্য এখানে বাক্সে আর বিলে পিসে লিখতে হত। */
        packs,

        /* ── সরবরাহকারীর কার্ডের চারটা লাইন ────────────────
           ⓘ তথ্যগুলো আগেই ছিল, দেখানো হত না। ⚠️ আইডিটা
           স্ট্রিং করা হয়েছে ইচ্ছে করে: `<select>`-এর মান
           সবসময় স্ট্রিং, আর `===` মেলাতে গিয়ে একদিন
           কার্ডটা চিরকাল খালি থাকত। */
        suppliers,
        supplierId: '',

        /* ⓘ দুইটা তালিকার খোলা-বন্ধ — বিক্রয়ের কাউন্টারের মতো।
           ⚠️ `browsing` মানে "চিহ্নে চাপা হয়েছে", অর্থাৎ কিছু
           টাইপ না করলেও পুরো তালিকা দেখানো হবে। */
        supplierPickerOpen: false,
        supplierTerm: '',
        browsing: false,

        /*
         * পণ্যটা বাছার সময় ক্রয়দর কত ছিল।
         *
         * ⓘ "বদলেছে" বলতে গেলে **কীসের তুলনায়** তা জানা লাগে।
         * ⚠️ পণ্যের `last_rate`-এর সাথে তুলনা করলে হত না: মানুষ
         * একবার দর বদলে প্রস্তাব ফিরিয়ে দিলে প্রতিটা কি-স্ট্রোকে
         * সে আবার জিজ্ঞেস করত।
         */
        lastKnownRate: '',

        /*
         * দাম বদলানোর প্রস্তাব — বসানো নয়, জিজ্ঞাসা।
         *
         * ⓘ `null` মানে কোনো প্রশ্ন নেই। ⚠️ মালিকের সিদ্ধান্ত:
         * *"জিজ্ঞেস করে বদলাবে"* — তাই এখানে কেবল প্রস্তাবটা
         * থাকে, আর বসে মানুষের এক চাপে।
         */
        priceAsk: null,

        /* ── দুইটা প্যানেল ──────────────────────────────────
           ⓘ দুইটাই বন্ধ অবস্থায় শুরু হয়, আর একসাথে দুইটাই খোলা
           থাকতে পারে — কেউ দরের তালিকা খুলে রেখে খরচের হিসাব
           দেখতে চাইলে বাধা দেওয়ার কারণ নেই। */
        /* ── কত দিনের বাকিতে ────────────────────────────────
           ⓘ তালিকাটা `mdm_payment_terms`-এর সারি। ⚠️ বাছাইটা
           নিজে সংরক্ষিত হয় না — তার ফল (`due_on`) হয়। */
        /* ⓘ ঘরটার একটাই মান: হয় দিনের সংখ্যা ('7'), হয় খালি
           ('নগদ'), নয় 'date' — আর শেষেরটা বাছলে ঘরটাই
           তারিখের ঘর হয়ে যায়। ⚠️ দুইটা আলাদা মান রাখলে
           আবার দুইটা বাক্স হয়ে যেত, শুধু চোখের আড়ালে। */
        /* ⓘ ঘরটার একটাই মান: `cash` · `credit:7` ·
           `month_end` · `fixed`। ⚠️ কোলনের পরের সংখ্যাটা কেবল
           `credit`-এ, আর সার্ভারে যায় কেবল কোলনের **আগের**
           অংশটা — দিনসংখ্যাটা তারিখ হয়ে যায়। */
        termChoice: paymentTermDefault,
        dueOn: '',

        chartOpen: false,
        depositOpen: false,
        transportOpen: false,
        noteOpen: false,
        shipmentOpen: false,

        /* ── খসড়া ────────────────────────────────────────────

           গাড়ি গেটে দাঁড়ানো, বিশ লাইন টাইপ করা — পর্দা হারানো
           মানে পুরোটা আবার। বিক্রয়ে এই ব্যবস্থা আগেই আছে, আর
           এখানে সেটার গড়নই নেওয়া হয়েছে, ওদের শেখা ভুলগুলোসহ।

           ⚠️ চাবিটা **ক্রয়ের নিজস্ব** — বিক্রয়েরটা থেকে আলাদা।
           এক চাবি হলে বিক্রয়ের কার্ট ক্রয়ের পর্দায় খুলত, আর
           কেউ না বুঝে সেটার উপরেই কিনতে বসতেন। */
        draftKey,
        draftFound: false,
        draftAt: '',

        /* সরবরাহকারী · গুদাম · বিল নম্বর — এগুলো Alpine-এর ঘরে
           নেই, সাধারণ DOM ঘর। তাই খসড়ায় বসানোর সময় পর্দা থেকেই
           পড়া হয়, আর ফেরানোর সময় পর্দাতেই লেখা হয়। */
        /* ⚠️ `$root`, `$el` নয়।

           `$el` মানে **যে এলিমেন্টের ভিতর থেকে ডাকা হয়েছে**।
           `init()`-এ ওটা ফর্ম, কিন্তু `@click="restoreDraft()"`
           থেকে ডাকলে ওটা **বোতামটা** — আর বোতামের ভিতরে
           `[name=...]` কিছুই নেই।

           ⛔ ফলটা নীরব ছিল: খসড়া ফিরত, সারিগুলোও ফিরত, কিন্তু
           সরবরাহকারী আর বিল নম্বর **ফাঁকা** থেকে যেত — আর কেউ
           ভুল সরবরাহকারীর নামে বিলটা নিশ্চিত করে ফেলতে পারতেন।
           ⓘ ব্রাউজারে চাপ দিয়ে ধরা পড়েছে, কোড পড়ে নয়। */
        box(name) {
            return this.$root.querySelector('[name="' + name + '"]');
        },

        boxValue(name) {
            const el = this.box(name);

            return el ? el.value : '';
        },

        setBox(name, value) {
            const el = this.box(name);

            if (el) el.value = value ?? '';
        },

        /* ⚠️ কার্ট খালি হলে খসড়া মুছে যায়, রাখা হয় না — খালি
           খসড়া ফেরানোর প্রস্তাব মানে প্রতিদিন একটা অর্থহীন
           প্রশ্ন। */
        saveDraft() {
            /* ⚠️ প্রস্তাব পর্দায় থাকা অবস্থায় লেখা বা মোছা নয়।

               এই লাইনটা ছাড়া বাগটা নীরব হত: পাতা খোলার সাথে
               সাথেই `x-effect` একবার চলে, আর তখন কার্ট খালি —
               অর্থাৎ খসড়াটা মুছে যেত ঠিক সেই মুহূর্তে যখন
               ফেরানোর প্রস্তাব দেখানো হচ্ছে। বোতামটা থাকত,
               চাপলে কিছুই ফিরত না। ⓘ বিক্রয়ে এটা শেখা হয়েছে। */
            if (this.draftFound) return;

            try {
                if (this.lines.length === 0) {
                    localStorage.removeItem(this.draftKey);

                    return;
                }

                localStorage.setItem(this.draftKey, JSON.stringify({
                    at: new Date().toISOString(),
                    supplierId: this.boxValue('supplier_id'),
                    warehouseId: this.boxValue('warehouse_id'),
                    supplierBillNo: this.boxValue('supplier_bill_no'),
                    lines: this.lines,
                    paidNow: this.paidNow,
                    deposits: this.deposits,
                    carrierId: this.carrierId,
                    carrierName: this.carrierName,
                    transportCost: this.transportCost,
                    vehicleNo: this.vehicleNo,
                    driverName: this.driverName,
                    nextKey: this.nextKey,
                }));
            } catch (e) {
                /* ⚠️ চুপ করে থাকা ইচ্ছাকৃত — localStorage বন্ধ
                   থাকতে পারে (ব্যক্তিগত উইন্ডো, সাইট-ডেটা বন্ধ
                   করা ব্রাউজার), আর তখন লেখা ব্যতিক্রম ছোঁড়ে।
                   কিন্তু ওটা ক্রয় থামানোর কারণ নয়। */
            }
        },

        /** পাতা খোলার সময় — আছে কিনা দেখা, নিজে থেকে ফেরানো নয়। */
        lookForDraft() {
            try {
                const parked = localStorage.getItem(this.draftKey + '.pending');

                if (parked) {
                    localStorage.removeItem(this.draftKey + '.pending');

                    /* ⚠️ সার্ভার ফিরিয়ে দিয়েছে — তাই প্রশ্ন নয়,
                       সরাসরি ফেরানো। ব্যবহারকারী "নতুন ক্রয়"
                       চাননি, তিনি এইটাই পাঠিয়েছিলেন। */
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

        /* ⚠️ ফেরানো **কেবল চাপ দিলে** — নিজে থেকে নয়।

           নিজে থেকে ফেরালে সবচেয়ে বিপজ্জনক জিনিসটা ঘটত: কেউ নতুন
           ক্রয় লিখতে এসে আগের অসমাপ্ত বিলটা পেয়ে যেতেন, না বুঝে,
           আর তার উপরেই নতুন সারি যোগ করে নিশ্চিত করতেন। ⛔ **ভুল
           সরবরাহকারীর নামে ভুল মাল, আর ভুল দেনা।** */
        restoreDraft() {
            this.applyDraft(localStorage.getItem(this.draftKey));
            this.draftFound = false;
        },

        discardDraft() {
            try {
                localStorage.removeItem(this.draftKey);
            } catch (e) {
                // মুছতে না পারলেও প্রস্তাবটা সরিয়ে দেওয়াই যথেষ্ট
            }

            this.draftFound = false;
        },

        /** খসড়াটা পর্দায় বসানো — কোথা থেকে এল তা জানার দরকার নেই। */
        applyDraft(raw) {
            try {
                const d = JSON.parse(raw || '{}');

                this.setBox('supplier_id', d.supplierId);
                this.setBox('warehouse_id', d.warehouseId);
                this.setBox('supplier_bill_no', d.supplierBillNo);

                this.lines = Array.isArray(d.lines) ? d.lines : [];
                this.paidNow = d.paidNow ?? '';
                this.deposits = Array.isArray(d.deposits) ? d.deposits : [];
                this.carrierId = d.carrierId ?? '';
                this.carrierName = d.carrierName ?? '';
                this.transportCost = d.transportCost ?? '';
                this.vehicleNo = d.vehicleNo ?? '';
                this.driverName = d.driverName ?? '';
                this.nextKey = d.nextKey ?? (this.lines.length + 1);

                /* ⚠️ গতবারের দরগুলো আবার আনতে হয়। সরবরাহকারীর
                   ঘরটা কোড দিয়ে বসানো হয়েছে, তাই `change` ঘটে
                   না — আর তখন কার্টে সারি আছে অথচ "গতবার কত"
                   কলামটা ফাঁকা থাকত, ঠিক দরাদরির মুহূর্তে। */
                if (d.supplierId) this.supplierPicked(d.supplierId);
            } catch (e) {
                // ভাঙা খসড়া — ফেরানোর চেয়ে বাদ দেওয়াই নিরাপদ
            }
        },

        /* ── সাবমিটে খসড়া মোছা হয় না, সরিয়ে রাখা হয় ──────────

           ⚠️ বিক্রয়ে এটা মালিকের অভিযোগে শেখা (৪ সেপ্টেম্বর
           ২০২৬): *"এই warning-এ আমার সব entry হারিয়ে গেল"*।

           সাবমিটে খসড়া মুছে দিলে, আর তার পরেই সার্ভার বিলটা
           ফিরিয়ে দিলে (মজুদ কম, নম্বর নেওয়া, যাচাই — যা-ই হোক)
           পাতাটা **খালি হয়ে ফিরত**: বিশ লাইনের কার্ট,
           সরবরাহকারী, জমা — সব শেষ। ⓘ তাই খসড়াটা `.pending`-এ
           সরে যায়, আর পাতাটা ভুলের বার্তাসহ ফিরলে ওটা নিজে
           থেকেই ফিরে আসে, প্রশ্ন ছাড়াই। */
        parkDraft() {
            try {
                const raw = localStorage.getItem(this.draftKey);

                if (raw) {
                    localStorage.setItem(this.draftKey + '.pending', raw);
                    localStorage.removeItem(this.draftKey);
                }
            } catch (e) {
                // সরাতে না পারলে খসড়াটা যেখানে আছে সেখানেই থাক
            }
        },

        get visible() {
            /*
             * ── ⛔ পক্ষ আগে, পণ্য পরে — মালিকের নিয়ম, ৬ সেপ্টেম্বর ২০২৬ ──
             *
             * *"সরবরাহকারী আগে সিলেক্ট করলে পরে প্রোডাক্ট সার্চ হবে,
             * সরবরাহকারী না দিলে প্রোডাক্ট শো করবে না।"*
             *
             * ── কেন এটা কেবল ক্রম নয় ─────────────────────────
             * ⚠️ **দর সরবরাহকারীভেদে আলাদা** — একই পণ্য এক সরবরাহকারীর কাছে এক দরে, আরেকজনের কাছে আরেক দরে। পণ্য আগে বাছলে
             * পর্দা এমন একটা দর বসাত যেটা এখনো জানা যায়নি কার
             * জন্য, আর সরবরাহকারী বাছার পর সেটা **নীরবে ভুল** হয়ে থাকত।
             *
             * ⓘ কার্টে লাইন বসানোর পর সরবরাহকারী বদলালে ঐ দরগুলো আর
             * নিজে থেকে ঠিক হয় না — অর্থাৎ ভুলটা কাগজ পর্যন্ত যেত।
             *
             * ⭐ তাই তালিকাটা খালি থাকে, আর নিচের বার্তাটা বলে দেয়
             * **কী করতে হবে** — শুধু "পারবেন না" নয় (নিয়ম ১)।
             */
            if (! this.supplierId) return [];

            const t = this.term.trim().toLowerCase();

            /*
             * ⛔ আগে এখানে খালি লেখায় **খালি তালিকা** ফিরত, তাই
             * খোঁজার চিহ্নে চেপে কিছুই হত না — মালিক ঠিকই
             * বলেছেন *"Product aseo na"*।
             *
             * ⭐ এখন চিহ্নে চাপলে (`browsing`) পুরো তালিকা,
             * আর টাইপ করলে ছাঁকা — বিক্রয়ের কাউন্টারের নিয়ম।
             */
            if (t === '') return this.browsing ? this.catalogue.slice(0, 30) : [];

            // ⓘ দুইটা নামই — সরবরাহকারীর ঘরের একই কারণে (৭ সেপ্টেম্বর ২০২৬)
            return this.catalogue.filter(p =>
                p.name.toLowerCase().includes(t)
                || (p.name_en || '').toLowerCase().includes(t)
                || (p.name_bn || '').toLowerCase().includes(t)
                || p.code.toLowerCase().includes(t)
            ).slice(0, 30);
        },

        blankEntry() {
            return {
                qty: '', free_qty: '', rate: '', discount: '', tax: '',
                markup: '', margin: '', sales_price: '', anchor: '',

                /* ── প্যাকের দুইটা ঘর ─────────────────────────
                   ফাঁকা মানে পণ্যের নিজের একক, অর্থাৎ আগের মতোই।
                   ⓘ ফ্রি-রটা আলাদা: মিল কার্টনে বেচে, ফ্রি দেয়
                   পিসে। */
                unit_id: '', free_unit_id: '',

                /* ছাড়টা কীভাবে লেখা হচ্ছে — টাকায় নাকি শতাংশে।
                   ⚠️ **সংরক্ষিত হয় সবসময় টাকায়**; এটা কেবল লেখার
                   ভঙ্গি। ⓘ ডিফল্ট টাকা, কারণ সরবরাহকারীর কাগজে
                   ছাড়টা টাকাতেই ছাপা থাকে। */
                discount_mode: 'amount',

                /* ভ্যাট কোন নিয়মে — pick()-এ পণ্য দেখে বসে */
                vat_mode: 'amount',
            };
        },

        init() {
            this.entry = this.blankEntry();

            /* ⚠️ খসড়া খোঁজা **সবার আগে** — নাহলে নিচের
               `loadLastRates` খসড়ার সরবরাহকারীকে নয়, পর্দার
               পুরনো মানটাকে ধরে বসত। */
            this.lookForDraft();

            /* যাচাই ব্যর্থ হয়ে পাতাটা ফিরে এলে সরবরাহকারী আগে
               থেকেই বাছা থাকে, অথচ `change` আর ঘটে না। তখন
               গতবারের দরগুলো উধাও থাকত — ঠিক যখন মানুষটা ভুল
               শুধরে আবার দেখছেন। */
            const chosen = this.$root.querySelector('[name="supplier_id"]');

            /* ⚠️ `supplierPicked`, `loadLastRates` নয় — কার্ডের
               চারটা লাইন (মোবাইল · ঠিকানা · স্বত্বাধিকারী)
               `supplierId` ধরে বসে। ⓘ কেবল দরগুলো আনলে যাচাই
               ব্যর্থ হয়ে ফেরা পাতায় সরবরাহকারী বাছা থাকত অথচ
               কার্ডটা ফাঁকা — আর মানুষটা ভাবতেন বাছাই হারিয়ে
               গেছে। */
            if (chosen && chosen.value) this.supplierPicked(chosen.value);

            /* ⚠️ ডিফল্ট মেয়াদটা পাতা খোলার সময়েই তারিখ হয়ে বসে।
               ⛔ না বসালে ঘরটা "৩ দিন" দেখাত অথচ `due_on` খালি
               যেত — পর্দা এক কথা বলত, খাতা আরেক।

               ⓘ অপশনগুলো সার্ভারে আঁকা, তাই এখানে আর কোনো
               `$nextTick`-এর কসরত লাগে না — মানটা আগে থেকেই বসা। */
            this.termPicked();
        },

        /**
         * এই সরবরাহকারীর গতবারের দরগুলো আনা।
         *
         * ⚠️ ব্যর্থ হলে তালিকাটা **খালি** করা হয়, পুরনোটা রাখা
         * হয় না। রেখে দিলে পর্দায় অন্য একজনের দর "এই
         * সরবরাহকারীর গতবার" নামে বসে থাকত — চুপচাপ, আর ঠিক
         * দরাদরির মুহূর্তে।
         */
        async loadLastRates(supplierId) {
            const id = Number(supplierId) || 0;

            this.supplierChosen = id > 0;
            this.lastRates = {};

            /* ⚠️ বকেয়াটাও এখানেই শূন্য হয়, দরগুলোর সাথে।
               সরবরাহকারী বদলে পুরনো সংখ্যাটা রেখে দিলে পর্দায়
               **অন্য একজনের বকেয়া** এই একজনের নামে বসে থাকত —
               ঠিক যে ভুলটা দরের বেলায় উপরে ঠেকানো হয়েছে। */
            this.previousDue = 0;

            if (id <= 0) return;

            try {
                const res = await fetch(this.lastRatesUrl.replace(/0$/, String(id)), {
                    headers: { 'Accept': 'application/json' },
                });

                if (! res.ok) return;

                const payload = await res.json();

                this.lastRates = payload.rates ?? {};
                this.previousDue = Number(payload.due) || 0;
            } catch (e) {
                /* নীরবে ছেড়ে দেওয়া — সংখ্যাটা সুবিধার, বাধ্যতামূলক
                   নয়। ওটা না এলে ক্রয় থেমে যাওয়া অনেক বড় ক্ষতি। */
            }
        },

        /** এই সারির পণ্যের গতবারের দর — না থাকলে null। */
        lastRateFor(line) {
            return this.lastRates[line.id] || null;
        },

        /** এই সারির সাথে একটা উপহার। */
        addGift(line) {
            if (! Array.isArray(line.gifts)) line.gifts = [];

            line.gifts.push({
                key: this.nextKey++,
                product_id: '',
                qty: '',
                remarks: '',
            });
        },

        pick(product) {
            this.picked = product;
            this.entry = this.blankEntry();
            this.entry.qty = '1';

            /*
             * শেষ ক্রয়দর আর চলতি বিক্রয়মূল্য বসিয়ে দেওয়া হয়,
             * কিন্তু নোঙর ফাঁকাই থাকে।
             *
             * নোঙর বসালে ঘরগুলো নিজে থেকেই একটা দর "বলত" যা
             * কেউ বেছে নেয়নি, আর সেটাই সেভ হয়ে যেত। মানুষটা
             * তিনটার একটায় হাত দিলে তবেই অঙ্ক শুরু হয়।
             */
            if (product.last_rate > 0) this.entry.rate = String(product.last_rate);
            if (product.sales_price > 0) this.entry.sales_price = String(product.sales_price);

            /*
             * ── পণ্যের **নীতি** ফিরিয়ে আনা, ৬ সেপ্টেম্বর ২০২৬ ──
             *
             * মালিকের শর্ত: *"পরের বার আর বসাতে হবে না।"* ⓘ আগে
             * দাম দুইটা ফিরত, কিন্তু নীতি ফিরত না — তাই ক্রয়দর
             * বদলালে ব্যবস্থা জানত না নতুন দাম কত হওয়া উচিত।
             *
             * ⚠️ নোঙরটা বসে **শেষে**, markup/margin বসানোর পরে:
             * `reprice()` নোঙর দেখে কাজ করে, আর আগে বসালে সে
             * খালি শতাংশ নিয়ে হিসাব করতে যেত।
             *
             * ⓘ নীতি না থাকলে (`''`) কিছুই বসে না — পুরনো পণ্যের
             * নোঙর `null`, আর তখন আচরণ আগের মতোই: দাম ফেরে,
             * ক্রয়দর বদলালে কিছু হয় না। ⭐ প্রথম যেদিন কেউ
             * markup বা margin লিখে বিল নিশ্চিত করবেন, সেদিন
             * থেকে ঐ পণ্যের নীতি চালু।
             */
            this.entry.markup = '';
            this.entry.margin = '';
            this.entry.anchor = '';
            this.lastKnownRate = product.last_rate > 0 ? String(product.last_rate) : '';

            if (product.pricing_anchor) {
                if (product.pricing_anchor === 'markup') this.entry.markup = String(product.pricing_pct);
                if (product.pricing_anchor === 'margin') this.entry.margin = String(product.pricing_pct);

                this.entry.anchor = product.pricing_anchor;
            }

            /*
             * ── ভ্যাটের ধরনটা পণ্য দেখে বসে ───────────────────
             *
             * পণ্যের নিজের হার বসানো থাকলে "পণ্য অনুযায়ী", নাহলে
             * "অঙ্ক লিখুন"।
             *
             * ⚠️ কেন সবসময় "পণ্য অনুযায়ী" নয়: হার বসানো না থাকলে
             * ওই ধরনটা সবসময় ০ দিত, আর ঘরটা হত একটা **নীরব
             * শূন্য** — মানুষ ভাবতেন ভ্যাট ধরা হয়েছে, অথচ হয়নি।
             *
             * ⓘ আর কেন সবসময় "অঙ্ক লিখুন" নয়: তাহলে যে পণ্যের হার
             * সত্যিই বসানো আছে তার ভ্যাটও প্রতিবার হাতে লিখতে হত,
             * আর একদিন কেউ ভুল লিখতেন। ⭐ হারটা যাঁর আছে, তাঁর
             * ব্যবস্থাটাই কাজে লাগে।
             */
            this.entry.vat_mode = (Number(product.tax_rate) || 0) > 0 ? 'product' : 'amount';

            this.term = '';
        },

        /** সরবরাহকারী বাছা হলো — দর, বকেয়া আর কার্ডের লাইনগুলো। */
        supplierPicked(id) {
            this.supplierId = String(id || '');
            this.loadLastRates(id);
        },

        /** কার্ডের চারটা লাইনের উৎস — বাছা সরবরাহকারীর সারি। */
        get supplier() {
            return this.suppliers.find(s => s.id === this.supplierId) || null;
        },

        /** টাইপের সাথে ছাঁকা — খালি হলে সবাই। */
        get supplierMatches() {
            const t = this.supplierTerm.trim().toLowerCase();

            if (t === '') return this.suppliers.slice(0, 40);

            /*
             * ⓘ দুইটা নামই দেখা হয়, দেখানো হয় চলতি ভাষারটা।
             *
             * ⚠️ শুধু `name` দেখলে যাঁর কীবোর্ড ইংরেজিতে তিনি
             * নিজের সরবরাহকারীকেই খুঁজে পেতেন না — মাপা লাইভে,
             * ৭ সেপ্টেম্বর ২০২৬।
             */
            return this.suppliers.filter(x =>
                (x.name || '').toLowerCase().includes(t)
                || (x.name_en || '').toLowerCase().includes(t)
                || (x.name_bn || '').toLowerCase().includes(t)
                || (x.phone || '').toLowerCase().includes(t)
            ).slice(0, 40);
        },

        chooseSupplier(id) {
            this.supplierPicked(id);
            this.supplierId = String(id);
            this.supplierTerm = '';
            this.supplierPickerOpen = false;
        },

        /**
         * ⭐ 🎁 GIFT ITEM — সারিটা কার্টে বসিয়ে তার সাথেই উপহার।
         *
         * ── কেন দুইটা কাজ এক বোতামে ─────────────────────────
         * উপহার সবসময় **কোনো একটা পণ্যের বিপরীতে** বসে
         * (`against_product_id`), আর জোড়াটা ছাড়া *"সাবানের আসল
         * ক্রয়দর কত পড়ল"* হিসাবটাই করা যায় না। ⛔ কিন্তু চলতি
         * এন্ট্রিটা এখনো কার্টে নেই, তাই জোড়া লাগানোর মতো কিছুই
         * নেই — বোতামটা তাই আগে সারিটা বসায়, তারপর তার নিচে
         * উপহারের ঘর খোলে।
         *
         * ⓘ কার্টের সারিতে "উপহার যোগ" বোতামটা আগের মতোই আছে —
         * পরে মনে পড়লে ওখান থেকেও যোগ করা যায়।
         */
        giftForThisLine() {
            if (! this.picked) return;

            this.addToCart();

            const line = this.lines[this.lines.length - 1];

            if (line) this.addGift(line);
        },

        /** এককের নাম — আইডি না থাকলে পণ্যের নিজেরটা। */
        unitName(unitId, line) {
            const options = this.packs[line.id] ?? [];
            const found = options.find(u => String(u.id) === String(unitId));

            return found ? found.label : (line.unit || '');
        },

        /* ── দরের তালিকা ────────────────────────────────────
           ⓘ `lastRates` ইতিমধ্যেই সরবরাহকারী বাছার মুহূর্তে চলে
           আসে — তালিকাটা নতুন কোনো ডাক করে না, কেবল যা আছে তা
           পড়ার মতো করে সাজায়। */
        openChart() {
            this.chartOpen = ! this.chartOpen;
        },

        get chartRows() {
            return Object.entries(this.lastRates).map(([id, row]) => {
                const product = this.catalogue.find(p => String(p.id) === String(id));

                return {
                    id,
                    name: product?.name ?? '',
                    rate: row.rate,
                    on: row.on,
                };
            }).filter(row => row.name !== '');
        },

        /** তালিকা থেকে সরাসরি এন্ট্রিতে — গতবারের দর বসানো অবস্থায়। */
        pickFromChart(row) {
            const product = this.catalogue.find(p => String(p.id) === String(row.id));

            if (! product) return;

            this.pick(product);
            this.entry.rate = String(row.rate);
            this.chartOpen = false;
        },


        pickFirst() {
            const first = this.visible[0];
            if (first) this.pick(first);
        },

        /** তিনটা ঘরের একটায় লেখা হল — বাকিগুলো নতুন করে বসে। */
        priced(edited) {
            Object.assign(this.entry, window.abos.reprice(this.entry, edited));
        },

        /*
         * দাম বলা হয়েছে অথচ দর বলা হয়নি — ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ markup মাপা হয় ক্রয়দরের উপর, তাই দর খালি থাকলে
         * ঘর দুইটা খালিই থাকে। ⚠️ কারণটা পর্দায় লেখা না
         * থাকলে সেটা ভাঙা বলে মনে হয় — মালিক নিজেই তাই ভেবেছিলেন।
         */
        get needsRate() {
            const e = this.entry || {};

            return (parseFloat(e.rate) || 0) <= 0
                && !! (e.sales_price || e.markup || e.margin);
        },

        /*
         * ── ক্রয়দর বদলাল — **জিজ্ঞেস করে**, নিজে নয় ──────────
         *
         * মালিকের শর্ত, ৬ সেপ্টেম্বর ২০২৬: *"যদি ক্রয়মূল্য কমে বা
         * বাড়ে মানে পরিবর্তন হলেই warning ও বিক্রয়মূল্য পরিবর্তন
         * হবে।"* আর কীভাবে, সেটাও তাঁর: **"জিজ্ঞেস করে বদলাবে।"**
         *
         * ⛔ নিজে বদলে দিলে কাউন্টারে কেউ খেয়াল না করে বিক্রি করে
         * ফেলতেন — দামটা বদলে গেছে, অথচ কেউ বলেনি। ⚠️ আর এই পর্দার
         * সংখ্যাগুলো সরাসরি কাগজে যায়, তাই নীরব বদল মানে **ভুল
         * দামে ছাপা চালান**।
         *
         * ⓘ তাই এখানে কেবল প্রস্তাবটা তৈরি হয় — পুরনো দর, নতুন দর,
         * আর নীতিটা মানলে দাম কত হত। বসানোর কাজটা মানুষের এক
         * চাপে (`takeSuggestedPrice`)।
         *
         * ⚠️ শর্ত তিনটাই লাগে: নীতি আছে · দর সত্যিই বদলেছে ·
         * নতুন দামটা আগেরটার চেয়ে আলাদা। ⓘ শেষেরটা ছাড়া
         * গোল করার ফলে "৳১০ → ৳১০" প্রস্তাবও দেখাত।
         */
        /*
         * ── ক্রয়দরের ঘরে লেখা হল ────────────────────────────
         *
         * ⛔ আগে এখানে সরাসরি `priced('rate')` ডাকা হত, আর নোঙর
         * markup/margin হলে সে **নিজেই দামটা বদলে দিত**। ⚠️ মেপে
         * দেখা গেছে: দর ১০০ → ১২০ করতেই দাম ১৬৬.৬৭ → ২০০ হয়ে
         * যেত, কেউ কিছু না বলতেই।
         *
         * ⓘ মালিকের সিদ্ধান্ত তার উল্টো — **"জিজ্ঞেস করে বদলাবে।"**
         *
         * ⭐ তাই দুইটা পথ, আর পার্থক্যটা **নোঙরে**:
         *
         *     নোঙর markup/margin  → নীতি আছে, দাম বদলানোর কথা
         *                            → প্রস্তাব তৈরি হয়, বসে না
         *     নোঙর দাম / নেই       → দামটাই সিদ্ধান্ত, সে টেকে
         *                            → markup ও margin নতুন করে বসে
         *
         * ⚠️ দ্বিতীয় পথে কিছু জিজ্ঞেস করা হয় না, আর সেটাই ঠিক:
         * ওখানে দাম বদলাচ্ছেই না, কেবল শতাংশ দুইটা নতুন দর ধরে
         * নিজেদের মিলিয়ে নিচ্ছে।
         */
        rateEdited() {
            const anchor = this.entry.anchor;

            if (anchor === 'markup' || anchor === 'margin') {
                this.rateChanged();

                return;
            }

            this.priced('rate');
        },

        rateChanged() {
            this.priceAsk = null;

            const anchor = this.entry.anchor;

            if (anchor !== 'markup' && anchor !== 'margin') return;

            const was = parseFloat(this.lastKnownRate);
            const now = parseFloat(this.entry.rate);

            if (! Number.isFinite(was) || ! Number.isFinite(now) || was === now) return;

            const patch = window.abos.reprice(
                { ...this.entry, rate: this.entry.rate },
                anchor,
            );

            if (! patch.sales_price || patch.sales_price === this.entry.sales_price) return;

            this.priceAsk = {
                was: this.lastKnownRate,
                now: this.entry.rate,
                from: this.entry.sales_price,
                to: patch.sales_price,
                patch,
            };
        },

        /** প্রস্তাবটা মানুষ মেনে নিলেন। */
        takeSuggestedPrice() {
            if (! this.priceAsk) return;

            Object.assign(this.entry, this.priceAsk.patch);
            this.lastKnownRate = this.entry.rate;
            this.priceAsk = null;
        },

        /** প্রস্তাবটা মানুষ ফিরিয়ে দিলেন — দাম যেমন ছিল তেমনই। */
        keepOldPrice() {
            this.lastKnownRate = this.entry.rate;
            this.priceAsk = null;
        },

        // ── চলতি লাইনের অঙ্ক ────────────────────────────────

        /** এই পণ্যের প্যাকের তালিকা — না থাকলে খালি। */
        get unitOptions() {
            return this.packs[this.picked?.id] ?? [];
        },

        /**
         * যেকোনো পণ্যের প্যাকের তালিকা — উপহারের সারির জন্য।
         *
         * ⚠️ উপরের `unitOptions` কেবল **চলতি** পণ্যের, কিন্তু উপহার
         * অন্য পণ্যও হতে পারে (সাবান কিনে তেল উপহার)। ⓘ তাই
         * আইডি ধরে আলাদা করে জিজ্ঞেস করতে হয়।
         */
        unitsFor(productId) {
            return this.packs[productId] ?? [];
        },

        /**
         * খালি বিকল্পের লেখা — ঐ পণ্যের নিজের একক।
         *
         * ⓘ ড্যাশ নয়: খালি মানে "একক বাছা হয়নি" নয়, খালি মানে
         * **পণ্যের নিজের একক** — আর সেটাই লেখা থাকা উচিত।
         */
        unitLabelFor(productId) {
            const p = this.catalogue.find(x => String(x.id) === String(productId));

            return p?.unit || '—';
        },

        get entryBase() {
            return (Number(this.entry.qty) || 0) * (Number(this.entry.rate) || 0);
        },

        /**
         * ছাড় — সবসময় টাকায়।
         *
         * ── কেন শতাংশটা এখানেই টাকা হয়ে যায় ─────────────────
         * `pur_bill_lines.discount` একটা টাকার কলাম, আর সার্ভার
         * সরাসরি বিয়োগ করে ([[CalculatesLineTotals::lineFigures]])।
         * ⛔ একই কলামে কখনো টাকা কখনো শতাংশ বসলে একদিন কেউ ৫
         * লিখতেন আর ৫ টাকা বাদ যেত, যেখানে তিনি ৫% বুঝিয়েছিলেন —
         * আর কোনো ত্রুটি হত না, কেবল সংখ্যাটা ভুল হত।
         *
         * ⭐ শতাংশটা হারায় না, রূপান্তরিত হয় — আর পর্দায় টাকার
         * অঙ্কটা পাশেই দেখা যায়, অর্থাৎ **যা দেখা যাচ্ছে সেটাই
         * সেভ হয়**।
         */
        get entryDiscount() {
            const typed = Number(this.entry.discount) || 0;

            if (this.entry.discount_mode !== 'percent') return typed;

            return this.entryBase * typed / 100;
        },

        get discountOverLine() {
            return this.entryDiscount > this.entryBase;
        },

        /**
         * ভ্যাট — তিনটা ধরনের যেটা বাছা হয়েছে।
         *
         * ⚠️ অঙ্কটা সার্ভারের সূত্রেরই নকল ([[Tax::amountOn]]):
         * ছাড়ের **পরের** টাকার উপর, আর দামের ভিতরের ভ্যাটে
         * উল্টো হিসাব। ⓘ দুই জায়গায় দুই সূত্র হলে পর্দা এক
         * সংখ্যা দেখাত আর খতিয়ানে আরেকটা বসত।
         */
        taxOn(net, mode, typed, product) {
            if (! this.vatEnabled) return 0;
            if (mode === 'none') return 0;
            if (mode === 'amount') return Number(typed) || 0;

            const rate = Number(product?.tax_rate) || 0;

            if (rate <= 0) return 0;

            /* দামের ভিতরে থাকলে মোট বাড়ে না — ১১৫-তে ১৫% মানে
               ১১৫ − (১১৫ ÷ ১.১৫) = ১৫, ১১৫ × ০.১৫ নয়। */
            return product?.tax_inclusive
                ? net - (net / (1 + rate / 100))
                : net * rate / 100;
        },

        get entryTax() {
            return this.taxOn(
                this.entryBase - this.entryDiscount,
                this.entry.vat_mode,
                this.entry.tax,
                this.picked,
            );
        },

        get entryNet() {
            const net = this.entryBase - this.entryDiscount;

            /* ভিতরের ভ্যাটে মোট বাড়ে না; দরেই ওটা আছে। */
            return this.picked?.tax_inclusive && this.entry.vat_mode === 'product'
                ? net
                : net + this.entryTax;
        },

        get entryTotalQty() {
            return (Number(this.entry.qty) || 0) + (Number(this.entry.free_qty) || 0);
        },

        toggleDiscountMode() {
            this.entry.discount_mode =
                this.entry.discount_mode === 'percent' ? 'amount' : 'percent';
        },

        addToCart() {
            if (! this.picked) return;

            this.lines.push({
                key: this.nextKey++,
                id: this.picked.id,
                name: this.picked.name,
                qty: this.entry.qty || '1',
                free_qty: this.entry.free_qty || '',
                rate: this.entry.rate || '0',

                /* ⭐ ছাড়টা **টাকায়** বসে, শতাংশে নয় — যা পর্দায়
                   দেখা যাচ্ছিল ঠিক সেটাই। ⓘ শতাংশটা এন্ট্রির
                   ভঙ্গি ছিল, সারির তথ্য নয়। */
                discount: this.entryDiscount ? String(this.entryDiscount.toFixed(4)) : '',

                /* ভ্যাটের ধরনটা সারির সাথে যায়: সারিটা কার্টে
                   বসার পরেও পর্দা জানে ঘরটা দেখাতে হবে নাকি
                   সার্ভারকে কষতে দিতে হবে। */
                vat_mode: this.entry.vat_mode,
                tax: this.entry.tax || '',

                /* প্যাকের দুইটা ঘর — না বসালে সারিটা কার্টে
                   গিয়ে একক হারাত, আর "১ বাক্স" পিস হয়ে যেত। */
                unit_id: this.entry.unit_id || '',
                free_unit_id: this.entry.free_unit_id || '',
                unit: this.picked.unit || '',
                tax_rate: this.picked.tax_rate || 0,
                tax_inclusive: this.picked.tax_inclusive || false,

                sales_price: this.entry.sales_price || '',

                /*
                 * দামের নীতি — কোন ঘরটা মানুষ নিজে লিখেছিলেন।
                 *
                 * ⓘ সার্ভার এটা লাইনে লেখে, আর বিল নিশ্চিত হলে
                 * পণ্যেও বসিয়ে দেয়। ⭐ পরের বার ঐ পণ্য বাছলে
                 * নীতিটা ফিরে আসে, আর ক্রয়দর বদলালে পর্দা
                 * জানে নতুন দাম কত হওয়া উচিত।
                 *
                 * ⚠️ নোঙর `sales_price` হলে শতাংশ পাঠানো হয় না:
                 * সেখানে নীতিটা *"দামটাই ঠিক"*, কোনো শতাংশ নয়।
                 */
                pricing_anchor: this.entry.anchor || '',
                pricing_pct: this.entry.anchor === 'markup'
                    ? (this.entry.markup || '')
                    : (this.entry.anchor === 'margin' ? (this.entry.margin || '') : ''),

                /* উপহারের তালিকা সারির সাথেই জন্মায়, চাহিদামতো
                   নয় — `line.gifts` না থাকলে Alpine-এর x-for
                   undefined-এ হোঁচট খেত, আর হ্যান্ডলারটা মাঝপথে
                   থেমে যেত। */
                gifts: [],
            });

            this.clearEntry();

            /* ?. — একটা ঘর খুঁজে না পাওয়া কখনো পুরো পর্দা
               থামানোর কারণ হওয়া উচিত নয়। এখানে ঠিক তা-ই
               হয়েছিল: focus() এররে Alpine থেমে যেত, কার্টের
               ঘরগুলোর name বাঁধা হত না, আর সাবমিটে সার্ভার
               কোনো লাইনই পেত না। */
            this.$nextTick(() => this.$refs.search?.focus());
        },

        clearEntry() {
            this.picked = null;
            this.entry = this.blankEntry();
            this.term = '';
        },

        clearAll() {
            this.lines = [];
            this.paidNow = '';

            /* ⚠️ জমাগুলোও — নাহলে নতুন বিলে আগের বিলের টাকা
               বসে থাকত, আর কেউ সেটা খেয়াল না করে নিশ্চিত করে
               ফেলতেন। */
            this.deposits = [];
            this.depositDraft = {
                methodId: '', accountId: '', amount: '', reference: '', refDate: '', narration: '',
            };

            this.carrierId = '';
            this.carrierName = '';
            this.transportCost = '';
            this.vehicleNo = '';
            this.driverName = '';

            /* ⚠️ তিনটা প্যানেলও বন্ধ হয় — খোলা রেখে দিলে নতুন
               ক্রয়ের পর্দায় আগের সরবরাহকারীর দরের তালিকা খুলে
               বসে থাকত, আর কেউ ওই দর ধরে দরাদরি করতেন। */
            this.chartOpen = false;
            this.depositOpen = false;
            this.transportOpen = false;
            this.noteOpen = false;
            this.shipmentOpen = false;

            this.clearEntry();
        },

        // ── কার্টের অঙ্ক ────────────────────────────────────

        /* ⚠️ সারির ভ্যাটটা আর সরাসরি `line.tax` নয়।

           সারিটা নিজের ধরন মনে রাখে, তাই "পণ্য অনুযায়ী" হলে
           অঙ্কটা পণ্যের হার থেকে কষতে হয় — ঠিক যেভাবে সার্ভার
           কষবে। ⛔ আগের মতো `line.tax` পড়লে ওই সারিগুলোর ভ্যাট
           পর্দায় ০ দেখাত, অথচ খতিয়ানে বসত পুরো অঙ্ক, আর
           "মোট দেয়" দুই জায়গায় দুই রকম হত। */
        lineTax(line) {
            const base = (Number(line.qty) || 0) * (Number(line.rate) || 0);

            return this.taxOn(base - (Number(line.discount) || 0), line.vat_mode, line.tax, line);
        },

        lineNet(line) {
            const base = (Number(line.qty) || 0) * (Number(line.rate) || 0);
            const net = base - (Number(line.discount) || 0);

            /* দামের ভিতরের ভ্যাটে মোট বাড়ে না; দরেই ওটা আছে। */
            return line.tax_inclusive && line.vat_mode === 'product'
                ? net
                : net + this.lineTax(line);
        },

        /** ছবির `Total Qty` — কেনা আর ফ্রি একসাথে। */
        lineTotalQty(line) {
            return (Number(line.qty) || 0) + (Number(line.free_qty) || 0);
        },

        get subTotal() {
            return this.lines.reduce(
                (s, l) => s + (Number(l.qty) || 0) * (Number(l.rate) || 0) - (Number(l.discount) || 0), 0,
            );
        },

        get taxTotal() {
            if (! this.vatEnabled) return 0;

            return this.lines.reduce((s, l) => s + this.lineTax(l), 0);
        },

        /**
         * মন্তব্যের ঘরটা খুলে কার্সর ভিতরে।
         *
         * ⚠️ `$nextTick` ছাড়া `focus()` কিছুই করত না: ওই মুহূর্তে
         * ঘরটা এখনো `x-show`-এর নিচে লুকানো, আর লুকানো ঘরে
         * কার্সর বসে না। ⓘ বোতামটা তখন খুলত ঠিকই, কিন্তু
         * লিখতে আরেকটা ক্লিক লাগত।
         */
        openNote() {
            this.noteOpen = ! this.noteOpen;

            if (this.noteOpen) this.$nextTick(() => this.$refs.note?.focus());
        },

        /** ছবির `Total Purchase Qnty` — কেবল কেনা, ফ্রি ছাড়া। */
        get boughtQty() {
            return this.lines.reduce((s, l) => s + (Number(l.qty) || 0), 0);
        },

        /** রসিদের ছকের "ফ্রি পাওয়া গেল" — কেবল পরিমাণ, টাকা নয়। */
        get freeTotal() {
            return this.lines.reduce((s, l) => s + (Number(l.free_qty) || 0), 0);
        },

        /* ⏳ খরচ ও রাউন্ডিং এখানে যোগ হবে — কিন্তু সেবা ও
           ডাটাবেসের ঘর বসার পরেই, একসাথে। কারণটা কার্ডের
           কমেন্টে লেখা: পর্দায় যোগ করে খতিয়ানে না বসালে
           সংখ্যাটা নীরবে মিথ্যা হয়। */
        get netPayable() {
            return this.subTotal + this.taxTotal;
        },

        /* এই বিলে কত বাকি — আগে এটার নাম ছিল `balanceDue`।
           ⓘ নামটা বদলেছে কারণ পর্দায় এখন **দুইটা** বকেয়া:
           এই বিলেরটা, আর সরবরাহকারীকে মোট। এক নামে দুই অর্থ
           থাকলে দুইজন মানুষ দুইটা উত্তর পান। */
        get invoiceDue() {
            return this.balanceDue;
        },

        /*
         * ⭐ সরবরাহকারীকে মোট কত — ছবির `DUE`।
         *
         * `DUE = এই বিলে বাকি + আগের বকেয়া`
         *
         * ⚠️ আগের বকেয়া ঋণাত্মক হতে পারে — অগ্রিম দেওয়া
         * থাকলে। ⓘ তখন যোগফলটা এমনিতেই কমে, আর সেটাই ঠিক:
         * অগ্রিম টাকাটা এই বিলের দায় মেটায়।
         */
        get totalDue() {
            return this.invoiceDue + this.previousDue;
        },


        /* ⚠️ দুইটাই বাদ যায় — জমার সারিগুলো **আর** পুরনো একক
           ঘরটা। ⓘ পর্দায় আজ কেবল সারিগুলোই ভরা হয়, কিন্তু
           `paidNow` এখনো কোডে আছে (API ও ইমপোর্টের জন্য), আর
           যোগফল থেকে বাদ না দিলে কোনো একদিন বকেয়া ভুল দেখাত। */
        get balanceDue() {
            const paid = this.paidTotal + (Number(this.paidNow) || 0);
            const due = this.netPayable - paid;

            return due > 0 ? due : 0;
        },

        get totalQty() {
            return this.lines.reduce(
                (s, l) => s + (Number(l.qty) || 0) + (Number(l.free_qty) || 0), 0,
            );
        },

        /*
         * পাঠানোর আগে দুইটা প্রশ্ন।
         *
         * বেশি টাকা দেওয়া মানে সরবরাহকারীর কাছে অগ্রিম জমা —
         * সেটা বৈধ, কিন্তু বেশিরভাগ সময় ওটা টাইপো। আর দুইবার
         * পাঠানো মানে দুইটা চালান, দুইবার মাল।
         */
        /* ভাড়া আছে অথচ কে আনল বলা নেই — সার্ভারও এটাই আটকায়,
           কিন্তু পর্দায় আগে বলাটাই ভদ্রতা: সাবমিটের পর ভুল
           দেখানো মানে বিশ লাইন টাইপ করার পর জানা। */
        get transportNeedsWho() {
            return Number(this.transportCost) > 0
                && ! this.carrierId
                && this.carrierName.trim() === '';
        },

        /** ধরনটার প্রথম অংশ — `credit:7` থেকে `credit`। */
        get termKind() {
            return String(this.termChoice || '').split(':')[0];
        },

        /** কোম্পানির ছকে দেখানোর জন্য — `2026-09-20` → `20-09-2026`। */
        get dueOnShown() {
            const parts = String(this.dueOn || '').split('-');

            return parts.length === 3 ? `${parts[2]}-${parts[1]}-${parts[0]}` : '';
        },

        /**
         * শর্তটাকে একটা তারিখে অনুবাদ করা।
         *
         * ⚠️ গোনাটা **বিলের তারিখ থেকে**, আজ থেকে নয় — পুরনো
         * তারিখের বিল তোলা হলে পরিশোধের তারিখও পিছিয়ে বসে।
         *
         * ⓘ `a fixed date`-এ কিছু গোনা হয় না: তারিখটা মানুষটা
         * নিজে বাছেন, আর নিচের লাইনটাই তখন লেখার ঘর।
         */
        termPicked() {
            const [kind, days] = String(this.termChoice || '').split(':');

            if (kind === 'fixed') {
                /* ⚠️ আগের হিসাব করা তারিখটা মুছে দেওয়া হয়,
                   নাহলে মানুষটা তারিখের ঘরে একটা **আগের
                   হিসাবের** তারিখ বসা দেখতেন আর ভাবতেন
                   তিনিই বসিয়েছেন। */
                this.dueOn = '';

                return;
            }

            const from = new Date(this.boxValue('trx_date') || Date.now());

            if (Number.isNaN(from.getTime())) {
                this.dueOn = '';

                return;
            }

            if (kind === 'month_end') {
                /*
                 * ⭐ বিলের **মাসের** শেষ দিন — মালিকের
                 * `Cr. Upto Closing date`।
                 *
                 * ⚠️ `new Date(y, m + 1, 0)` মানে "পরের মাসের
                 * শূন্যতম দিন", অর্থাৎ চলতি মাসের শেষ দিন।
                 * ⛔ `addDays(30)` দিয়ে গুনলে ফেব্রুয়ারিতে
                 * মার্চে গিয়ে পড়ত, আর লিপ ইয়ারে আরও একদিন।
                 *
                 * ⓘ মাসটা **বিলের তারিখের** মাস, আজকের নয় —
                 * পুরনো তারিখের বিল বসালে ঐ মাসের শেষ।
                 */
                this.dueOn = this.isoDate(
                    new Date(from.getFullYear(), from.getMonth() + 1, 0)
                );

                return;
            }

            if (kind === 'cash') {
                this.dueOn = this.isoDate(from);

                return;
            }

            from.setDate(from.getDate() + (Number(days) || 0));
            this.dueOn = this.isoDate(from);
        },

        /**
         * তারিখটা `YYYY-MM-DD` হয়ে।
         *
         * ⚠️ `toISOString()` নয় — ওটা UTC-তে নামায়, আর
         * বাংলাদেশে সন্ধ্যার পর তারিখটা একদিন পিছিয়ে যেত।
         */
        isoDate(d) {
            return [
                d.getFullYear(),
                String(d.getMonth() + 1).padStart(2, '0'),
                String(d.getDate()).padStart(2, '0'),
            ].join('-');
        },

        /** বাছা উপায়টার সারি — id ধরে। */
        get depositMethod() {
            return this.depositMethods.find(
                m => String(m.id) === String(this.depositDraft.methodId)
            ) || null;
        },

        get depositNeedsReference() {
            return !! this.depositMethod?.needsReference;
        },

        /* ── কোন উপায়ে কোন খাত ─────────────────────────────

           ⚠️ ক্রয়ের চেক বিক্রয়ের চেকের উল্টো, আর নকল করলে ভুল
           হত। বিক্রয়ে চেক **পাওয়া** যায়, তাই ওদিকে ১১০৪ (হাতে
           আসা চেক) — ব্যাংক নয়, কারণ পাওয়া চেক এখনো টাকা নয়।
           ক্রয়ে চেক **দেওয়া** হয়, আর তখন ব্যাংকের খাতাই কমে।

           ⛔ হিসাবের দিক থেকে ইস্যু করা চেকের আসল ঘর `2115`
           (ইস্যু করা চেক, একটা দায়) — পাশ হওয়া পর্যন্ত ব্যাংক
           কমার কথা নয়। ⓘ কিন্তু আজকের [[PaymentService::confirm]]
           যে খাত বাছা হয় সেটাই কমায়, `instrument` যা-ই হোক —
           অর্থাৎ ফাঁকটা এই প্যানেলের আগেও ছিল, আর চেক-রেজিস্টার
           ([[ChequeService]]) ক্রয়ের পরিশোধে যুক্ত হলে তবেই
           সারবে। **এখানে লিখে রাখা হলো, যাতে দিনটা এলে জায়গাটা
           খুঁজতে না হয়।**

           ⚠️ উপায় না বাছা পর্যন্ত **একটাও খাত নয়** — খালি
           তালিকা আর "সব খাত" দুইটা আলাদা অবস্থা। সব দেখালে কেউ
           নগদের খাতে চেকের টাকা বসিয়ে দিতেন। */
        depositKindParents: {
            ...accountCodes,
        },

        get depositAccounts() {
            if (! this.depositDraft.methodId) return [];

            const parent = this.depositKindParents[this.depositMethod?.kind];

            /* ⓘ উপায়ের `kind` অচেনা হলে ছাঁকনিটা চুপ করে থাকে,
               সব খাত দেখায় — ভুল কনফিগে পর্দাটা অচল হওয়ার চেয়ে
               সেটা ভালো। */
            if (! parent) return this.moneyAccounts;

            return this.moneyAccounts.filter(a => a.parent === parent);
        },

        /** উপায় বাছার সাথে সাথে তার নিজের খাতটা বসে যায়। */
        methodPicked() {
            this.depositDraft.accountId = this.depositMethod?.accountId || '';
        },

        get depositReady() {
            return this.depositDraft.methodId !== ''
                && this.depositDraft.accountId !== ''
                && Number(this.depositDraft.amount) > 0;
        },

        addDeposit() {
            if (! this.depositReady) return;

            this.deposits.push({ ...this.depositDraft });

            this.depositDraft = {
                methodId: '', accountId: '', amount: '', reference: '', refDate: '', narration: '',
            };
        },

        dropDeposit(index) {
            this.deposits.splice(index, 1);
        },

        /** তালিকায় নাম দেখানোর জন্য — id নয়। */
        methodName(id) {
            return this.depositMethods.find(
                m => String(m.id) === String(id)
            )?.label || '';
        },

        /* ⓘ সার্ভারও এই যোগটা নিজে করে — পর্দার সংখ্যা বিশ্বাস
           করে খাতায় কিছু বসানো হয় না। */
        get paidTotal() {
            return this.deposits.reduce(
                (sum, row) => sum + (Number(row.amount) || 0), 0
            );
        },

        guard(event) {
            if (this.busy || this.lines.length === 0) {
                event.preventDefault();

                return;
            }

            const paid = this.paidTotal + (Number(this.paidNow) || 0);

            if (paid > this.netPayable
                && ! window.confirm(texts.paidMoreConfirm)) {
                event.preventDefault();

                return;
            }

            /* ⚠️ ভাড়া লিখে কে আনল না বললে সার্ভার ফিরিয়ে দেবে।
               এখানে আগেই থামানো হয়, নাহলে পুরো ফর্মটা গিয়ে
               ভুলসহ ফিরত — আর সেটা কাউন্টারে এক মিনিটের ক্ষতি। */
            if (this.transportNeedsWho) {
                event.preventDefault();

                return;
            }

            /* ⚠️ মোছা নয়, সরিয়ে রাখা — সার্ভার ফিরিয়ে দিলে
               পাতাটা যেন খালি হয়ে না ফেরে। */
            this.parkDraft();

            this.busy = true;
        },

        money(v) {
            return Number(v || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2, maximumFractionDigits: 2,
            });
        },

        qty(v) {
            return String(Number(v || 0));
        },
    };
}
