/*
 * মডিউলের ফর্মগুলোর ছোট যুক্তি — খাত, ভাউচার, জমা, মাল, রান্নাঘর।
 *
 * ⓘ সবগুলো আগে ব্লেডের `x-data="{ … }"`-এ লেখা ছিল। ১৯ সেপ্টেম্বর ২০২৬-এ
 * এখানে এসেছে (নিরীক্ষার ধাপ ৩.১), কারণ CSP-Alpine অ্যাট্রিবিউটের ভিতরে
 * পদ্ধতি, `?.`, `=>`, `Math` — কিছুই পড়তে পারে না। বিস্তার [[shell.js]]-এ।
 *
 * ⚠️ যুক্তি বদলায়নি — হুবহু সরানো। কেবল `$refs`/`$el`-এর আগে `this.` বসেছে,
 * কারণ ব্লেডে ওগুলো Alpine-এর `with`-এর ভিতরে ছিল আর এখানে সাধারণ JS।
 */

/*
 * খাতের ফর্ম — বাবা বাছলে পরের কোড আর ধরন (নগদ/ব্যাংক/MFS) আসে।
 * ⓘ ডাকটা ব্যর্থ হলে চুপ: ঘরটা খালি রাখলে সার্ভার নিজেই নম্বর বসায়।
 */
export function coaForm ({ parent = '', isGroup = false, isNew = false, kind = '', url }) {
    return {
        busy: false,
        parent,
        isGroup,
        isNew,
        kind,
        suggested: '',

        init () {
            this.preview()
        },

        async preview () {
            const wantsCode = this.isNew
            const address = new URL(url, window.location.origin)
            address.searchParams.set('parent', this.parent)
            address.searchParams.set('group', this.isGroup ? '1' : '0')

            try {
                const response = await fetch(address, { headers: { Accept: 'application/json' } })

                if (! response.ok) {
                    this.suggested = ''

                    return
                }

                const body = await response.json()
                this.suggested = wantsCode ? (body.code ?? '') : ''
                this.kind = body.money_kind ?? ''
            } catch {
                this.suggested = ''
            }
        },

        guard (event) {
            if (this.busy) {
                event.preventDefault()
            } else {
                this.busy = true
            }
        },
    }
}

/*
 * বাতিলের কারণ — বাধ্যতামূলক, কারণ কারণ ছাড়া বাতিল হওয়া কাগজ পরে কেউ
 * ব্যাখ্যা করতে পারে না। ⓘ না লিখলে জমাটাই থামে।
 */
export function reasonPrompt ({ question }) {
    return {
        ask (event) {
            const reason = prompt(question)

            if (! reason) {
                event.preventDefault()

                return
            }

            this.$refs.reason.value = reason
        },
    }
}

/*
 * সহজ ভাউচারের বাক্স — খাত, পক্ষ, টাকার শ্রেণি, আর পক্ষের বকেয়া।
 */
