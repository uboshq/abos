/*
 * টাকার ঘরগুলো — কোন খাত, কীভাবে, কত।
 *
 * ⓘ ব্লেডের অ্যাট্রিবিউট থেকে এখানে এসেছে ১৯ সেপ্টেম্বর ২০২৬-এ (নিরীক্ষার
 * ধাপ ৩.১): CSP-Alpine অ্যাট্রিবিউটের ভিতরে getter, `?.`, `Math` বা
 * `Object` পড়তে পারে না। কারণের বিস্তার [[shell.js]]-এর মাথায়।
 */

/**
 * খাত বাছাই — বাছা খাতটা কোন ধরনের, আর নম্বরের ঘর লাগবে কি না।
 *
 * ⚠️ ধরনটা প্রতিটা option-এ `data-kind`-এ বসে, তালিকার আলাদা কোনো নকলে
 * নয়। দুই জায়গায় রাখলে একটা তালিকা ছাঁকা হত আর অন্যটা নয়, আর তখন
 * নম্বরের ঘরটা ভুল খাতে ভেসে উঠত।
 */
export function moneyAccount ({ chosen = '' } = {}) {
    return {
        chosen,

        /*
         * ⛔ ধরনটা `chosen` ধরে — ২৭ সেপ্টেম্বর ২০২৬, লাইভে TCL-এ ধরা।
         *
         * আগে এখানে `$refs.picker.selectedOptions` পড়া হত। ⚠️ Alpine ওটা
         * নজরে রাখে না, তাই `x-show="needsReference"` একবার মেপে আর কখনো
         * মাপত না: ব্যাংক বাছার পরেও লেনদেন-নম্বরের ঘর আসত না — হাতধার,
         * আমানত, মূলধন, উত্তোলন আর ভাড়ার প্রতিটা পর্দায়। ⓘ `chosen` নজরে
         * থাকা মান (`x-model`), তাই এখন বাছাই বদলালেই ঘরটা আসে-যায়।
         */
        get kind () {
            const value = String(this.chosen ?? '')
            const option = [...(this.$refs.picker?.options ?? [])].find(o => o.value === value)

            return option?.dataset?.kind ?? ''
        },

        get needsReference () {
            return this.kind === 'bank' || this.kind === 'mfs'
        },
    }
}

/**
 * টাকার অঙ্ক লেখার একটাই নিয়ম — লাখ-কোটির কমা।
 *
 * ── ⛔ কেন এক জায়গায়, ২১ সেপ্টেম্বর ২০২৬ ────────────────────
 * পাঁচটা ফাইলে সাতবার আলাদা করে `toLocaleString('en-US')` লেখা ছিল।
 * ⚠️ তাতে সার্ভারের লেখা ([[Money::format]]) আর পর্দার লেখা একই
 * সংখ্যা দুই রকম দেখাত — কাউন্টারে `1,234,567`, আর ছাপা কাগজে
 * `12,34,567`।
 *
 * ⓘ `en-IN` ইংরেজি অঙ্কেই লেখে, কেবল কমার জায়গা বাংলাদেশ-
 * ভারতের নিয়মে — সার্ভারের সাথে হুবহু এক।
 */
export function taka (value, { decimals = 2 } = {}) {
    return Number(value || 0).toLocaleString('en-IN', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    })
}

/** নোট গুনে মোট — বড় নোট থেকে ছোট, যা বসানো হয়েছে */
export function countNotes (notes) {
    return Object.entries(notes)
        .reduce((sum, [note, qty]) => sum + (Number(note) * (Number(qty) || 0)), 0)
}

/**
 * টাকা কীভাবে এল বা গেল — মাধ্যম, চার্জ, নোট গোনা, চেকের তারিখ।
 *
 * ⓘ `amountField`: ফর্মের কোন ঘরে টাকার অঙ্কটা থাকে — শুরুতে সেখান
 * থেকেই পড়া হয়, যাতে নোট গোনার তুলনাটা প্রথম থেকেই ঠিক থাকে।
 */
/**
 * খাতের ধরন দেখে মাধ্যম — নগদ খাতে নগদ, MFS-এ MFS, ব্যাংকে ব্যাংকের কোনো পথ।
 *
 * ⓘ ব্যাংকে ট্রান্সফার, চেক আর কার্ড তিনটাই চলে, তাই আগে থেকে ওর একটা বাছা
 * থাকলে সেটাই থাকে; নইলে ট্রান্সফার। ⚠️ সার্ভারও ঠিক এই মিল চায়
 * ([[VoucherService::assertTheWayMatchesTheAccount()]]) — দুই জায়গা এক কথা বলে।
 */
export function wayForKind (kind, current) {
    if (kind === 'cash') return 'cash'
    if (kind === 'mfs') return 'mfs'
    if (kind === 'bank') return ['transfer', 'cheque', 'card'].includes(current) ? current : 'transfer'

    return current
}

/**
 * ⭐ [[components.money.method]]-এর মগজ — পদ্ধতি, চার্জের লাইন, আগাম চেক, নোট গোনা (মালিক, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ ব্যাংকের খাতে চারটা পথই চলে (ট্রান্সফার, নগদ জমা, চেক, পুরনো কার্ড), তাই আগে থেকে ওর একটা বাছা থাকলে সেটাই থাকে;
 * নইলে ট্রান্সফার — [[wayForKind]]-এর একই নিয়ম, নগদ জমা যোগ করে।
 */
export function methodForKind (kind, current) {
    if (kind === 'cash') return 'cash'
    if (kind === 'mfs') return 'mfs'
    if (kind === 'bank') return ['transfer', 'deposit', 'cheque', 'card'].includes(current) ? current : 'transfer'

    return current
}

