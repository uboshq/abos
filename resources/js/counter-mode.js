/*
 * ⭐ কাউন্টার মোড — মালিক, ২৬ নভেম্বর ২০২৬ (নমুনা "ঠিক আছে")।
 * ⓘ মেনুর "সরাসরি ক্রয়" / "সরাসরি বিক্রয়" চাপলে বড় পর্দায় (১০২৪px+) নতুন ট্যাবে `?counter=1` — মেনু-বার ছাড়া কেবল বিলের পর্দা
 *   ([[CounterMode]])। ফোন বা ছোট পর্দায় আগের মতো একই ট্যাবে। Ctrl/⌘/Shift/মাঝের বোতামে ব্রাউজারের নিজের নিয়ম।
 * ⓘ "✕ বন্ধ করুন" — নিজের খোলা ট্যাব বন্ধ করে; ব্রাউজার না দিলে মূল পাতায় ফেরে।
 *
 * ⭐ মেইল — একটাই নিজের ট্যাব (মালিক, ২৭ নভেম্বর ২০২৬; ABOS ও NEXUS-এর যৌথ পরিকল্পনা PLAN.md §1, Gmail-এর মতো):
 *   · মেনু/খাম/প্রোফাইল থেকে মেইল খোলে "abos-mail" নামের ট্যাবে; আবার চাপলে নতুন ট্যাব নয়, আগেরটাতেই যায়;
 *   · নতুন ট্যাব কেবল Ctrl/⌘/Shift/মাঝের বোতামে — ব্রাউজারের নিজের নিয়ম;
 *   · ABOS-এর মূল ট্যাবের নাম "abos-main" — মেইলের ভিতরের বিল/রসিদ/গ্রাহক সেখানে খোলে, মেইলের ট্যাব যেখানে ছিল সেখানেই থাকে।
 */
export const COUNTER_PATHS = ['/purchase/direct', '/sales/direct', '/mail']

export const MAIL_WINDOW = 'abos-mail'

export const MAIN_WINDOW = 'abos-main'

export function counterUrl (href, origin) {
    const url = new URL(href, origin)

    if (! COUNTER_PATHS.includes(url.pathname.replace(/\/$/, '')) || url.searchParams.has('counter')) {
        return null
    }

    url.searchParams.set('counter', '1')

    return url.toString()
}

/** ⓘ মেইলের সব পাতা (ইনবক্স, স্বাক্ষর, টেমপ্লেট, ঠিকানা, ইতিহাস) — একই মেইলের ট্যাবে (PLAN.md §2: সেটিংসও একই ট্যাবে) */
export function isMailUrl (href, origin) {
    const path = new URL(href, origin).pathname.replace(/\/$/, '')

    return path === '/mail' || path.startsWith('/mail/')
}

/**
 * ⓘ মেইলের ট্যাবে যাওয়া। খালি নাম দিয়ে খুললে ব্রাউজার আগের ট্যাবটা ফেরত দেয়, পাতা আবার লোড করে না।
 *   নতুন ট্যাব হলে, বা কোনো কাজ নিয়ে আসা হলে (নতুন মেইল, টেমপ্লেট, কাগজ) — ঠিকানায় যায়; নইলে কেবল সামনে আনে।
 */
export function openMail (url, win = window) {
    const target = new URL(url, win.location.origin)
    const asks = [...target.searchParams.keys()].some(k => k !== 'counter')
    const w = win.open('', MAIL_WINDOW)

    if (! w) {
        win.location.assign(target.toString())

        return null
    }

    let here = null

    try {
        here = w.location.href && w.location.href !== 'about:blank' ? new URL(w.location.href) : null
    } catch (e) {
        here = null
    }

    // ⓘ একই পাতা খোলা থাকলে কেবল সামনে আনা — পড়তে থাকা মেইল বা লেখা খসড়া হারায় না
    if (here === null || asks || here.pathname.replace(/\/$/, '') !== target.pathname.replace(/\/$/, '')) {
        w.location.href = target.toString()
    }
    w.focus()

    return w
}

