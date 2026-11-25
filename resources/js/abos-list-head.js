/*
 * ⭐ "ABOS" রূপে তালিকার মাথা এক সারিতে — মালিক, ১৯ নভেম্বর ২০২৬ (নমুনা Q2W6mM3S v3, "ok"): "মডিউলের বারটা ফিল্টারের বারটা দুইটা মিলে
 *   একটা" — রঙহীন সাদা বক্সে: নাম · সংখ্যা · ছাঁকনি · খোঁজা · দৃশ্য/কলাম/নামান · "+ নতুন"।
 *
 * ⓘ টাকার পাতাগুলোতে ([[x-money.page]]) নাম, সংখ্যা আর "নতুন" আলাদা রঙিন পট্টিতে (`data-money-hero`), আর ছাঁকনির সারি নিচের বাক্সে
 *   ([[x-ui.toolbar]]) — দুইটা আলাদা ধারকে, তাই কেবল CSS-এ এক সারি হয় না। এখানে পট্টির তিনটা জিনিস টুলবারে তুলে আনা হয়, আর
 *   পট্টিটা লুকায়। বাকি সাজ (এক সারি, রং, ক্রম) [[abos-look.css]]-এ।
 * ⛔ কেবল `rose`-এ — অন্য রূপে কিছুই নড়ে না। জাভাস্ক্রিপ্ট ছাড়া পট্টিটা সাদা হয়েই থাকে, কাজ সব চলে।
 */
/*
 * ⭐ The three ABOS looks share one head (owner "ok", 24 Nov 2026, sample 9VVoe6uQ v2: "Filter line r module r create egulo sob ek
 *   line thakbe") — navy and blue now merge, fold and compact like "ABOS Smart" did since 19 Nov. ⛔ SAP, Dynamic (except its
 *   note fold, as before), Odoo and the rest: nothing moves.
 */
export const ABOS_HEAD_LOOKS = ['rose', 'navy', 'blue']

export function mergeMoneyHead (root = document) {
    if (! ABOS_HEAD_LOOKS.includes(root.documentElement?.dataset.ui)) {
        return 0
    }

    let merged = 0

    for (const page of root.querySelectorAll('[data-page-kind]')) {
        const hero = page.querySelector(':scope > [data-money-hero]')
        const toolbar = page.querySelector('.toolbar-view')

        if (! hero || ! toolbar || toolbar.hasAttribute('data-merged-head')) {
            continue
        }

        const title = hero.querySelector('h1')
        const count = hero.querySelector('[data-hero-count]')
        const actions = hero.querySelector('[data-hero-actions]')

        // ⓘ নাম আর সংখ্যা সারির শুরুতে, "নতুন" শেষে — ক্রম CSS-এর `order`-এ ([[abos-look.css]])
        if (count) {
            count.setAttribute('data-record-count', '')
            toolbar.prepend(count)
        }

        if (title) {
            title.setAttribute('data-merged-title', '')
            toolbar.prepend(title)
        }

        if (actions && actions.children.length) {
            actions.setAttribute('data-command-bar', '')
            toolbar.append(actions)
        }

        hero.hidden = true
        toolbar.setAttribute('data-merged-head', '')
        merged++
    }

    return merged
}

/*
 * ⭐ বাকি পাতার মাথাও এক সারিতে — মালিক, ১৯ নভেম্বর ২০২৬ রাত ("ok … যে প্ল্যানটা দিয়েছো সেমভাবে করে ফেলো"):
 *   নাম · ট্যাব (পিল) · ছাঁকনি ▾ ও চিপ … বোতাম। ট্যাব আর পাতার নিজের ছাঁকনির ঘর ([[x-ui.page-header]]-এর পরে আলাদা সারিতে)
 *   মাথার সারিতে ওঠে; ছাঁকনির ঘরগুলো "ছাঁকনি" বোতামের নিচে ঝোলে, আর বাছা মানগুলো চিপ হয়ে দেখায়।
 * ⓘ ঘর আর লিংক নিজেরাই সরে — নকল নয় — তাই জমা দেওয়া, ঠিকানা, পেছনে যাওয়া সব আগের মতো।
 * ⛔ কেবল `rose`-এ। তালিকার টুলবার ([[x-ui.toolbar]]) আর তার ছাঁকনির পাতা ছোঁয়া হয় না — ওগুলো আগেই এক সারিতে।
 */