/**
 * ⭐ পদ্ধতি আগে, খাত পরে — পদ্ধতিই ঠিক করে কোন ধরনের খাত চলে (মালিকের প্রশ্ন, ২৪ অক্টোবর ২০২৬, fe)। ⓘ সার্ভারের একই তালিকা
 * ([[MoneyMethod::KIND_OF]]) — পর্দা যা দেখায়, সার্ভার তা-ই মানে।
 */
export const KIND_OF = { cash: 'cash', mfs: 'mfs', transfer: 'bank', deposit: 'bank', cheque: 'bank', card: 'bank' }

/** খাতটা এই পদ্ধতির কি না — পদ্ধতি না জানা বা খাতের ধরন খালি হলে প্রশ্ন নেই */
export function fitsMethod (method, kind) {
    if (!(method in KIND_OF) || !kind) return true

    return KIND_OF[method] === kind
}

/**
 * পদ্ধতি বদলালে খাতের নতুন অবস্থা — কোন খাতগুলো তালিকায়, কোনটা বাছা, আগেরটা সরল কি না।
 *
 * ⓘ আগের খাত মিললে থাকে; না মিললে ফাঁকা (`dropped` = আগের খাত); একটাই খাত থাকলে সেটা নিজে বসে (fe)।
 *
 * @param accounts [{ id, label, kind }]
 * @returns { list, picked, dropped }
 */
export function accountsForMethod (accounts, method, current) {
    const list = (accounts ?? []).filter(a => fitsMethod(method, a.kind))
    const now = String(current ?? '')
    const keeps = now !== '' && list.some(a => String(a.id) === now)
    const dropped = now !== '' && !keeps ? (accounts ?? []).find(a => String(a.id) === now) ?? null : null
    let picked = keeps ? now : ''

    if (picked === '' && list.length === 1) picked = String(list[0].id)

    return { list, picked, dropped }
}

/** চার্জের লাইন — লেখার ছাঁচে অঙ্ক দুইটা বসানো (`:charge`, `:amount`, `:head`) */
export function chargeLine (template, { charge, amount, head }) {
    return String(template ?? '')
        .replace(':charge', taka(charge))
        .replace(':amount', taka(amount))
        .replace(':head', head ?? '')
}