export function simpleVoucherBox (config) {
    return {
        from: config.from ?? '',
        credit: config.credit ?? [],
        partyType: config.partyType ?? '',
        partyId: config.partyId ?? '',
        parties: config.parties ?? [],
        cats: config.cats ?? [],
        catId: config.catId ?? '',
        subId: config.subId ?? '',
        dueUrl: config.dueUrl,
        due: null,
        dueBusy: false,

        get onCredit () {
            return this.credit.includes(Number(this.from))
        },

        get partyOptions () {
            return this.parties.filter(p => p.type === this.partyType)
        },

        get parentCats () {
            return this.cats.filter(c => c.parent_id === null)
        },

        get subCats () {
            return this.cats.filter(c => String(c.parent_id) === String(this.catId))
        },

        /*
         * ⭐ চিহ্নটাই বাক্যটা বদলে দেয় — ডেবিট − ক্রেডিট ধনাত্মক মানে তিনি
         * আমাদের দেবেন। ⚠️ কেবল সংখ্যা দেখালে সরবরাহকারীর ঘরে "−৫,০০০"
         * বসত আর কেউ ভাবতেন হিসাবে গোলমাল।
         */
        get dueText () {
            const n = Number(this.due)
            const shown = Math.abs(n).toLocaleString(undefined, {
                minimumFractionDigits: 2, maximumFractionDigits: 2,
            })

            return shown + ' — ' + (n < 0 ? config.weOweThem : config.theyOweUs)
        },

        /* ধরন বদলালে মানুষটাও — নাহলে ভুল পক্ষের খতিয়ানে টাকা বসত */
        resetParty () {
            this.partyId = ''
            this.due = null
        },

        loadDue () {
            this.due = null

            if (! this.partyType || ! this.partyId) {
                return
            }

            this.dueBusy = true

            fetch(this.dueUrl + '?party_type=' + encodeURIComponent(this.partyType)
                    + '&party_id=' + encodeURIComponent(this.partyId),
            { headers: { Accept: 'application/json' } })
                .then(r => r.ok ? r.json() : null)
                .then(d => { this.due = (d && d.known) ? d.amount : null })
                .catch(() => { this.due = null })
                .finally(() => { this.dueBusy = false })
        },

        /* শ্রেণি বাছলে খাত নিজে বসে — আসল নিয়মটা সার্ভারে, এটা সুবিধা */
        pickCategory () {
            this.subId = ''
            this.applyAccount(this.catId)
        },

        pickSub () {
            this.applyAccount(this.subId || this.catId)
        },

        applyAccount (id) {
            const row = this.cats.find(c => String(c.id) === String(id))

            if (row && row.account_id) {
                this.from = String(row.account_id)
            }
        },

        init () {
            this.loadDue()
        },
    }
}

/* নতুন জমা — ধরনের আকৃতি অনুযায়ী ঘর; ব্যক্তিগত ধরনে মালিকই ধারক */
export function depositOpener ({ kinds = {}, kindId = '', heldBy = '' }) {
    return {
        kinds,
        kindId,
        heldBy,

        get shape () {
            return this.kinds[this.kindId]?.shape ?? null
        },

        get personalOnly () {
            return this.kinds[this.kindId]?.personal ?? false
        },

        init () {
            this.$watch('personalOnly', (only) => {
                if (only) {
                    this.heldBy = 'owner'
                }
            })

            if (this.personalOnly) {
                this.heldBy = 'owner'
            }
        },
    }
}

/*
 * ⭐ বোতামটা ঘরগুলো বয়ে নেয় — টাকা, বিবরণ, মানুষ — রসিদের ঠিকানায়।
 * ⓘ [[VoucherController::prefill()]] একটা সাদা তালিকা রাখে; এখানে বেশি
 * পাঠালে কিছু ঘর চুপচাপ হারাত।
 */
export function handoffLink () {
    return {
        carry () {
            const form = this.$el.closest('form')

            if (! form) {
                return
            }

            const url = new URL(this.$el.href, window.location.origin)
            const pick = {
                amount: ['amount'],
                narration: ['narration', 'reason'],
                party_id: ['person_id'],
            }

            for (const [key, names] of Object.entries(pick)) {
                for (const name of names) {
                    const box = form.querySelector('[name=' + name + ']')

                    if (box && box.value) {
                        url.searchParams.set(key, box.value)
                        break
                    }
                }
            }

            // ⓘ পক্ষের ধরন বসে কেবল যখন সত্যিই একজন বাছা হয়েছে
            if (url.searchParams.has('party_id')) {
                url.searchParams.set('party_type', 'person')
            }

            this.$el.href = url.toString()
        },
    }
}

const taka = (v) => new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2 }).format(v || 0)

/*
 * নগদ গোনা — দশটা নোট, আর লেখা অঙ্কের সাথে মিলছে কি না।
 * ⓘ না মিললে কথাটা স্পষ্ট করে; রঙ একা যথেষ্ট নয়।
 */
