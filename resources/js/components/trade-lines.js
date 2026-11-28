import { taka } from './money.js'

/*
 * ⭐ নোট আর স্টক ছাড়া ভাউচারের সারির ছক — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬ (SV, PUV, CN, গ্রাহকের DN, সরবরাহকারীর DN)।
 *
 * ⓘ টাকার অঙ্ক সার্ভারের [[LineMath]]-এর হুবহু: bc-র মতো দশমিকের ৪ ঘর পর্যন্ত কেটে পড়া, অঙ্ক = পরিমাণ × দর (অঙ্ক লিখলে দর =
 *   অঙ্ক ÷ পরিমাণ), ভ্যাট = অঙ্ক × হার ÷ ১০০, দুই দশমিকে শূন্য থেকে দূরে গোল। ⚠️ তাই হিসাব BigInt-এ, float-এ নয় — float-এ
 *   1.005 × 100 = 100.49999… হয়ে পর্দা আর খাতা এক পয়সা আলাদা হত।
 * ⓘ "খাতায় যা উঠবে" সার্ভারের [[NotePosting]] আর [[TradeVoucherService::postingLines()]]-এর একই নিয়মে।
 * ⛔ CSP-Alpine: পর্দায় কেবল নাম আর ডাক; সব যুক্তি এখানে।
 */

const S4 = 10000n

/** কাঁচা লেখা → ৪ দশমিকের BigInt (PHP `bcadd($v, '0', 4)` — কাটা, গোল নয়); অচেনা লেখা = ০ */
export function money (value) {
    let s = String(value ?? '').replace(/,/g, '').trim()

    if (! /^[+-]?(\d+\.?\d*|\.\d+)$/.test(s)) {
        return 0n
    }

    const negative = s.startsWith('-')
    s = s.replace(/^[+-]/, '')

    const [whole, fraction = ''] = s.split('.')
    const value4 = BigInt(whole === '' ? '0' : whole) * S4 + BigInt((fraction + '0000').slice(0, 4))

    return negative ? -value4 : value4
}

/** `scale` দশমিকের BigInt → দুই দশমিকে গোল (শূন্য থেকে দূরে), ফল ৪ দশমিকে — [[LineMath::round2()]] */
function round2At (value, scale) {
    const half = 5n * 10n ** BigInt(scale - 3)
    const shifted = value >= 0n ? value + half : value - half

    return (shifted / 10n ** BigInt(scale - 2)) * 100n
}

/**
 * [[LineMath::figures()]] — সব ফল ৪ দশমিকের BigInt।
 *
 * @return {{qty: bigint, rate: bigint, amount: bigint, vat: bigint, total: bigint}}
 */
export function figures (qty, rate, amount, vatRate) {
    const q = money(qty)
    let r = money(rate)
    let a

    if (amount !== null && amount !== undefined && String(amount).trim() !== '') {
        a = round2At(money(amount), 4)
        r = q > 0n ? (a * S4) / q : r
    } else {
        // bcmul(qty, rate, 6) — ৮ দশমিকের গুণফল ৬-এ কাটা
        a = round2At((q * r) / 100n, 6)
    }

    // bcdiv(bcmul(amount, rate, 8), 100, 6)
    const vat = round2At((a * money(vatRate)) / S4, 6)

    return { qty: q, rate: r, amount: a, vat, total: a + vat }
}

/** BigInt (৪ দশমিক) → সংখ্যা */
export function toNumber (value4) {
    return Number(value4) / 10000
}