export function moneyMethod ({
    method = 'cash',
    charge = '',
    chequeDate = '',
    amountField = 'amount',
    chargeField = 'charge_amount',
    moneyField = '',
    direction = 'in',
    notes = {},
    accounts = null,
    methodNames = {},
    cleared = '',
} = {}) {
    // ⓘ খাতের তালিকা বন্ধনীতে, প্রতিক্রিয়াশীল তথ্যে নয় — বদলায় না, কেবল পড়া হয়
    const picking = Array.isArray(accounts)

    return {
        method: method || 'cash',
        charge: charge ?? '',
        chequeDate: chequeDate || '',
        amount: 0,
        notes: (notes && typeof notes === 'object') ? { ...notes } : {},
        // ⓘ চলতি পদ্ধতির খাত কয়টা — শূন্য হলে তালিকার জায়গায় কারণ, আর জমার বোতাম বন্ধ
        accountCount: picking ? accounts.filter(a => fitsMethod(method || 'cash', a.kind)).length : 1,
        clearedLine: cleared || '',
        startPick: '',

        get hasAccounts () {
            return this.accountCount > 0
        },

        get noAccountsLine () {
            return String(this.$root.dataset.accountNoneLine ?? '').replace(':method', methodNames[this.method] ?? this.method)
        },

        /*
         * ⭐ পদ্ধতি বদলালে খাতের তালিকা নতুন করে — কেবল মিলে যাওয়া খাত, বাকিগুলো তালিকায় থাকেই না (fe, ২৪ অক্টোবর ২০২৬)।
         * ⓘ বাছা মান বদলালে `change` ছাড়া হয় — হোস্টের `x-model` (স্থিতি, প্রিভিউ, রেফারেন্সের উত্তর) নিজের পথেই খবর পায়।
         */
        fillAccounts (start = false) {
            const select = this.$root.querySelector('select[data-money-account]')

            if (!picking || !select) return

            const { list, picked, dropped } = accountsForMethod(accounts, this.method, start ? (this.startPick ?? select.value) : select.value)

            for (const option of [...select.options]) {
                if (option.value !== '') option.remove()
            }

            for (const row of list) {
                const option = document.createElement('option')
                option.value = String(row.id)
                option.textContent = row.label
                option.dataset.kind = row.kind ?? ''
                select.add(option)
            }

            this.accountCount = list.length
            if (!start) {
                this.clearedLine = dropped
                    ? String(this.$root.dataset.accountClearedLine ?? '').replace(':account', dropped.label).replace(':method', methodNames[this.method] ?? this.method)
                    : ''
            }

            select.value = picked
            select.dispatchEvent(new Event('change', { bubbles: true }))
            this.blockSubmit(list.length === 0)
        },

        /* ⛔ খাত ছাড়া জমা নয় — যে বোতাম এই বাক্স বন্ধ করেছে, কেবল সেটাই খোলে (হোস্টের নিজের `:disabled` ছোঁয়া হয় না) */
        blockSubmit (on) {
            const buttons = this.$el.closest('form')?.querySelectorAll('button[type="submit"], button:not([type])') ?? []

            for (const button of buttons) {
                if (on && !button.disabled) {
                    button.disabled = true
                    button.dataset.moneyBlocked = '1'
                } else if (!on && button.dataset.moneyBlocked) {
                    button.disabled = false
                    delete button.dataset.moneyBlocked
                }
            }
        },

        readAmount () {
            const field = this.$el.closest('form')?.querySelector(`[name="${amountField}"]`)
            const raw = String(field?.value ?? '').replace(/[^0-9.]/g, '')

            this.amount = raw === '' ? 0 : Number(raw)
        },

        /*
         * ⓘ বাইরের অংশের জন্য (আদায়ের সারাংশ, a5): পদ্ধতি বা চার্জ বদলালেই `money-method` ঘটনা, `detail = { method, charge }` —
         * চার্জ কেবল যে পদ্ধতিতে চার্জের ঘর আছে, নইলে ০ ([[chargeNumber]])।
         */
        announce () {
            this.$dispatch('money-method', { method: this.method, charge: this.chargeNumber })
        },

        init () {
            this.readAmount()
            this.$watch('method', () => this.announce())
            this.$watch('charge', () => this.announce())
            this.$nextTick(() => this.announce())

            if (picking) {
                /*
                 * ⓘ সার্ভারের বাছা খাত এখনই পড়া — হোস্টের `x-model` একটু পরে বসে নিজের পুরনো মান চাপাত (সার্ভার না-মেলা খাত সরালে বা
                 * একমাত্র খাত বসালে হোস্ট তা জানত না)। তাই পরের ধাপে আবার বসিয়ে `change` ছাড়া হয়।
                 */
                this.startPick = this.$root.querySelector('select[data-money-account]')?.value ?? ''
                this.$watch('method', () => this.fillAccounts())
                this.$nextTick(() => this.fillAccounts(true))
            }

            const form = this.$el.closest('form')

            form?.addEventListener('input', (event) => {
                if (event.target?.name === amountField) {
                    this.readAmount()
                }
            })

            if (moneyField) {
                form?.addEventListener('change', (event) => {
                    if (event.target?.name === moneyField) {
                        this.followAccount(event.target)
                    }
                })
            }
        },

        followAccount (select) {
            const option = [...(select?.options ?? [])].find(o => o.value === select.value)

            this.method = methodForKind(option?.dataset?.kind ?? '', this.method)
        },

        /* ⓘ চার্জ কেবল যে পদ্ধতিতে চার্জের ঘর আছে — নগদে বা চেকে পুরনো সংখ্যা থেকে গেলে লাইনটা মিথ্যা বলত */
        get chargeNumber () {
            return ['mfs', 'transfer', 'deposit'].includes(this.method) ? (Number(this.charge) || 0) : 0
        },

        get hasCharge () {
            return this.chargeNumber > 0
        },

        /* ⓘ `$root`, `$el` নয় — getter-এ `$el` হল যে ঘর লাইনটা আঁকে, আর ছাঁচগুলো বাক্সের মাথায় */
        get chargeLine () {
            const data = this.$root.dataset

            return chargeLine(direction === 'out' ? data.lineOut : data.lineIn, {
                charge: this.chargeNumber,
                amount: this.amount,
                head: this.method === 'mfs' ? data.headMfs : data.headBank,
            })
        },

        get postDated () {
            return this.method === 'cheque' && this.chequeDate !== ''
                && this.chequeDate > new Date().toISOString().slice(0, 10)
        },

        noteTotal (note) {
            const sum = (Number(this.notes[note]) || 0) * note

            return sum ? taka(sum, { decimals: 0 }) : '—'
        },

        get counted () {
            return countNotes(this.notes)
        },

        get countMatches () {
            return Math.abs(this.counted - Number(this.amount || 0)) < 0.005
        },

        get countClass () {
            return this.countMatches
                ? 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                : 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)'
        },

        get countText () {
            const line = this.$root.querySelector('[data-agrees]')

            return this.countMatches ? (line?.dataset?.agrees ?? '') : (line?.dataset?.differs ?? '')
        },
    }
}

/** লেখা টাকা → পয়সায় পূর্ণসংখ্যা — যোগ-বিয়োগে ভগ্নাংশের ভুল নয় (নকশা #২৫: পয়সা পর্যন্ত নির্ভুল) */
export function paisa (value) {
    const clean = String(value ?? '').replace(/[^0-9.-]/g, '')

    return clean === '' || Number.isNaN(Number(clean)) ? 0 : Math.round(Number(clean) * 100)
}

/** পয়সা → লেখা ঘরের মান ("1250.50") */
export function fromPaisa (value) {
    return (value / 100).toFixed(2)
}

/**
 * পুরনো বিল আগে — টাকাটা শেষ দিনের ক্রমে বিলে বিলে বসে, যতক্ষণ থাকে (নকশা #৩; SAP-এর clearing)।
 *
 * @param bills [{ id, remaining (পয়সা) }] — আগে থেকেই ক্রমে সাজানো
 * @returns { [id]: পয়সা }
 */
export function allocateOldestFirst (bills, amount) {
    let left = Math.max(0, amount)
    const out = {}

    for (const bill of bills) {
        const take = Math.min(left, bill.remaining)
        out[String(bill.id)] = take
        left -= take
    }

    return out
}

/**
 * ⭐ রসিদ ও পরিশোধ ভাউচারের টাকার অংশ — অঙ্ক লাখ-কমায়, খাতে কত আছে, রেফারেন্সের চলতি উত্তর আর পোস্টের আগে খাতায় যা যাবে
 * (fe, ২৩ অক্টোবর ২০২৬; চূড়ান্ত নকশার মিল-তালিকা)। ⓘ টাকা দেওয়ার পর্দার ([[payBills]]) একই আচরণ, বিলের টেবিল ছাড়া।
 *
 * ⓘ খাতায় কী যাবে বলে সার্ভার ([[VoucherController::simulate()]]) — জমার একই সারি আর শাখায়; রেফারেন্সের উত্তর পোস্টের একই
 * প্রশ্নে ([[VoucherController::referenceCheck()]])।
 */
