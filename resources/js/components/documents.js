/*
 * কাগজের সারিগুলো — চালান, ফেরত, আদায়, পরিশোধ, আর বাল্ক শীট।
 *
 * ⓘ আগে প্রতিটা ব্লেডের `x-data="{ … }"`-এ ছিল; ১৯ সেপ্টেম্বর ২০২৬-এ
 * এখানে (নিরীক্ষার ধাপ ৩.১) — CSP-Alpine অ্যাট্রিবিউটের ভিতরে পদ্ধতি,
 * `=>` বা `??` পড়ে না। যুক্তি হুবহু, মন্তব্যসহ; ব্লেড থেকে আসা মানগুলো
 * এখন `config`-এ, আর `$refs`/`$dispatch`-এর আগে `this.`।
 */

export function salesLineEditor (config = {}) {
    return {
        rows: config.rows,
        packs: config.packs,
        packDefaults: config.packDefaults || {},

        // ⓘ কেবল অর্ডারের পর্দা এটা পাঠায়; বাকিদের কাছে `{}`।
        stock: config.stock || {},
        unitsFor(row) {
            return this.packs[row.product_id] ?? [];
        },

        /*
         * পণ্য বাছলে কোন প্যাকটা বসবে — মালিকের বাছাই, ধাপ ৫।
         *
         * ⓘ পণ্যের ফর্মের রেডিও থেকে আসা তালিকা (`packDefaults`)। ⚠️ কিছু
         * না বাছা থাকলে ফাঁকা স্ট্রিং, আর তখন সার্ভার পণ্যের নিজের একক
         * ধরে — আগের আচরণ হুবহু।
         */
        defaultUnit(productId) {
            const chosen = (this.packDefaults || {})[productId];

            return chosen === undefined ? '' : String(chosen);
        },
        add() {
            this.rows.push({
                product_id: '', qty: '', rate: '', discount: '', tax: '', link: '', unit_id: '',
            });
        },
        remove(i) {
            this.rows.splice(i, 1);
            if (this.rows.length === 0) this.add();
        },

        /*
         * চার্ট/বাল্ক শীট থেকে আসা সারিগুলো।
         *
         * ── কেন মিলিয়ে বসানো হয়, বদলে দেওয়া হয় না ──────────────────
         * অর্ডার ধরে খোলা চালানে লাইনগুলো আগে থেকেই ভরা থাকে। শীট
         * Apply করলে ওগুলো মুছে গেলে মানুষ ভাবতেন শীটটা কিছু নষ্ট
         * করেছে — অথচ তিনি শুধু আরও কয়েকটা পণ্য যোগ করতে চেয়েছিলেন।
         *
         * একই পণ্য দুই জায়গায় থাকলে শীটের সংখ্যাটাই থাকে: শীটে তিনি
         * সবে ওটা টাইপ করেছেন, আর নতুন কথাটাই শেষ কথা।
         */
        absorb(rows) {
            for (const row of rows) {
                const existing = this.rows.find(r => String(r.product_id) === String(row.product_id));

                if (existing) {
                    existing.qty = row.qty;
                    existing.rate = row.rate || existing.rate;
                    continue;
                }

                this.rows.push({
                    product_id: String(row.product_id),
                    qty: row.qty, rate: row.rate ?? '',
                    discount: '', tax: '', link: '', unit_id: '',
                });
            }

            // শুরুর খালি সারিটা — শীট থেকে আসার পর ওটা কেবল একটা ফাঁকা ঘর
            this.rows = this.rows.filter(r => r.product_id !== '' || this.rows.length === 1);
        },
        amount(row) {
            const base = (parseFloat(row.qty) || 0) * (parseFloat(row.rate) || 0);
            const net = base - (parseFloat(row.discount) || 0);
            return net + (parseFloat(row.tax) || 0);
        },
        get total() {
            return this.rows.reduce((sum, row) => sum + this.amount(row), 0);
        },

        /*
         * ⭐ মোটটা ভেঙে দেখানো — ২১ সেপ্টেম্বর ২০২৬, অর্ডারের পর্দার জন্য।
         *
         * ⓘ নিচে এতদিন কেবল একটা সংখ্যা বসত। ⚠️ কিন্তু মালিক অর্ডার
         * নেওয়ার সময় ছাড় দেন, আর "মোট কত" আর "ছাড় কত" এক সংখ্যায়
         * মিশে গেলে ফোনে গ্রাহককে বলার মতো কিছু থাকে না।
         *
         * ⛔ যোগফলগুলো সার্ভারে আবার কষা হয় ([[CalculatesLineTotals]]) —
         * এগুলো কেবল সেভ করার আগে চোখে দেখার জন্য।
         */
        get subtotal() {
            return this.rows.reduce(function (sum, row) {
                return sum + (parseFloat(row.qty) || 0) * (parseFloat(row.rate) || 0);
            }, 0);
        },
        get discountTotal() {
            return this.rows.reduce(function (sum, row) {
                return sum + (parseFloat(row.discount) || 0);
            }, 0);
        },
        get taxTotal() {
            return this.rows.reduce(function (sum, row) {
                return sum + (parseFloat(row.tax) || 0);
            }, 0);
        },

        /*
         * ⭐ এই পণ্যের কতটা বিক্রয়যোগ্য আছে।
         *
         * ── ⛔ এটা অর্ডার আটকায় না, আর সেটা ইচ্ছাকৃত ─────────────────
         * abos-8b-র কথা, আর কথাটা ঠিক: অর্ডার **ভবিষ্যতের কাগজ**। মাল
         * কাল আসতে পারে, আর আজ মজুদ নেই বলে অর্ডারটা নেওয়া যাবে না —
         * এমন নিয়ম ব্যবসাটাই আটকে দিত।
         *
         * ⓘ তাই কেবল দেখানো হয়। ⚠️ মজুদের তালিকা না এলে (`{}`) ঘরটাই
         * আসে না — অন্য পাঁচটা ফর্মে তখন কিছুই বদলায় না।
         */
        stockFor(row) {
            const key = String(row.product_id || '');

            if (key === '' || this.stock[key] === undefined) return null;

            return parseFloat(this.stock[key]) || 0;
        },

        init() {
            if (this.rows.length === 0) this.add();
        },
    }
}

