/**
 * ⭐ টাকার পর্দায় Enter কিছুই পাঠায় না — Ctrl+Enter মূল বোতাম চাপে (fe, ২৪ অক্টোবর ২০২৬; চার পর্দা: রসিদ, পরিশোধ, খরচের ভাউচার
 * আর সরবরাহকারী-পরিশোধ)।
 *
 * ── ⛔ কেন ─────────────────────────────────────────────────────────
 * ঘরে Enter চাপলে ব্রাউজার ফর্মের **প্রথম** জমার বোতাম চাপে। বোতামের ক্রম "বাতিল · খসড়া রাখুন · [মূল]" হওয়ার পর সেটা খসড়া;
 * আগে ছিল পোস্ট — টাকার অঙ্ক লিখে অভ্যাসে Enter চাপলে কাগজ খাতায় বসে যেত। টাকার কাগজ একটা ইচ্ছাকৃত চাপেই যাবে।
 *
 *   Enter, লেখার ঘরে (input, select)   → কিছু হয় না
 *   Enter, বড় লেখার ঘরে (textarea)     → নতুন লাইন (ব্রাউজারের নিজের)
 *   Enter, বোতামে                      → সেই বোতাম (ব্রাউজারের নিজের) — খোঁজার তালিকার নিজের Enter-ও আগের মতো
 *   Ctrl+Enter (বা ⌘+Enter), যেকোনো ঘরে → মূল বোতাম (`[data-page-primary]`) চাপা — তার নিশ্চিত-করার পপআপসহ, কারণ চাপটা
 *                                        আসল ক্লিক
 *
 * ⓘ ফর্মে `x-money-keys` — একটা directive, একবার নিবন্ধিত ([[registerComponents]])। CSP-Alpine-এ কোনো এক্সপ্রেশন পড়া হয় না।
 * ⚠️ কেবল `preventDefault`, `stopPropagation` নয় — ঘরের নিজের Enter (পক্ষের খোঁজায় নাম বাছা) আগে চলে, তারপর এখানে আসে।
 */
export function moneyKeyAction (event) {
    if (event.key !== 'Enter' || event.isComposing) return 'none'

    const target = event.target
    const tag = String(target?.tagName ?? '').toLowerCase()

    if (event.ctrlKey || event.metaKey) return 'primary'
    if (tag === 'textarea' || tag === 'button' || tag === 'a') return 'native'
    if (tag === 'input' || tag === 'select') return 'block'

    return 'none'
}

export function registerMoneyKeys (Alpine) {
    Alpine.directive('money-keys', (el, _directive, { cleanup }) => {
        const onKey = (event) => {
            const action = moneyKeyAction(event)

            if (action === 'block') {
                event.preventDefault()
            } else if (action === 'primary') {
                event.preventDefault()
                el.querySelector('[data-page-primary]')?.click()
            }
        }

        el.addEventListener('keydown', onKey)
        cleanup(() => el.removeEventListener('keydown', onKey))
    })
}