export function noteTally ({ matches = '', differs = '' } = {}) {
    return {
        n: { 1000: 0, 500: 0, 200: 0, 100: 0, 50: 0, 20: 0, 10: 0, 5: 0, 2: 0, 1: 0 },
        written: 0,

        get counted () {
            return Object.entries(this.n)
                .reduce((t, [note, count]) => t + (Number(note) * (Number(count) || 0)), 0)
        },

        get verdict () {
            if (this.counted === this.written) {
                return matches
            }

            return differs
                .replace('__C__', '৳ ' + taka(this.counted))
                .replace('__W__', '৳ ' + taka(this.written))
                .replace('__G__', '৳ ' + taka(Math.abs(this.written - this.counted)))
        },

        money (v) {
            return taka(v)
        },

        readWritten () {
            const box = this.$root.closest('form')?.querySelector('[name=amount]')
            this.written = Number(box?.value || 0)
        },

        init () {
            this.readWritten()
        },
    }
}

/*
 * দাম — ক্রয়, বিক্রয়, মার্কআপ, মার্জিন। সূত্রটা সার্ভারের
 * [[App\Modules\Inventory\Support\Margin]]-এর যমজ; যে ঘরটা শেষ লেখা
 * হলো সেটাই ধ্রুব, বাকিগুলো কেবল আঁকা।
 */
export function productPricing ({ cost = '', sale = '' } = {}) {
    const n = (v) => {
        v = (v ?? '').toString().trim()

        return v !== '' && ! isNaN(v) ? parseFloat(v) : null
    }

    return {
        cost,
        sale,
        markup: '',
        margin: '',

        init () {
            this.fromPrices()
        },

        fromPrices () {
            const c = n(this.cost)
            const s = n(this.sale)
            this.markup = (c !== null && c > 0 && s !== null) ? ((s - c) / c * 100).toFixed(2) : ''
            this.margin = (s !== null && s > 0 && c !== null) ? ((s - c) / s * 100).toFixed(2) : ''
        },

        fromMarkup () {
            const c = n(this.cost)
            const m = n(this.markup)

            if (c === null || c <= 0 || m === null || m <= -100) return

            this.sale = (c * (100 + m) / 100).toFixed(4)
            const s = n(this.sale)
            this.margin = (s !== null && s > 0) ? ((s - c) / s * 100).toFixed(2) : ''
        },

        fromMargin () {
            const c = n(this.cost)
            const m = n(this.margin)

            if (c === null || c <= 0 || m === null || m >= 100) return

            this.sale = (c * 100 / (100 - m)).toFixed(4)
            const s = n(this.sale)
            this.markup = (s !== null && s > 0) ? ((s - c) / c * 100).toFixed(2) : ''
        },
    }
}

/* প্রারম্ভিক মজুদ — দর লেখার সময়েই মোট মূল্য, যাতে ৫০ আর ৫০০ গুলিয়ে না যায় */
export function openingValue () {
    return {
        qty: '',
        rate: '',

        get value () {
            const v = (parseFloat(this.qty) || 0) * (parseFloat(this.rate) || 0)

            return v ? v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—'
        },
    }
}

/* সারির তালিকা — যোগ, বাদ, আর কখনো একেবারে খালি নয় */
export function lineRows ({ rows = [], blank = {} } = {}) {
    return {
        rows,

        add () {
            this.rows.push({ ...blank })
        },

        remove (i) {
            this.rows.splice(i, 1)

            if (this.rows.length === 0) {
                this.add()
            }
        },

        init () {
            if (this.rows.length === 0) {
                this.add()
            }
        },
    }
}

/*
 * নাম থেকে কোড — ইংরেজি অক্ষর ও অঙ্ক রেখে গোড়ার তিনটা। সার্ভারের
 * [[CodeFromName]] একই নিয়ম মানে। ⓘ কেউ কোডের ঘরে হাত দিলে আর নড়ে না।
 */
export function codeFromName ({ touched = false } = {}) {
    return {
        touched,

        suggest (name) {
            if (this.touched) return

            const letters = (name || '').replace(/[^A-Za-z0-9]+/g, '')
            this.$refs.code.value = letters.slice(0, 3).toUpperCase()
        },
    }
}

/*
 * রান্নাঘরের মজুদ — বিশ সেকেন্ডে একবার মিলিয়ে দেখা।
 * ⓘ নেট গেলে পুরনো সংখ্যা চুপচাপ রাখা হয় না — উপরে লেখা হয় যে মিলছে না।
 */