/*
 * ⭐ অর্ডারের পর্দার উপরের অংশ — ২১ সেপ্টেম্বর ২০২৬।
 *
 * ── ⓘ মালিকের নির্দেশ ও তাঁর উত্তর ───────────────────────────────────
 * *"New order form ali update koro zate direct sales er activiti gulo hoy
 * ekhane, ekhon to ekhane kichui hoyna"* — অর্ডারের ফর্মে সরাসরি বিক্রয়ের
 * সুবিধাগুলো চাই।
 *
 * ⛔ কিন্তু সবটা নয়। জিজ্ঞেস করা হয়েছিল *"অর্ডার নেওয়ার সময় কি টাকাও
 * নেন?"*, আর মালিকের উত্তর: **না, অর্ডার আলাদা, টাকা পরে**। তাই টাকা
 * নেওয়ার প্যানেল, ক্যাশ ড্রয়ার আর নোট গোনা এখানে **নেই** — বসালে
 * ব্যবহারকারী ভাবতেন টাকা নেওয়া হয়ে গেছে, অথচ কিছুই হয়নি।
 *
 * ⚠️ এই শ্রেণিটা সারির হিসাব জানে না — ওটা `salesLineEditor`-এর কাজ।
 * এখানে কেবল ক্রেতাটি কে, আর তাঁর খাতা কেমন।
 */
