/*
 * ⭐ ড্যাশবোর্ডের বক্সের ক্রম — মালিক, ২০ নভেম্বর ২০২৬ (নমুনা 5gynumii "যা দিস ঠিক আছে … আগে যেগুলো ছিল এগুলার নিচে দিয়ে বসায় দেবো
 *   এইভাবে সুন্দর করে, কোনোটাই যাতে না হারায়")। খোলার প্রথম পর্দায় কেবল গ্রাফ, দুই সারিতে, এক মাপের বক্সে; স্ক্রল করলে বাকি সব,
 *   আর "সমস্যা কেন্দ্র" সবার নিচে।
 *
 * ⓘ বক্সগুলো পাতা আগের মতোই আঁকে (নিজের চিহ্নসহ: `data-fin-loans`, `data-inv-trend` …); এখানে কেবল জায়গা বদলায়, ভেতরে কিছু নয়।
 * ⛔ কোনো বক্স হারায় না: তালিকায় যার নাম নেই (পরে নতুন যোগ হলে), সে সবার শেষের আগে নিজের সারিতে বসে।
 * ⛔ কেবল "ABOS"-এ — বাকি রূপে ড্যাশবোর্ড আগের মতো।
 */
export const BOARD_ORDERS = {
    /*
     * ⛔ লাইভ 1.3.7-এ (২১ নভেম্বর ২০২৬) অর্থের প্রথম পর্দায় ওপরে চারটা, নিচে তিনটা, তিন রকম চওড়া — মালিকের নিয়ম "দুই সারি, এক মাপের
     *   কার্ড" ভাঙছিল। ⭐ এখন গ্রাফের বোর্ডের (viz-card) মাপে: সারিতে তিনটা সমান চওড়া, সমান উঁচু (`equal`); ঋণের বক্স তৃতীয় সারির মাথায়,
     *   মালিকানার বক্স পূর্বাভাসের পাশে — একটাও হারায়নি।
     */
    'data-fin-board-position': {
        first: 2,
        equal: true,
        rows: [
            ['fin-receivable', 'fin-payable', 'fin-ratios'],
            ['fin-cycle', 'fin-fund-trend', 'fin-calendar'],
            { cols: '1.35fr 1fr 1fr', keys: ['fin-loans', 'fin-margins', 'fin-banks'] },
            { cols: '3fr 1fr', keys: ['fund', 'fund-news'] },
            ['fin-forecast', 'fin-cash-flow', 'fin-owners'],
            ['fin-wc', 'fin-budget', 'fin-cheques'],
            ['fin-fdr', 'fin-health', 'fin-flows'],
        ],
    },
    // ⭐ মজুদ আর বিক্রয়ও এক মাপে (মালিক, ২১ নভেম্বর ২০২৬) — লাইভ/ডেমোতে প্রথম সারি অর্ধেক পর্দার বেশি উঁচু, দ্বিতীয় সারি কাটা পড়ত
    'data-inv-board-trend': {
        first: 2,
        equal: true,
        rows: [
            ['inv-trend', 'inv-wh', 'inv-cover'],
            ['inv-roll', 'inv-brand', 'inv-ageing'],
            ['inv-value-corner'],
            ['inv-daily-flow', 'inv-health', 'inv-abc'],
            ['inv-space', 'inv-count', 'inv-otif'],
            ['inv-reorder', 'inv-slow', 'inv-expiry'],
            ['inv-grn', 'inv-transit', 'inv-damage'],
            ['inv-problems'],
        ],
    },
    'data-sales-board-profit': {
        first: 2,
        equal: true,
        rows: [
            ['sales-ladder', 'sales-target', 'sales-path'],
            ['sales-branches', 'sales-brands', 'sales-pareto'],
            ['sales-glance'],
            ['sales-pvm', 'sales-o2c', 'sales-channels'],
            ['sales-retention', 'sales-cei', 'sales-returns'],
            ['sales-sr-targets', 'sales-top-two', 'sales-dealers'],
            ['sales-heat', 'sales-areas'],
            ['sales-products', 'sales-sr-commission'],
            ['sales-problems'],
        ],
    },
}

export function arrangeBoards (root = document, orders = BOARD_ORDERS) {
    if (root.documentElement?.dataset.ui !== 'rose') {
        return null
    }

    const main = root.querySelector('main')
    const found = main && Object.entries(orders).find(([mark]) => main.querySelector(`[${mark}]`))

    if (! found || main.hasAttribute('data-board-arranged')) {
        return null
    }

    const [, spec] = found
    const boxes = [...main.querySelectorAll('section')].filter(s => ! s.parentElement.closest('section'))
    // ⓘ বক্স চেনা যায় তালিকার নাম দিয়েই — `data-<নাম>` চিহ্নটা যার আছে (মডিউলের উপসর্গ ধরে নয়, তাই নতুন মডিউলেও খাটে)
    const named = new Set(spec.rows.flatMap(r => (Array.isArray(r) ? r : r.keys)))
    const keyOf = s => [...s.attributes].map(a => a.name.slice(5)).find(n => named.has(n))
    const byKey = new Map(boxes.map(s => [keyOf(s), s]).filter(([k]) => k))

    // ⓘ সব বক্সের সাধারণ ধারক — প্রথম আর শেষ বক্স দুইটাই যার ভিতরে
    let host = boxes[0]?.parentElement
    while (host && ! host.contains(boxes[boxes.length - 1])) host = host.parentElement
    if (! host) return null

    const mark = root.createElement('div')
    host.prepend(mark)
    const used = new Set()
    const makeRow = (keys, cols, i, first) => {
        const row = root.createElement('div')
        row.setAttribute('data-board-row', String(i))
        if (first) row.setAttribute('data-board-first', '')
        // ⓘ প্রথম পর্দার সারিতে সব কার্ড এক মাপে (viz-card-এর মতো) — কেবল যে বোর্ড চায় ([[abos-look.css]] `[data-board-equal]`)
        if (first && spec.equal) row.setAttribute('data-board-equal', '')
        row.style.gridTemplateColumns = cols || `repeat(${keys.length}, minmax(0, 1fr))`
        for (const k of keys) {
            const box = byKey.get(k)
            if (box && ! used.has(box)) {
                row.append(box)
                used.add(box)
            }
        }
        return row.children.length ? row : null
    }

    const rows = spec.rows.map((r, i) => {
        const keys = Array.isArray(r) ? r : r.keys
        return makeRow(keys, Array.isArray(r) ? null : r.cols, i + 1, i < spec.first)
    }).filter(Boolean)

    // ⛔ কেউ হারায় না — তালিকার বাইরের বক্স, শেষ সারির ("সমস্যা কেন্দ্র") ঠিক আগে
    const rest = boxes.filter(b => ! used.has(b))
    if (rest.length) {
        const extra = root.createElement('div')
        extra.setAttribute('data-board-row', 'rest')
        extra.style.gridTemplateColumns = `repeat(${Math.min(rest.length, 3)}, minmax(0, 1fr))`
        rest.forEach(b => extra.append(b))
        rows.splice(Math.max(rows.length - 1, 0), 0, extra)
    }

    rows.forEach(r => host.insertBefore(r, mark))
    mark.remove()

    // ⓘ খালি হয়ে যাওয়া পুরনো সারিগুলো জায়গা নেয় না
    for (const old of [...host.children]) {
        if (! old.hasAttribute('data-board-row') && ! old.querySelector('section') && ! (old.textContent || '').trim()) {
            old.hidden = true
        }
    }

    main.setAttribute('data-board-arranged', '')

    return { rows: rows.length, boxes: boxes.length, placed: used.size + rest.length }
}
