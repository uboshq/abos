/**
 * কার্টের সারির মার্জিন — কেবল পর্দার জন্য, আনুমানিক (NEXUS §৩২)।
 *
 * ⭐ আসল দেয়াল সার্ভারে ([[MarginGuard]]): টাকার অঙ্ক সেখানে bcmath-এ, আর
 * খরচ FIFO স্তর হেঁটে। ⓘ এখানে কেবল আগে থেকে বলা — বিক্রেতা "নিশ্চিত"
 * চাপার আগেই দেখেন কোন সারিটা সীমার নিচে।
 *
 * ⚠️ নিয়মটা দেয়ালের হুবহু, যাতে পর্দা আর সার্ভার দুই কথা না বলে:
 *     নিচে  ⇔  (বিক্রয় − খরচ) × ১০০  <  সীমা × বিক্রয়
 * ঠিক সীমায় থাকা সারি নিচে নয়; বিক্রয় শূন্য বা কম হলে খরচ থাকলেই নিচে।
 *
 * ⓘ বিশুদ্ধ ফাংশন — কম্পোনেন্ট ছাড়াই পরীক্ষা করা যায় ([[margin.test.js]])।
 */

/**
 * @param {{ qty: string|number, unitId?: string|number, batchId?: string|number, freeFromStock?: number, net: number }} line
 *        `net` = ছাড়ের পরে, দামের ভেতরের ভ্যাট বাদ দিয়ে
 * @param {{ cost: string|null, lots?: Object<string, string|null>, other?: string|null, factors?: Object<string, string> }|undefined} entry
 *        সার্ভারের `marginCosts[productId]` — খরচের চাবি না থাকলে নেই
 * @param {number} floor  সীমা, শতাংশে
 * @returns {null | { unknown: true } | { unknown: false, percent: number|null, below: boolean }}
 */
export function lineMargin(line, entry, floor) {
    // ⛔ চাবি নেই → খরচের তালিকাও নেই → কিছুই দেখানো হয় না
    if (! entry) return null

    /*
     * ⭐ বাছা লটের খরচ — ৪ অক্টোবর ২০২৬ ([[MarginGuard::screenCosts()]])। ⓘ লটের নিজের স্তর থাকলে তার দাম, না
     * থাকলে `other` (বিল তখন যেখান থেকে নেয়); লট ছাড়া সারিতে আগের মতো `cost`। ⛔ আগে সবসময় পণ্যের সবচেয়ে পুরনো
     * স্তরের দাম দেখাত, তাই মার্জিন ফুলে দেখাত।
     */
    const lot = line.batchId === undefined || line.batchId === null ? '' : String(line.batchId)
    const unitCost = lot === ''
        ? entry.cost
        : (entry.lots && entry.lots[lot] !== undefined ? entry.lots[lot] : (entry.other !== undefined ? entry.other : entry.cost))

    if (unitCost === null || unitCost === undefined || unitCost === '') {
        return { unknown: true }
    }

    const qty = Number(line.qty) || 0
    const unit = line.unitId === undefined || line.unitId === null ? '' : String(line.unitId)

    // ⓘ "২ বাক্স" → পিসে; খরচটা ভিত্তি-এককের
    const factor = unit !== '' && entry.factors && entry.factors[unit] !== undefined
        ? Number(entry.factors[unit]) || 1
        : 1

    /*
     * ⭐ ফ্রির খরচ — সিদ্ধান্ত "ক", ৪ অক্টোবর ২০২৬ ([[MarginGuard::freeCost()]])। ⓘ `freeFromStock` = নিজের মাল থেকে যাওয়া
     * ফ্রি (ভিত্তি-এককে); ভাণ্ডারের ফ্রির খরচ শূন্য, তাই সেটা এখানে আসেই না।
     */
    const fromStock = Number(line.freeFromStock) || 0
    const cost = (qty * factor + fromStock) * (Number(unitCost) || 0)
    const net = Number(line.net) || 0
    const limit = Number(floor) || 0

    if (net <= 0) {
        return { unknown: false, percent: null, below: cost > 0 }
    }

    return {
        unknown: false,
        percent: (net - cost) * 100 / net,
        below: (net - cost) * 100 < limit * net,
    }
}

/**
 * সারির নিচে যে লেখাটা বসে।
 *
 * @param {ReturnType<typeof lineMargin>} margin
 * @param {{ margin: string, below: string, unknown: string }} words  ব্লেড থেকে, `@js(__())`
 */
/**
 * নিজের মাল থেকে কতটা ফ্রি যাবে — ভাণ্ডারে যা নেই তা-ই, কেবল সুইচ চালু থাকলে ([[MarginGuard::freeCost()]])।
 *
 * @param {string|number} free
 * @param {string|number} batchId
 * @param {{ lots?: Object<string, string>, any?: string }|undefined} pool
 * @param {boolean} beyond
 */
export function freeFromStock(free, batchId, pool, beyond) {
    const want = Number(free) || 0

    if (! beyond || want <= 0) return 0

    const lot = batchId === undefined || batchId === null ? '' : String(batchId)
    const have = Math.max(0, Number(lot === '' ? (pool && pool.any) : (pool && pool.lots && pool.lots[lot])) || 0)

    return Math.max(0, want - have)
}

export function marginLabel(margin, words) {
    if (! margin) return ''

    if (margin.unknown) return words.unknown

    const percent = margin.percent === null ? '—' : margin.percent.toFixed(2) + '%'

    return words.margin + ' ' + percent + (margin.below ? ' · ' + words.below : '')
}