export function kitchenBoard ({ at = '', url, every = 20000 }) {
    return {
        at,
        busy: false,
        stale: false,

        init () {
            setInterval(() => this.pull(), every)
        },

        async pull () {
            if (this.busy) {
                return
            }

            this.busy = true

            try {
                const r = await fetch(url, { headers: { Accept: 'application/json' } })

                if (! r.ok) {
                    throw new Error(r.status)
                }

                const d = await r.json()
                this.at = d.at
                this.stale = false

                d.dishes.forEach(dish => {
                    const cell = document.querySelector(`[data-portions='${dish.id}']`)
                    if (cell) { cell.textContent = dish.portions }

                    const row = document.querySelector(`[data-dish='${dish.id}']`)
                    if (row) { row.classList.toggle('is-out', dish.portions === 0) }
                })
            } catch (e) {
                this.stale = true
            } finally {
                this.busy = false
            }
        },
    }
}

/* রান্নাঘরের টিকিট — সংখ্যা বদলালে গোটা পাতা, কারণ reload সৎ */
export function kitchenTickets ({ at = '', url, count = 0, every = 10000 }) {
    return {
        at,
        stale: false,

        init () {
            setInterval(() => this.pull(), every)
        },

        async pull () {
            try {
                const r = await fetch(url, { headers: { Accept: 'application/json' } })

                if (! r.ok) {
                    throw new Error(r.status)
                }

                const d = await r.json()
                this.at = d.at
                this.stale = false

                if (d.tickets.length !== count) {
                    window.location.reload()
                }
            } catch (e) {
                this.stale = true
            }
        },
    }
}

/*
 * নিয়ন্ত্রণ-প্যানেলের সুইচ — কোনটা চালু, আর কয়টা বদল জমেছে।
 * ⓘ বদলগুলো জমে, সেভ হয় একসাথে — "ভ্যাট চালু" আর "ভ্যাটের হার" দলবেঁধে।
 */
export function switchBoard ({ on = {} } = {}) {
    return {
        on,
        changed: {},

        get count () {
            return Object.keys(this.changed).length
        },

        touch (el) {
            const now = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value

            if (now === el.dataset.was) {
                delete this.changed[el.name]
            } else {
                this.changed[el.name] = true
            }
        },
    }
}

/*
 * খরচের ভাউচার — কাকে, কত কর্তন, আর কোন চালানের মালে ভাগ।
 *
 * ⛔ টিক দিয়ে ভাগ না বসালে ট্যাগটা নীরবে হারাত (১৮ সেপ্টেম্বর ২০২৬) —
 * তাই টিক দিলেই অঙ্কটা অনুপাতে বসে, আর ঘরগুলো খোলা থাকে।
 */
export function expenseFields (config) {
    return {
        head: config.head ?? '',
        payeeType: config.payeeType ?? '',
        payeesByType: config.payeesByType ?? {},
        gross: config.gross ?? 0,
        ait: config.ait ?? 0,
        vds: config.vds ?? 0,
        tagged: config.tagged ?? 0,
        bills: config.bills ?? [],
        basis: config.basis ?? 'qty',
        amount: config.amount ?? 0,
        picked: config.picked ?? [],
        ...(config.texts ?? {}),

        get payeeList () {
            return this.payeesByType[this.payeeType] ?? []
        },

        /* হাতে যাবে — বিলের মোট থেকে কর্তন বাদ */
        get net () {
            return Math.max(0, this.gross - this.ait - this.vds)
        },

        /* একটাও চালান বাছা হয়নি মানে পরোক্ষ খরচ — মালিকের নিয়ম */
        get isDirect () {
            return this.tagged > 0
        },

        setAmount (amount) {
            this.amount = amount
            this.spread()
        },

        toggle (i, on) {
            this.picked = on
                ? [...this.picked, i]
                : this.picked.filter(x => x !== i)

            this.tagged = this.picked.length
            this.spread()
        },

        /*
         * ⚠️ হাতে লেখা ভাগ মুছে যায় না — কেবল খালি ঘরগুলো ভরে। শেষ সারিটা
         * বিয়োগ করে বসে, যাতে পয়সার গোলে যোগফল না সরে।
         */
        spread () {
            const total = Number(this.amount) || 0

            if (total <= 0 || this.picked.length === 0) return

            let left = total
            const empty = []

            this.picked.forEach(i => {
                const box = this.$refs['share' + i]
                if (! box) return

                const typed = parseFloat(box.value)

                if (Number.isFinite(typed) && typed > 0) {
                    left -= typed
                } else {
                    empty.push(i)
                }
            })

            if (empty.length === 0 || left <= 0) return

            const weightOf = i => {
                const bill = this.bills[i] || {}
                const w = this.basis === 'value' ? bill.value : bill.qty

                return Number(w) || 0
            }

            let sum = empty.reduce((t, i) => t + weightOf(i), 0)

            // ⓘ অনুপাতের ভিত্তি না থাকলে সমান ভাগ — শূন্যে ভাগের চেয়ে সৎ
            const equal = sum <= 0

            if (equal) sum = empty.length

            let placed = 0

            empty.forEach((i, n) => {
                const w = equal ? 1 : weightOf(i)
                const share = n === empty.length - 1
                    ? left - placed
                    : Math.round((left * w / sum) * 100) / 100

                placed += share
                this.$refs['share' + i].value = share.toFixed(2)
            })
        },
    }
}