export function voucherMoney ({
    amount = '',
    account = '',
    balances = {},
    simulateUrl = '',
    referenceUrl = '',
    voucherId = null,
    out = false,
    ait = '',
    vds = '',
    owedLabels = {},
    branchLabels = {},
} = {}) {
    // ⓘ ফর্মটা বন্ধনীতে, প্রতিক্রিয়াশীল তথ্যে নয় — DOM-কে Alpine-এর proxy-তে মোড়ানো নয়
    let form = null

    return {
        // ⓘ ফর্মের দুইবার-জমা ঠেকানো ([[simple-form]]-এর @submit) — আগের `{ busy: false }`-এর জায়গায়
        busy: false,
        amount: String(amount ?? ''),
        account: String(account ?? ''),
        // ⭐ উৎসে কর্তন — পরিশোধে (x-ui.field type="money", model ait/vds); খাতায় কী যায় সার্ভার বলে ([[WithheldTax::onPayment()]])
        ait: String(ait ?? ''),
        vds: String(vds ?? ''),
        /*
         * ⭐ মাথার তিন অঙ্ক — পক্ষের বকেয়া/পাওনা · এই কাগজ · বাকি থাকবে (নকশা পাতা ২ আর ৪; fe, ২৪ অক্টোবর ২০২৬)। ⓘ বকেয়া আসে পক্ষের
         * বাক্স থেকে (`party-due` ঘটনা, [[party-voucher.js]]) — সার্ভারের একই উত্তর ([[VoucherController::due()]]); চিহ্ন: ধনাত্মক = তিনি
         * আমাদের দেবেন। পক্ষ না থাকলে (`null`) অঙ্কগুলো নেই, এক লাইনের কারণ।
         */
        partyDue: null,
        hasParty: true,
        // ⓘ পক্ষের ধরন — মাথার প্রথম অঙ্কের নাম তাতে (`owedLabels`, ধরন → "গ্রাহকের বকেয়া"; অজানায় '' → সাধারণ নাম)
        headPartyType: '',
        headPartyBranch: '',
        charge: 0,
        balances,
        slip: { state: 'empty', message: '' },
        slipAsked: 0,
        slipTimer: null,
        preview: { rows: [], debit: '', credit: '', balanced: true, message: '' },
        asked: 0,
        timer: null,

        init () {
            form = this.$el.closest('form')
            this.$watch('account', () => { this.slipSoon(); this.refresh() })
            this.$watch('amount', (now, was) => { if (paisa(now) !== paisa(was)) { this.refresh(); this.tellGross() } })
            this.$watch('ait', (now, was) => { if (paisa(now) !== paisa(was)) { this.refresh(); this.tellGross() } })
            this.$watch('vds', (now, was) => { if (paisa(now) !== paisa(was)) { this.refresh(); this.tellGross() } })
            this.$nextTick(() => this.tellGross())

            // ⓘ পদ্ধতির ঘর x-if-এ আসে-যায়, আর পক্ষ-বিবরণ অন্য বাক্সে — তাই শোনা গোটা ফর্মে
            form?.addEventListener('input', (e) => {
                if (e.target?.name === 'instrument_no' || e.target?.name === 'deposit_slip_no') this.slipSoon()
            })
            form?.addEventListener('change', (e) => {
                if (e.target?.name !== 'instrument_no' && e.target?.name !== 'deposit_slip_no') this.refresh()
            })
            this.$nextTick(() => this.refresh())
        },

        /* ⓘ পদ্ধতির বাক্সের ঘটনা ([[moneyMethod]]) — চার্জ "খাতে আছে কি না"-য়, আর প্রিভিউ নতুন করে */
        heard (detail) {
            this.charge = paisa(detail?.charge ?? 0)
            this.slipSoon()
            this.refresh()
        },

        /* ⓘ পক্ষের বাক্সের ঘটনা — বকেয়া (সার্ভারের চিহ্নে) আর পক্ষ আছে কি না ("অন্য (সরাসরি খাত)"-এ নেই) */
        heardParty (detail) {
            this.hasParty = detail?.hasParty !== false
            this.partyDue = this.hasParty && detail?.due !== null && detail?.due !== undefined ? String(detail.due) : null
            this.headPartyType = String(detail?.partyType ?? '')
            this.headPartyBranch = String(detail?.partyBranch ?? '')
        },

        /*
         * ⓘ ফর্মের মোট (পক্ষের খাতা যতটা কমে) পক্ষের বাক্সকে — বিলের যোগফলের বাইরে অগ্রিম কত, বেশি কি না ([[party-voucher.js]] `heardGross`)।
         * ⓘ জানালায়, কারণ পক্ষের বাক্স এই ফর্মের ভিতরে — ভিতরের দিকে ঘটনা নামে না।
         */
        tellGross () {
            window.dispatchEvent(new CustomEvent('money-gross', { detail: this.headGrossPaisa }))
        },

        /* ⓘ পক্ষের বাক্সের "পুরো বকেয়া" / "নির্বাচিত বিল" — অঙ্কটা টাকার ঘরে (কাঁচা অঙ্ক; দেখানোর ঘর নিজে লাখ-কমায় সাজায়) */
        heardFill (value) {
            const amount = String(value ?? '').replace(/,/g, '').trim()
            if (amount !== '' && Number.isFinite(Number(amount))) this.amount = amount
        },

        /*
         * ⭐ মাথার "শাখা: গ্রাহকের শাখা (ময়মনসিংহ)" — কেবল তথ্য, নকশার লেখা; পাশে "পোস্ট হবে: <শাখা>" — খাতা যে শাখায় বসবে, সার্ভারের
         * প্রিভিউ থেকে (বাক্সের শাখা — মালিকের ১৯ অক্টোবরের নিয়ম; দুটো আলাদা হলে চোখে পড়ে)।
         */
        get partyBranchText () {
            if (this.headPartyBranch === '') return ''
            const label = branchLabels[this.headPartyType] ?? branchLabels[''] ?? ''
            return label + ' (' + this.headPartyBranch + ')'
        },
        get postBranchText () { return String(this.preview?.branch ?? '') },

        /* ⭐ মাথার প্রথম অঙ্কের নাম পক্ষের ধরনে (নকশা: "গ্রাহকের বকেয়া", "সরবরাহকারীর পাওনা") */
        get headOwedLabel () { return owedLabels[this.headPartyType] ?? owedLabels[''] ?? '' },
        // ⓘ বকেয়া লালে (নকশা); পক্ষের অগ্রিম হলে সবুজ, "অগ্রিম" শব্দের সাথে; শূন্যে সাধারণ রং — নকশায় "৳0.00" রং ছাড়া
        get headOwedClass () {
            if (this.headOwedPaisa === 0) return ''
            return this.headOwedIsAdvance ? 'text-(--color-badge-success-ink)' : 'text-(--color-danger)'
        },

        /*
         * ⭐ মাথার তিন অঙ্ক। রসিদ: বকেয়া (তিনি দেবেন) · এই আদায় · বাকি থাকবে = বকেয়া − আদায়। পরিশোধ: পাওনা (আমরা দেব, তাই চিহ্ন
         * উল্টো) · এই পরিশোধ (মোট = দেওয়া + AIT + VDS — পক্ষের খাতে যতটা কমে) · বাকি থাকবে = পাওনা − মোট। ⓘ পয়সায় গোনা; ঋণাত্মক
         * বাকি মানে অগ্রিম — চিহ্নে নয়, কথায় ([[party-voucher.js]]-এর একই নিয়ম)।
         */
        get hasHeadFigures () { return this.hasParty && this.partyDue !== null },
        get headOwedPaisa () { return (out ? -1 : 1) * paisa(this.partyDue ?? 0) },
        // ⓘ পক্ষের খাত কমে মোটে — দুই দিকেই (রসিদে পক্ষ কাটেন, পরিশোধে আমরা কাটি): টাকা + AIT + VDS
        // ⭐ পরিশোধে লেখা ঘরটাই মোট (`gross_amount`, পরিশোধে-মোট-টাকা-চুক্তি) — রসিদে লেখা পাওয়া টাকা, মোট তার সাথে পক্ষের কাটা অংশ
        get headGrossPaisa () { return out ? paisa(this.amount) : paisa(this.amount) + paisa(this.ait) + paisa(this.vds) },
        // ⓘ উৎসে কাটা আছে কি না — পরিশোধে কমলা "করণীয় তালিকায় উঠবে" লাইন এতে
        get hasWithheld () { return paisa(this.ait) + paisa(this.vds) > 0 },
        // ⓘ নিট — পক্ষের হাতে যা যায়: পরিশোধে মোট − AIT − VDS, রসিদে লেখা টাকাই
        get netPaisa () { return out ? this.headGrossPaisa - paisa(this.ait) - paisa(this.vds) : paisa(this.amount) },
        // ⓘ "এই আদায়" নকশার মতো হাতে পাওয়া টাকা; "এই পরিশোধ (মোট)" মোট
        get headThisPaisa () { return out ? this.headGrossPaisa : paisa(this.amount) },
        get headLeftPaisa () { return this.headOwedPaisa - this.headGrossPaisa },
        get headOwedText () { return this.hasHeadFigures ? '৳' + taka(Math.abs(this.headOwedPaisa) / 100) : '—' },
        get headThisText () { return '৳' + taka(this.headThisPaisa / 100) },
        get headLeftText () { return this.hasHeadFigures ? '৳' + taka(Math.abs(this.headLeftPaisa) / 100) : '—' },
        get headLeftIsAdvance () { return this.hasHeadFigures && this.headLeftPaisa < 0 },
        // ⛔ পক্ষের আগে থেকে অগ্রিম থাকলে বকেয়ার অঙ্ক চিহ্ন ছাড়া "বকেয়া" পড়াত — কথায় "অগ্রিম" (মাপা: ২৪ অক্টোবর ২০২৬, আলম স্টোর)
        get headOwedIsAdvance () { return this.hasHeadFigures && this.headOwedPaisa < 0 },

        /* ⭐ খাতে আছে কত — পরিশোধে যথেষ্ট কি না (নকশার "খাতে আছে ৳…") */
        get balance () { return this.balances[this.account] ?? null },
        get hasBalance () { return this.balance !== null },
        get balanceText () { return this.hasBalance ? taka(Number(this.balance)) : '' },
        // ⓘ খাত থেকে বেরোয় নিট + চার্জ (চার্জ মোটের বাইরে, কোম্পানির খরচ)
        get short () { return out && this.hasBalance && paisa(this.balance) < this.netPaisa + this.charge },
        get balanceClass () { return this.short ? 'text-(--color-danger)' : 'text-(--color-ink-muted)' },

        slipSoon () {
            clearTimeout(this.slipTimer)
            this.slipTimer = setTimeout(() => this.checkSlip(), 300)
        },

        /* ⓘ নম্বর পড়া হয় প্রশ্নের মুহূর্তে; ⛔ কেবল শেষ প্রশ্নের উত্তর — দেরিতে-আসা আগেরটা নতুনটাকে ঢাকে না */
        async checkSlip () {
            // ⓘ নগদ জমায় স্লিপই রেফারেন্স ([[VoucherRequest]]) — যে ঘরটা এখন পর্দায়, সেটাই
            const no = String(form?.querySelector('[name="instrument_no"]')?.value || form?.querySelector('[name="deposit_slip_no"]')?.value || '').trim()
            const ticket = ++this.slipAsked

            if (!referenceUrl || no === '' || this.account === '') {
                this.slip = { state: 'empty', message: '' }
                return
            }

            const query = new URLSearchParams({ account_id: this.account, instrument_no: no })
            if (voucherId) query.set('voucher', String(voucherId))

            try {
                const response = await fetch(referenceUrl + '?' + query.toString(), { headers: { Accept: 'application/json' } })
                const answer = response.ok ? await response.json() : { state: 'empty', message: '' }
                if (ticket === this.slipAsked) this.slip = answer
            } catch {
                if (ticket === this.slipAsked) this.slip = { state: 'empty', message: '' }
            }
        },

        get slipShown () { return this.slip.state === 'free' || this.slip.state === 'used' },
        get slipUsed () { return this.slip.state === 'used' },

        /* ⭐ খাতায় যা যাবে — সার্ভারের হিসাবে, একটু থেমে (প্রতিটা অক্ষরে নয়) */
        refresh () {
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.simulate(), 350)
        },

        async simulate () {
            const empty = { rows: [], debit: '', credit: '', balanced: true, message: '' }
            if (!form || !simulateUrl || paisa(this.amount) <= 0 || this.account === '') {
                this.preview = empty
                return
            }

            const body = new FormData(form)
            body.delete('_method')
            // ⛔ একবারের জমার টোকেন প্রিভিউতে নয় — খরচ হলে আসল "পোস্ট" দ্বিতীয় চাপ ধরা পড়ে ফিরত ([[OneSubmitPerForm]])
            body.delete('_once')
            // ⓘ ফাইল প্রিভিউতে যায় না — খাতার সারি তার উপর দাঁড়ায় না
            body.delete('attachment')
            // ⓘ সম্পাদনায় নিজের রেফারেন্স নিজের সাথে "আগে বসেছে" নয়
            if (voucherId) body.set('voucher_id', String(voucherId))

            const ticket = ++this.asked

            try {
                const response = await fetch(simulateUrl, { method: 'POST', body, headers: { Accept: 'application/json' } })
                const answer = await response.json().catch(() => null)
                if (ticket !== this.asked) return
                // ⓘ সারি থাকলে খাতার ছবি; সারি খালি আর `message` থাকলে পোস্ট যে কারণে থামত; ঘর অর্ধেক ভরা হলে দুইটাই খালি — চুপ
                this.preview = response.ok && answer && Array.isArray(answer.rows) ? { ...empty, ...answer } : empty
            } catch {
                if (ticket === this.asked) this.preview = empty
            }
        },

        get hasPreview () { return this.preview.rows.length > 0 },
        get previewBlocked () { return this.preview.message !== '' },
        get previewMatches () { return this.hasPreview && this.preview.balanced },
        get unbalanced () { return this.hasPreview && !this.preview.balanced },

        /*
         * ⭐ হিসাবের সারাংশ ([[x-money.summary]]) — অঙ্ক আর কথা সার্ভারের, খাতার একই সারি থেকে ([[VoucherController::summaryOf()]]);
         * পর্দা কিছু গোনে না। প্রিভিউর আগে "—", কথা ফাঁকা।
         */
        get summary () { return this.hasPreview ? (this.preview.summary ?? null) : null },
        get sumAmount () { return this.summary?.amount ?? '—' },
        get sumCharge () { return this.summary?.charge ?? '—' },
        get sumNet () { return this.summary?.net ?? '—' },
        get sumWords () { return this.summary?.words ?? '' },
        get sumMatches () { return this.summary?.matches === true },
        get sumGross () { return this.summary?.gross ?? '—' },
        // ⓘ রসিদে পক্ষ কাটেন — অঙ্ক যোগে, চিহ্ন ছাড়া (পক্ষের খাতে মোট = পাওয়া + VDS + AIT)
        get sumAitText () { return this.summary?.ait ?? '—' },
        get sumVdsText () { return this.summary?.vds ?? '—' },
        // ⓘ বিয়োগ চিহ্ন কেবল সত্যি কাটলে — "−0.00" চোখে ভুল পড়ে
        get sumAitMinus () { return this.summary ? (paisa(this.summary.ait) > 0 ? '−' : '') + this.summary.ait : '—' },
        get sumVdsMinus () { return this.summary ? (paisa(this.summary.vds) > 0 ? '−' : '') + this.summary.vds : '—' },
    }
}

