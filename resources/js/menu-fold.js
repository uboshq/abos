/*
 * ⭐ বাঁয়ের মেনুর দল ভাঁজ করা — মালিক, ২০ নভেম্বর ২০২৬ (D365/SAP/Oracle-এর বাঁ নেভিগেশন: মডিউল → দল → পাতা)।
 *   · দলের মাথা একটা বোতাম: দলের নিজের রঙের টাইলে চিহ্ন + নাম, ডানে তীর › (মালিক, ২১ নভেম্বর ২০২৬, নমুনা "বাঁয়ের মেনু — ভাঁজ")
 *   · পাতা খুললে সব দল বন্ধ, কেবল যে দলে এই পাতা সেটা খোলা
 *   · ⭐ একবারে একটাই দল খোলা — অন্যটা খুললে আগেরটা বন্ধ; খোলাটা চাপলে বন্ধ (মালিক, ২১ নভেম্বর ২০২৬)
 *   · কোন দল শেষবার খোলা ছিল তা মডিউল ধরে মনে থাকে (localStorage — না পেলেও পাতা চলে); এই পাতার দল থাকলে সেটাই খোলে
 *   · Enter/Space বোতাম নিজেই চাপে; `aria-expanded` মাথায়, বন্ধ দলের সারিতে `hidden`
 * ⓘ কেবল তিন ABOS রূপে — "ABOS" (rose), ABOS Navy, ABOS Blue (মালিক, ২০ নভেম্বর ২০২৬); বাকি রূপে বোতামই আঁকা হয় না
 *   ([[shell.sidebar]], [[Ui::foldsMenuGroups()]]), আর এখানেও রূপ দেখে থামে।
 * ⓘ সার্ভার প্রথম অবস্থাটা নিজেই আঁকে (এই পাতার দল খোলা, বাকি `hidden`) — এই ফাইল কেবল মনে রাখা দলগুলো খোলে আর চাপ সামলায়।
 */

export const FOLD_LOOKS = ['rose', 'navy', 'blue']

export const STORE_PREFIX = 'abos.menuFold.'

/** localStorage, না পেলে null — ব্যক্তিগত জানালা বা বন্ধ সাইট-ডাটায় ছুঁড়তে পারে */
function defaultStore () {
    try {
        return window.localStorage ?? null
    } catch (e) {
        return null
    }
}

export function readSaved (store, module) {
    if (! store) {
        return {}
    }

    try {
        const raw = store.getItem(STORE_PREFIX + module)
        const said = raw ? JSON.parse(raw) : {}

        return said && typeof said === 'object' && ! Array.isArray(said) ? said : {}
    } catch (e) {
        return {}
    }
}

function save (store, module, state) {
    if (! store) {
        return
    }

    try {
        store.setItem(STORE_PREFIX + module, JSON.stringify(state))
    } catch (e) {
        // ⓘ জায়গা নেই বা বন্ধ — মনে রাখা যায় না, ভাঁজ তবু চলে
    }
}

/** এক দলের মাথা আর তার সারিগুলো খোলা বা বন্ধ */
export function setOpen (nav, head, open) {
    head.setAttribute('aria-expanded', open ? 'true' : 'false')

    nav.querySelectorAll('[data-menu-fold-row]').forEach(row => {
        if (row.dataset.menuFoldRow === head.dataset.menuFold) {
            row.hidden = ! open
        }
    })
}

/** খোঁজার ঘরে কিছু লেখা থাকলে সব সারি খোলা — নইলে বন্ধ দলের মিল খুঁজে পাওয়া যেত না */
function showAllWhileFiltering (nav, heads, store, module) {
    const panel = nav.closest('[data-site-map]') ?? nav.parentElement
    const input = panel?.querySelector('input[type="search"]')

    if (! input) {
        return
    }

    input.addEventListener('input', () => {
        if (input.value.trim() !== '') {
            nav.querySelectorAll('[data-menu-fold-row]').forEach(row => { row.hidden = false })

            return
        }

        apply(nav, heads, readSaved(store, module))
    })
}

/** একটাই দল খোলা (বা কোনোটাই না) — বাকি সব বন্ধ */
export function openOnly (nav, heads, key) {
    heads.forEach(head => setOpen(nav, head, key !== null && head.dataset.menuFold === key))
}

/** কোন দল খুলবে: এই পাতার দল; না থাকলে শেষবার খোলা দল (যদি এখনো মেনুতে থাকে); নইলে কোনোটাই না */
export function groupToOpen (heads, saved) {
    const here = heads.find(head => head.hasAttribute('data-menu-fold-here'))

    if (here) {
        return here.dataset.menuFold
    }

    const remembered = heads.find(head => saved[head.dataset.menuFold] === true)

    return remembered ? remembered.dataset.menuFold : null
}

function apply (nav, heads, saved) {
    openOnly(nav, heads, groupToOpen(heads, saved))
}

/**
 * পাতার প্রতিটা ভাঁজ-করা মেনু জাগায়; কতগুলো জাগল তা ফেরায়।
 *
 * @param {Document|HTMLElement} root
 * @param {{ store?: Storage|null, ui?: string }} options
 */
export function foldMenuGroups (root = document, { store = defaultStore(), ui } = {}) {
    const look = ui ?? (root.ownerDocument ?? root).documentElement?.dataset.ui

    if (! FOLD_LOOKS.includes(look)) {
        return 0
    }

    let woke = 0

    root.querySelectorAll('[data-menu-fold-nav]').forEach(nav => {
        if (nav.dataset.menuFoldWoke === '1') {
            return
        }

        nav.dataset.menuFoldWoke = '1'
        woke++

        const module = nav.dataset.menuFoldNav
        const heads = [...nav.querySelectorAll('[data-menu-fold]')]

        apply(nav, heads, readSaved(store, module))

        nav.addEventListener('click', event => {
            const head = event.target.closest?.('[data-menu-fold]')

            if (! head || ! nav.contains(head)) {
                return
            }

            // ⭐ একবারে একটাই দল — খোলাটা চাপলে সব বন্ধ, অন্যটা চাপলে কেবল সেটা খোলা
            const open = head.getAttribute('aria-expanded') !== 'true'
            openOnly(nav, heads, open ? head.dataset.menuFold : null)

            save(store, module, open ? { [head.dataset.menuFold]: true } : {})
        })

        showAllWhileFiltering(nav, heads, store, module)
    })

    return woke
}
