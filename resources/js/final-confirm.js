/*
 * ⭐ শেষবার পাকা করা — মালিকের অনুমোদিত নমুনা (২৮ নভেম্বর ২০২৬, "নিশ্চিত-ও-খসড়ার-পপআপ-নমুনা"): *"কোনোভাবে যাতে ভুল না হয়"*।
 *
 * ── কোথায় ─────────────────────────────────────────────────────────────
 * কেবল যে ফর্মে `data-final-confirm` (দুই কাউন্টার, ABOS-এর তিন রূপে)। ফর্মের ভেতরে দুইটা `<dialog>`:
 *   `[data-final-dialog="confirm"]` — সারাংশে "নিশ্চিত" চাপার পর: ক্রেতা · পয়েন্ট · বড় মোট · বাকি → "হ্যাঁ, পাকা করুন"।
 *   `[data-final-dialog="draft"]`   — "খসড়া রাখুন" চাপলে: ক্রেতা · এখন পর্যন্ত মোট → "হ্যাঁ, খসড়া রাখুন"।
 * লেখাগুলো Alpine-এর নিজের বাঁধাই (ফর্মের ভেতরে বলে); এই ফাইল কেবল খোলা-বন্ধ আর জমা সামলায়।
 *
 * ── ⛔ দুবার Enter ─────────────────────────────────────────────────────
 * পপআপ খোলার মুহূর্তে "হ্যাঁ" বন্ধ, ARM_MS পরে খোলে আর ফোকাস পায়। ⓘ তাই সারাংশে চাপা Enter-এর পরের Enter
 * (আঙুল দুবার পড়লে) "হ্যাঁ"-তে পৌঁছায় না; একটু থেমে Enter = হ্যাঁ। Esc বা "ফিরে যান" = কিছুই না।
 *
 * ⛔ পপআপ কেবল দেখায় আর থামায় — আসল পাহারা (সীমা, লট, দর) সার্ভারে, আগের মতোই।
 */
export const ARM_MS = 400

const isDraftButton = (el) => el instanceof Element && el.matches('button[name="save_as_draft"][value="1"]')

/**
 * পপআপ খুলে "হ্যাঁ" চাপলে তবেই `proceed()`। ফর্মে পপআপ না থাকলে সরাসরি `proceed()` (অন্য রূপে আগের মতো)।
 */
export function askFinal (form, kind, proceed, timer = setTimeout) {
    const dialog = form.querySelector(`[data-final-dialog="${kind}"]`)
    if (!dialog) {
        proceed()
        return
    }

    const yes = dialog.querySelector('[data-final-yes]')
    const back = dialog.querySelector('[data-final-back]')

    if (yes) yes.disabled = true
    open(dialog)
    // ⓘ ফোকাস আগে "ফিরে যান"-এ — নিরাপদ দিক; তারপর "হ্যাঁ" খোলে
    back?.focus?.()
    timer(() => {
        if (yes) {
            yes.disabled = false
            yes.focus?.()
        }
    }, ARM_MS)

    dialog.onclick = (click) => {
        const button = click.target instanceof Element ? click.target.closest('[data-final-yes], [data-final-back]') : null
        if (!button || button.disabled) return
        click.preventDefault()
        close(dialog)
        if (button.hasAttribute('data-final-yes')) proceed()
    }
}

/**
 * "খসড়া রাখুন" — সরাসরি জমা দেয়, তাই জমার মুহূর্তে থামিয়ে পপআপ। "হ্যাঁ"-র পরের জমায় চিহ্ন দেখে ছেড়ে দেয়।
 * ⚠️ capture — একবার-জমার পাহারার ([[one-submit.js]]) আগে; থামালে `defaultPrevented`, তাই সে জমা গোনে না।
 */
export function guardFinalConfirm (root = document) {
    root.addEventListener('submit', (event) => {
        const form = event.target
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-final-confirm')) return
        if (!isDraftButton(event.submitter)) return

        if (form.dataset.draftOk === '1') {
            delete form.dataset.draftOk
            return
        }

        event.preventDefault()
        event.stopImmediatePropagation()

        const submitter = event.submitter
        askFinal(form, 'draft', () => {
            form.dataset.draftOk = '1'
            if (typeof form.requestSubmit === 'function') form.requestSubmit(submitter)
        })
    }, true)
}

function open (dialog) {
    if (typeof dialog.showModal === 'function') dialog.showModal()
    else dialog.setAttribute('open', '')
}

function close (dialog) {
    if (typeof dialog.close === 'function') dialog.close()
    else dialog.removeAttribute('open')
}