/*
 * ⭐ পাতার নিজের ব্যাখ্যার লাইন (page-header-এর `subtitle` নয় — পাতা নিজেই লেখা একটা ম্লান `<p>`, তালিকার বাক্সের আগে) — লুকায়,
 *   আর লেখাটা নামের পাশে ⓘ-এর ভিতরে যায়, মাউস রাখলে দেখা যায়। "ABOS" আর "Dynamic"-এ (মালিক, ১৯ নভেম্বর ২০২৬ রাত — Dynamic-এর
 *   কোম্পানির পাতার ছবি: "এই ধরনের সমস্যা আছে কোথায় কোথায় দেখে এগুলো রিমুভ করে")।
 * ⓘ সতর্কবার্তা (`role=alert`), ফর্ম, বাক্স বা টেবিলের ভিতরের লেখা ছোঁয় না — কেবল মাথা আর প্রথম বাক্সের মাঝের ম্লান লাইন।
 */
const INFO = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>'

export function foldPageNotes (root = document) {
    if (! [...ABOS_HEAD_LOOKS, 'dynamic'].includes(root.documentElement?.dataset.ui)) {
        return 0
    }

    const main = root.querySelector('main')
    const box = main?.querySelector('[data-boxed], table, .toolbar-view')

    if (! main || ! box) {
        return 0
    }

    const notes = [...main.querySelectorAll('p')].filter(p => before(p, box)
        && /(^|\s)text-\(--color-ink-muted\)(\s|$)/.test(p.className) && ! p.hidden && p.textContent.trim() !== ''
        && ! p.hasAttribute('data-page-subtitle')
        && ! p.closest('[data-boxed], form, section, table, dialog, aside, li, details, [role=alert], [data-head-filter], [data-page-kind] > [data-money-hero]'))

    if (notes.length === 0) {
        return 0
    }

    const title = [...main.querySelectorAll('h1, h2[data-view-selector]')].find(h => ! h.hidden && h.getClientRects().length !== 0)
        ?? main.querySelector('h1, h2[data-view-selector]')
    const text = notes.map(p => p.textContent.trim().replace(/\s+/g, ' ')).join(' ')

    if (title && ! title.querySelector('[data-page-info]')) {
        const info = root.createElement('span')
        info.setAttribute('data-page-info', '')
        info.setAttribute('data-page-note-info', '')
        info.title = text
        info.setAttribute('aria-label', text)
        info.innerHTML = INFO
        title.append(info)
    } else if (title) {
        const info = title.querySelector('[data-page-info]')
        info.title = `${info.title} ${text}`.trim()
    }

    notes.forEach(p => { p.hidden = true; p.setAttribute('data-page-note', '') })

    return notes.length
}

const FUNNEL ='<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5h18l-7 8v6l-4-2v-4z"/></svg>'

const before = (a, b) => b === null || Boolean(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING)

const visibleFields = form => [...form.querySelectorAll('input, select, textarea')]
    .filter(f => f.name && ! ['hidden', 'submit', 'button', 'reset'].includes((f.type || '').toLowerCase()))

function chipsOf (form) {
    const chips = []

    for (const f of visibleFields(form)) {
        let value = ''

        if (f.tagName === 'SELECT') {
            const o = f.selectedOptions?.[0]
            value = o && o.value !== '' ? o.textContent.trim() : ''
        } else if (['checkbox', 'radio'].includes(f.type)) {
            value = f.checked ? (f.closest('label')?.textContent.trim() || f.value) : ''
        } else {
            value = (f.value || '').trim()
        }

        if (value === '') {
            continue
        }

        const label = (f.id && [...form.querySelectorAll('label')].find(l => l.htmlFor === f.id)?.textContent.trim()) || ''
        chips.push(label && ! ['checkbox', 'radio'].includes(f.type) ? `${label}: ${value}` : value)
    }

    return chips
}

/*
 * ⓘ পাঁচটা পর্যন্ত ট্যাব পাশাপাশি পিল। তার বেশি হলে এক সারিতে ধরে না — তখন একটা "▾" বোতাম, তাতে বাছা ট্যাবের নাম আর সংখ্যা,
 *   চাপলে সবগুলো নিচে খোলে (SAP-এর variant, Salesforce-এর list view-এর ধরন)। লিংকগুলো একই — কেবল জায়গা বদলায়।
 */
const MAX_PILLS = 5

// ⓘ সারিটা ভেঙে দুই লাইন হয়েছে কি না — প্রথম আর শেষ দেখা জিনিসের মাথা মিলিয়ে
const flexItems = el => [...el.children].flatMap(c => (getComputedStyle(c).display === 'contents' ? flexItems(c) : [c]))

