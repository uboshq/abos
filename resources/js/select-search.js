/*
 * ⭐ লম্বা বাছাইয়ের তালিকায় খোঁজা — পণ্য, পক্ষ, হিসাব (মালিকের অডিট, ২৫ নভেম্বর ২০২৬: ক্রয়/বিক্রয় আদেশ, উদ্ধৃতি আর ফেরতে পণ্য বাছাইয়ে
 *   খোঁজার ঘর ছিল না, ছয়শো পণ্যে গড়িয়ে খুঁজতে হত)।
 *
 * ⓘ আসল `<select>` যেখানে ছিল সেখানেই — নাম, মান, `x-model`, জমা, পাঠক সব আগের মতো; কেবল খুললে ব্রাউজারের তালিকার বদলে খোঁজার ঘরসহ
 *   আমাদের তালিকা, আর বাছলে সেই `<select>`-এই মান বসে, `input` আর `change` ছোঁড়ে।
 * ⓘ কীবোর্ড: Enter / Space / ↓ খোলে, লিখলেই খোঁজে, ↑ ↓ নড়ে, Enter বাছে, Esc বন্ধ, ফোকাস আবার ঘরে। Tab আগের মতোই পরের ঘরে যায়।
 * ⛔ কেবল তিন ABOS রূপে; ছোট তালিকা ([[MIN]]-এর কম), একাধিক-বাছাই, ছাঁকনির প্যানেল, সাজানো আর পাতার-সারির বাছাই ছোঁয় না।
 *   `data-no-search` দিলেও নয়।
 */
import { ABOS_HEAD_LOOKS } from './abos-list-head.js'

export const MIN = 12

const SKIP = '[data-no-search], [data-filter-panel] select, [data-pager] select, .toolbar-view select, [data-head-filter] select, select[multiple], select[size]'

export function searches (select) {
    return select instanceof Object && select.tagName === 'SELECT' && ! select.disabled && ! select.matches(SKIP)
        && select.closest('main, dialog') !== null && select.options.length >= MIN
}

const plain = (text) => (text ?? '').toLowerCase().normalize('NFC').replace(/[০-৯]/g, (d) => String('০১২৩৪৫৬৭৮৯'.indexOf(d))).replace(/\s+/g, ' ').trim()

/** প্রতিটা শব্দ যেকোনো ক্রমে মিললেই — "তেল ৫" → "৫ লিটার তেল" */
export function matches (text, term) {
    const hay = plain(text)

    return plain(term).split(' ').filter(Boolean).every((word) => hay.includes(word))
}