/** ⓘ এই ট্যাবের নাম — মেইলের পাতা "abos-mail", কাউন্টার ছাড়া বাকি ABOS "abos-main" (নিজের নাম থাকলে বদলায় না) */
export function nameThisTab (win = window) {
    const url = new URL(win.location.href)

    if (isMailUrl(url.href, url.origin)) {
        win.name = MAIL_WINDOW
    } else if (! url.searchParams.has('counter') && (! win.name || win.name === MAIL_WINDOW)) {
        win.name = MAIN_WINDOW
    }
}

export const SIGN_OUT_KEY = 'abos-signed-out'

/**
 * ⭐ একটা ট্যাব থেকে বের হলে সব ট্যাব থেকে (যৌথ PLAN.md §3.8) — বের হওয়ার ফর্ম জমা পড়লে খবরটা রাখা হয়;
 *   অন্য ট্যাব (মেইল বা মূল) সেটা শুনে ঢোকার পাতায় যায়। ⓘ সার্ভারের সেশন এমনিতেই এক; এটা পর্দাটাও মিলিয়ে দেয়।
 */
export function installSignOutSync (doc = document, win = window) {
    doc.addEventListener('submit', (event) => {
        const form = event.target
        if (form && form.matches && form.matches('form[action$="/logout"]')) {
            try { win.localStorage.setItem(SIGN_OUT_KEY, String(Date.now())) } catch (e) { /* ⓘ জমা রাখা বন্ধ থাকলে কেবল এই ট্যাব */ }
        }
    })

    win.addEventListener('storage', (event) => {
        if (event.key !== SIGN_OUT_KEY || ! event.newValue) return
        if (/^\/(login|logout)\b/.test(win.location.pathname)) return
        win.location.assign('/login')
    })
}

export function installCounterMode (doc = document, win = window) {
    nameThisTab(win)
    installSignOutSync(doc, win)

    doc.addEventListener('click', (event) => {
        const link = event.target?.closest?.('a[href]')

        if (! link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return
        }

        const href = link.getAttribute('href')
        const toMail = link.target === MAIL_WINDOW || isMailUrl(href, win.location.origin)

        if (toMail) {
            // ⓘ মেইলের ট্যাবের ভিতরে মেইলের লিংক — একই ট্যাবে
            if (win.name === MAIL_WINDOW) return
            // ⓘ ফোন বা ছোট পর্দায় একই ট্যাবে (আগের নিয়ম)
            if (win.innerWidth < 1024) {
                if (link.target === MAIL_WINDOW) { event.preventDefault(); win.location.assign(counterUrl(href, win.location.origin) ?? href) }

                return
            }

            event.preventDefault()
            openMail(counterUrl(href, win.location.origin) ?? new URL(href, win.location.origin).toString(), win)

            return
        }

        // ⓘ মেইলের ট্যাবের ভিতরে ABOS-এর অন্য পাতা (বিল, রসিদ, গ্রাহক, খতিয়ান) — মূল ট্যাবে; মেইলের ট্যাব যেখানে ছিল সেখানেই
        if (win.name === MAIL_WINDOW && (! link.target || link.target === '_self' || link.target === MAIN_WINDOW)) {
            const to = new URL(href, win.location.origin)

            if (to.origin !== win.location.origin || href.startsWith('#') || link.hasAttribute('download')) return

            event.preventDefault()
            const main = win.open(to.toString(), MAIN_WINDOW)

            if (main) main.focus()
            else win.location.assign(to.toString())

            return
        }

        if (link.target && link.target !== '_self') return
        if (win.innerWidth < 1024) return
        if (new URL(win.location.href).searchParams.has('counter')) return

        const url = counterUrl(href, win.location.origin)

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
