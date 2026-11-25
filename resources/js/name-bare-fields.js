/*
 * নামহীন ঘরের নাম — ছকের ভিতরের ঘর (নোট গোনা, লক্ষ্য, হাজিরা, খরচের ভাগ) চোখে কলাম আর সারি দেখে বোঝা যায়, পর্দা-পাঠক কেবল "সম্পাদনা"
 * বলত (মালিকের অডিট, ২৫ নভেম্বর ২০২৬; WCAG 2.2 SC 4.1.2)। এখন নাম = কলামের শিরোনাম + সারির প্রথম লেখা; ছকের বাইরে হলে আগের লেখা।
 *
 * ⓘ যে ঘরের নাম আগে থেকেই আছে (label, aria-label, title, placeholder) সেটা ছোঁয় না। চোখে কিছু বদলায় না।
 */
const FIELDS = 'main input:not([type=hidden]):not([type=submit]):not([type=button]), main select, main textarea'

const text = (el) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim()

export function hasName (field) {
    return (field.labels?.length ?? 0) > 0 && text(field.labels[0]) !== ''
        || ['aria-label', 'aria-labelledby', 'title', 'placeholder'].some((a) => (field.getAttribute(a) ?? '') !== '')
}

export function nameOf (field) {
    const cell = field.closest('td, th')

    if (cell) {
        const table = cell.closest('table')
        const heads = table ? [...table.querySelectorAll(':scope > thead > tr')] : []
        const cells = [...cell.parentElement.children]
        const head = heads[heads.length - 1]?.children[cells.indexOf(cell)]
        const first = cells.find((c) => c !== cell && text(c) !== '' && ! c.querySelector('select, textarea'))

        return [text(head) || cell.dataset?.label, text(first)].filter(Boolean).join(' — ').slice(0, 120)
    }

    for (let el = field; el && el.tagName !== 'MAIN' && el.tagName !== 'FORM'; el = el.parentElement) {
        const before = el.previousElementSibling
        const said = before && ! before.querySelector('input, select, textarea, button') ? text(before) : ''

        if (said !== '' && said.length <= 80) {
            return said
        }
    }

    const box = field.closest('section, fieldset, [data-boxed], details')

    return text(box?.querySelector('legend, h2, h3, summary')).slice(0, 80)
}

export function nameBareFields (root = document) {
    let named = 0

    for (const field of root.querySelectorAll(FIELDS)) {
        if (hasName(field)) {
            continue
        }

        const name = nameOf(field)

        if (name) {
            field.setAttribute('aria-label', name)
            named++
        }
    }

    return named
}

/** ⓘ Alpine-এর পরে জন্মানো সারি — ফোকাস পাওয়ার মুহূর্তে */
export function installFieldNames (doc = document) {
    nameBareFields(doc)
    doc.addEventListener('focusin', (event) => {
        const field = event.target

        if (field?.matches?.(FIELDS) && ! hasName(field)) {
            const name = nameOf(field)
            name && field.setAttribute('aria-label', name)
        }
    })
}