export function salesOrderDesk (config = {}) {
    return {
        terms: config.terms || {},
        barcodes: config.barcodes || {},
        customerId: String(config.customerId || ''),
        code: '',
        missed: '',

        /* বাছা ক্রেতার ঘরগুলো — কেউ না বাছলে `null`, আর পটিটাই আসে না। */
        get party() {
            const found = this.terms[this.customerId];

            return found === undefined ? null : found;
        },

        /*
         * ⭐ সীমা ছাড়ানো আছে কি না — **অর্ডার নেওয়ার আগেই**।
         *
         * ⓘ abos-8b-র কথা: এটা ডেলিভারির দিন জানা মানে দেরিতে জানা।
         * ⚠️ সংখ্যাটা কেবল **আজকের বকেয়া**; এই অর্ডারটা এখনো খাতায়
         * ওঠেনি, তাই ওটা এর মধ্যে ধরা নেই।
         *
         * ⛔ সীমা শূন্য মানে "সীমা বসানো হয়নি", "শূন্য টাকার সীমা" নয় —
         * তাই তখন কোনো সতর্কবার্তা নেই।
         */
        get overLimit() {
            const party = this.party;

            if (party === null) return false;

            const limit = parseFloat(party.limit) || 0;

            if (limit <= 0) return false;

            return (parseFloat(party.due) || 0) > limit;
        },

        /*
         * ⭐ বারকোড — স্ক্যান করলে সারিটা নিজেই বসে।
         *
         * ── ⓘ কেন এটা সারির সম্পাদককে সরাসরি ছোঁয় না ────────────────
         * `salesLineEditor` আগে থেকেই `bulk-applied` সংকেতটা শোনে (বাল্ক
         * শীটের জন্য বানানো)। ⭐ তাই বারকোডটা ঐ একই সংকেতই পাঠায় —
         * দুইটা শ্রেণির মধ্যে নতুন কোনো জোড়া লাগে না, আর সারি মিলিয়ে
         * বসানোর নিয়মটাও (একই পণ্য দুইবার এলে পরিমাণ বসে) এমনিই পাওয়া যায়।
         *
         * ⚠️ না মিললে ঘরটা খালি হয় না — কোডটা পর্দায় থাকে, নাহলে
         * স্ক্যানারটা কাজ করল কি না সেটাই বোঝা যেত না।
         */
        scan() {
            const code = String(this.code || '').trim();

            if (code === '') return;

            const productId = this.barcodes[code];

            if (productId === undefined) {
                this.missed = code;
                return;
            }

            this.missed = '';
            this.code = '';

            this.$dispatch('bulk-applied', {
                rows: [{ product_id: String(productId), qty: '1' }],
            });
        },
    }
}

export function purchaseLineEditor (config = {}) {
    return {
        rows: config.rows,
        packs: config.packs,
        packDefaults: config.packDefaults || {},
        lots: config.lots,
        unitsFor(row) {
            return this.packs[row.product_id] ?? [];
        },

        /*
         * পণ্য বাছলে কোন প্যাকটা বসবে — মালিকের বাছাই, ধাপ ৫।
         *
         * ⓘ পণ্যের ফর্মের রেডিও থেকে আসা তালিকা (`packDefaults`)। ⚠️ কিছু
         * না বাছা থাকলে ফাঁকা স্ট্রিং, আর তখন সার্ভার পণ্যের নিজের একক
         * ধরে — আগের আচরণ হুবহু।
         */
        defaultUnit(productId) {
            const chosen = (this.packDefaults || {})[productId];

            return chosen === undefined ? '' : String(chosen);
        },

        /*
         * এই সারির পণ্য কি লট ধরে চলে।
         *
         * ⚠️ তুলনাটা String() দিয়ে: পণ্যের আইডি সার্ভার থেকে সংখ্যা
         * হয়ে আসে, আর `<select>`-এর মান সবসময় স্ট্রিং। `===` দিলে
         * কোনো সারিতেই ঘর তিনটা কখনো দেখা যেত না — আর পর্দা কিছু
         * ভাঙত না, কেবল ওষুধ কেনা যেত না।
         */
        tracksLot(row) {
            return this.lots.some(id => String(id) === String(row.product_id));
        },
        add() {
            this.rows.push({
                product_id: '', qty: '', free_qty: '', rate: '', discount: '', tax: '', link: '', unit_id: '',
                sales_price: '', markup: '', margin: '', anchor: '',
                batch_no: '', expiry_date: '', mrp: '',
            });
        },
        remove(i) {
            this.rows.splice(i, 1);
            if (this.rows.length === 0) this.add();
        },
        amount(row) {
            const base = (parseFloat(row.qty) || 0) * (parseFloat(row.rate) || 0);
            const net = base - (parseFloat(row.discount) || 0);
            return net + (parseFloat(row.tax) || 0);
        },
        get total() {
            return this.rows.reduce((sum, row) => sum + this.amount(row), 0);
        },

        priced(row, edited) {
            Object.assign(row, window.abos.reprice(row, edited));
        },

        /*
         * কোনো সারিতে দাম বলা হয়েছে অথচ দর বলা হয়নি।
         *
         * ⓘ তখনই markup ও margin খালি থেকে যায়, আর কারণটা পর্দায়
         * লেখা না থাকলে সেটা ভাঙা বলে মনে হয়।
         */
        get needsRate() {
            return this.rows.some(row => (parseFloat(row.rate) || 0) <= 0
                && (row.sales_price || row.markup || row.margin));
        },

        init() {
            if (this.rows.length === 0) this.add();
        },
    }
}

