/*
 * ক্রয়ের কাগজে বিক্রয়মূল্য — দাম, markup, margin আর ক্রয়দর।
 *
 * ── কেন এটা আলাদা ফাইলে, Blade-এর ভেতরে নয় ─────────────────────────
 * এই অঙ্কটা একটা গোটা ডিপোর প্রতিটা পণ্যের বিক্রয়মূল্য ঠিক করে। ভুল
 * হলে ত্রুটিবার্তা আসে না — শুধু সারা বছর একটু একটু করে কম দামে বিক্রি
 * হয়, আর বছরশেষে কেউ ধরতে পারে না কেন কম পড়ল।
 *
 * Blade-এর ভেতরে লেখা থাকলে এটার কোনো পরীক্ষা লেখা যেত না। এখানে
 * বিশুদ্ধ ফাংশন, তাই `npm test` ধরতে পারে।
 *
 * ── markup আর margin এক জিনিস নয় ────────────────────────────────────
 * markup মাপা হয় খরচের ওপর, margin দামের ওপর:
 *
 *     ১০০-তে কিনে ১৫০-তে বেচা  →  ৫০% markup,  ৩৩.৩৩% margin
 *
 * যে ডিপো "৪০%" বলতে margin বোঝে আর পায় markup, সে প্রতিটা লাইনেই কম
 * দামে বেচে। তাই দুইটা ঘরই থাকে, দুইটাই জ্যান্ত।
 */

/** দুই দশমিক — টাকা আর শতাংশ, দুইটাই এই নির্ভুলতায় মানুষ পড়ে। */
const round = (n) => Math.round(n * 100) / 100

const num = (v) => {
    const n = parseFloat(v)

    return Number.isFinite(n) ? n : null
}

/**
 * এক সারির দাম নতুন করে বসানো।
 *
 * @param {{rate: *, sales_price: *, markup: *, margin: *, anchor: string}} row
 * @param {'rate'|'sales_price'|'markup'|'margin'} edited  যে ঘরে এইমাত্র লেখা হল
 * @returns {object} কেবল যেসব ঘর বদলাবে — edited ঘরটা কখনো এতে থাকে না
 *
 * ── কোনটা স্থির থাকে ────────────────────────────────────────────────
 * শেষে কোন ঘরে লেখা হয়েছিল, সেটাই নোঙর (anchor):
 *
 *   markup / margin লেখা  → একটা **নীতি** বলা হয়েছে ("আমি এটা চল্লিশ
 *                            শতাংশে বেচি")। দর বদলালে দাম নতুন করে বসে।
 *   দাম লেখা              → একটা **দাম** বলা হয়েছে, সচরাচর প্যাকেটে ছাপা
 *                            বা ডিলারের সাথে ঠিক করা। দর বদলালেও দামটা
 *                            টেকে, বদলায় markup ও margin।
 *
 * ── আর কিছু না ছোঁয়া পর্যন্ত কিছুই বসে না ───────────────────────────
 * নোঙর খালি থাকলে ফাংশনটা কিছুই ফেরত দেয় না। নইলে কেউ শুধু ক্রয়দরটা
 * লিখলেই একটা বিক্রয়মূল্য ভেসে উঠত যেটা কেউ বেছে নেয়নি — আর সেটাই
 * সেভ হয়ে যেত।
 */
export function reprice(row, edited) {
    const anchor = edited === 'rate' ? row.anchor : edited
    const patch = edited === 'rate' ? {} : { anchor }

    if (! anchor) {
        return patch
    }

    const cost = num(row.rate)

    if (anchor === 'markup' || anchor === 'margin') {
        const pct = num(anchor === 'markup' ? row.markup : row.margin)

        // খরচ শূন্য হলে markup অসীম, আর ১০০% margin মানে খরচ শূন্য —
        // দুইটাই এমন সমীকরণ যার কোনো সমাধান নেই
        if (cost === null || cost <= 0 || pct === null) {
            return patch
        }

        if (anchor === 'margin' && pct >= 100) {
            return patch
        }

        const price = anchor === 'markup'
            ? cost * (1 + pct / 100)
            : cost / (1 - pct / 100)

        if (price <= 0) {
            return patch
        }

        patch.sales_price = round(price).toFixed(2)

        if (edited !== 'markup') patch.markup = round(((price - cost) / cost) * 100).toFixed(2)
        if (edited !== 'margin') patch.margin = round(((price - cost) / price) * 100).toFixed(2)

        return patch
    }

    // নোঙর দাম — মানুষের বলা দামটাই টেকে, বাকি দুইটা তার চারপাশে বসে
    const price = num(row.sales_price)

    if (price === null || price <= 0 || cost === null || cost <= 0) {
        return patch
    }

    if (edited !== 'markup') patch.markup = round(((price - cost) / cost) * 100).toFixed(2)
    if (edited !== 'margin') patch.margin = round(((price - cost) / price) * 100).toFixed(2)

    return patch
}
