/*
 * ⭐ "নিশ্চিত?" — ব্রাউজারের ছোট বাক্সের বদলে পর্দার মাঝখানে বড়, রঙিন প্রশ্ন (মালিক, ৮ নভেম্বর ২০২৬: অনুমোদনে "পপ আপটা একদম
 * উপরে চলে যায় এটা বড় করে স্কিনের মাঝামাঝি দিলে সবচেয়ে ভালো হয়" · "কালারফুল সুন্দর ডিজাইন করে দিবে")।
 *
 * ⓘ সব `data-confirm` এখান দিয়েই ([[actions.js]]) — অনুমোদন, মোছা, বাতিল, বন্ধ করা। রঙ কাজ ধরে: `data-confirm-tone`
 * ("ok" সবুজ · "danger" লাল), না দিলে লেখা থেকে আন্দাজ — "অনুমোদন" সবুজ, "মুছ/বাতিল/প্রত্যাখ্যান/বন্ধ" লাল, বাকি নীল।
 * ⓘ টাকার অঙ্ক ("34,000.00", "৳1,20,000") আলাদা বড় রঙিন অক্ষরে — কত টাকায় হ্যাঁ বলছেন, সেটাই চোখে পড়ুক।
 * ⛔ style অ্যাট্রিবিউট নয় (CSP) — সব চেহারা app.css-এর `.abos-ask*` ক্লাসে।
 */

const MONEY = /(৳\s?)?\d{1,3}(?:,\d{2,3})+(?:\.\d+)?|৳\s?\d+(?:\.\d+)?|\d+\.\d{2}/g

export function toneOf (message, given = '') {
    if (given === 'ok' || given === 'danger' || given === 'info') return given
    const m = String(message)
    if (/মুছ|বাতিল|প্রত্যাখ্যান|বন্ধ|delete|cancel|reject|remove|close/i.test(m)) return 'danger'
    if (/অনুমোদন|approve|নিশ্চিত করুন|post/i.test(m)) return 'ok'
    return 'info'
}

/** লেখাটা টুকরো করে — সাধারণ লেখা আর টাকার অঙ্ক আলাদা (কোনো HTML নয়, তাই কিছু ঢুকে পড়ে না) */
export function piecesOf (message) {
    const text = String(message ?? '')
    const out = []
    let at = 0
    for (const m of text.matchAll(MONEY)) {
        if (m.index > at) out.push({ money: false, text: text.slice(at, m.index) })
        out.push({ money: true, text: m[0] })
        at = m.index + m[0].length
    }
    if (at < text.length) out.push({ money: false, text: text.slice(at) })
    return out
}

function words (doc) {
    const bn = (doc.documentElement.lang || 'bn').startsWith('bn')
    return bn
        ? { title: 'নিশ্চিত করুন', yes: 'হ্যাঁ, নিশ্চিত', no: 'না, ফিরে যাই' }
        : { title: 'Please confirm', yes: 'Yes, go ahead', no: 'No, go back' }
}

const ICON = { ok: '✓', danger: '!', info: '?' }

/**
 * প্রশ্ন দেখায়, উত্তর দেয় — true হ্যাঁ, false না (Esc, বাইরে চাপ, "না")।
 * @returns {Promise<boolean>}
 */
export function ask (message, { tone = '', yes = '', doc = document } = {}) {
    const w = words(doc)
    const kind = toneOf(message, tone)

    return new Promise((resolve) => {
        const dialog = doc.createElement('dialog')
        dialog.className = 'abos-ask abos-ask--' + kind
        dialog.setAttribute('aria-labelledby', 'abos-ask-title')
        dialog.dataset.askDialog = ''

        const band = doc.createElement('div')
        band.className = 'abos-ask__band'
        const icon = doc.createElement('span')
        icon.className = 'abos-ask__icon'
        icon.setAttribute('aria-hidden', 'true')
        icon.textContent = ICON[kind]
        const title = doc.createElement('h2')
        title.id = 'abos-ask-title'
        title.className = 'abos-ask__title'
        title.textContent = w.title
        band.append(icon, title)

        const body = doc.createElement('p')
        body.className = 'abos-ask__text'
        body.dataset.askText = ''
        for (const piece of piecesOf(message)) {
            if (piece.money) {
                const b = doc.createElement('b')
                b.className = 'abos-ask__money'
                b.textContent = piece.text
                body.append(b)
            } else {
                body.append(doc.createTextNode(piece.text))
            }
        }

        const row = doc.createElement('div')
        row.className = 'abos-ask__row'
        const no = doc.createElement('button')
        no.type = 'button'
        no.className = 'abos-ask__no'
        no.textContent = w.no
        no.dataset.askNo = ''
        const go = doc.createElement('button')
        go.type = 'button'
        go.className = 'abos-ask__yes'
        go.textContent = yes || w.yes
        go.dataset.askYes = ''
        row.append(no, go)

        dialog.append(band, body, row)
        doc.body.append(dialog)

        let done = false
        const finish = (answer) => {
            if (done) return
            done = true
            if (typeof dialog.close === 'function' && dialog.open) dialog.close()
            dialog.remove()
            resolve(answer)
        }

        no.addEventListener('click', () => finish(false))
        go.addEventListener('click', () => finish(true))
        dialog.addEventListener('cancel', (e) => { e.preventDefault(); finish(false) })
        dialog.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); finish(false) } })
        // ⓘ বাইরের অন্ধকারে চাপ — বাক্সের বাইরে মানে "না"
        dialog.addEventListener('click', (e) => { if (e.target === dialog) finish(false) })

        if (typeof dialog.showModal === 'function') dialog.showModal()
        else dialog.setAttribute('open', '')
        go.focus()
    })
}
