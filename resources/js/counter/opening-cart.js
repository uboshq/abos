/**
 * খোলা মজুদের কার্ট — এক পর্দায় অনেক পণ্য, সারিপ্রতি এক পণ্য।
 *
 * ── ⭐ কেন, মালিক, ৬ অক্টোবর ২০২৬ ─────────────────────────────────────
 * প্রথম সংস্করণে পণ্যের ঘর ছিল ব্রাউজারের নিজের তালিকা (`datalist`), আর দাম ছিল কেবল দর। মালিক চাইলেন:
 *   (১) *"Product-এ সার্চ বার দাও"* — কোড, নাম বা বারকোড লিখলে নিচে মেলা পণ্যের তালিকা;
 *   (২) *"Quantity * Free"* — পরিমাণের পাশে ফ্রি (খরচ ছাড়া, একই লটে, ক্রয়ের মতো);
 *   (৩) *"Rate, Markup, Margin, Sales price"* — যেকোনো একটা লিখলে বাকি দুইটা বসে ([[pricing.js]]-এর `reprice`);
 *   (৪) লট খালি → "Opening" (সার্ভারে, [[OpeningStockService]])।
 * তার সাথে Enter চাপলে পরের ঘর, আর সারির মূল্য ও মোট সাথে সাথে।
 *
 * ── ⚠️ যা বদলায় না ────────────────────────────────────────────────────
 * ফর্ম আগের নামেই পাঠায় (`rows[i][product]`, `rows[i][qty]` …) — সার্ভার আগের মতোই কোড ধরে চেনে, আর বাছা পণ্যের
 * id আলাদা লুকানো ঘরে (`rows[i][product_id]`)। দামের হিসাব এখানে নয়, [[pricing.js]]-এ — একটাই কপি।
 */
import { reprice } from '../pricing.js'

/** একবারে কতগুলো মেলা পণ্য আঁকা হয় — হাজার পণ্যের দোকানেও প্রতিটা অক্ষরে পর্দা থমকায় না। */
export const SHOWN_AT_ONCE = 30

/** নতুন সারি চাইলে একবারে কতগুলো যোগ হয়। */
export const MORE_ROWS = 10

const num = (v) => {
    const n = parseFloat(v)

    return Number.isFinite(n) ? n : 0
}

/**
 * ⭐ খোঁজা — কোড, দুই ভাষার নাম বা বারকোডের যেকোনো অংশ; লেখাটা শব্দে ভাঙে, আর **প্রতিটা** শব্দ মিলতে হয়।
 * ⓘ কোড বা বারকোড হুবহু মিললে সেটা সবার আগে — স্ক্যানারে বারকোড পড়লে প্রথমটাই ঠিক পণ্য।
 */
export function matchProducts(products, term) {
    const text = String(term || '').trim().toLowerCase()
    const words = text.split(/\s+/).filter(Boolean)

    if (words.length === 0) {
        return []
    }

    const hits = products.filter((p) => words.every((w) => p.find.includes(w)))
    const exact = hits.filter((p) => p.code.toLowerCase() === text || (p.barcode || '').toLowerCase() === text)

    return [...exact, ...hits.filter((p) => ! exact.includes(p))]
}

/** সারির মূল্য — পরিমাণ × দর; ফ্রি গোনে না, ওটার খরচ নেই। */
export function rowValue(row) {
    return Math.round(num(row.qty) * num(row.rate) * 100) / 100
}

/**
 * একটা ফাঁকা বা পুরনো (`old()`) সারি।
 *
 * @param {object} seed  সার্ভার ফেরত দিলে আগের লেখা, আর ভুল থাকলে তার বার্তা
 */