const wraps = row => {
    const tops = flexItems(row).filter(e => e.offsetHeight > 0 && ! (row.closest('[data-bar-head]') && e.hasAttribute('data-chip-row'))).map(e => e.getBoundingClientRect().top)

    return tops.length > 1 && Math.max(...tops) - Math.min(...tops) > 24
}

function asHeadTabs (nav, root, collapse = false) {
    nav.setAttribute('data-head-tabs', '')

    const links = nav.querySelectorAll('a')

    if (links.length <= MAX_PILLS && ! collapse) {
        return nav
    }

    const active = nav.querySelector('a[aria-current="page"]')
    const wrap = root.createElement('div')
    wrap.setAttribute('data-head-tabs-menu', '')

    const button = root.createElement('button')
    button.type = 'button'
    button.setAttribute('aria-expanded', 'false')
    button.setAttribute('aria-haspopup', 'true')

    for (const node of active.childNodes) {
        button.append(node.cloneNode(true))
    }

    button.insertAdjacentHTML('beforeend', '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>')

    nav.hidden = true
    button.addEventListener('click', () => {
        nav.hidden = ! nav.hidden
        button.setAttribute('aria-expanded', String(! nav.hidden))
    })
    root.addEventListener('click', e => {
        if (! wrap.contains(e.target)) {
            nav.hidden = true
            button.setAttribute('aria-expanded', 'false')
        }
    })

    nav.replaceWith(wrap)
    wrap.append(button, nav)

    return wrap
}

/*
 * ⭐ Pills first: the search shrinks (CSS, down to 140px) and the tabs fold only if the row still breaks. ⓘ Measured again when the
 *   fonts are in, when Alpine is up and when the window changes — the first measure runs in the fallback font, which is wider, and
 *   a fold made there used to stay for good.
 */
function keepPills (nav, row, root) {
    let menu = null

    const fit = () => {
        if (menu !== null && nav.parentElement === menu) {
            menu.replaceWith(nav)
            nav.hidden = false
        }

        if (! wraps(row)) {
            return
        }

        if (menu === null) {
            menu = asHeadTabs(nav, root, true)

            return
        }

        nav.replaceWith(menu)
        menu.append(nav)
        nav.hidden = true
        menu.querySelector(':scope > button').setAttribute('aria-expanded', 'false')
    }

    fit()
    root.fonts?.ready?.then(fit)
    root.addEventListener?.('alpine:initialized', fit, { once: true })

    let waiting = 0
    root.defaultView?.addEventListener('resize', () => {
        root.defaultView.cancelAnimationFrame(waiting)
        waiting = root.defaultView.requestAnimationFrame(fit)
    })
}

/*
 * ⭐ ছাঁকনির সরু বারওয়ালা পাতা ([[x-ui.filter-bar]]: ডে বুক, দিন শেষ, স্থিতিপত্র, বাজেট …) — নাম, ⓘ আর পাতার বোতাম বারের সারিতে ওঠে,
 *   খালি মাথা লুকায় (মালিক, ২৪ নভেম্বর ২০২৬: "Filter line r module r create egulo sob ek line thakbe")। ট্যাব তোলে [[compactPageHead]]।
 * ⓘ বার নয়, মাথা সরে — ছাঁকনির বোতামের Alpine আর ফর্ম যেখানে ছিল সেখানেই থাকে।
 * ⛔ বারটা প্রথম টেবিল বা বাক্সের পরে হলে (নিচের কোনো অংশের বার) কিছুই নড়ে না। ⛔ বোতামে ফর্ম বা জমার বোতাম থাকলেও নয় — বারটা
 *   ছাঁকনির ফর্মের ভিতরে, তাই ওখানে গেলে জমাটা ছাঁকনিতে পড়ত।
 * ⓘ বার কাগজে ছাপে না (print-hide) — ছাপার সময় নামটা নিজের জায়গায় ফেরে, পরে আবার বারে।
 */