export function installSelectSearch (doc = document) {
    if (! ABOS_HEAD_LOOKS.includes(doc.documentElement?.dataset.ui)) {
        return null
    }

    const bn = doc.documentElement.lang === 'bn'
    const words = bn ? { find: 'খুঁজুন', none: 'কিছু মিলল না' } : { find: 'Search', none: 'Nothing matches' }
    let box = null
    let owner = null
    let shown = []
    let at = -1

    const close = (refocus = true) => {
        if (! box) {
            return
        }

        box.remove()
        owner.removeAttribute('aria-expanded')
        const back = owner
        box = null
        owner = null

        if (refocus) {
            back.focus({ preventScroll: true })
        }
    }

    const choose = (option) => {
        const select = owner

        close()

        if (! option || select.value === option.value) {
            return
        }

        select.value = option.value
        select.dispatchEvent(new Event('input', { bubbles: true }))
        select.dispatchEvent(new Event('change', { bubbles: true }))
    }

    const mark = (index) => {
        at = Math.max(0, Math.min(index, shown.length - 1))
        const list = box.querySelector('[role=listbox]')
        ;[...list.children].forEach((row, i) => row.setAttribute('aria-selected', String(i === at)))
        const row = list.children[at]
        row?.scrollIntoView?.({ block: 'nearest' })
        box.querySelector('input').setAttribute('aria-activedescendant', row?.id ?? '')
    }

    const paint = (term) => {
        const list = box.querySelector('[role=listbox]')
        shown = [...owner.options].filter((o) => ! o.disabled && ! o.hidden && matches(o.textContent, term)).slice(0, 300)
        list.replaceChildren(...shown.map((option, i) => {
            const row = doc.createElement('div')
            row.setAttribute('role', 'option')
            row.id = `select-search-${i}`
            row.className = 'select-search-row'
            row.textContent = option.textContent.trim() || '—'
            row.addEventListener('pointerdown', (event) => { event.preventDefault(); choose(option) })

            return row
        }))

        if (shown.length === 0) {
            const none = doc.createElement('div')
            none.className = 'select-search-none'
            none.textContent = words.none
            list.replaceChildren(none)
            at = -1

            return
        }

        const now = shown.findIndex((o) => o.value === owner.value)
        mark(term === '' && now >= 0 ? now : 0)
    }

    const place = () => {
        const rect = owner.getBoundingClientRect()
        const view = doc.defaultView
        const width = Math.min(Math.max(rect.width, 280), view.innerWidth - 16)
        const below = view.innerHeight - rect.bottom
        const up = below < 260 && rect.top > below
        box.style.width = `${width}px`
        box.style.left = `${Math.max(8, Math.min(rect.left, view.innerWidth - width - 8))}px`
        box.style.top = up ? 'auto' : `${rect.bottom + 4}px`
        box.style.bottom = up ? `${view.innerHeight - rect.top + 4}px` : 'auto'
        box.style.maxHeight = `${Math.max(180, Math.min(360, (up ? rect.top : below) - 12))}px`
    }

    const open = (select, first = '') => {
        close(false)
        owner = select
        box = doc.createElement('div')
        box.className = 'select-search'
        box.setAttribute('data-select-search', '')
        const input = doc.createElement('input')
        input.type = 'search'
        input.autocomplete = 'off'
        input.placeholder = words.find
        input.setAttribute('role', 'combobox')
        input.setAttribute('aria-expanded', 'true')
        input.setAttribute('aria-controls', 'select-search-list')
        input.setAttribute('aria-label', words.find)
        const list = doc.createElement('div')
        list.id = 'select-search-list'
        list.setAttribute('role', 'listbox')
        box.append(input, list)
        // ⓘ খোলা `<dialog>`-এর ভিতরের ঘর হলে তালিকাও তার ভিতরে — নইলে পর্দার উপরের স্তরে ঢাকা পড়ত
        ;(select.closest('dialog[open]') ?? doc.body).append(box)
        select.setAttribute('aria-expanded', 'true')
        place()
        input.value = first
        paint(first)
        input.focus({ preventScroll: true })

        input.addEventListener('input', () => paint(input.value))
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown') { event.preventDefault(); mark(at + 1) }
            else if (event.key === 'ArrowUp') { event.preventDefault(); mark(at - 1) }
            else if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); choose(shown[at]) }
            else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close() }
            else if (event.key === 'Tab') { close() }
        })
    }

    doc.addEventListener('mousedown', (event) => {
        const select = event.target?.closest?.('select')

        if (box && ! box.contains(event.target) && event.target !== owner) {
            close(false)
        }

        if (select && event.button === 0 && searches(select)) {
            event.preventDefault()
            owner === select ? close() : open(select)
        }
    })

    doc.addEventListener('keydown', (event) => {
        const select = event.target

        if (event.defaultPrevented || event.ctrlKey || event.metaKey || ! searches(select)) {
            return
        }

        if (['Enter', ' ', 'ArrowDown', 'F4'].includes(event.key)) {
            event.preventDefault()
            open(select)
        } else if (event.key.length === 1 && ! event.altKey) {
            event.preventDefault()
            open(select, event.key)
        }
    })

    doc.defaultView.addEventListener('resize', () => close(false))
    doc.addEventListener('scroll', (event) => { if (box && ! box.contains(event.target)) place() }, true)

    return { open, close }
}
