/*
 * তালিকার খোঁজ — টাইপ করতেই, Enter ছাড়া (মালিক, ১ অক্টোবর ২০২৬)।
 *
 * ── কেন ─────────────────────────────────────────────────────────────
 * মালিকের কথা: প্রতিটা তালিকার খোঁজার ঘরে Enter চাপতে হয়, অথচ তিনি চান
 * লিখতে লিখতেই তালিকা ছেঁকে আসুক। ⓘ তাই একবারই, টুলবারের খোঁজার ঘরে
 * (`ui/toolbar.blade.php`, `data-live-search`) — প্রতিটা তালিকা একসাথে পায়।
 *
 * ── নিয়ম ─────────────────────────────────────────────────────────────
 *   শেষ চাপের ৪০০ মিলিসেকেন্ড পরে একই GET ফর্ম জমা — Enter যা করত ঠিক তাই
 *   (ফর্মে `page` নেই, তাই পাতা ১-এ ফেরে)।
 *   ঘর খালি করলে ছাঁকনিও উঠে যায় (`q=` জমা পড়ে)।
 *   ⛔ বাংলা লেখার সময় (IME-এর `composition`) কিছুই না — অর্ধেক বানানো
 *   অক্ষর দিয়ে খুঁজলে প্রতিটা যুক্তাক্ষরে পাতা ফিরত। লেখা শেষ হলে
 *   (`compositionend`) তবেই সময় গোনা শুরু।
 *   ⓘ লেখা যা খোঁজা আছে তাই হলে জমা নয় — পাতা বিনা কারণে ফিরত না।
 *   ⓘ Enter আগের মতোই চলে, আর চাপলে অপেক্ষার ঘড়ি বাতিল — একই খোঁজ দুইবার
 *   যায় না। ⛔ জমা পড়ার পর পাতা ফেরার আগে আবার Enter চাপলেও দ্বিতীয়বার নয়।
 *
 * ── ফেরার পরে ─────────────────────────────────────────────────────────
 * পাতা নতুন করে আসে, তাই কার্সর হারাত। ⓘ টুলবার খোঁজের পরে ঘরটায়
 * `autofocus` বসায়; এখানে কেবল কার্সরটা লেখার **শেষে** নেওয়া — নাহলে
 * কিছু ব্রাউজারে শুরুতে বসত আর পরের অক্ষর সামনে ঢুকত।
 */

/** শেষ চাপের পরে কতক্ষণ অপেক্ষা — মিলিসেকেন্ড */
export const SEARCH_DELAY = 400

const SELECTOR = 'input[data-live-search]'

/** @type {WeakMap<HTMLInputElement, {timer: ReturnType<typeof setTimeout>|null, composing: boolean, sent: string|null}>} */
const state = new WeakMap()

function boxState(box) {
    if (! state.has(box)) state.set(box, { timer: null, composing: false, sent: null })

    return state.get(box)
}

function cancel(box) {
    const s = boxState(box)

    if (s.timer !== null) {
        clearTimeout(s.timer)
        s.timer = null
    }
}

/** যা এখন খোঁজা আছে — পাতাটা যে `q` নিয়ে এসেছে */
function current(box) {
    return (box.dataset.liveSearch ?? '').trim()
}

function send(box) {
    const s = boxState(box)
    s.timer = null

    // ⓘ `closest` — happy-dom `input.form` দেয় না
    const form = box.form || box.closest('form')
    const value = box.value.trim()

    if (! form || value === current(box) || value === s.sent) return

    s.sent = value
    form.requestSubmit()
}

function schedule(box) {
    cancel(box)
    boxState(box).timer = setTimeout(() => send(box), SEARCH_DELAY)
}

function liveBox(target) {
    return target instanceof HTMLInputElement && target.matches(SELECTOR) ? target : null
}

let listening = false

export function searchAsYouType(root = document) {
    // ফেরা পাতায় কার্সর লেখার শেষে — `autofocus` বসায় টুলবার
    root.querySelectorAll(`${SELECTOR}[autofocus]`).forEach((box) => {
        const end = box.value.length

        try {
            box.setSelectionRange(end, end)
        } catch {
            // ⓘ কিছু ধরনের ঘর নির্বাচন মানে না — তখন কার্সর যেখানে ব্রাউজার বসায়
        }
    })

    if (listening) return

    listening = true

    root.addEventListener('compositionstart', (event) => {
        const box = liveBox(event.target)
        if (! box) return

        boxState(box).composing = true
        cancel(box)
    })

    root.addEventListener('compositionend', (event) => {
        const box = liveBox(event.target)
        if (! box) return

        boxState(box).composing = false
        schedule(box)
    })

    root.addEventListener('input', (event) => {
        const box = liveBox(event.target)
        if (! box) return

        if (event.isComposing || boxState(box).composing) return

        schedule(box)
    })

    /*
     * ⓘ Enter (বা যেকোনো জমা) — অপেক্ষার ঘড়ি বাতিল, নাহলে একটু পরে একই খোঁজ
     * আবার যেত। ⛔ একই লেখা আগেই জমা পড়ে থাকলে এই জমাটা থামে: পাতা তখনো
     * ফেরেনি, আর দ্বিতীয় অনুরোধ কেবল প্রথমটাকে বাতিল করে নতুন করে শুরু করত।
     */
    root.addEventListener('submit', (event) => {
        const form = event.target
        if (! (form instanceof HTMLFormElement)) return

        const box = form.querySelector(SELECTOR)
        if (! box) return

        cancel(box)

        const s = boxState(box)
        const value = box.value.trim()

        // ⓘ Enter চালায় খোঁজার নামহীন বোতামটা; ঘনত্ব বা সাজানোর মতো নামওয়ালা জমা আটকায় না
        const enter = Boolean(event.submitter?.matches?.('button, input')) && ! event.submitter.getAttribute('name')

        if (enter && s.sent === value) {
            event.preventDefault()

            return
        }

        s.sent = value
    }, true)
}