/** BigInt (৪ দশমিক) → ঘরের লেখা: অন্তত দুই দশমিক, শেষের বাড়তি শূন্য বাদ (242.5000 → 242.50, 3.3333 → 3.3333) */
export function plain (value4) {
    const negative = value4 < 0n
    const abs = negative ? -value4 : value4
    let fraction = String(abs % S4).padStart(4, '0')

    while (fraction.length > 2 && fraction.endsWith('0')) {
        fraction = fraction.slice(0, -1)
    }

    return (negative ? '-' : '') + String(abs / S4) + '.' + fraction
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/** ISO তারিখ + দিন → "23 Dec 2026" (ইংরেজি অঙ্ক, মালিকের নিয়ম) */
export function addDays (iso, days) {
    const [y, m, d] = String(iso).split('-').map(Number)
    const date = new Date(Date.UTC(y, (m || 1) - 1, d || 1))

    date.setUTCDate(date.getUTCDate() + (Number(days) || 0))

    return String(date.getUTCDate()).padStart(2, '0') + ' ' + MONTHS[date.getUTCMonth()] + ' ' + date.getUTCFullYear()
}

const NOTE_DOCS = ['cn', 'cdn', 'dn']

/**
 * Alpine কম্পোনেন্ট — `x-data="tradeLines(@js($config))"`।
 *
 * config: doc (sv|puv|cn|cdn|dn), kind (নোটের পক্ষের ধরন), products, taxes, accounts, productsAllowed, productAccount,
 * partyId, billNo, billUrl, creditDays, today (ISO), tds, supplierNoteNo, supplierNoteDate, lines (ফেরানো জমার সারি), labels, text
 */
export function tradeLines (config = {}) {
    const doc = String(config.doc ?? 'sv')
    const products = Array.isArray(config.products) ? config.products : []
    const taxes = Array.isArray(config.taxes) ? config.taxes : []
    const accounts = Array.isArray(config.accounts) ? config.accounts : []
    const labels = config.labels ?? {}
    const text = config.text ?? {}
    let seq = 0

    const blank = (type) => ({
        key: ++seq,
        type,
        product_id: '',
        source_line_id: null,
        item_name: '',
        account_id: '',
        description: '',
        qty: '1',
        rate: '',
        amountText: '',
        amountTyped: false,
        tax_id: '',
        vat_rate: '0',
        unit: '',
        billed: null,
        room: null,
        in_stock: null,
        bill_rate: null,
        goods_moved: false,
    })

    return {
        doc,
        kind: String(config.kind ?? ''),
        isNote: NOTE_DOCS.includes(doc),
        productsAllowed: config.productsAllowed !== false,
        partyId: config.partyId ? String(config.partyId) : '',
        billNo: String(config.billNo ?? ''),
        bill: null,
        billBusy: false,
        billError: '',
        supNo: String(config.supplierNoteNo ?? ''),
        supDate: String(config.supplierNoteDate ?? ''),
        tds: String(config.tds ?? ''),
        lines: [],

        init () {
            const seeded = Array.isArray(config.lines) ? config.lines : []

            this.lines = seeded.length > 0 ? seeded.map((row) => this.restore(row)) : [blank(this.defaultType())]

            // ⓘ ঠিকানায় বা ফেরানো জমায় বিলের নম্বর থাকলে বিলটা আবার আনা — ফেরানো সারিগুলো রেখে
            if (this.isNote && this.billNo.trim() !== '' && this.partyId !== '' && config.billUrl) {
                this.fetchBill(seeded.length > 0)
            }
        },

        defaultType () {
            if (this.isNote) {
                return this.productsAllowed && this.bill !== null ? 'product' : 'account'
            }

            return products.length > 0 ? 'product' : 'account'
        },

        /** ফেরানো জমার একটা সারি (lines_json) → পর্দার সারি */
        restore (row) {
            const line = blank(String(row.type ?? 'account'))
            const typed = row.amount !== null && row.amount !== undefined && String(row.amount) !== ''

            line.product_id = row.product_id ? String(row.product_id) : ''
            line.source_line_id = row.source_line_id ? Number(row.source_line_id) : null
            line.item_name = String(row.item_name ?? '')
            line.account_id = row.account_id ? String(row.account_id) : ''
            line.description = String(row.description ?? '')
            line.qty = String(row.qty ?? '1')
            line.rate = String(row.rate ?? '')
            line.amountText = typed ? String(row.amount) : ''
            line.amountTyped = typed && line.type !== 'account'
            line.tax_id = row.tax_id ? String(row.tax_id) : ''
            line.unit = String(row.unit ?? '')
            line.goods_moved = row.goods_moved === true || row.goods_moved === 'true' || row.goods_moved === 1

            const product = products.find((p) => String(p.id) === line.product_id)

            if (product && line.unit === '') {
                line.unit = String(product.unit ?? '')
            }

            return line
        },

        // ── সারি ──────────────────────────────────────────────────────────

        add () {
            this.lines.push(blank(this.defaultType()))
        },

        remove (line) {
            this.lines = this.lines.filter((l) => l.key !== line.key)

            if (this.lines.length === 0) {
                this.add()
            }
        },

        rowNo (line) {
            return this.lines.indexOf(line) + 1
        },

        typeChanged (line) {
            const fresh = blank(line.type)

            for (const field of ['product_id', 'source_line_id', 'item_name', 'account_id', 'qty', 'rate', 'amountText',
                'amountTyped', 'tax_id', 'vat_rate', 'unit', 'billed', 'room', 'in_stock', 'bill_rate', 'goods_moved']) {
                line[field] = fresh[field]
            }
        },

        isService (line) {
            return line.type === 'service'
        },

        isAccount (line) {
            return line.type === 'account'
        },

        /** পণ্যের সারি বিলের সারি থেকে — বিল আনা থাকলে */
        fromBill (line) {
            return line.type === 'product' && this.bill !== null
        },

        /** পণ্যের তালিকা থেকে — বিল না থাকলে */
        fromCatalogue (line) {
            return line.type === 'product' && this.bill === null
        },

        productPicked (line) {
            const product = products.find((p) => String(p.id) === String(line.product_id))

            if (! product) {
                return
            }

            const sells = ['sv', 'cn', 'cdn'].includes(doc)

            line.unit = String(product.unit ?? '')
            line.rate = plain(money(sells ? product.sale_price : product.purchase_price))
            line.amountTyped = false
            line.amountText = ''
            line.tax_id = product.tax_id ? String(product.tax_id) : ''
        },

        billLines () {
            return this.bill === null ? [] : this.bill.lines
        },

        billLinePicked (line, event) {
            const id = Number(event.target.value)
            const found = this.billLines().find((b) => Number(b.line_id) === id)

            if (found) {
                Object.assign(line, this.fromBillLine(found), { key: line.key, description: line.description })
            }
        },

        isBillLine (line, billLine) {
            return Number(line.source_line_id) === Number(billLine.line_id)
        },

        billLineLabel (billLine) {
            return billLine.name
        },

        setQty (line, event) {
            let value = String(event.target.value)

            // ⛔ বিলের জায়গার বেশি নয় — বিলের পরিমাণ − পাকা ফেরত ([[NoteLineBuilder]]-এর একই পাহারা; অন্য নোটের অপেক্ষার ফেরত সার্ভার মাপে)
            const cap = line.room ?? line.billed

            if (cap !== null && money(value) > money(cap)) {
                value = plain(money(cap))
                event.target.value = value
            }

            line.qty = value
            line.amountTyped = false
        },

        setRate (line, event) {
            line.rate = String(event.target.value)
            line.amountTyped = false
        },

        /** অঙ্ক লিখলে দর নিজে বদলায় — অঙ্ক ÷ পরিমাণ */
        setAmount (line, event) {
            const value = String(event.target.value)

            line.amountText = value

            if (line.type === 'account') {
                return
            }

            line.amountTyped = true

            const q = money(line.qty)

            if (q > 0n) {
                line.rate = plain((round2At(money(value), 4) * S4) / q)
            }
        },

        setGoods (line, event) {
            line.goods_moved = Boolean(event.target.checked)

            if (line.goods_moved && line.bill_rate !== null) {
                line.rate = plain(money(line.bill_rate))
                line.amountTyped = false
            }
        },

        /** মাল ফেরতের সারির দর বিলের নিট দর — তালাবদ্ধ (ফেরতের কাগজ যে দরে তোলে) */
        rateLocked (line) {
            return line.type === 'account' || (line.goods_moved && line.bill_rate !== null)
        },

        amountLocked (line) {
            return line.goods_moved && line.bill_rate !== null
        },

        qtyLocked (line) {
            return line.type === 'account'
        },

        qtyShown (line) {
            return line.type === 'account' ? '1' : line.qty
        },

        rateShown (line) {
            return line.type === 'account' ? this.amountShown(line) : line.rate
        },

        amountShown (line) {
            if (line.type === 'account' || line.amountTyped) {
                return line.amountText
            }

            return plain(this.lineFigures(line).amount)
        },

        unitShown (line) {
            return line.type === 'account' ? '—' : line.unit
        },

        billedShown (line) {
            return line.billed === null ? '—' : plain(money(line.billed))
        },

        canReturn (line) {
            return (doc === 'cn' || doc === 'dn') && line.type === 'product' && line.source_line_id !== null
        },

        // ── ভ্যাট ─────────────────────────────────────────────────────────

        taxRate (taxId) {
            const found = taxes.find((t) => String(t.id) === String(taxId))

            return found ? String(found.rate) : '0'
        },

        /**
         * ⛔ নোটে ভ্যাট কেবল মূল বিলের বিপরীতে (মূসক আইন ২০১২, ধারা ৪৪), আর সরবরাহকারীর ডেবিট নোটে তার মূসক-৬.৭ নম্বর ও তারিখ
         * দুটোই থাকলে — নাহলে ০, "সমন্বয় নয়"।
         */
        get vatAllowed () {
            if (! this.isNote) {
                return true
            }

            return this.bill !== null && (doc !== 'dn' || (this.supNo.trim() !== '' && this.supDate.trim() !== ''))
        },

        noVat () {
            return ! this.vatAllowed
        },

        /** বিলের সারির ভ্যাট — হার তালাবদ্ধ */
        vatLocked (line) {
            return this.isNote && this.vatAllowed && line.type === 'product'
        },

        vatPickable (line) {
            return this.vatAllowed && ! this.vatLocked(line)
        },

        vatRateOf (line) {
            if (! this.vatAllowed) {
                return '0'
            }

            if (this.isNote && line.type === 'product') {
                return String(line.vat_rate ?? '0')
            }

            return this.taxRate(line.tax_id)
        },

        vatLockedText (line) {
            return (text.vatPrefix ?? '') + plain(money(line.vat_rate)).replace(/\.?0+$/, '') + '%'
        },

        // ── অঙ্ক ──────────────────────────────────────────────────────────

        lineFigures (line) {
            if (line.type === 'account') {
                return figures('1', line.amountText, null, this.vatRateOf(line))
            }

            if (line.goods_moved && line.bill_rate !== null) {
                return figures(line.qty, line.bill_rate, null, this.vatRateOf(line))
            }

            return figures(line.qty, line.rate, line.amountTyped ? line.amountText : null, this.vatRateOf(line))
        },

        sums () {
            let amount = 0n
            let vat = 0n

            for (const line of this.lines) {
                const f = this.lineFigures(line)

                amount += f.amount
                vat += f.vat
            }

            return { amount, vat, gross: amount + vat }
        },

        fmt (value4) {
            return taka(toNumber(value4))
        },

        lineVatText (line) {
            return this.fmt(this.lineFigures(line).vat)
        },

        lineTotalText (line) {
            return this.fmt(this.lineFigures(line).total)
        },

        get sumAmountText () {
            return this.fmt(this.sums().amount)
        },

        get sumVatText () {
            return this.fmt(this.sums().vat)
        },

        get sumGrossText () {
            return this.fmt(this.sums().gross)
        },

        tdsValue () {
            return doc === 'puv' ? round2At(money(this.tds), 4) : 0n
        },

        /** নিচের লাইন — PUV-তে মোট − উৎসে কর; বাকিগুলোয় মোট */
        bottomValue () {
            return this.sums().gross - this.tdsValue()
        },

        get bottomText () {
            return this.fmt(this.bottomValue())
        },

        get tdsOver () {
            return this.tdsValue() > this.sums().gross
        },

        // ── শেষ দিন ───────────────────────────────────────────────────────

        get dueText () {
            if (this.partyId === '') {
                return '—'
            }

            const days = Number((config.creditDays ?? {})[this.partyId] ?? 0)

            return addDays(config.today, days) + ' · ' + days + ' ' + (text.days ?? '')
        },

        // ── পক্ষ আর বিল ───────────────────────────────────────────────────

        /** party-search-এর লুকানো ঘর `change` পাঠায় — পক্ষ বদলালে বিলটা আর এই পক্ষের নয় */
        changed (event) {
            if (! event.target || event.target.name !== 'party_id') {
                return
            }

            this.partyId = String(event.target.value ?? '')

            if (this.bill !== null) {
                this.clearBill()
            }

            this.billError = ''
        },

        goKind (event) {
            const url = String(event.target.value ?? '')

            if (url !== '') {
                window.location.href = url
            }
        },

        async fetchBill (keep = false) {
            this.billError = ''

            if (this.partyId === '') {
                this.billError = text.needParty ?? ''

                return
            }

            if (this.billNo.trim() === '') {
                this.billError = text.needNo ?? ''

                return
            }

            this.billBusy = true

            try {
                const query = new URLSearchParams({ type: doc, kind: this.kind, party_id: this.partyId, no: this.billNo.trim() })
                const response = await fetch(config.billUrl + '?' + query.toString(), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                const body = await response.json().catch(() => ({}))

                if (! response.ok) {
                    this.billError = body.message ?? text.billFailed ?? ''

                    return
                }

                this.applyBill(body, keep === true)
            } catch {
                this.billError = text.billFailed ?? ''
            } finally {
                this.billBusy = false
            }
        },

        fromBillLine (billLine) {
            const line = blank('product')

            line.product_id = String(billLine.product_id)
            line.source_line_id = Number(billLine.line_id)
            line.item_name = String(billLine.name ?? '')
            line.unit = String(billLine.unit ?? '')
            line.billed = String(billLine.qty)
            const room = money(billLine.qty) - money(billLine.returned ?? '0')
            line.room = plain(room > 0n ? room : 0n)
            line.qty = line.room
            line.rate = plain(money(billLine.rate))
            line.bill_rate = String(billLine.rate)
            line.vat_rate = String(billLine.vat_rate ?? '0')
            line.in_stock = billLine.in_stock === null || billLine.in_stock === undefined ? null : String(billLine.in_stock)

            return line
        },

        /**
         * ⭐ বিলের সারি বসানো — পণ্যের সারি বিলের সারিতে বদলায়, খাতের সারি থাকে। ⓘ `keep`: ফেরানো জমার সারি রেখে কেবল বিলের
         * তথ্য (বিলে ছিল, হার, মজুদ) জোড়া।
         */
        applyBill (bill, keep = false) {
            const billLines = Array.isArray(bill.lines) ? bill.lines : []

            this.bill = { no: String(bill.no ?? ''), issued_at: bill.issued_at ?? null, room: bill.room ?? null, lines: billLines }
            this.billNo = this.bill.no

            if (! this.productsAllowed) {
                return
            }

            const others = this.lines.filter((l) => l.type !== 'product')

            if (keep) {
                const kept = []

                for (const line of this.lines.filter((l) => l.type === 'product')) {
                    const source = billLines.find((b) => Number(b.line_id) === Number(line.source_line_id))

                    if (source) {
                        const fresh = this.fromBillLine(source)

                        line.billed = fresh.billed
                        line.room = fresh.room
                        line.bill_rate = fresh.bill_rate
                        line.vat_rate = fresh.vat_rate
                        line.in_stock = fresh.in_stock
                        line.item_name = fresh.item_name
                        line.unit = fresh.unit
                        kept.push(line)
                    }
                }

                this.lines = [...kept, ...others]
            } else {
                // ⓘ খালি খাতের সারি (শুরুর ফাঁকা সারি) বিলের সারির জায়গা ছাড়ে
                const filled = others.filter((l) => l.account_id !== '' || l.amountText !== '' || l.description !== '')

                this.lines = [...billLines.map((b) => this.fromBillLine(b)), ...filled]
            }

            if (this.lines.length === 0) {
                this.add()
            }
        },

        clearBill () {
            this.bill = null
            this.lines = this.lines.filter((l) => ! (l.type === 'product' && l.source_line_id !== null))

            if (this.lines.length === 0) {
                this.add()
            }
        },

        get hasBill () {
            return this.bill !== null
        },

        get hasBillError () {
            return this.billError !== ''
        },

        get billInfo () {
            if (this.bill === null) {
                return ''
            }

            const parts = [this.bill.no]

            if (this.bill.issued_at) {
                parts.push(this.bill.issued_at)
            }

            if (this.bill.room !== null && this.bill.room !== undefined) {
                parts.push((text.room ?? '') + ' ' + taka(toNumber(money(this.bill.room))))
            }

            return parts.join(' · ')
        },

        get vatBoxText () {
            return this.bill !== null ? (text.vatFromBill ?? '') : (text.vatNoBill ?? '')
        },

        // ── খাতা ──────────────────────────────────────────────────────────

        accountCode (id) {
            const found = accounts.find((a) => String(a.id) === String(id))

            return found ? String(found.code) : '#' + String(id)
        },

        /** পণ্যের সারির খাতের কোড — সার্ভারের [[NoteLineBuilder::productAccount()]] / [[TradeVoucherService::productAccountId()]] */
        productCode (line) {
            if (doc === 'cn') {
                return line.goods_moved ? '4110' : '4100'
            }

            if (doc === 'cdn') {
                return '4100'
            }

            if (doc === 'dn') {
                return '1120'
            }

            return String(config.productAccount ?? '').split(' · ')[0]
        },

        codeLabel (code) {
            if (labels['acc_' + code]) {
                return labels['acc_' + code]
            }

            const found = accounts.find((a) => String(a.code) === String(code))

            if (found) {
                return found.code + ' · ' + found.name
            }

            if (String(config.productAccount ?? '').startsWith(code + ' · ')) {
                return config.productAccount
            }

            return code
        },

        productAccountText (line) {
            return (text.accountOf ?? '') + ' ' + this.codeLabel(this.productCode(line))
        },

        partyCode () {
            if (doc === 'sv') return '1110'
            if (doc === 'puv') return '2118'
            if (this.kind === 'customer') return '1110'
            if (this.kind === 'supplier') return '2111'

            return 'party'
        },

        partyLabel () {
            const code = this.partyCode()

            return code === 'party' ? (labels.acc_party ?? '') : this.codeLabel(code)
        },

        /** কোড → অঙ্ক, ক্রম রেখে (সার্ভারের মতো একই খাত এক লাইনে) */
        group (rows, code, amount) {
            const found = rows.find((r) => r.code === code)

            if (found) {
                found.amount += amount
            } else {
                rows.push({ code, amount })
            }
        },

        /**
         * ⭐ "খাতায় যা উঠবে" — নোট বা ভাউচার নিজে যা তোলে; মাল ফেরতের সারি আলাদা (ফেরতের কাগজ তোলে, নোট নয়)।
         *
         * @return {{rows: Array, dr: bigint, cr: bigint, returns: Array}}
         */
        ledger () {
            const out = []
            const party = { code: this.partyCode(), label: this.partyLabel(), party: true }
            const push = (code, label, debit, credit, isParty = false) => {
                if (debit > 0n || credit > 0n) {
                    out.push({ code, label, party: isParty, debit, credit })
                }
            }

            const lineRows = []
            let vat = 0n
            let gross = 0n
            let movedAmount = 0n
            let movedVat = 0n

            for (const line of this.lines) {
                const f = this.lineFigures(line)

                if (this.isNote && line.type === 'product' && line.goods_moved) {
                    movedAmount += f.amount
                    movedVat += f.vat

                    continue
                }

                vat += f.vat
                gross += f.total

                if (line.type !== 'product') {
                    this.group(lineRows, this.accountCode(line.account_id), f.amount)

                    continue
                }

                if (doc === 'dn') {
                    // ⓘ মজুদে থাকা ভাগ ১১২০, বিক্রি হয়ে যাওয়া ভাগ ৫১৫০ ([[NotePosting]])
                    const share = this.stockShare(line)
                    const inStock = round2At((f.amount * share) / 100n, 8)

                    this.group(lineRows, '1120', inStock)
                    this.group(lineRows, '5150', f.amount - inStock)

                    continue
                }

                this.group(lineRows, this.productCode(line), f.amount)
            }

            if (doc === 'sv') {
                push(party.code, party.label, gross, 0n, true)
                lineRows.forEach((r) => push(r.code, this.codeLabel(r.code), 0n, r.amount))
                push('2120', this.codeLabel('2120'), 0n, vat)
            } else if (doc === 'puv') {
                const tds = this.tdsValue()

                lineRows.forEach((r) => push(r.code, this.codeLabel(r.code), r.amount, 0n))
                push('1139', this.codeLabel('1139'), vat, 0n)
                push(party.code, party.label, 0n, gross - tds, true)
                push('2121', this.codeLabel('2121'), 0n, tds)
            } else if (doc === 'cn') {
                lineRows.forEach((r) => push(r.code, this.codeLabel(r.code), r.amount, 0n))
                push('2120', this.codeLabel('2120'), vat, 0n)
                push(party.code, party.label, 0n, gross, true)
            } else {
                push(party.code, party.label, gross, 0n, true)
                lineRows.forEach((r) => push(r.code, this.codeLabel(r.code), 0n, r.amount))
                push('2120', this.codeLabel('2120'), 0n, vat)
            }

            const returns = []

            if (movedAmount + movedVat > 0n) {
                if (doc === 'cn') {
                    returns.push({ code: '4110', label: this.codeLabel('4110'), party: false, debit: movedAmount, credit: 0n })
                    returns.push({ code: '2120', label: this.codeLabel('2120'), party: false, debit: movedVat, credit: 0n })
                    returns.push({ code: party.code, label: party.label, party: true, debit: 0n, credit: movedAmount + movedVat })
                } else {
                    returns.push({ code: party.code, label: party.label, party: true, debit: movedAmount + movedVat, credit: 0n })
                    returns.push({ code: '1120', label: this.codeLabel('1120'), party: false, debit: 0n, credit: movedAmount })
                    returns.push({ code: '2120', label: this.codeLabel('2120'), party: false, debit: 0n, credit: movedVat })
                }
            }

            const dr = out.reduce((t, r) => t + r.debit, 0n)
            const cr = out.reduce((t, r) => t + r.credit, 0n)

            return { rows: out, dr, cr, returns: returns.filter((r) => r.debit > 0n || r.credit > 0n) }
        },

        /** বিলের মালের যত ভাগ এখনো মজুদে — ৬ দশমিকে কাটা, ০–১ ([[NoteLineBuilder]]) */
        stockShare (line) {
            const billed = money(line.billed ?? '0')

            // ⓘ বিল ছাড়া পণ্যের সারি — কোন স্তর জানা নেই, পুরোটা ৫১৫০ (সার্ভারের একই নিয়ম)
            if (line.source_line_id === null || line.source_line_id === undefined || line.source_line_id === '') {
                return 0n
            }

            if (line.in_stock === null || billed <= 0n) {
                return 1000000n
            }

            const share = (money(line.in_stock) * 1000000n) / billed

            return share > 1000000n ? 1000000n : (share < 0n ? 0n : share)
        },

        postingRows () {
            return this.ledger().rows.map((r, i) => this.rowView(r, i))
        },

        returnRows () {
            return this.ledger().returns.map((r, i) => this.rowView(r, i))
        },

        partyName () {
            return String((config.partyNames ?? {})[this.partyId] ?? '✓')
        },

        rowView (row, i) {
            return {
                key: i + ':' + row.code,
                label: row.label,
                party: row.party ? this.partyName() : '—',
                debit: row.debit > 0n ? this.fmt(row.debit) : '',
                credit: row.credit > 0n ? this.fmt(row.credit) : '',
            }
        },

        get drText () {
            return this.fmt(this.ledger().dr)
        },

        get crText () {
            return this.fmt(this.ledger().cr)
        },

        get balanced () {
            const { dr, cr } = this.ledger()

            return dr > 0n && dr === cr
        },

        get hasReturns () {
            return this.lines.some((l) => this.isNote && l.type === 'product' && l.goods_moved)
        },

        get returnItems () {
            return this.lines
                .filter((l) => l.type === 'product' && l.goods_moved)
                .map((l) => l.item_name + ' ' + plain(money(l.qty)).replace(/\.00$/, '') + ' ' + l.unit)
                .join(', ')
        },

        // ── জমা ───────────────────────────────────────────────────────────

        /** সার্ভারে যায় একটাই লুকানো ঘরে — `lines_json` */
        serialise () {
            return this.lines.map((line) => {
                const out = {
                    type: line.type,
                    product_id: line.type === 'product' && line.product_id !== '' ? Number(line.product_id) : null,
                    item_name: line.type === 'service' ? line.item_name : null,
                    account_id: line.type !== 'product' && line.account_id !== '' ? Number(line.account_id) : null,
                    description: line.description.trim() === '' ? null : line.description,
                    qty: line.type === 'account' ? '1' : line.qty,
                    rate: line.type === 'account' ? line.amountText : (line.goods_moved && line.bill_rate !== null ? line.bill_rate : line.rate),
                    amount: line.type === 'account' || line.amountTyped ? line.amountText : null,
                    tax_id: line.tax_id !== '' && ! (this.isNote && line.type === 'product') ? Number(line.tax_id) : null,
                    unit: line.type === 'service' && line.unit !== '' ? line.unit : null,
                }

                if (this.isNote) {
                    out.source_line_id = line.type === 'product' ? line.source_line_id : null
                    out.goods_moved = this.canReturn(line) && line.goods_moved
                }

                return out
            })
        },

        get linesJson () {
            return JSON.stringify(this.serialise())
        },
    }
}
