/*
 * ⭐ কাউন্টার মোড — মালিক, ২৬ নভেম্বর ২০২৬ (নমুনা "ঠিক আছে")।
 * ⓘ মেনুর "সরাসরি ক্রয়" / "সরাসরি বিক্রয়" চাপলে বড় পর্দায় (১০২৪px+) নতুন ট্যাবে `?counter=1` — মেনু-বার ছাড়া কেবল বিলের পর্দা
 *   ([[CounterMode]])। ফোন বা ছোট পর্দায় আগের মতো একই ট্যাবে। Ctrl/⌘/Shift/মাঝের বোতামে ব্রাউজারের নিজের নিয়ম।
 * ⓘ "✕ বন্ধ করুন" — নিজের খোলা ট্যাব বন্ধ করে; ব্রাউজার না দিলে মূল পাতায় ফেরে।
 */
export const COUNTER_PATHS = ['/purchase/direct', '/sales/direct']

export function counterUrl (href, origin) {
    const url = new URL(href, origin)

    if (! COUNTER_PATHS.includes(url.pathname.replace(/\/$/, '')) || url.searchParams.has('counter')) {
        return null
    }

    url.searchParams.set('counter', '1')

    return url.toString()
}

export function installCounterMode (doc = document, win = window) {
    doc.addEventListener('click', (event) => {
        const link = event.target?.closest?.('a[href]')

        if (! link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return
        }

        if (link.target && link.target !== '_self') return
        if (win.innerWidth < 1024) return
        if (new URL(win.location.href).searchParams.has('counter')) return

        const url = counterUrl(link.getAttribute('href'), win.location.origin)

        if (url === null) return

        event.preventDefault()
        const opened = win.open(url, '_blank')

        if (! opened) win.location.assign(url)
    })

    doc.addEventListener('click', (event) => {
        if (! event.target?.closest?.('[data-counter-close]')) return

        win.close()
        // ⓘ ব্রাউজার বন্ধ করতে না দিলে (হাতে খোলা ট্যাব) — মূল পাতায়
        setTimeout(() => { if (! win.closed) win.location.assign(win.location.pathname) }, 150)
    })
}
