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
 * @param {{ qty: string|number, unitId?: string|number, net: number }} line
 *        `net` = ছাড়ের পরে, দামের ভেতরের ভ্যাট বাদ দিয়ে
 * @param {{ cost: string|null, factors?: Object<string, string> }|undefined} entry
 *        সার্ভারের `marginCosts[productId]` — খরচের চাবি না থাকলে নেই
 * @param {number} floor  সীমা, শতাংশে
 * @returns {null | { unknown: true } | { unknown: false, percent: number|null, below: boolean }}
 */
export function lineMargin(line, entry, floor) {
    // ⛔ চাবি নেই → খরচের তালিকাও নেই → কিছুই দেখানো হয় না
    if (! entry) return null

    if (entry.cost === null || entry.cost === undefined || entry.cost === '') {
        return { unknown: true }
    }

    const qty = Number(line.qty) || 0
    const unit = line.unitId === undefined || line.unitId === null ? '' : String(line.unitId)

    // ⓘ "২ বাক্স" → পিসে; খরচটা ভিত্তি-এককের
    const factor = unit !== '' && entry.factors && entry.factors[unit] !== undefined
        ? Number(entry.factors[unit]) || 1
        : 1

    const cost = qty * factor * (Number(entry.cost) || 0)
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
export function marginLabel(margin, words) {
    if (! margin) return ''

    if (margin.unknown) return words.unknown

    const percent = margin.percent === null ? '—' : margin.percent.toFixed(2) + '%'

    return words.margin + ' ' + percent + (margin.below ? ' · ' + words.below : '')
}