export function billPayment (config = {}) {
    return {
                    rows: config.rows,
                    add() { this.rows.push({ purchase_bill_id: '', amount: '' }); },
                    remove(i) { this.rows.splice(i, 1); if (this.rows.length === 0) this.add(); },
                    get allocated() {
                        return this.rows.reduce((s, r) => s + (parseFloat(r.amount) || 0), 0);
                    },

        init() {
            if (this.rows.length === 0) this.add();
        },
    }
}

export function purchaseReturn (config = {}) {
    return {
                    rows: config.rows,
                    add() { this.rows.push({ product_id: '', purchase_bill_line_id: '', qty: '', rate: '', tax: '' }); },
                    remove(i) { this.rows.splice(i, 1); if (this.rows.length === 0) this.add(); },
                    amount(row) {
                        return (parseFloat(row.qty) || 0) * (parseFloat(row.rate) || 0) + (parseFloat(row.tax) || 0);
                    },
                    get total() { return this.rows.reduce((s, r) => s + this.amount(r), 0); },

        init() {
            if (this.rows.length === 0) this.add();
        },
    }
}

export function salesReturn (config = {}) {
    return {
                    rows: config.rows,
                    add() { this.rows.push({ product_id: '', sales_invoice_line_id: '', qty: '', rate: '', tax: '', to_hold: false }); },
                    remove(i) { this.rows.splice(i, 1); if (this.rows.length === 0) this.add(); },
                    amount(row) {
                        return (parseFloat(row.qty) || 0) * (parseFloat(row.rate) || 0) + (parseFloat(row.tax) || 0);
                    },
                    get total() { return this.rows.reduce((s, r) => s + this.amount(r), 0); },

        init() {
            if (this.rows.length === 0) this.add();
        },
    }
}

export function invoiceCollection (config = {}) {
    return {
                    /*
                     * গ্রাহকের নম্বরটা এখানেই রাখা, বাইরের কোনো
                     * `x-data` থেকে ধার করা নয়।
                     *
                     * Alpine-এ ভেতরের কম্পোনেন্ট বাইরেরটার ঘর পড়তে
                     * পারে, কিন্তু সেটা নির্ভর করে স্কোপ-চেইনের উপর —
                     * আর একদিন কেউ মাঝখানে আরেকটা `x-data` বসালে
                     * তালিকাটা নীরবে খালি হয়ে যেত। ঘটনাটা নিজের সাথে
                     * নম্বরটা বয়ে আনে, তাই মাঝখানে কী আছে তাতে কিছু
                     * আসে যায় না।
                     */
                    customerId: config.customerId,
                    rows: config.rows,
                    open: config.open,
                    add() { this.rows.push({ sales_invoice_id: '', amount: '' }); },
                    remove(i) { this.rows.splice(i, 1); if (this.rows.length === 0) this.add(); },
                    /* এই গ্রাহকের বিলগুলোই — গ্রাহক না বাছা থাকলে কিছুই নয়,
                       কারণ কার টাকা তা না জেনে বিল বাছার কোনো মানে নেই। */
                    get mine() {
                        return this.customerId
                            ? this.open.filter(o => o.customer_id === String(this.customerId))
                            : [];
                    },
                    /* বিল বাছলে তার বকেয়াটাই বসে — মানুষ প্রায় সবসময়
                       পুরোটাই নেন, আর টাইপ করা মানে টাইপের ভুল। */
                    fillDue(row) {
                        const found = this.open.find(o => o.id === String(row.sales_invoice_id));
                        if (found) { row.amount = found.due; }
                    },
                    get allocated() {
                        return this.rows.reduce((s, r) => s + (parseFloat(r.amount) || 0), 0);
                    },

        init() {
            if (this.rows.length === 0) this.add();
        },

        /* ক্রেতা বদলালে বাছা বিলগুলোও মোছে — অন্যের বিলে টাকা বসবে না */
        pickCustomer(id) {
            this.customerId = id;
            this.rows.forEach(r => { r.sales_invoice_id = ''; r.amount = ''; });
        },
    }
}