export function makeRow(key, seed = {}) {
    const anchor = seed.pricing_anchor || ''

    return {
        key,
        product_id: seed.product_id ? String(seed.product_id) : '',
        search: seed.product || '',
        qty: seed.qty || '',
        free_qty: seed.free_qty || '',
        rate: seed.unit_cost || '',
        markup: anchor === 'markup' ? (seed.pricing_pct || '') : '',
        margin: anchor === 'margin' ? (seed.pricing_pct || '') : '',
        sales_price: seed.sales_price || '',
        anchor,
        batch_no: seed.batch_no || '',
        expiry_date: seed.expiry_date || '',
        supplier_id: seed.supplier_id ? String(seed.supplier_id) : '',
        error: seed.error || '',
        open: false,
        cursor: 0,
    }
}

/**
 * Alpine কম্পোনেন্ট — `x-data="openingCart({ products, rows, count })"`।
 *
 * @param {{products: Array, rows: Object, count: number, money?: string}} config
 *   products — `{id, code, name, barcode, find, rate, sales_price, pricing_anchor, pricing_pct}`
 *   rows     — সার্ভারের ফেরত দেওয়া পুরনো সারি, পর্দার সারির নম্বর ধরে
 *   count    — কতগুলো সারি আঁকা হবে
 */
