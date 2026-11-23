/*
 * ⭐ তালিকার কেবল তালিকা-অংশ বদলায় — পুরো পাতা নয় (মালিক, ২৩ নভেম্বর ২০২৬: *খুঁজলে পুরো পাতা নতুন করে আসে*)।
 *
 * ── কী হয় ────────────────────────────────────────────────────────────
 * তালিকার GET ফর্ম জমা (খোঁজা, সাজানো, ছাঁকনি), পাতার তীর, টেবিলের মাথার সাজানোর লিংক আর ছাঁকনির চিপ — সবই এখন
 * পেছনে একই ঠিকানা আনে, হেডার `X-ABOS-Part: list` সহ ([[ListPart]]); সার্ভার খোলস ছাড়া কেবল ভিতরটা দেয়। তা থেকে
 * `data-list-part` চিহ্নের অংশগুলো (টেবিল, পাতার সারি, গোনা, চিপ, কার্ড) আর নিচের যোগফল জায়গায় বসে। ঠিকানা বদলায়
 * (`pushState`), আর "পেছনে" চাপলে আগের তালিকা ফেরে (`popstate`)।
 *
 * ⓘ খোঁজার ঘর বদলায় না — কার্সর আর লেখা যেখানে ছিল সেখানেই। নতুন অনুরোধ এলে আগেরটা বাতিল (`AbortController`)।
 *
 * ── ⛔ কখন পুরো পাতা (আগের মতো) ─────────────────────────────────────
 *   - উত্তর ঠিক না (ত্রুটি, ৪০১/৪১৯ — লগইন ফুরিয়েছে, অন্য ঠিকানায় গেল), বা টুকরোর চিহ্ন নেই;
 *   - নতুন তালিকার গড়ন আলাদা (কোনো অংশ আসল/গেল — যেমন প্রথম ছাঁকনির চিপ) — জোড়াতালি নয়, পুরো পাতা;
 *   - রিপোর্টের ফর্ম (`data-report`), পাতার নিজের বাছাই-ফর্ম (`data-bulk-form`), পিকের ভিতর।
 */

export const PART_HEADER = 'X-ABOS-Part'
export const PART_VALUE = 'list'
export const FRAGMENT = '[data-list-part-fragment]'

const PART = '[data-list-part]'
const SEARCH_BOX = 'input[data-live-search]'

/** পাতাটা কি তালিকা — অংশ বদলানোর যোগ্য */
export function isListPage (doc = document) {
    return !!doc.querySelector(`main#main ${PART}[data-list-part="table"]`) && !doc.querySelector('form[data-bulk-form]')
}

/** এই ফর্মের জমা কি অংশ-বদলে যাবে */
export function swapsForm (form, doc = document) {
    if (!(form instanceof doc.defaultView.HTMLFormElement)) return false
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') return false
    if (form.hasAttribute('data-report') || form.hasAttribute('target') || form.hasAttribute('data-no-swap')) return false
    if (!form.closest('main#main') || !form.querySelector('.toolbar-view')) return false

    return isListPage(doc)
}

/** ফর্মের জমার ঠিকানা — ব্রাউজার যা বানাত ঠিক তাই (ফর্মের বাইরের `form="…"` ঘরসহ, বোতামের নাম-মানসহ) */
export function formUrl (form, submitter = null, base = window.location.href) {
    const data = new FormData(form)

    // ⓘ `form="<id>"` দিয়ে বাঁধা বাইরের ঘর — কিছু ব্রাউজার-নকল (happy-dom) FormData-তে আনে না
    if (form.id) {
        for (const field of form.ownerDocument.querySelectorAll('[form][name]')) {
            if (field.getAttribute('form') !== form.id || data.has(field.name) || field.disabled) continue
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) continue
            data.append(field.name, field.value)
        }
    }

    if (submitter && submitter.name) data.append(submitter.name, submitter.value ?? '')

    const url = new URL(form.getAttribute('action') || base, base)
    url.search = new URLSearchParams([...data.entries()].filter(([, v]) => typeof v === 'string')).toString()
    url.hash = ''

    return url.toString()
}

/** কোন লিংকের চাপ অংশ-বদলে যাবে — পাতার তীর, টেবিলের মাথা (সাজানো), ছাঁকনির চিপ; একই পাতার ঠিকানা হলে */
export function swapsLink (link, event, doc = document) {
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false
    if (link.hasAttribute('download') || (link.target && link.target !== '_self') || link.hasAttribute('data-no-swap')) return false
    if (!link.closest('main#main')) return false
    if (!link.closest('[data-list-part="pager"], [data-list-part="table"] thead, [data-list-part="chips"]')) return false

    const here = new URL(doc.defaultView.location.href)
    const to = new URL(link.href, here)

    return to.origin === here.origin && to.pathname === here.pathname && isListPage(doc)
}

