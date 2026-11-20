/*
 * ⭐ রোল ও অনুমতির পাতা — মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬ ("ok"; [[role/form.blade.php]])।
 *
 *   · রোলের ট্যাব (অনুমতি · অনুমোদনের ক্ষমতা · সদস্য · কে আসলে কী পারে · বদলের ইতিহাস) — পাতা না বদলে, তাই না-জমা টিক হারায় না
 *   · মডিউলের ভাঁজ — একটা খুললে আগেরটা নিজে বন্ধ (ব্রাউজারের নিজের `<details name>`-এর সাথে, পুরনো ব্রাউজারের জন্যও)
 *   · শুরুতেই আংশিক "সব" আর "পুরো কলাম" মাঝামাঝি — `indeterminate` HTML-এ লেখা যায় না
 *   · নিচের পট্টিতে "● n টা ঘর বদলেছে", আর সংরক্ষণের আগে বদলের সারাংশ ও কারণ (ইতিহাসে থাকে)
 *
 * ⓘ সবই delegated শ্রোতা, `document`-এ — CSP-তে ইনলাইন হ্যান্ডলার চলে না ([[actions.js]]-এর একই কারণ)।
 * ⚠️ জমার শ্রোতা [[one-submit.js]]-এর আগে বসে (app.js-এ আগে ডাকা) — থামানো জমাকে সে "জমা হয়েছে" ধরে না।
 */
import { countPermissions } from './actions.js'

const FORM = 'form[data-role-form]'
const CELL = 'input[name="permissions[]"]'

export function showRoleTab (screen, key) {
    const tabs = [...screen.querySelectorAll('[data-role-tab]')]

    if (! tabs.some((t) => t.dataset.roleTab === key)) {
        return false
    }

    for (const tab of tabs) {
        const on = tab.dataset.roleTab === key
        tab.classList.toggle('is-on', on)
        tab.setAttribute('aria-selected', on ? 'true' : 'false')
    }

    for (const panel of screen.querySelectorAll('[data-role-panel]')) {
        panel.hidden = panel.dataset.rolePanel !== key
    }

    return true
}

/* ⓘ বদলানো ঘর — শুরুর অবস্থা (`defaultChecked`) থেকে আলাদা যেগুলো; নামের ঘরও */
export function changedCells (form) {
    const changes = []

    for (const box of form.querySelectorAll(CELL)) {
        if (box.checked !== box.defaultChecked) {
            changes.push({ label: box.dataset.cellLabel || box.value, on: box.checked })
        }
    }

    const name = form.querySelector('input[name="name"]')

    if (name && name.value !== name.defaultValue) {
        changes.push({ label: `${name.defaultValue} → ${name.value}`, rename: true })
    }

    return changes
}

function showDirty (form) {
    const dirty = form.querySelector('[data-role-dirty]')

    if (! dirty) {
        return
    }

    const n = changedCells(form).length
    dirty.hidden = n === 0
    dirty.textContent = (dirty.dataset.label ?? ':count').replace(':count', String(n))
}

/* ⭐ একসাথে একটাই খোলা — একই দলের (`data-accordion`) বাকিগুলো বন্ধ */
export function keepOneOpen (opened, root = document) {
    const group = opened?.dataset?.accordion

    if (! opened?.open || group === undefined) {
        return
    }

    for (const other of root.querySelectorAll(`details[data-accordion="${group}"]`)) {
        if (other !== opened && other.open) {
            other.open = false
        }
    }
}

export function wireRoleScreen (root = document) {
    for (const module of root.querySelectorAll('[data-permission-module]')) {
        countPermissions(module)
    }

    const screen = root.querySelector?.('[data-role-screen]')

    if (screen?.dataset.roleTabStart) {
        showRoleTab(screen, screen.dataset.roleTabStart)
    }

    root.addEventListener('click', (event) => {
        const tab = event.target?.closest?.('[data-role-tab]')

        if (tab) {
            showRoleTab(tab.closest('[data-role-screen]') ?? root, tab.dataset.roleTab)
        }
    })

    // ⓘ `toggle` ঘটনা বুদবুদ হয় না — তাই ধরার ধাপে (capture) শোনা
    root.addEventListener('toggle', (event) => keepOneOpen(event.target, root), true)

    root.addEventListener('change', (event) => {
        const form = event.target?.closest?.(FORM)

        if (form) {
            showDirty(form)
        }
    })

    root.addEventListener('input', (event) => {
        if (event.target?.matches?.('input[name="name"]')) {
            const form = event.target.closest(FORM)

            if (form) {
                showDirty(form)
            }
        }
    })

    // ⓘ "সব বাছুন" / "সব মুছুন" ক্লিকে ঘর বদলায়, change ঘটনা আসে না — তাই ক্লিকের পরেও গোনা
    root.addEventListener('click', (event) => {
        const button = event.target?.closest?.('[data-permission-bulk]')
        const form = button?.closest(FORM)

        if (form) {
            showDirty(form)
        }
    })

    /*
     * ⭐ সংরক্ষণের আগে সারাংশ আর কারণ — দফা ৭। ⓘ কিছু না বদলালে প্রশ্ন নেই, সরাসরি জমা। ⓘ সংলাপের "সংরক্ষণ" নিজেই জমার বোতাম,
     *   আর কারণের ঘর তখন বাধ্যতামূলক — ব্রাউজার নিজেই খালি জমা থামায়; সার্ভারও আবার দেখে।
     */
    root.addEventListener('submit', (event) => {
        const form = event.target

        if (! form?.matches?.(`${FORM}[data-role-reason-needed]`)) {
            return
        }

        const dialog = form.querySelector('[data-role-reason]')
        const text = form.querySelector('[data-role-reason-text]')

        if (! dialog || event.submitter?.matches?.('[data-role-reason-ok]')) {
            return
        }

        const changes = changedCells(form)

        if (changes.length === 0) {
            return
        }

        event.preventDefault()

        const list = form.querySelector('[data-role-reason-list]')

        if (list) {
            list.replaceChildren(...changes.map((c) => {
                const li = form.ownerDocument.createElement('li')
                const word = c.rename ? list.dataset.rename : (c.on ? list.dataset.add : list.dataset.remove)
                li.textContent = `${word ?? ''}: ${c.label}`

                return li
            }))
        }

        if (text) {
            text.required = true
        }

        if (typeof dialog.showModal === 'function') {
            dialog.showModal()
        } else {
            dialog.setAttribute('open', '')
        }

        text?.focus?.()
    })

    root.addEventListener('click', (event) => {
        const cancel = event.target?.closest?.('[data-role-reason-cancel]')

        if (! cancel) {
            return
        }

        const dialog = cancel.closest('[data-role-reason]')
        const text = dialog?.querySelector('[data-role-reason-text]')

        if (text) {
            text.required = false
        }

        if (typeof dialog?.close === 'function') {
            dialog.close()
        } else {
            dialog?.removeAttribute('open')
        }
    })
}