/*
 * ব্যাংকের সুবিধার ফর্ম — ধরন, শাখা, আর কিস্তির দুই দিকের অঙ্ক।
 * ২০ সেপ্টেম্বর ২০২৬, মালিকের নির্দেশে।
 *
 * ── ⭐ কিস্তি দুই দিকেই ──────────────────────────────────────────────
 * মালিকের কথা: *"markup marjin sales price er moto"*। অর্থাৎ সীমা, হার
 * ও কিস্তির সংখ্যা দিলে কিস্তির অঙ্ক বসে; আর ব্যাংকের কাগজে অন্য অঙ্ক
 * লেখা থাকলে সেটা টাইপ করলে **হার** নতুন করে বসে।
 *
 * ⛔ কোনো দিকই মানুষের টাইপ করা ঘর চুপচাপ মুছে দেয় না: যে ঘরে হাত পড়ে
 * সেটাই সত্যি, আর অন্যটা তার থেকে হিসাব হয়। ⚠️ ব্যাংকের কাগজই শেষ কথা;
 * আমাদের অঙ্ক কেবল সাহায্য।
 *
 * ── ⓘ সরল সুদ, EMI নয় ───────────────────────────────────────────────
 * মোট শোধ = আসল + আসল × হার × বছর, আর কিস্তি = মোট ÷ সংখ্যা। বাংলাদেশে
 * মেয়াদি ঋণের কাগজে এভাবেই লেখা থাকে (flat)। ⚠️ EMI ধরলে আমাদের সংখ্যা
 * ব্যাংকের কাগজের সাথে মিলত না, আর মানুষ ভাবতেন কোনোটা ভুল।
 *
 * ── ⭐ ব্যাংক বাছলে শাখা ─────────────────────────────────────────────
 * ⓘ শাখার নামগুলো পাতার সাথেই আসে (`branches`), তাই কোনো fetch নেই।
 * ⛔ ঘরটা **ভরে, আটকায় না**: এক ব্যাংকের বহু শাখা, আর সুবিধাটা অন্য
 * শাখার হতেই পারে। ⚠️ আর কেউ আগে থেকে কিছু লিখে থাকলে সেটা রাখা হয় —
 * টাইপ করা জিনিস নীরবে বদলে গেলে ফর্মের উপর বিশ্বাস চলে যায়।
 */
/*
 * মাসিক কিস্তি — ক্ষয়িষ্ণু জেরে (reducing balance)।
 *
 *     EMI = P × r × (1+r)ⁿ ÷ ((1+r)ⁿ − 1),  r = বার্ষিক হার ÷ 12 ÷ 100
 *
 * ⓘ হার শূন্য হলে সোজা ভাগ, নাহলে শূন্য দিয়ে ভাগ পড়ত।
 */
function emi (principal, annualRate, months) {
    const r = annualRate / 12 / 100

    if (r <= 0) return principal / months

    const growth = Math.pow(1 + r, months)

    return principal * r * growth / (growth - 1)
}