/**
 * ⭐ টাকা দেওয়ার পর্দা (সরবরাহকারী-পরিশোধ) — মাথার তিন অঙ্ক, খোলা বিলের টেবিল, সারাংশ, খাতে টাকা আছে কি না, আর পোস্টের আগের
 * প্রিভিউ (নকশা #১, #৩, #৪, #১০, #২৪; মালিক, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ অঙ্ক দেখায় পর্দা, কিন্তু খাতায় কী যাবে বলে সার্ভার: প্রিভিউ আসে [[PaymentController::simulate()]] থেকে — পোস্টের একই নিয়মে।
 */
export function payBills ({
    bills = [],
    supplier = '',
    amount = '',
    account = '',
    picks = {},
    balances = {},
    taxIds = {},
    bankIds = [],
    simulateUrl = '',
    slipUrl = '',
    paymentId = null,
} = {}) {
    // ⓘ ফর্মটা বন্ধনীতে, প্রতিক্রিয়াশীল তথ্যে নয় — DOM-কে Alpine-এর proxy-তে মোড়ানো নয়
    let slipForm = null

    return {
        bills: bills.map(b => ({ ...b, remaining: paisa(b.remaining), total: paisa(b.total) })),
        /* ⭐ স্লিপ/TrxID-এর তাৎক্ষণিক উত্তর (মিল-তালিকা #৫): empty · free · used — জমার একই নিয়মে, [[PaymentController::slipCheck()]] */
        slip: { state: 'empty', message: '' },
        slipAsked: 0,
        slipTimer: null,
        supplier: String(supplier ?? ''),
        amount: String(amount ?? ''),
        account: String(account ?? ''),
        charge: 0,
        method: 'cash',
        lines: Object.fromEntries(Object.entries(picks).map(([id, v]) => [String(id), paisa(v)])),
        balances,
        preview: { rows: [], debit: '', credit: '', balanced: true },
        timer: null,
        asked: 0,

        init () {
            this.$watch('supplier', () => this.allocate())
            // ⓘ কেবল অঙ্ক বদলালে নতুন করে বসানো — লাখ-কমা বসানো (একই অঙ্ক) হাতে বসানো প্রয়োগ মুছবে না
            this.$watch('amount', (now, was) => { if (paisa(now) !== paisa(was)) this.allocate() })
            this.$watch('account', () => this.refresh())
            this.$nextTick(() => this.refresh())

            // ⓘ পদ্ধতির ঘরগুলো x-if-এ আসে-যায়, তাই শোনা ফর্মে — যে ঘরের নাম `instrument_no`
            slipForm = this.$el
            this.$el.addEventListener('input', (e) => {
                if (e.target?.name === 'instrument_no') this.slipSoon()
            })
            this.$watch('supplier', () => this.slipSoon())
        },

        slipSoon () {
            clearTimeout(this.slipTimer)
            this.slipTimer = setTimeout(() => this.checkSlip(), 300)
        },

        /*
         * ⓘ নম্বরটা পড়া হয় প্রশ্নের মুহূর্তে, সাজানোর মুহূর্তে নয় — সরবরাহকারী বদলালে যে প্রশ্ন যায় তার হাতে কোনো লেখা থাকে না,
         * আর ৩০০ মিলিসেকেন্ডে ঘরের লেখা বদলেও যেতে পারে; তাই সবসময় ঘরে এখন যা আছে সেটাই।
         */
        async checkSlip (value = null) {
            const no = String(value ?? slipForm?.querySelector('[name="instrument_no"]')?.value ?? '').trim()
            // ⛔ কেবল শেষ প্রশ্নের উত্তর — দেরিতে-আসা আগের উত্তর নতুনটাকে ঢাকবে না (প্রিভিউর একই নিয়ম)
            const ticket = ++this.slipAsked

            if (!slipUrl || no === '' || this.supplier === '') {
                this.slip = { state: 'empty', message: '' }
                return
            }

            const query = new URLSearchParams({ supplier_id: this.supplier, instrument_no: no })
            if (paymentId) query.set('payment', String(paymentId))

            try {
                const response = await fetch(slipUrl + '?' + query.toString(), { headers: { Accept: 'application/json' } })
                const answer = response.ok ? await response.json() : { state: 'empty', message: '' }
                if (ticket === this.slipAsked) this.slip = answer
            } catch {
                if (ticket === this.slipAsked) this.slip = { state: 'empty', message: '' }
            }
        },

        get slipShown () { return this.slip.state === 'free' || this.slip.state === 'used' },
        get slipUsed () { return this.slip.state === 'used' },

        /* ⓘ পদ্ধতির বাক্সের ঘটনা ([[moneyMethod]]) — চার্জ সারাংশে, আর প্রিভিউ নতুন করে */
        heard (detail) {
            this.charge = paisa(detail?.charge ?? 0)
            this.method = detail?.method ?? 'cash'
            this.refresh()
        },

        get visible () {
            return this.bills.filter(b => String(b.supplier) === this.supplier)
        },

        allocate () {
            this.lines = allocateOldestFirst(this.visible, paisa(this.amount))
            this.refresh()
        },

        lineOf (id) {
            return this.lines[String(id)] ?? 0
        },

        isOn (id) {
            return this.lineOf(id) > 0
        },

        shown (id) {
            const v = this.lineOf(id)

            return v > 0 ? fromPaisa(v) : ''
        },

        /* টিক — দিলে বাকি টাকা থেকে যতটা ধরে, তুললে শূন্য */
        toggle (id) {
            const bill = this.visible.find(b => String(b.id) === String(id))
            if (!bill) return
            const free = Math.max(0, paisa(this.amount) - this.applied + this.lineOf(id))
            this.lines = { ...this.lines, [String(id)]: this.isOn(id) ? 0 : Math.min(bill.remaining, free) }
            this.refresh()
        },

        /* হাতে লেখা — বিলের বাকির বেশি নয় */
        typed (id, value) {
            const bill = this.visible.find(b => String(b.id) === String(id))
            if (!bill) return
            this.lines = { ...this.lines, [String(id)]: Math.min(bill.remaining, Math.max(0, paisa(value))) }
            this.refresh()
        },

        get picked () {
            return this.visible.filter(b => this.lineOf(b.id) > 0).map(b => ({ id: b.id, amount: fromPaisa(this.lineOf(b.id)) }))
        },

        get applied () {
            return this.visible.reduce((s, b) => s + this.lineOf(b.id), 0)
        },

        get owed () {
            return this.visible.reduce((s, b) => s + b.remaining, 0)
        },

        get billTotal () {
            return this.visible.reduce((s, b) => s + b.total, 0)
        },

        /* মাথার তিন অঙ্ক */
        get owedText () { return taka(this.owed / 100) },
        get payingText () { return taka(paisa(this.amount) / 100) },
        get leftText () { return taka(Math.max(0, this.owed - paisa(this.amount)) / 100) },

        /* সারাংশ */
        get appliedText () { return taka(this.applied / 100) },
        get remainingText () { return taka(this.owed / 100) },
        get billTotalText () { return taka(this.billTotal / 100) },
        get chargeText () { return taka(this.charge / 100) },
        /* ⓘ কাটা আর নিট — সার্ভারের প্রিভিউ থেকে ([[PaymentWithholding]]); প্রিভিউ আসার আগে কাটা শূন্য, নিট মোট */
        get aitText () { return this.preview.ait ?? '0.00' },
        get vdsText () { return this.preview.vds ?? '0.00' },

        /* ⓘ সারাংশে কাটা অঙ্ক বিয়োগে — শূন্যে চিহ্ন নয় (নকশা; মিল-তালিকার ফাঁক ৬) */
        get aitMinus () { return this.aitText === '0.00' ? '0.00' : '−' + this.aitText },
        get vdsMinus () { return this.vdsText === '0.00' ? '0.00' : '−' + this.vdsText },
        /* ⓘ সত্যি কিছু কাটা হলে তবেই কর-নোট (মিল-তালিকা #৩) */
        get withheld () { return paisa(this.aitText) > 0 || paisa(this.vdsText) > 0 },

        /* ⓘ নিট কোথা থেকে যায় — পদ্ধতি ধরে (ব্যাংক · MFS · নগদ), লেখা পাতার data-তে */
        get netLabel () {
            const label = this.$root.querySelector('[data-net-label]')?.dataset ?? {}
            if (this.method === 'mfs') return label.netMfs ?? ''
            if (this.method === 'cash') return label.netCash ?? ''

            return label.netBank ?? ''
        },
        get netText () { return this.preview.net ?? taka(paisa(this.amount) / 100) },

        /* ⓘ প্রয়োগের বাইরে যা থাকে — অগ্রিম, সরবরাহকারীর খাতায় (নকশা #৪) */
        get unapplied () {
            return paisa(this.amount) - this.applied
        },

        get hasUnapplied () { return this.unapplied > 0 },
        get overApplied () { return this.unapplied < 0 },
        get unappliedText () { return taka(Math.abs(this.unapplied) / 100) },
        get matches () { return this.unapplied === 0 && paisa(this.amount) > 0 },

        /* ⭐ খাতে আছে কত, আর যথেষ্ট কি না (নকশা #২৪) */
        get balance () {
            return this.balances[this.account] ?? null
        },

        get hasBalance () { return this.balance !== null },
        get balanceText () { return this.hasBalance ? taka(Number(this.balance.balance)) : '' },

        get short () {
            return this.hasBalance && paisa(this.balance.balance) < paisa(this.amount) + this.charge
        },

        get balanceClass () {
            return this.short ? 'text-(--color-danger)' : 'text-(--color-badge-success-ink)'
        },

        /* ⭐ খাতায় যা যাবে — সার্ভারের হিসাবে, একটু থেমে (প্রতিটা অক্ষরে নয়) */
        refresh () {
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.simulate(), 350)
        },

        async simulate () {
            const form = this.$root.closest('form')
            if (!form || !simulateUrl || this.supplier === '' || paisa(this.amount) <= 0) {
                this.preview = { rows: [], debit: '', credit: '', balanced: true }
                return
            }

            const body = new FormData(form)
            body.delete('_method')
            // ⛔ একবারের জমার টোকেন প্রিভিউতে নয় — খরচ হলে আসল "পোস্ট" দ্বিতীয় চাপ ধরা পড়ে ফিরত (সার্ভারও ছাড় দেয়: [[OneSubmitPerForm]])
            body.delete('_once')

            /*
             * ⛔ কেবল শেষ প্রশ্নের উত্তর — আগের প্রশ্নের দেরিতে-আসা উত্তর নতুনটাকে ঢেকে দিত (মিল-তালিকার সময় ধরা, ২২ অক্টোবর
             * ২০২৬: চার্জ লেখার পরেও প্রিভিউতে চার্জের সারি নেই, ব্যাংক থেকে ১,১৪,০০০ — চার্জ ছাড়া)।
             */
            const ticket = ++this.asked

            try {
                const response = await fetch(simulateUrl, { method: 'POST', body, headers: { Accept: 'application/json' } })
                const answer = response.ok ? await response.json() : { rows: [], debit: '', credit: '', balanced: true }
                if (ticket === this.asked) this.preview = answer
            } catch {
                if (ticket === this.asked) this.preview = { rows: [], debit: '', credit: '', balanced: true }
            }
        },

        get hasPreview () { return this.preview.rows.length > 0 },

        /* ⓘ বাছা সরবরাহকারীর ব্যাংক তথ্যের বাক্স আঁকা নেই — তখন "নেই" বাক্স (নকশা #৬) */
        get noBankRecord () { return !bankIds.includes(this.supplier) },

        /* ⓘ সরবরাহকারীর BIN · TIN — নকশায় নামের নিচে */
        get taxLine () { return taxIds[this.supplier] ?? '' },

        /* পয়সা → লাখ-কমার লেখা, টেবিলের ঘরে */
        money (value) { return taka(value / 100) },
        get unbalanced () { return this.hasPreview && !this.preview.balanced },
    }
}