export function mergeFilterBarHead (root = document) {
    if (! ABOS_HEAD_LOOKS.includes(root.documentElement?.dataset.ui)) {
        return false
    }

    const main = root.querySelector('main')
    const title = main?.querySelector('h1')
    const bar = main?.querySelector('[data-filter-bar]')
    const row = bar?.querySelector('.toolbar-view > div')
    const home = title?.closest('.min-w-0')
    const head = home?.parentElement

    if (! row || ! head || title.closest('.toolbar-view, [data-boxed], [data-money-hero]') || ! before(head, bar)) {
        return false
    }

    const first = [...main.querySelectorAll('table, [data-boxed]')].find(el => ! bar.contains(el) && ! el.closest('[data-filter-panel], dialog'))
    const actions = head.querySelector(':scope > [data-page-actions]')

    if ((first && ! before(bar, first)) || actions?.querySelector('form, [type=submit], button:not([type])')) {
        return false
    }

    const place = () => {
        row.prepend(title)
        head.hidden = true
    }

    title.style.order = '-1'
    place()

    if (actions) {
        // ⓘ চিপের সারি `order-last`, তাই বোতাম তার আগে, সারির ডান মাথায়
        actions.style.order = '9'
        actions.style.marginInlineStart = 'auto'
        row.append(actions)
    }

    bar.setAttribute('data-bar-head', '')

    // ⓘ নিচের টুলবারে একই নাম আবার থাকলে দ্বিতীয়টা লুকায় — নাম একবারই
    const name = title.textContent.trim()

    for (const twin of main.querySelectorAll('.toolbar-view h2[data-view-selector]')) {
        if (twin.textContent.trim() === name) {
            twin.hidden = true
        }
    }

    const view = root.defaultView

    view?.addEventListener('beforeprint', () => {
        home.prepend(title)
        head.hidden = false
    })
    view?.addEventListener('afterprint', place)

    return true
}

/*
 * ⛔ পাশের তালিকা ট্যাব নয় — `<aside>` বা কন্ট্রোল প্যানেলের কাঠামো (`data-control-frame`): ওখানকার nav আর খোঁজার ফর্ম মাথায় তুললে
 *   বাঁয়ের কলামটা খালি পড়ে থাকত (মালিক, ২৪ নভেম্বর ২০২৬: "মডিউল ও মেনু ⌄")।
 */
const aside = el => Boolean(el.closest('aside, [data-control-frame]'))

