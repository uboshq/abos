/*
 * ⭐ সারির কাজ কেবল একটা ছোট ⋯ — যে তালিকা বোতাম, কারণের ঘর বা লিংক সারির ভিতরেই আঁকত, তারও (মালিকের নিয়ম, ১৬ নভেম্বর ২০২৬;
 *   অডিট, ২৫ নভেম্বর ২০২৬)। ঘরের জিনিসগুলো যেমন ছিল তেমনই একটা ভাসমান তালিকায় সরে — ফর্ম, টোকেন, ঠিকানা কিছুই বদলায় না।
 *
 * ⓘ কেবল তিন ABOS রূপে। ⛔ যে ছক নিজেই একটা ফর্মের ভিতরে (সারি-বসানোর ছক) তার বোতাম ছোঁয়া হয় না; যে সারিতে আগে থেকেই ⋯ আছে তাও নয়।
 */
import { ABOS_HEAD_LOOKS } from './abos-list-head.js'

const DOTS = '<svg viewBox="0 0 24 24" aria-hidden="true" class="size-5 shrink-0 fill-current"><path d="M6 14a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm6 0a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm6 0a2 2 0 1 1 0-4 2 2 0 0 1 0 4Z"/></svg>'

const ACTS = 'a[href], button'

/** ⓘ কাজের কলাম = `data-row-menu` মাথা ([[x-ui.table]]), অথবা শেষ কলাম যার মাথায় নাম নেই */
function actsColumn (table) {
    const head = table.tHead?.rows[table.tHead.rows.length - 1]

    if (! head) {
        return -1
    }

    const last = head.cells.length - 1
    const marked = [...head.cells].findIndex((c) => c.hasAttribute('data-row-menu'))

    if (marked >= 0) {
        return marked
    }

    return /^[\s—-]*$|^(কাজ|অ্যাকশন|actions?)$/i.test((head.cells[last]?.textContent ?? '').trim()) ? last : -1
}

function worthFolding (cell) {
    // ⓘ সারিতেই লেখার ঘর থাকলে (নম্বর সিরিজ) "সংরক্ষণ" তার পাশেই থাকে
    if (! cell || [...cell.parentElement.cells].some((c) => c !== cell && c.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea'))) {
        return false
    }

    if ( cell.querySelector('[x-data="rowActions"], [role=menu], [role=dialog], [data-folded-acts], svg[data-chart], [data-keep-acts]')) {
        return false
    }

    // ⓘ কেবল একটা চিহ্ন ("+" — হিসাবের গাছে নিচে যোগ) গোটানো নয়, ওটা এমনিতেই ছোট
    if ([...cell.querySelectorAll(ACTS)].every((a) => a.textContent.trim().length <= 1)) {
        return false
    }

    // ⓘ ছাঁকনি-টিক বা সংখ্যার ঘর (হাতে লেখা) কাজ নয় — যে ফর্ম ঘরের বাইরে, তার ঘর এখানে থাকলে কিছু নয়
    return [...cell.querySelectorAll('input:not([type=hidden]), select, textarea')].every((f) => cell.contains(f.closest('form')))
}

/** ⓘ বোতামের ঠিক নিচে, ডান ধার মিলিয়ে; নিচে জায়গা না থাকলে উপরে — চওড়ায় পর্দার ভেতরে */
export function spot (button, menu, view) {
    const top = button.bottom + 4 + menu.height > view.height ? Math.max(8, button.top - 4 - menu.height) : button.bottom + 4

    return { top, left: Math.max(8, Math.min(button.right - menu.width, view.width - menu.width - 8)) }
}

export function foldRowActs (root = document) {
    if (! ABOS_HEAD_LOOKS.includes(root.documentElement?.dataset.ui)) {
        return 0
    }

    let folded = 0

    for (const table of root.querySelectorAll('main table')) {
        if (table.closest('form, dialog, [data-keep-acts]')) {
            continue
        }

        const at = actsColumn(table)
        let here = 0

        if (at < 0) {
            continue
        }

        for (const body of table.tBodies) {
            for (const row of body.rows) {
                const cell = row.cells.length === table.tHead.rows[table.tHead.rows.length - 1].cells.length ? row.cells[at] : null

                if (worthFolding(cell)) {
                    fold(cell)
                    here++
                }
            }
        }

        const head = table.tHead.rows[table.tHead.rows.length - 1].cells[at]

        folded += here

        if (here) {
            head.textContent = root.documentElement.lang?.startsWith('bn') ? 'অ্যাকশন' : 'Action'
            head.classList.remove('text-start')
            head.classList.add('text-end')
        }
    }

    return folded
}

function fold (cell) {
    const doc = cell.ownerDocument
    const menu = doc.createElement('div')
    const button = doc.createElement('button')
    const name = doc.documentElement.lang?.startsWith('bn') ? 'অ্যাকশন' : 'Action'

    menu.setAttribute('popover', 'auto')
    menu.setAttribute('data-folded-acts', '')
    menu.append(...cell.childNodes)

    button.type = 'button'
    button.title = name
    button.setAttribute('aria-label', name)
    button.setAttribute('aria-haspopup', 'true')
    button.setAttribute('aria-expanded', 'false')
    button.setAttribute('data-folded-acts-button', '')
    button.innerHTML = DOTS

    button.addEventListener('click', (event) => {
        // ⓘ সারির নিজের চাপ (দেখুন) যেন সাথে না খোলে
        event.stopPropagation()
        menu.togglePopover()
    })
    menu.addEventListener('click', (event) => {
        // ⓘ ঘরে লিখলে সারির চাপ নয়; বোতাম বা লিংকে চাপলে তালিকা বন্ধ, কাজটা আগের পথেই চলে
        if (event.target.closest('input, select, textarea, label')) {
            event.stopPropagation()
        } else if (event.target.closest('a[href], button:not([type=submit]), button[type=submit]:not(form *)')) {
            menu.hidePopover()
        }
    })
    menu.addEventListener('toggle', (event) => {
        const open = event.newState === 'open'
        button.setAttribute('aria-expanded', open ? 'true' : 'false')

        if (open) {
            const place = spot(button.getBoundingClientRect(), menu.getBoundingClientRect(), { width: innerWidth, height: innerHeight })
            menu.style.top = `${place.top}px`
            menu.style.left = `${place.left}px`
        }
    })
    addEventListener('scroll', (event) => {
        if (menu.matches(':popover-open') && ! menu.contains(event.target)) {
            menu.hidePopover()
        }
    }, { capture: true, passive: true })

    cell.classList.add('text-end')
    cell.append(button, menu)
}
