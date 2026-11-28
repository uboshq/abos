/**
 * ⭐ শিরোনামের ⓘ — ছুঁলে, বা Tab দিয়ে এসে Enter/Space চাপলে ছোট বাক্সে লেখা খোলে; Esc, বাইরে ছোঁয়া বা আবার চাপলে বন্ধ।
 *
 * ⛔ আগে ⓘ কেবল মাউস রাখলে (`title`) লেখা দেখাত — ফোনে আঙুলে কিছু হত না, Tab-এ ⓘ-তে যাওয়াই যেত না
 *    (impeccable নিরীক্ষা ২৭ নভেম্বর ২০২৬, বিষয় ১; সমন্বয়কের "ok" ২৮ নভেম্বর, মালিকের দায়িত্ব-অর্পণে)।
 * ⓘ মান: WCAG 2.2 AA 2.1.1 (কীবোর্ড), 1.4.13 (হোভার/ফোকাসের লেখা — বন্ধ করা যায়, থাকে, ঢাকে না)।
 * ⓘ দেখতে একই, জায়গা একই — চিহ্নটা নিজেই বোতাম হয় (`role=button`, `tabindex=0`); মাউসের `title` যেমন ছিল।
 * ⛔ কেবল তিন ABOS রূপে (rose/navy/blue) — নকল রূপগুলো (SAP, Dynamic, Odoo …) হুবহু আগের মতো।
 * ⓘ চিহ্ন পরে তৈরি হলেও ধরে ([[abos-list-head.js]] foldPageNotes, তালিকা বদল) — ক্লিক আর কী-চাপ নথিতে একবার শোনা।
 */
const LOOKS = ['rose', 'navy', 'blue']
const MARKS = '[data-page-info], [data-title-note]'
const BOX_ID = 'abos-info-box'
// ⓘ একটা নথিতে একবারই শোনা — দুবার ডাকলে এক চাপে খুলে আবার বন্ধ হত
const installed = new WeakSet()

const textOf = (mark) => (mark.getAttribute('title') || mark.getAttribute('aria-label') || '').trim()

export function prepareMarks(root = document) {
    root.querySelectorAll(MARKS).forEach((mark) => {
        if (mark.hasAttribute('data-info-ready') || textOf(mark) === '') {
            return
        }
        mark.setAttribute('data-info-ready', '')
        mark.setAttribute('role', 'button')
        mark.setAttribute('tabindex', '0')
        mark.setAttribute('aria-expanded', 'false')
        if (! mark.hasAttribute('aria-label')) {
            mark.setAttribute('aria-label', textOf(mark))
        }
    })
}

export function infoTap(root = document) {
    const html = root.documentElement
    if (! html || ! LOOKS.includes(html.dataset.ui)) {
        return false
    }
    if (installed.has(root)) {
        prepareMarks(root)
        return true
    }
    installed.add(root)

    let box = null
    let owner = null

    const close = (giveFocusBack = false) => {
        if (box === null) {
            return
        }
        box.remove()
        owner.setAttribute('aria-expanded', 'false')
        owner.removeAttribute('aria-describedby')
        if (giveFocusBack) {
            owner.focus()
        }
        box = null
        owner = null
    }

    const open = (mark) => {
        close()
        box = root.createElement('div')
        box.id = BOX_ID
        box.setAttribute('role', 'tooltip')
        box.setAttribute('data-info-box', '')
        box.textContent = textOf(mark)
        root.body.append(box)

        // ⓘ চিহ্নের নিচে; পর্দার কিনারা পেরোলে ভেতরে টেনে আনা (৮px ফাঁক), নিচে জায়গা না থাকলে উপরে
        const view = root.defaultView
        const r = mark.getBoundingClientRect()
        const w = box.offsetWidth
        const h = box.offsetHeight
        const left = Math.max(8, Math.min(r.left, view.innerWidth - w - 8))
        const below = r.bottom + 6
        const top = below + h + 8 > view.innerHeight ? Math.max(8, r.top - h - 6) : below
        box.style.left = `${left}px`
        box.style.top = `${top}px`

        mark.setAttribute('aria-expanded', 'true')
        mark.setAttribute('aria-describedby', BOX_ID)
        owner = mark
    }

    const toggle = (mark) => (owner === mark ? close() : open(mark))

    prepareMarks(root)

    root.addEventListener('click', (event) => {
        const mark = event.target instanceof Element ? event.target.closest(MARKS) : null
        if (mark && textOf(mark) !== '') {
            event.preventDefault()
            prepareMarks(root)
            toggle(mark)
            return
        }
        if (box !== null && ! box.contains(event.target)) {
            close()
        }
    })

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && box !== null) {
            close(true)
            return
        }
        const mark = event.target instanceof Element ? event.target.closest(MARKS) : null
        if (mark && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault()
            toggle(mark)
        }
    })

    // ⓘ Tab দিয়ে অন্য কোথাও গেলে বাক্স বন্ধ — খোলা বাক্স অন্য ঘর ঢেকে রাখে না
    root.addEventListener('focusin', (event) => {
        if (owner !== null && event.target !== owner) {
            close()
        }
    })

    // ⓘ পরে আসা চিহ্নও Tab-এ ধরা পড়ুক — পাতা সাজানো শেষ হলে আরেকবার
    root.defaultView.addEventListener('load', () => prepareMarks(root))
    // ⓘ তালিকা বদলালে ([[list-swap.js]] অংশ বদলে দেয়, কোনো ঘটনা ছাড়ে না) — এক ফ্রেমে একবার খোঁজা
    const main = root.querySelector('main') ?? root.body
    let queued = false
    new root.defaultView.MutationObserver(() => {
        if (queued) {
            return
        }
        queued = true
        root.defaultView.requestAnimationFrame(() => { queued = false; prepareMarks(root) })
    }).observe(main, { childList: true, subtree: true })

    return true
}