export default function openingCart({ products = [], rows = {}, count = 15 } = {}) {
    const list = []

    for (let i = 0; i < count; i++) {
        list.push(makeRow(i, rows[i] || rows[String(i)] || {}))
    }

    return {
        products: products.map((p) => ({ ...p, find: (p.find || `${p.code} ${p.name} ${p.barcode || ''}`).toLowerCase() })),
        rows: list,
        nextKey: count,

        // ── খোঁজা ──────────────────────────────────────────────────────

        matches(row) {
            return matchProducts(this.products, row.search).slice(0, SHOWN_AT_ONCE)
        },

        /** লেখা বদলালে আগের বাছাই আর টেকে না — নইলে লেখা এক পণ্য, যায় আরেক id। */
        typed(row) {
            row.product_id = ''
            row.open = true
            row.cursor = 0
        },

        down(row) {
            row.open = true
            row.cursor = Math.min(row.cursor + 1, Math.max(this.matches(row).length - 1, 0))
        },

        up(row) {
            row.cursor = Math.max(row.cursor - 1, 0)
        },

        close(row) {
            row.open = false
        },

        isCursor(row, index) {
            return row.cursor === index
        },

        /**
         * ⭐ পণ্য বাছা — দর, বিক্রয়মূল্য আর পণ্যের নীতি (markup বা margin) ফিরে আসে, ক্রয়ের পর্দার মতো
         * ([[direct-purchase.js]])। ⓘ আগে লেখা দর থাকলে সেটা টেকে — মানুষের লেখা মুছে দেওয়া হয় না।
         */
        pick(row, product) {
            row.product_id = String(product.id)
            row.search = product.barcode ? `${product.code} — ${product.name} — ${product.barcode}` : `${product.code} — ${product.name}`
            row.open = false
            row.cursor = 0

            // ⓘ সার্ভারের দশমিক ঘর (`50.0000`) পর্দায় ছাঁটা — `50`
            if (row.rate === '' && num(product.rate) > 0) row.rate = String(num(product.rate))

            row.markup = ''
            row.margin = ''
            row.anchor = ''
            row.sales_price = num(product.sales_price) > 0 ? String(num(product.sales_price)) : ''

            if (product.pricing_anchor === 'markup' || product.pricing_anchor === 'margin') {
                row[product.pricing_anchor] = String(num(product.pricing_pct))
                row.anchor = product.pricing_anchor
                Object.assign(row, reprice(row, 'rate'))
            } else if (row.sales_price !== '') {
                // ⓘ নীতি নেই, দাম আছে — দামটাই নোঙর, markup ও margin তার চারপাশে দেখানো হয়
                Object.assign(row, reprice({ ...row, anchor: 'sales_price' }, 'sales_price'))
                row.anchor = product.pricing_anchor === 'sales_price' ? 'sales_price' : ''
            }
        },

        /** Enter বা খোঁজার পরের ঘর — তালিকা খোলা থাকলে কার্সারের পণ্যটা বাছে। */
        pickCursor(row) {
            const found = this.matches(row)[row.cursor] || this.matches(row)[0]

            if (row.open && found) {
                this.pick(row, found)

                return true
            }

            return false
        },

        // ── দাম ─────────────────────────────────────────────────────────

        /** দর, markup, margin বা বিক্রয়মূল্যের একটায় লেখা হল — বাকিগুলো [[pricing.js]] বসায়। */
        priced(row, edited) {
            Object.assign(row, reprice(row, edited))
        },

        /** সার্ভারে যাওয়া শতাংশ — কেবল markup বা margin নোঙর হলে, ক্রয়ের একই নিয়মে। */
        pctOf(row) {
            if (row.anchor === 'markup') return row.markup
            if (row.anchor === 'margin') return row.margin

            return ''
        },

        /**
         * ⭐ ফ্রি পরিমাণের সমান বা বেশি — হলুদ সতর্কতা, আটকানো নয় (৬ অক্টোবর ২০২৬, লাইভের ঘটনা: ADI-তে ৮ কার্টন কেনা,
         * Enter-এর পর ফ্রি ঘরেও ৮ বসে তাকে ১৬ হয়েছিল)। ⓘ সত্যিই সমান ফ্রি হতে পারে ("একটা কিনলে একটা ফ্রি"), তাই কেবল বলা।
         */
        freeWarn(row) {
            const free = num(row.free_qty)

            return free > 0 && num(row.qty) > 0 && free >= num(row.qty)
        },

        // ── মোট ─────────────────────────────────────────────────────────

        value(row) {
            return rowValue(row)
        },

        valueText(row) {
            const v = rowValue(row)

            return v > 0 ? v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : ''
        },

        get filled() {
            return this.rows.filter((r) => r.search.trim() !== '' || r.qty !== '')
        },

        get totalQty() {
            return this.filled.reduce((sum, r) => sum + num(r.qty), 0)
        },

        get totalFree() {
            return this.filled.reduce((sum, r) => sum + num(r.free_qty), 0)
        },

        get totalValue() {
            return Math.round(this.filled.reduce((sum, r) => sum + rowValue(r), 0) * 100) / 100
        },

        get totalValueText() {
            return this.totalValue.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
        },

        get filledCount() {
            return this.filled.length
        },

        // ── সারি আর Enter ───────────────────────────────────────────────

        addRows() {
            for (let i = 0; i < MORE_ROWS; i++) {
                this.rows.push(makeRow(this.nextKey))
                this.nextKey++
            }
        },

        name(index, field) {
            return `rows[${index}][${field}]`
        },

        /**
         * ⭐ Enter = পরের ঘর, ফর্ম জমা নয় — মালিক, ৬ অক্টোবর ২০২৬।
         *
         * ⓘ খোঁজার তালিকা খোলা থাকলে Enter আগে পণ্যটা বাছে, তারপর পরের ঘরে যায়। শেষ সারির শেষ ঘরে Enter চাপলে নতুন
         * দশটা সারি যোগ হয়ে পরের সারির পণ্যের ঘরে যায় — কাজ থামে না। ⚠️ জমা দেওয়া কেবল "সংরক্ষণ" বোতামে।
         */
        enter(event, row) {
            event.preventDefault()

            if (row && event.target && event.target.dataset && event.target.dataset.cell === 'product') {
                this.pickCursor(row)
            }

            const cells = [...this.$root.querySelectorAll('[data-cell]')]
            const at = cells.indexOf(event.target)

            if (at >= 0 && at < cells.length - 1) {
                cells[at + 1].focus()

                return
            }

            this.addRows()
            this.$nextTick(() => {
                const again = [...this.$root.querySelectorAll('[data-cell]')]
                const next = again[at + 1]

                if (next) next.focus()
            })
        },
    }
}