export function bulkSheet (config = {}) {
    return {
       open: false,
       search: '',
       filter: 'all',
       sort: 'name',
       sheet: config.sheet,
       /* পণ্য ধরে রাখা, সারির ক্রম ধরে নয় — নাহলে ছাঁকনি বদলালে বা
          খুঁজলে আগের টাইপ করা সংখ্যাগুলো অন্য পণ্যের ঘরে গিয়ে বসত।
          চারটা পরিমাণ লিখে পঞ্চমটা খোঁজা যেন প্রথম চারটা হারানোর উপায়
          না হয়। */
       typed: {},

       box(id) {
           if (! this.typed[id]) this.typed[id] = { qty: '', free: '' };
           return this.typed[id];
       },
       num(value) {
           const n = parseFloat(value);
           return Number.isFinite(n) ? n : 0;
       },
       hasSomething(id) {
           const row = this.typed[id];
           return !! row && (this.num(row.qty) > 0 || this.num(row.free) > 0);
       },

       get visible() {
           const needle = this.search.trim().toLowerCase();
           let rows = this.sheet;

           if (needle) {
               rows = rows.filter(r => r.name.toLowerCase().includes(needle)
                   || (r.code ?? '').toLowerCase().includes(needle));
           }
           if (this.filter === 'in_stock') rows = rows.filter(r => this.num(r.available) > 0);
           if (this.filter === 'typed') rows = rows.filter(r => this.hasSomething(r.id));

           const sorted = [...rows];
           if (this.sort === 'name') sorted.sort((a, b) => a.name.localeCompare(b.name));
           if (this.sort === 'available') sorted.sort((a, b) => this.num(b.available) - this.num(a.available));
           if (this.sort === 'typed') {
               sorted.sort((a, b) => (this.hasSomething(a.id) ? 0 : 1) - (this.hasSomething(b.id) ? 0 : 1)
                   || a.name.localeCompare(b.name));
           }
           return sorted;
       },

       /* তিনটা সংখ্যা — যা টাইপ করা হয়েছে তার উপর, যা দেখা যাচ্ছে তার
          উপর নয়। খুঁজলেই বদলে যায় এমন যোগফল কেউ বিশ্বাস করে না। */
       get totals() {
           let amount = 0, items = 0, free = 0;

           for (const row of this.sheet) {
               const box = this.typed[row.id];
               if (! box) continue;

               const qty = this.num(box.qty), freeQty = this.num(box.free);
               if (qty <= 0 && freeQty <= 0) continue;

               items += 1;
               free += freeQty;
               amount += qty * this.num(row.rate);
           }
           return { amount, items, free };
       },

       apply() {
           const rows = this.sheet
               .filter(row => this.hasSomething(row.id))
               .map(row => ({
                   product_id: row.id,
                   qty: this.typed[row.id].qty || '0',
                   free_qty: this.typed[row.id].free || '',
                   rate: row.rate,
                   discount: '', tax: '', link: '', unit_id: '',
               }));

           /* ইভেন্ট দিয়ে, সরাসরি নয় — শীটটা জানে না কে শুনছে, তাই
              একই শীট চালান, সরাসরি বিক্রয় ও ক্রয়েও বসানো যায়। */
           this.$dispatch('bulk-applied', { rows });

           this.open = false;
       },
    }
}
