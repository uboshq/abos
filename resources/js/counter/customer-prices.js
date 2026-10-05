/*
 * গ্রাহক বদলালে দর বদলায় — দর তালিকা (মালিক, ৫ অক্টোবর ২০২৬: আন্তর্জাতিক মান, SAP-এর ধাঁচ)।
 *
 * ⓘ সার্ভার এই গ্রাহকের দর বলে ([[SalesPrice]], `sales.price_list.quote`); এখানে কেবল বসানো:
 *   ⓵ তালিকার প্রতিটা পণ্যের দর আর তার উৎস ("গ্রাহকের দাম");
 *   ⓶ কার্টের যে সারির দর এখনো আগের আপনা-আপনি দরটাই, সেটা নতুন দরে;
 *   ⓷ ঘরে বাছা পণ্যের দরও, একই শর্তে।
 * ⛔ হাতে বদলানো দর ছোঁয়া হয় না — বিক্রেতা যা লিখেছেন তা-ই থাকে (সার্ভারের দামের নীতি তবু মাপে)।
 */

const same = (a, b) => Number(a) === Number(b)

/**
 * @param {Array<object>} catalogue  পণ্যের তালিকা — `{id, rate, priceSource, priceLabel}`
 * @param {Array<object>} lines      কার্টের সারি — `{id, rate}`
 * @param {object|null} entry        ঘরে বাছা পণ্যের দর `{rate}`, সাথে `picked`
 * @param {object|null} picked
 * @param {Object<string, {rate: string, source: string, label: string}>} prices
 * @returns {number} কয়টা কার্টের সারির দর বদলাল
 */
export function applyPrices(catalogue, lines, entry, picked, prices) {
    const before = {}
    let moved = 0

    for (const item of catalogue || []) {
        const next = prices?.[String(item.id)]

        if (! next) continue

        before[String(item.id)] = item.rate
        item.rate = String(next.rate)
        item.priceSource = next.source
        item.priceLabel = next.label
    }

    for (const line of lines || []) {
        const old = before[String(line.id)]
        const next = prices?.[String(line.id)]

        if (old === undefined || ! next || ! same(line.rate, old) || same(line.rate, next.rate)) continue

        line.rate = String(next.rate)
        moved++
    }

    if (entry && picked) {
        const old = before[String(picked.id)]
        const next = prices?.[String(picked.id)]

        if (old !== undefined && next && same(entry.rate, old)) entry.rate = String(next.rate)
    }

    return moved
}

/**
 * সার্ভারকে জিজ্ঞেস — এই গ্রাহকের দর। ⓘ ভুল হলে `null`, আর পর্দা আগের দরেই চলে (সার্ভার তবু মাপে)।
 */
export async function fetchPrices(url, customerId) {
    if (! url || customerId === '' || customerId === null || customerId === undefined) return null

    try {
        const answer = await fetch(url + (url.includes('?') ? '&' : '?') + 'customer=' + encodeURIComponent(customerId), {
            headers: { Accept: 'application/json' },
        })

        if (! answer.ok) return null

        return (await answer.json())?.prices ?? null
    } catch {
        return null
    }
}