export function compactPageHead (root = document) {
    mergeFilterBarHead(root)

    if (! ABOS_HEAD_LOOKS.includes(root.documentElement?.dataset.ui)) {
        return null
    }

    const main = root.querySelector('main')
    const title = main?.querySelector('h1')

    if (! title) {
        return null
    }

    // ⓘ তালিকার পাতা: নাম আগেই টুলবারের সারিতে — ওপরে আলাদা সারির ট্যাবগুলো নাম আর সংখ্যার ঠিক পরে বসে
    const toolbar = title.closest('.toolbar-view')

    if (toolbar) {
        const tabs = [...main.querySelectorAll('nav, [data-list-tabs]')].find(n => ! n.hasAttribute('data-head-tabs')
            && ! aside(n) && (toolbar.contains(n) || before(n, toolbar))
            && n.querySelectorAll('a').length >= 2 && n.querySelector('a[aria-current="page"]'))

        if (! tabs) {
            return null
        }

        const count = [...toolbar.querySelectorAll('[data-record-count]')].pop()
        const panel = tabs.closest('#toolbar-filters')
        const placed = asHeadTabs(tabs, root)
        ;(count && title.parentElement === count.parentElement ? count : title).after(placed)

        // ⓘ ট্যাব উঠে গেলে পুরনো ছাঁকনির খোপে আর কিছু নেই — "+ ছাঁকনি" বোতামও নয় (অডিট, ২৫ নভেম্বর ২০২৬)
        if (panel && ! panel.querySelector('input:not([type=hidden]), select, textarea, a[href]')) {
            panel.style.display = 'none'
            toolbar.querySelectorAll('[aria-controls="toolbar-filters"]').forEach(b => { b.style.display = 'none' })
        }

        // ⓘ ছাঁকনির বারে জিনিসগুলো ভিতরের সারিতে; চালু ছাঁকনির চিপ ইচ্ছে করেই নিজের লাইনে, তাই ভাঙা বলে গোনা নয়
        const barRow = toolbar.closest('[data-bar-head]') ? title.parentElement : null

        if (placed === tabs) {
            keepPills(tabs, barRow ?? toolbar, root)
        }

        return { tabs: true, filter: false }
    }

    const head = title.closest('.min-w-0')?.parentElement

    if (! head || head.hasAttribute('data-head-compact') || head.closest('[data-money-hero]')) {
        return null
    }

    const firstTable = main.querySelector('table, [data-boxed], .toolbar-view')
    const outside = el => ! head.contains(el) && ! aside(el) && ! el.closest('.toolbar-view, [data-filter-panel], [data-filter-layout], dialog, [data-print-only]')
    const done = { tabs: false, filter: false }
    const actions = head.querySelector(':scope > [data-page-actions]')

    // ১) ট্যাব: মাথার পরে, টেবিলের আগে, অন্তত দুই লিংক আর একটা বাছা ("এই পাতা")
    const nav = [...main.querySelectorAll('nav, [data-list-tabs]')].find(n => outside(n) && before(head, n) && before(n, firstTable)
        && n.querySelectorAll('a').length >= 2 && n.querySelector('a[aria-current="page"]'))

    if (nav) {
        head.insertBefore(asHeadTabs(nav, root), actions)
        done.tabs = true
    }

    // ২) পাতার নিজের ছাঁকনি: একটা GET ফর্ম, টেবিলের আগে; কেবল একটা খোঁজার ঘর হলে নয় (খোঁজা চোখের সামনেই থাকে)
    const form = [...main.querySelectorAll('form')].find(f => (f.getAttribute('method') || 'get').toLowerCase() === 'get'
        && outside(f) && before(head, f) && before(f, firstTable) && visibleFields(f).length >= 1
        && ! (visibleFields(f).length === 1 && ['q', 'search'].includes(visibleFields(f)[0].name)))

    if (form) {
        const wrap = root.createElement('div')
        wrap.setAttribute('data-head-filter', '')

        const button = root.createElement('button')
        button.type = 'button'
        button.setAttribute('aria-expanded', 'false')
        button.innerHTML = FUNNEL
        button.append(root.documentElement.lang === 'bn' ? 'ছাঁকনি' : 'Filter')

        const chips = root.createElement('span')
        chips.setAttribute('data-head-chips', '')

        for (const text of chipsOf(form)) {
            const chip = root.createElement('span')
            chip.textContent = text
            chips.append(chip)
        }

        form.setAttribute('data-head-filter-form', '')
        form.hidden = true
        button.addEventListener('click', () => {
            form.hidden = ! form.hidden
            button.setAttribute('aria-expanded', String(! form.hidden))
        })
        root.addEventListener('click', e => {
            if (! wrap.contains(e.target)) {
                form.hidden = true
                button.setAttribute('aria-expanded', 'false')
            }
        })

        wrap.append(button, chips, form)
        head.insertBefore(wrap, actions)
        done.filter = true
    }

    // ⓘ নিচের টুলবারে একই নাম আবার লেখা থাকলে (মাথায় "করণীয়", টুলবারেও "করণীয়") দ্বিতীয়টা লুকায় — নাম একবারই
    const name = title.textContent.trim()

    for (const twin of main.querySelectorAll('.toolbar-view h2[data-view-selector]')) {
        if (twin.textContent.trim() === name) {
            twin.hidden = true
        }
    }

    if (done.tabs || done.filter) {
        head.setAttribute('data-head-compact', '')

        const pills = head.querySelector(':scope > nav[data-head-tabs]')

        if (pills) {
            keepPills(pills, head, root)
        }
    }

    return done
}

/**
 * ⭐ মাথা সবার আগে (মালিক, ২৪ নভেম্বর ২০২৬) — তালিকার রঙিন কার্ডের সারি বারের উপরে নয়, ঠিক নিচে; কার্ড, সংখ্যা আর লিংক একই।
 * ⓘ অংশ-বদল ([[list-swap.js]]) `data-list-part="cards"` ধরে বদলায়, জায়গা ধরে নয় — তাই সরালেও চলে। ⛔ অন্য রূপে কিছু নড়ে না।
 */
export function cardsUnderHead (root = document) {
    if (! ABOS_HEAD_LOOKS.includes(root.documentElement?.dataset.ui)) {
        return 0
    }

    let moved = 0

    for (const cards of root.querySelectorAll('main [data-filter-layout] > [data-list-cards]')) {
        const bar = cards.parentElement.querySelector('.toolbar-view')
        const after = bar?.closest('form') ?? bar

        if (! after || ! (cards.compareDocumentPosition(after) & 4)) {
            continue
        }

        after.after(cards)
        moved++
    }

    // ⓘ তালিকার নিজের সংখ্যার বাক্স (চেক, কমিশন, ডেলিভারির হিসাব) — পাতা `data-under-head` বলে দেয়
    for (const box of root.querySelectorAll('main [data-under-head]')) {
        const bar = root.querySelector('main [data-filter-layout] .toolbar-view') ?? root.querySelector('main .toolbar-view')
        const after = bar?.closest('form') ?? bar

        if (after && (box.compareDocumentPosition(after) & 4)) {
            after.after(box)
            moved++
        }
    }

    return moved
}