export function bankFacilityForm ({ kind = 'cc', branches = {}, branch = '', typedBranch = false, running = false } = {}) {
    const n = (v) => {
        v = (v ?? '').toString().trim()

        return v !== '' && ! isNaN(v) ? parseFloat(v) : null
    }

    return {
        kind,
        branches,
        branch,
        typedBranch,
        running,

        limit: '',
        rate: '',
        count: '',
        instalment: '',

        /* ⓘ ধরনটা বদলালে কোন ঘরগুলো থাকবে — পর্দার `x-show` এগুলোই পড়ে */
        get hasInstalments () {
            return this.kind === 'term' || this.kind === 'ltr' || this.kind === 'lease'
        },

        get hasRenewal () {
            return this.kind === 'cc'
        },

        get hasExpiry () {
            return this.kind === 'bg'
        },

        get hasInterest () {
            return this.kind !== 'bg'
        },

        /* ব্যাংক বাছা হলো — শাখাটা ভরে দাও, যদি কেউ নিজে কিছু না লিখে থাকেন */
        pickedBank (id) {
            const found = this.branches[id]

            if (this.typedBranch) return
            if (found === undefined || found === null || found === '') return

            this.branch = found
        },

        /* মানুষ নিজে শাখা লিখেছেন — এরপর আর ভরা হয় না */
        branchTyped () {
            this.typedBranch = this.branch.toString().trim() !== ''
        },

        /*
         * সীমা + হার + সংখ্যা → কিস্তির অঙ্ক, ক্ষয়িষ্ণু জেরে।
         *
         * ⛔ আগে সরল সুদে গোনা হত, আর সেটা টাকার ভুল ছিল: ২৫,০০,০০০ ·
         * ১৩.৫৫% · ৩৬ কিস্তিতে পর্দা বলত ৯৭,৬৭৩.৬১, ব্যাংক বলে
         * ৮৪,৮৯৮.৯৬ — তিন বছরে ৪.৬ লাখ টাকার ফারাক (মালিক ধরেছেন,
         * ২১ সেপ্টেম্বর ২০২৬)।
         *
         * ⓘ সূত্রটা সার্ভারেও হুবহু এক ([[LoanSchedule::instalment]]) —
         * পর্দা আর খাতা দুই সংখ্যা বললে কোনটা সত্যি তা বলা যেত না।
         */
        fromTerms () {
            const p = n(this.limit)
            const r = n(this.rate)
            const c = n(this.count)

            if (p === null || p <= 0 || c === null || c < 1) return

            this.instalment = emi(p, r === null ? 0 : r, c).toFixed(2)
        },

        /*
         * কিস্তির অঙ্ক টাইপ হলো → হার নতুন করে।
         *
         * ⓘ ক্ষয়িষ্ণু জেরে হারটা সূত্র উল্টে বের করা যায় না — তাই
         * দ্বিভাজন: শূন্য থেকে শুরু করে দুই পাশ থেকে চেপে আসা। ⚠️ ষাট
         * ধাপে পয়সার নিচে নেমে যায়, আর সেটা ব্রাউজারে চোখেই পড়ে না।
         */
        fromInstalment () {
            const p = n(this.limit)
            const c = n(this.count)
            const a = n(this.instalment)

            if (p === null || p <= 0 || c === null || c < 1 || a === null) return

            // ⛔ সুদহীন কিস্তির চেয়ে কম হলে হার ঋণাত্মক হত — ব্যাংক তা দেয় না
            if (a <= p / c) {
                this.rate = '0.00'

                return
            }

            let low = 0
            let high = 100

            for (let i = 0; i < 60; i++) {
                const mid = (low + high) / 2

                if (emi(p, mid, c) > a) {
                    high = mid
                } else {
                    low = mid
                }
            }

            this.rate = ((low + high) / 2).toFixed(2)
        },
    }
}

/*
 * ব্যাংকের সুবিধা — এখনো কত তোলা যাবে: স্টক × (১ − মার্জিন) থেকে ব্যবহৃত বাদ।
 * ⛔ কেবল দেখানো, সংরক্ষণ হয় না — আসল হিসাব খতিয়ানের।
 */
