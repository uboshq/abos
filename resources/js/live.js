/*
 * ⭐ রিয়েল-টাইম সিঙ্ক, ওয়েবের দিক — মালিক, ১০ অক্টোবর ২০২৬: "Real Time sync app r web dutotei koro", পথ (ক)।
 *
 * ⭐ ১১ অক্টোবর ২০২৬, মালিক: *"refresh korbe keno ze infota update hoyeche seta sei jaygay auto bosbe keu ter pabena"* আর
 * *"10 second e koro"* — "হালনাগাদ করুন" ফিতা উঠে গেল। প্রতি দশ সেকেন্ডে সার্ভারকে জিজ্ঞেস করা হয় পাতার মুহূর্তের পরে
 * কোম্পানিতে কিছু লেখা হয়েছে কি না ([[LiveStamp]]); হলে একই ঠিকানার নতুন পাতা পেছনে আনা হয়, আর কেবল যা বদলেছে সেটুকু
 * জায়গায় বসে (Alpine Morph) — স্ক্রল, খোলা পপআপ, ছাঁকনি, ট্যাব যেমন ছিল তেমন।
 *
 * ⛔ যে ঘরে মানুষ লিখছেন (ফোকাসে আছে, বা পাতা খোলার পর হাতে বদলেছেন) সেটা কখনো বদলায় না — লেখা হারায় না।
 * ⓘ লুকানো ট্যাব জিজ্ঞেস করে না; ফিরে এলেই একবার জিজ্ঞেস করে। ⛔ চিহ্নের জিজ্ঞাসায় কুকি যায় না — সেশন জাগে না;
 * কেবল নতুন পাতা আনার সময় যায় (পাতাটা তো এই মানুষেরই)।
 */

export const EVERY_MS = 10000

/** কোন অংশগুলো জায়গায় বসে — পাতার মূল অংশ, নিচের বার, আর টপবারের সংখ্যাগুলো ([data-live]) */
export const REGIONS = ['main#main', 'footer[data-footer]', '[data-live]']

/** নতুন তথ্য এসেছে কি — সার্ভারের চিহ্ন পাতার চিহ্নের পরে হলে। */
export function isFresh (pageStamp, serverStamp) {
    const page = Number(pageStamp) || 0
    const server = Number(serverStamp) || 0
    return server > page
}

/** মানুষ এই ঘরে হাত দিয়েছেন কি — ফোকাসে আছে, বা পাতা খোলার পর বদলেছেন */
export function isTouched (el, doc = document) {
    if (!el || el.nodeType !== 1) return false
    if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) && !el.isContentEditable) return false
    return el === doc.activeElement || el.dataset?.liveTouched === '1'
}

/**
 * খোলা পপ-আপ কি — নিশ্চিতের সারাংশ ([[confirm-overview.js]]) বা অন্য যেকোনো খোলা `<dialog>`।
 * ⛔ নতুন পাতায় পপ-আপটা বন্ধ আর খালি; বসালে মানুষের চোখের সামনে বন্ধ হত, "নিশ্চিত"-এর আগেই (a6, ২৩ অক্টোবর ২০২৬:
 * আদায়ের "সইয়ে পাঠান"-এর পপ-আপ দেড় সেকেন্ডে উধাও — কোম্পানিতে কেউ কিছু লিখলেই)।
 */
export function isOpenDialog (el) {
    return !!el && el.nodeType === 1 && el.tagName === 'DIALOG' && el.open === true
}

/**
 * হাতে খোলা বা বন্ধ করা ভাঁজ (`<details>`) যেমন আছে তেমন — নতুন পাতার `open` এখনকারটা দিয়ে বদলে নেওয়া, তাই ভিতরের লেখা
 * হালনাগাদ হয়, ভাঁজ নয়। ⛔ নইলে খোলা ভাঁজ দশ সেকেন্ডে বন্ধ হত (a6, ২৪ অক্টোবর ২০২৬, আসল ব্রাউজারে মাপা — খরচের ভাউচার)।
 */
export function keepFoldAsIs (from, to) {
    if (!from || from.nodeType !== 1 || from.tagName !== 'DETAILS' || !to || to.tagName !== 'DETAILS') return
    if (from.open) to.setAttribute('open', '')
    else to.removeAttribute('open')
}

/** হাতে লেখা ঘর চিহ্নিত — একবার লিখলেই, যতক্ষণ পাতা খোলা */
export function rememberTyping (doc = document) {
    const mark = (event) => {
        if (event.target && event.target.dataset) event.target.dataset.liveTouched = '1'
    }
    doc.addEventListener('input', mark, true)
    doc.addEventListener('change', mark, true)
}

/**
 * নতুন পাতার অংশগুলো এখনকার পাতায় বসানো।
 *
 * @param {Document} doc  এখনকার পাতা
 * @param {Document} fresh  পেছনে আনা নতুন পাতা
 * @param {(from: Element, to: Element, options: object) => void} morph  Alpine.morph
 * @returns {number} কয়টা অংশ বসল
 */
export function placeFresh (doc, fresh, morph) {
    let placed = 0

    for (const selector of REGIONS) {
        const now = Array.from(doc.querySelectorAll(selector))
        const next = Array.from(fresh.querySelectorAll(selector))

        now.forEach((el, i) => {
            // ⓘ [data-live] ধরে মেলানো হয় নাম দিয়ে, ক্রম দিয়ে নয় — একটা আইকন না থাকলে বাকিগুলো গুলিয়ে যেত না
            const to = selector === '[data-live]'
                ? next.find((n) => n.getAttribute('data-live') === el.getAttribute('data-live'))
                : next[i]

            if (!to) return

            morph(el, to, {
                updating (from, toEl, childrenOnly, skip) {
                    if (isTouched(from, doc) || isOpenDialog(from)) skip()
                    keepFoldAsIs(from, toEl)
                },
            })
            placed++
        })
    }

    return placed
}

export function listenForFreshData (doc = document, fetcher = window.fetch.bind(window), morph = window.Alpine?.morph) {
    const meta = doc.querySelector('meta[name="abos-live"]')
    if (!meta || !meta.content) return null

    rememberTyping(doc)
    let busy = false

    const refresh = async (stamp) => {
        const response = await fetcher(window.location.href, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-ABOS-Live': '1' },
        })

        // ⓘ লগইনের পাতায় ফিরল, বা ঠিকানা বদলাল — কিছুই বসে না, পরের বার আবার
        if (!response.ok || response.redirected) return

        const fresh = new DOMParser().parseFromString(await response.text(), 'text/html')
        if (!fresh.querySelector('main#main')) return

        if (typeof morph === 'function') placeFresh(doc, fresh, morph)

        meta.dataset.stamp = fresh.querySelector('meta[name="abos-live"]')?.dataset.stamp || String(stamp)
    }

    const ask = async () => {
        if (busy || doc.visibilityState === 'hidden') return
        busy = true
        try {
            const response = await fetcher(meta.content, { credentials: 'omit', cache: 'no-store' })
            if (!response.ok) return
            const body = await response.json()
            if (isFresh(meta.dataset.stamp, body.s)) await refresh(body.s)
        } catch {
            // ⓘ সংযোগ নেই — পরের বার
        } finally {
            busy = false
        }
    }

    doc.addEventListener('visibilitychange', () => {
        if (doc.visibilityState === 'visible') ask()
    })

    return window.setInterval(ask, EVERY_MS)
}
