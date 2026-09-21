/*
 * কাউন্টারের POS — আগে `pos/index.blade.php`-এর `<script @nonce>`-এ ছিল।
 *
 * ⓘ ১৯ সেপ্টেম্বর ২০২৬-এ এখানে এসেছে (নিরীক্ষার ধাপ ৩.১): CSP-Alpine
 * বৈশ্বিক `pos()` দেখতে পায় না, কেবল `Alpine.data()`-এ নিবন্ধিত নাম।
 * ব্লেডের দুইটা ঠিকানা আর একটা অনুবাদ এখন শেষ প্যারামিটারে (`urls`,
 * `texts`)। যুক্তি হুবহু।
 */

import { taka } from './components/money.js'


export function pos(catalogue, walkinId, resumed, discountOn, methods, { urls = {}, texts = {} } = {}) {
    return {
        catalogue,
        walkinId,
        discountOn,
        methods,

        /*
         * ভাগ করে পরিশোধ — শুরুতে বন্ধ।
         *
         * বেশিরভাগ বিক্রয় এক উপায়েই; ঘরগুলো খোলা রাখলে
         * প্রতিটা নগদ বিক্রয়ে বাড়তি ট্যাব চাপতে হত।
         */
        splitting: false,
        payments: [],

        /*
         * কি-বোর্ডের সাহায্য — F1।
         *
         * ── কেন এটা ছাড়া বাকি কি-গুলোর মানে নেই ─────────
         * দশটা শর্টকাট থাকা আর না থাকা সমান, যদি কেউ না
         * জানে কোনটা কী করে। নতুন ক্যাশিয়ার প্রথম দিনেই
         * F1 চেপে তালিকাটা দেখতে পান, আর তারপর আর লাগে না।
         */
        helping: false,

        closePanels() {
            this.helping = false;
            this.returning = false;
        },

        // ── কাউন্টার থেকেই ফেরত ──────────────────────────
        returning: false,
        billNo: '',
        bill: null,
        billError: '',
        refunding: true,

        openReturn() {
            this.returning = true;
            this.$nextTick(() => this.$refs.billNo?.focus());
        },

        /*
         * নম্বর ধরে বিলটা আনা।
         *
         * নেট গেলে কাউন্টার থামে না: ব্যর্থ হলে কেবল একটা
         * বার্তা দেখায়, ঝুড়ি ও বিক্রয় আগের মতোই চলে।
         */
        async findBill() {
            this.bill = null;
            this.billError = '';

            const no = String(this.billNo || '').trim();

            if (no === '') {
                return;
            }

            try {
                const response = await fetch(
                    `${urls.bill}?no=${encodeURIComponent(no)}`,
                    {headers: {Accept: 'application/json'}},
                );

                if (!response.ok) {
                    this.billError = texts.billNotFound;

                    return;
                }

                const bill = await response.json();

                // প্রতিটা সারিতে "কতটুকু ফেরত নিচ্ছি" — শুরুতে
                // খালি, কারণ বেশিরভাগ ফেরতে এক-দুইটা পণ্যই আসে
                bill.lines.forEach((l) => { l.take = ''; });

                this.bill = bill;
            } catch (e) {
                this.billError = texts.billNotFound;
            }
        },

        /** এই লাইনে আর কতটুকু ফেরত নেওয়া যায়। */
        roomOn(line) {
            return Math.max(0, Number(line.qty) - Number(line.returned || 0));
        },

        /*
         * তোলা বিলের সারিগুলো নিয়েই পর্দা খোলে।
         *
         * সার্ভার সারিগুলো পাঠায় লাইনের চেহারায় (product_id),
         * আর কার্ট চেনে id নামে — তাই এখানেই বদলে নেওয়া হয়।
         * না বদলালে সারিগুলো দেখা যেত, কিন্তু একই পণ্য আবার
         * চাপলে নতুন সারি হত আর রসিদে জিনিসটা দুইবার থাকত।
         */
        lines: (resumed || []).map(l => ({
            id: l.product_id,
            name: l.name,
            rate: l.rate,
            qty: Number(l.qty),
            discount: l.discount || '',
        })),

        term: '',
        paid: '',

        /*
         * শেষ যে কোডটা খুঁজে পাওয়া যায়নি।
         *
         * নীরবে কিছু না করলে ক্যাশিয়ার দ্বিতীয়বার, তৃতীয়বার
         * স্ক্যান করতেন আর ভাবতেন স্ক্যানারটা নষ্ট। কোডটা
         * দেখিয়ে দিলে তিনি অন্তত জানেন যন্ত্র পড়েছে,
         * ব্যবস্থা চেনেনি।
         */
        notFound: '',

        get visible() {
            const t = this.term.trim().toLowerCase();

            // খালি ঘরে সব পণ্য — কাউন্টারে বেশিরভাগ সময় লোকে
            // খোঁজে না, চোখে দেখে বেছে নেয়
            if (t === '') return this.catalogue.slice(0, 60);

            return this.catalogue.filter(p =>
                p.name.toLowerCase().includes(t)
                || p.code.toLowerCase().includes(t)
                || (p.barcode || '').toLowerCase().includes(t)
            ).slice(0, 60);
        },

        /*
         * Enter চাপলে প্রথমটা ঝুড়িতে।
         *
         * বারকোড স্ক্যানার শেষে Enter পাঠায়, আর বারকোডে
         * সাধারণত একটাই পণ্য মেলে — তাই স্ক্যান করলেই সরাসরি
         * ঝুড়িতে চলে যায়, কোনো ক্লিক ছাড়াই।
         */
        takeFirst() {
            const first = this.visible[0];

            if (first) {
                this.add(first);
                return;
            }

            /*
             * পাতার তালিকায় নেই — সার্ভারকে জিজ্ঞেস করা হয়।
             *
             * ── ওষুধের কার্টনে এটাই একমাত্র পথ ─────────────
             * GS1 DataMatrix স্ক্যান করলে স্ক্যানার পাঠায় গোটা
             * element string (পণ্য + লট + মেয়াদ একসাথে)। ওই
             * লেখাটা পাতার তালিকার নাম/কোড/বারকোডের কোনোটার
             * সাথেই মেলে না, তাই স্থানীয় খোঁজা সবসময় খালি
             * ফেরে — আর ক্যাশিয়ার দেখেন "পণ্য নেই", অথচ
             * প্যাকেটটা তাঁর হাতেই।
             *
             * সার্ভারের lookup বারকোডটা ভেঙে GTIN বের করে,
             * আর সাথে লট ও মেয়াদও ফেরত দেয়।
             */
            const scanned = this.term.trim();

            if (scanned === '') return;

            this.askServer(scanned);
        },

        async askServer(code) {
            try {
                const response = await fetch(
                    urls.lookup + '?code=' + encodeURIComponent(code),
                    { headers: { 'Accept': 'application/json' } },
                );

                if (!response.ok) {
                    this.notFound = code;
                    return;
                }

                const product = await response.json();

                this.add({
                    id: product.id,
                    name: product.name,
                    rate: product.rate,
                    batch: product.scanned_batch,
                    expiry: product.scanned_expiry,
                });
            } catch (e) {
                /*
                 * নেট গেলে কাউন্টার থামে না।
                 *
                 * খোঁজাটা ব্যর্থ হলে কেবল "পাওয়া গেল না"
                 * দেখায়; ঝুড়ি, মোট আর ছাপা সবই আগের মতো
                 * চলে। ব্যতিক্রম ছড়াতে দিলে Alpine-এর পুরো
                 * অংশটা থেমে যেত।
                 */
                this.notFound = code;
            }
        },

        add(product) {
            this.notFound = '';

            const existing = this.lines.find(l => l.id === product.id);

            // একই পণ্য দ্বিতীয়বার স্ক্যান করলে নতুন সারি নয়,
            // পরিমাণ এক বাড়ে — রসিদে একই জিনিস দুই সারিতে
            // দেখলে গ্রাহক ভাবেন দুইবার ধরা হয়েছে
            if (existing) {
                existing.qty = Number(existing.qty) + 1;
            } else {
                this.lines.push({
                    id: product.id,
                    name: product.name,
                    rate: product.rate,
                    qty: 1,

                    // ছাড় শুরুতে খালি — শূন্য লিখলে ঘরটায়
                    // "0" বসে থাকত আর টাইপ করতে আগে মুছতে হত
                    discount: '',

                    /*
                     * স্ক্যানে পাওয়া লট ও মেয়াদ — কেবল
                     * দেখানোর জন্য, কার্টে পাঠানোর জন্য নয়।
                     *
                     * কোন লট বেরোবে সেটা FEFO ঠিক করে মাল
                     * বেরোনোর মুহূর্তে। এখানে সংখ্যাগুলো
                     * থাকে যাতে ক্যাশিয়ার হাতের প্যাকেটের
                     * সাথে মিলিয়ে নিতে পারেন — বিশেষত
                     * মেয়াদটা, যেটা ছোট ছাপায় পড়া কঠিন।
                     */
                    batch: product.batch ?? null,
                    expiry: product.expiry ?? null,
                });
            }

            this.term = '';
            this.$nextTick(() => this.$refs.search.focus());
        },

        remove(index) {
            this.lines.splice(index, 1);
        },

        /*
         * ভাগ করে দেওয়া শুরু — প্রথম সারিতে পুরো টাকাটাই।
         *
         * খালি সারি দিয়ে শুরু করলে ক্যাশিয়ারকে দুইবার টাইপ
         * করতে হত: একবার মোট, একবার প্রথম ভাগ। পুরোটা বসিয়ে
         * দিলে তিনি কেবল দ্বিতীয় সারিতে যতটা সরাতে চান
         * ততটুকুই লেখেন।
         */
        startSplit() {
            this.splitting = true;

            if (this.payments.length === 0) {
                this.payments = [
                    {method_id: '', amount: (this.paid || this.total.toFixed(2)), reference: ''},
                    {method_id: '', amount: '', reference: ''},
                ];
            }
        },

        get splitTotal() {
            return this.payments.reduce((sum, p) => sum + (Number(p.amount) || 0), 0);
        },

        needsReference(part) {
            const found = this.methods.find((m) => String(m.id) === String(part.method_id));

            return Boolean(found && found.needs_reference);
        },

        lineBase(line) {
            return (Number(line.qty) || 0) * (Number(line.rate) || 0);
        },

        /*
         * ছাড় বাদ দিয়ে লাইনের টাকা।
         *
         * ছাড় লাইনের চেয়ে বেশি হতে পারে না — সেবাটাও সেটা
         * আটকায়, কিন্তু পর্দায় ঋণাত্মক সংখ্যা দেখানো মানে
         * ক্রেতাকে ভুল মোট দেখানো, আর সেটা সংশোধনের আগেই
         * বলা হয়ে যায়।
         */
        lineTotal(line) {
            const base = this.lineBase(line);
            const off = Math.min(Number(line.discount) || 0, base);

            return base - off;
        },

        get total() {
            return this.lines.reduce((sum, l) => sum + this.lineTotal(l), 0);
        },

        money(v) {
            return taka(v);
        },

        qty(v) {
            return String(Number(v || 0));
        },
    };
}
