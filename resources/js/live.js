/*
 * ⭐ রিয়েল-টাইম সিঙ্ক, ওয়েবের দিক — মালিক, ১০ অক্টোবর ২০২৬: "Real Time sync app r web dutotei koro", পথ (ক)।
 *
 * পাতা যে মুহূর্তের তথ্য নিয়ে এসেছে (`<meta name="abos-live" data-stamp>`), প্রতি কুড়ি সেকেন্ডে সার্ভারকে
 * জিজ্ঞেস করা হয় তার পরে কোম্পানিতে কিছু লেখা হয়েছে কি না ([[LiveStamp]])। হলে উপরে একটা ছোট খবর আর
 * "হালনাগাদ করুন" বোতাম।
 *
 * ⓘ পাতা নিজে নিজে নতুন হয় না — কেউ ফর্মে লিখছেন বা তালিকায় কিছু পড়ছেন, তখন পাতা বদলে গেলে কাজ হারায়।
 * ⓘ লুকানো ট্যাব জিজ্ঞেস করে না; ফিরে এলেই একবার জিজ্ঞেস করে। ⛔ জিজ্ঞাসায় কুকি যায় না — সেশন জাগে না।
 */

export const EVERY_MS = 20000

/** নতুন তথ্য এসেছে কি — সার্ভারের চিহ্ন পাতার চিহ্নের পরে হলে। */
export function isFresh (pageStamp, serverStamp) {
    const page = Number(pageStamp) || 0
    const server = Number(serverStamp) || 0
    return server > page
}

function showBanner (meta) {
    if (document.querySelector('[data-live-banner]')) return

    const bar = document.createElement('div')
    bar.setAttribute('data-live-banner', '')
    bar.setAttribute('role', 'status')
    bar.className = 'fixed inset-x-0 top-0 z-50 flex items-center justify-center gap-3 bg-(--color-brand-600) px-4 py-2 text-sm text-white shadow'

    const text = document.createElement('span')
    text.textContent = meta.dataset.message || ''

    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'rounded-(--radius-field) bg-white px-3 py-1 text-sm font-semibold text-(--color-brand-700)'
    button.textContent = meta.dataset.action || '↻'
    button.addEventListener('click', () => window.location.reload())

    bar.append(text, button)
    document.body.append(bar)
}

export function listenForFreshData (doc = document, fetcher = window.fetch.bind(window)) {
    const meta = doc.querySelector('meta[name="abos-live"]')
    if (!meta || !meta.content) return null

    const ask = async () => {
        if (doc.visibilityState === 'hidden' || doc.querySelector('[data-live-banner]')) return
        try {
            const response = await fetcher(meta.content, { credentials: 'omit', cache: 'no-store' })
            if (!response.ok) return
            const body = await response.json()
            if (isFresh(meta.dataset.stamp, body.s)) showBanner(meta)
        } catch {
            // ⓘ সংযোগ নেই — পরের বার
        }
    }

    doc.addEventListener('visibilitychange', () => {
        if (doc.visibilityState === 'visible') ask()
    })

    return window.setInterval(ask, EVERY_MS)
}
