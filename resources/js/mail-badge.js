/*
 * ⭐ বারের মেইল চিহ্নে না-পড়া সংখ্যা ([[topbar-mail.blade.php]]) — 3 মিনিটে একবার, পাতা লুকানো থাকলে নয়।
 * ⓘ সার্ভার নিজে 2 মিনিট জমিয়ে রাখে ([[MailController::unread]]), তাই মেইল সার্ভারে চাপ পড়ে না।
 */
const link = document.querySelector('[data-mail-topbar]')

if (link) {
    const badge = link.querySelector('[data-mail-badge]')
    const tick = async () => {
        if (document.hidden) return
        try {
            const r = await fetch(link.dataset.unreadUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            if (! r.ok) return
            const n = Number((await r.json()).unread || 0)
            badge.hidden = n <= 0
            badge.textContent = n > 99 ? '99+' : String(n)
        } catch (e) { /* চিহ্ন না এলে কিছু হয় না */ }
    }
    tick()
    setInterval(tick, 180000)
}