/** অংশগুলো জোড়া বেঁধে বসানো — গড়ন না মিললে কিছুই নয়, `false` (তখন পুরো পাতা) */
export function placeParts (doc, fresh) {
    const top = (el) => !el.parentElement?.closest(PART)
    const group = (els) => els.filter(top).reduce((map, el) => {
        const kind = el.getAttribute('data-list-part')
        map[kind] = [...(map[kind] ?? []), el]
        return map
    }, {})

    const now = group([...doc.querySelectorAll(`main#main ${PART}`)])
    const fragment = fresh.querySelector(FRAGMENT)
    const next = group([...(fragment?.querySelectorAll(`main ${PART}`) ?? [])])

    const kinds = Object.keys(now)
    if (!now.table || kinds.length !== Object.keys(next).length) return false
    if (kinds.some((kind) => (next[kind]?.length ?? -1) !== now[kind].length)) return false

    for (const kind of kinds) {
        now[kind].forEach((el, i) => {
            // ⓘ "ABOS"-এর বাছাইয়ের বার টেবিলের ঠিক আগে বসে ([[bulk-bar.js]]) — নতুন টেবিলের জন্য নতুন বার
            if (kind === 'table' && el.previousElementSibling?.hasAttribute('data-bulk-bar')) el.previousElementSibling.remove()
            el.replaceWith(doc.importNode(next[kind][i], true))
        })
    }

    // ⓘ নিচের যোগফলের পট্টি — খোলসের ভিতরে, `<main>`-এর বাইরে; হিন্টগুলো যেমন আছে
    const foot = doc.querySelector('[data-look-listfoot]')
    const totals = fragment.querySelector('[data-list-foot-totals]')
    if (foot && totals) {
        const hints = foot.querySelector(':scope > [data-look-hints]')
        ;[...foot.childNodes].filter((n) => n !== hints).forEach((n) => n.remove())
        ;[...totals.childNodes].forEach((n) => foot.insertBefore(doc.importNode(n, true), hints))
    }

    return true
}

/**
 * শোনা শুরু।
 *
 * @param {Document} doc
 * @param {{ fetcher?: typeof fetch, go?: (url: string) => void, after?: () => void, searched?: (box: HTMLInputElement, q: string) => void }} options
 */
export function listSwap (doc = document, options = {}) {
    const win = doc.defaultView
    const fetcher = options.fetcher ?? win.fetch.bind(win)
    const go = options.go ?? ((url) => win.location.assign(url))
    const after = options.after ?? (() => {})
    const searched = options.searched ?? ((box, q) => { box.dataset.liveSearch = q })

    let controller = null
    let started = false

    const load = async (url, { push = true } = {}) => {
        controller?.abort()
        const mine = new AbortController()
        controller = mine

        const active = doc.activeElement
        const caret = active && 'selectionStart' in active ? [active.selectionStart, active.selectionEnd] : null

        doc.documentElement.setAttribute('aria-busy', 'true')

        try {
            const response = await fetcher(url, {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: mine.signal,
                headers: { [PART_HEADER]: PART_VALUE, Accept: 'text/html' },
            })

            // ⛔ লগইন ফুরিয়েছে (৪০১), টোকেন পুরনো (৪১৯), ত্রুটি, বা অন্য ঠিকানায় গেল — পুরো পাতা, যা আগে হত
            if (!response.ok || response.redirected) return go(url)

            const fresh = new DOMParser().parseFromString(await response.text(), 'text/html')
            if (mine !== controller) return
            if (!fresh.querySelector(FRAGMENT) || !placeParts(doc, fresh)) return go(url)

            if (push) {
                if (!started) win.history.replaceState({ ...(win.history.state ?? {}), abosList: true }, '', win.location.href)
                win.history.pushState({ abosList: true }, '', url)
            }
            started = true

            const title = fresh.querySelector(`${FRAGMENT} > title`)?.textContent
            if (title) doc.title = title

            const q = new URL(url, win.location.href).searchParams.get('q') ?? ''
            for (const box of doc.querySelectorAll(`main#main ${SEARCH_BOX}`)) {
                if (!push && box !== doc.activeElement) box.value = q
                searched(box, q.trim())
            }

            // ⓘ ঘরটা বদলায় না, তবু কিছু ফোকাস সরালে ফেরত — লেখার মাঝখানে কার্সর যেখানে ছিল
            if (active && active.isConnected && doc.activeElement !== active) {
                active.focus({ preventScroll: true })
                if (caret) {
                    try { active.setSelectionRange(caret[0], caret[1]) } catch { /* কিছু ঘর নির্বাচন মানে না */ }
                }
            }

            after()
        } catch (error) {
            if (error?.name === 'AbortError') return
            go(url)
        } finally {
            if (mine === controller) {
                controller = null
                doc.documentElement.removeAttribute('aria-busy')
            }
        }
    }

    doc.addEventListener('submit', (event) => {
        const form = event.target
        if (event.defaultPrevented || !swapsForm(form, doc)) return

        // ⓘ নামওয়ালা বোতাম (ঘনত্ব, দৃশ্য) টুলবারের নিজের অবস্থা বদলায় — ওটা আগের মতো পুরো পাতায়
        if (event.submitter?.getAttribute?.('name')) return

        event.preventDefault()
        load(formUrl(form, event.submitter ?? null, win.location.href))
    })

    doc.addEventListener('click', (event) => {
        const link = event.target?.closest?.('a[href]')
        if (!swapsLink(link, event, doc)) return

        event.preventDefault()
        load(new URL(link.href, win.location.href).toString())
    })

    win.addEventListener('popstate', (event) => {
        if (!started && !event.state?.abosList) return
        load(win.location.href, { push: false })
    })

    return { load }
}