export function drawingPower ({ stock = 0, margin = 30, drawn = 0 } = {}) {
    return {
        stock,
        margin,
        drawn,

        get limit () {
            return this.stock * (1 - this.margin / 100)
        },

        get drawable () {
            return new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2 })
                .format(Math.max(0, this.limit - this.drawn))
        },

        get sum () {
            const f = new Intl.NumberFormat('en-IN')

            return f.format(this.stock) + ' - ' + this.margin + '% = ' + f.format(this.limit)
        },
    }
}

/*
 * পণ্যের প্যাকের টেবিল — "১ কার্টন = ১২ বক্স", আর পাশে "= ২৮৮ পিস"।
 * ধাপ ৪খ, ২০ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ মালিকের বাক্যটাই নকশা: *"২৪ পিসে এক বক্স, ১২ বক্সে এক কার্টন"*। তাই
 * প্রতিটা সারি অন্য একটা এককের হিসাবে লেখা যায়, আর base-এ কত সেটা এখানে
 * গুণ করে দেখানো হয় — টাইপ করার সাথে সাথেই, যাতে ভুলটা জমা দেওয়ার আগেই
 * চোখে পড়ে।
 *
 * ⚠️ এই সংখ্যাটা কেবল দেখানোর — আসল হিসাব আর পাহারা সার্ভারে
 * ([[ProductPackService]])। ⛔ দুই জায়গায় দুই নিয়ম হলে পর্দা এক সংখ্যা
 * দেখাত আর মজুদে বসত আরেকটা।
 */
export function productPacks ({ rows = [], base = 0, baseName = '', names = {}, defaults = {} } = {}) {
    return {
        rows,
        base,
        baseName,
        names,

        /*
         * কোন কাজে কোন প্যাক আগে থেকে বাছা — কেনা, বেচা, POS, কাউন্টার।
         * ⓘ রেডিও চারটা কলামে, তাই অবস্থাটা এখানে; সারি মুছে ফেললে ঐ
         * কাজের বাছাইটা base-এ ফিরে যায় ([[dropped()]])।
         */
        defaults,

        add () {
            this.rows.push({ unit_id: '', per_qty: '', per_unit_id: '', barcode: '' })
        },

        remove (i) {
            const gone = this.rows[i].unit_id

            this.rows.splice(i, 1)
            this.dropped(gone)
        },

        /** সরানো এককটা কোনো কাজের ডিফল্ট থাকলে সেটা base-এ ফেরে */
        dropped (unitId) {
            for (const kind of ['purchase', 'sales', 'pos', 'counter']) {
                if (String(this.defaults[kind]) === String(unitId)) {
                    this.defaults[kind] = this.base
                }
            }
        },

        /** এককের নাম — id থেকে; অচেনা হলে ফাঁকা */
        nameOf (id) {
            return this.names[String(id)] || ''
        },

        /*
         * base-এ কত — শিকল ধরে, সার্ভারের [[ProductPackService::factors()]]-এর
         * একই নিয়মে: যার "কিসের" জানা, সে মেটে; কেউ না মিটলে বাকিরা চক্রে।
         */
        factors () {
            const known = {}
            known[String(this.base)] = 1

            let left = this.rows.filter(row => row.unit_id && row.per_qty)
            let moved = true

            while (left.length > 0 && moved) {
                moved = false
                const still = []

                for (const row of left) {
                    const per = String(row.per_unit_id || this.base)

                    if (known[per] !== undefined) {
                        known[String(row.unit_id)] = known[per] * parseFloat(row.per_qty)
                        moved = true
                    } else {
                        still.push(row)
                    }
                }

                left = still
            }

            return known
        },

        /** এক সারির পাশে যা লেখা থাকে — "= ২৮৮ পিস", বা চক্র হলে ফাঁকা */
        inBase (row) {
            if (!row.unit_id || !row.per_qty) {
                return ''
            }

            const value = this.factors()[String(row.unit_id)]

            if (value === undefined || !isFinite(value)) {
                return ''
            }

            const shown = Number.isInteger(value) ? String(value) : String(Math.round(value * 1000000) / 1000000)

            return '= ' + shown + ' ' + this.baseName
        },

    }
}
