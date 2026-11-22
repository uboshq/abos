/*
 * ⭐ এক ব্যাংক-সারি, কয়েকটা কাগজ — টিক দেওয়া কাগজের চলতি মোট (মালিক, ২১ নভেম্বর ২০২৬; one-to-many reconciliation)।
 *
 * ⓘ `form[data-match-total]` — ফর্মে `data-line-amount` (ব্যাংক-সারির অঙ্ক), প্রতিটা টিকে `data-share` (কাগজের অঙ্ক)।
 * মোট লেখা হয় `[data-ticked-total]`-এ (ইংরেজি অঙ্ক); সমান হলে `data-total-state="equal"`, না হলে "unequal", আর
 * জমার বোতাম (`[data-match-submit]`) কেবল সমান হলে চালু। ⛔ আসল পাহারা সার্ভারে — এটা কেবল চোখের সুবিধা।
 * ⓘ `document`-এ এক শ্রোতা — Alpine CSP-র বাইরে, যেকোনো পর্দায় একই ছক।
 */
const cents = (value) => {
    const n = Number.parseFloat(String(value ?? '0').replace(/,/g, ''))

    return Number.isFinite(n) ? Math.round(n * 100) : 0
}

const money = (paisa) => (paisa / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export function refreshMatchTotal (form) {
    const want = cents(form.dataset.lineAmount)
    let sum = 0
    let ticked = 0

    form.querySelectorAll('input[data-share]').forEach((box) => {
        if (box.checked) {
            sum += cents(box.dataset.share)
            ticked++
        }
    })

    const out = form.querySelector('[data-ticked-total]')
    if (out) {
        out.textContent = money(sum)
    }

    const state = ticked > 0 && sum === want ? 'equal' : 'unequal'
    form.dataset.totalState = state

    const submit = form.querySelector('[data-match-submit]')
    if (submit) {
        submit.disabled = state !== 'equal'
    }

    return { sum, want, ticked, state }
}

export function watchMatchTotals (root = document) {
    root.querySelectorAll('form[data-match-total]').forEach((form) => refreshMatchTotal(form))

    root.addEventListener('change', (event) => {
        const box = event.target

        if (!(box instanceof HTMLInputElement) || !box.matches('input[data-share]')) {
            return
        }

        const form = box.closest('form[data-match-total]')
        if (form) {
            refreshMatchTotal(form)
        }
    })
}
