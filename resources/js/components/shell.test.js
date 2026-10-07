// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'
import { registerComponents } from './index.js'
import { wireActions } from './actions.js'

/*
 * খোঁজার প্যালেট — Ctrl+K (A-06) আর তীর/↵ (A-07), ২৭ সেপ্টেম্বর ২০২৬।
 *
 * ── ⭐ কেন আসল ব্লেডটাই পড়া হয় ───────────────────────────────────────
 * ⛔ হাতে লেখা একটা নকল HTML-এ পরীক্ষা করলে দাবিটা নকলটাকেই মাপত — আর
 * ব্লেডে একটা `@keydown` হারালেও সবুজ থাকত। ⓘ তাই এখানে
 * `command-center.blade.php`-টাই পড়ে, কেবল Blade-এর অংশগুলো (মন্তব্য,
 * `{{ }}`, `<x-ui.icon>`) সরিয়ে আসল CSP-Alpine-এ বসানো হয়।
 */

// ⓘ happy-dom-এ পরীক্ষার নিজের `import.meta.url` file: নয় — তাই প্রকল্পের মূল থেকে
const BLADE = join(process.cwd(), 'resources/views/components/shell/command-center.blade.php')

function palette () {
    return readFileSync(BLADE, 'utf8')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/\{\{\s*route\('search'\)\s*\}\}/g, '/search')
        .replace(/\{\{\s*__\('([^']+)'\)\s*\}\}/g, '$1')
        .replace(/<x-ui\.icon[^>]*\/>/g, '')
}

const warnings = []

beforeAll(() => {
    Alpine.setErrorHandler((error, el, expression) => {
        warnings.push(`Alpine Expression Error: ${error?.message} — ${expression}`)
    })

    const warn = console.warn
    console.warn = (...args) => {
        warnings.push(args.join(' '))
        warn(...args)
    }

    window.Alpine = Alpine
    registerComponents(Alpine)
    Alpine.start()
})

let host = null

afterEach(async () => {
    host?.remove()
    host = null
    await Alpine.nextTick()
    vi.restoreAllMocks()
})

async function mount (extra = '') {
    warnings.length = 0
    host = document.createElement('div')
    host.innerHTML = extra + palette()
    document.body.appendChild(host)
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()

    return host
}

const dialog = () => host.querySelector('[role="dialog"]')
const box = () => dialog().querySelector('input')
const isOpen = () => Alpine.$data(dialog()).open === true
const tick = async () => {
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()
}

function press (target, key, options = {}) {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...options })

    target.dispatchEvent(event)

    return event
}

/** খুঁজে ফল আনা — সার্ভারের বদলে একটা নকল `fetch` */
async function search (q, hits) {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({ json: async () => ({ hits }) })

    box().value = q
    box().dispatchEvent(new Event('input', { bubbles: true }))

    // ⓘ কম্পোনেন্ট ২০০ms থামে (shell.js-এর `ask()`)
    await new Promise(r => setTimeout(r, 260))
    await tick()
}

const HITS = [
    { url: '/sales/1', type: 'বিক্রি', no: 'SO-1', label: 'এক' },
    { url: '/sales/2', type: 'বিক্রি', no: 'SO-2', label: 'দুই' },
    { url: '/sales/3', type: 'বিক্রি', no: 'SO-3', label: 'তিন' },
]

const rows = () => [...dialog().querySelectorAll('a[href]')]
const selected = () => rows().map(a => a.getAttribute('aria-selected'))

describe('A-06 — Ctrl+K প্রতিটা পাতায় প্যালেট খোলে', () => {
    it('Ctrl+K: প্যালেট খোলে, ঘরে কার্সর, আর ব্রাউজারের নিজেরটা আটকায়', async () => {
        await mount()
        expect(isOpen()).toBe(false)

        const event = press(document.body, 'k', { ctrlKey: true })
        await tick()

        expect(isOpen()).toBe(true)
        expect(event.defaultPrevented).toBe(true)
        expect(document.activeElement).toBe(box())
        expect(warnings).toEqual([])
    })

    it('⌘K (ম্যাক) আর Caps Lock-এর K-ও', async () => {
        await mount()

        press(document.body, 'k', { metaKey: true })
        await tick()
        expect(isOpen()).toBe(true)

        press(box(), 'Escape')
        await tick()
        expect(isOpen()).toBe(false)

        press(document.body, 'K', { ctrlKey: true })
        await tick()
        expect(isOpen()).toBe(true)
    })

    /*
     * ⚠️ বাংলা কীবোর্ড লেআউটে `event.key` "k" নয়, একটা বাংলা অক্ষর — কিন্তু
     * চাবিটা একই জায়গায় (`code === 'KeyK'`)। ⓘ নাহলে বাংলায় টাইপ করা
     * মানুষের জন্য শর্টকাটটা নীরবে মরত।
     */
    it('বাংলা লেআউটেও — চাবির জায়গা দেখে', async () => {
        await mount()

        press(document.body, 'ক', { ctrlKey: true, code: 'KeyK' })
        await tick()

        expect(isOpen()).toBe(true)
    })

    it('লেখার ঘরে থেকেও Ctrl+K চলে', async () => {
        await mount('<input id="other">')
        const other = host.querySelector('#other')
        other.focus()

        press(other, 'k', { ctrlKey: true })
        await tick()

        expect(isOpen()).toBe(true)
    })

    it('⛔ কেবল k নয়, Ctrl+Shift+K নয় (ফায়ারফক্সের কনসোল), Ctrl+Alt+K নয়', async () => {
        await mount()

        const events = [
            press(document.body, 'k'),
            press(document.body, 'K', { ctrlKey: true, shiftKey: true }),
            press(document.body, 'k', { ctrlKey: true, altKey: true }),
        ]
        await tick()

        expect(isOpen()).toBe(false)
        expect(events.map(e => e.defaultPrevented)).toEqual([false, false, false])
    })

    /*
     * ⭐ রোলের পাতায় Ctrl+K রোল খোঁজে — মালিকের স্পেক §২.৯।
     *
     * ⓘ ওই শ্রোতা বসে `document`-এ (`actions.js`), প্যালেটেরটা `window`-এ,
     * তাই ঘটনা আগে রোলের কাছে পৌঁছায়। সে ঘর পেলে `preventDefault()` করে,
     * আর প্যালেট সেটা দেখে সরে দাঁড়ায়। ⚠️ এখানে `wireActions` বসে পাতার
     * মোড়কে — `document`-এর মতোই সে `window`-এর আগে শোনে।
     */
    it('রোলের পাতায় Ctrl+K রোল খোঁজে — প্যালেট খোলে না', async () => {
        await mount('<div id="roles"><input type="search" data-shortcut="search-roles"><p id="inside">x</p></div>')
        const roles = host.querySelector('#roles')
        wireActions(roles)

        const event = press(host.querySelector('#inside'), 'k', { ctrlKey: true })
        await tick()

        expect(document.activeElement).toBe(host.querySelector('[data-shortcut="search-roles"]'))
        expect(event.defaultPrevented).toBe(true)
        expect(isOpen()).toBe(false)
    })
})

describe('A-07 — তীর আর ↵ প্যালেটের ভিতরে', () => {
    it('ফল এলে প্রথমটা বাছা, ↓ ↑ সরে আর দুই প্রান্তে থামে', async () => {
        await mount()
        press(document.body, 'k', { ctrlKey: true })
        await tick()
        await search('sale', HITS)

        expect(selected()).toEqual(['true', 'false', 'false'])
        expect(box().getAttribute('aria-activedescendant')).toBe(rows()[0].id)
        expect(rows()[0].id).not.toBe('')

        const down = press(box(), 'ArrowDown')
        await tick()
        expect(down.defaultPrevented).toBe(true)
        expect(selected()).toEqual(['false', 'true', 'false'])
        expect(box().getAttribute('aria-activedescendant')).toBe(rows()[1].id)

        for (let i = 0; i < 5; i++) press(box(), 'ArrowDown')
        await tick()
        expect(selected()).toEqual(['false', 'false', 'true'])

        for (let i = 0; i < 5; i++) press(box(), 'ArrowUp')
        await tick()
        expect(selected()).toEqual(['true', 'false', 'false'])

        expect(warnings).toEqual([])
    })

    it('↵ বাছা ফলটা খোলে', async () => {
        await mount()
        press(document.body, 'k', { ctrlKey: true })
        await tick()
        await search('sale', HITS)

        const opened = []
        dialog().addEventListener('click', (event) => {
            const link = event.target.closest('a[href]')

            if (link) {
                event.preventDefault()
                opened.push(link.getAttribute('href'))
            }
        })

        press(box(), 'ArrowDown')
        press(box(), 'ArrowDown')
        await tick()

        // ⚠️ বাংলা IME-র অক্ষর গড়ার ↵ — ফল খোলে না
        press(box(), 'Enter', { isComposing: true })
        await tick()
        expect(opened).toEqual([])

        const enter = press(box(), 'Enter')
        await tick()

        expect(opened).toEqual(['/sales/3'])
        expect(enter.defaultPrevented).toBe(true)
    })

    it('ফল না থাকলে ↵ আর তীর কিছুই করে না, ভুলও ছোঁড়ে না', async () => {
        await mount()
        press(document.body, 'k', { ctrlKey: true })
        await tick()

        press(box(), 'ArrowDown')
        press(box(), 'Enter')
        await tick()

        expect(box().getAttribute('aria-activedescendant')).toBeNull()
        expect(warnings).toEqual([])
    })

    it('নতুন খোঁজায় বাছাই আবার প্রথমটায়', async () => {
        await mount()
        press(document.body, 'k', { ctrlKey: true })
        await tick()
        await search('sale', HITS)

        press(box(), 'ArrowDown')
        press(box(), 'ArrowDown')
        await tick()

        await search('sales', HITS.slice(0, 2))

        expect(selected()).toEqual(['true', 'false'])
    })

    it('Esc প্যালেট বন্ধ করে', async () => {
        await mount()
        press(document.body, 'k', { ctrlKey: true })
        await tick()

        press(box(), 'Escape')
        await tick()

        expect(isOpen()).toBe(false)
    })

    it('ইঁদুর যে সারিতে, বাছাইও সেখানে — দুই রকম হাইলাইট নয়', async () => {
        await mount()
        press(document.body, 'k', { ctrlKey: true })
        await tick()
        await search('sale', HITS)

        rows()[2].dispatchEvent(new MouseEvent('mousemove', { bubbles: true }))
        await tick()

        expect(selected()).toEqual(['false', 'false', 'true'])
    })

    it('ফলের তালিকাটা listbox, আর ঘরটা তার combobox', async () => {
        await mount()

        const list = dialog().querySelector('[role="listbox"]')

        expect(list).not.toBeNull()
        expect(box().getAttribute('role')).toBe('combobox')
        expect(box().getAttribute('aria-controls')).toBe(list.id)
    })
})

/*
 * ⭐ খালি বাক্স — সাম্প্রতিক কাগজ আর প্রস্তাবিত কাজ, ২ অক্টোবর ২০২৬।
 *
 * ⓘ ডিজাইন-চেকলিস্ট, ধাপ ৭ · ৫। কোন কাগজ, কোন কাজ — সার্ভার বাছে, দেয়াল
 * দিয়ে ([[StartingPoints]], PHP-তে মাপা)। এখানে মাপা হয় পর্দাটা: খালি
 * বাক্সে দুইটা দল দেখা যায়, আর ↓ ↑ ↵ দুই দলের সীমানা পেরিয়েও চলে।
 */
const RECENT = [
    { url: '/accounts/vouchers/5', type: 'ভাউচার', no: 'JV-5', label: 'পাঁচ' },
    { url: '/sales/invoices/9', type: 'বিক্রয় চালান', no: 'INV-9', label: 'নয়' },
]

const ACTIONS = [
    { url: '/sales/direct', type: 'বিক্রয়', no: '', label: 'সরাসরি বিক্রয়' },
    { url: '/purchase/direct', type: 'ক্রয়', no: '', label: 'সরাসরি ক্রয়' },
]

/** খালি বাক্সের উত্তর — Ctrl+K চাপার **আগে** বসাতে হয়, কারণ খোলার সাথেই অনুরোধ যায় */
function startsWith (recent, actions) {
    return vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        json: async () => ({ hits: [], recent, actions }),
    })
}

async function openEmpty (recent = RECENT, actions = ACTIONS) {
    const fetched = startsWith(recent, actions)

    await mount()
    press(document.body, 'k', { ctrlKey: true })
    await tick()
    await tick()

    return fetched
}

const hrefs = () => rows().map(a => a.getAttribute('href'))
const visible = (el) => el !== null && el.style.display !== 'none'

describe('⭐ খালি বাক্স: সাম্প্রতিক কাগজ, তারপর প্রস্তাবিত কাজ', () => {
    it('Ctrl+K খুললেই দুইটা দল, ঠিক ক্রমে — আর অনুরোধে কোনো q নেই', async () => {
        const fetched = await openEmpty()

        expect(fetched).toHaveBeenCalled()
        expect(String(fetched.mock.calls[0][0])).not.toContain('q=')

        expect(hrefs()).toEqual([...RECENT, ...ACTIONS].map(r => r.url))

        expect(visible(dialog().querySelector('#command-recent-label').parentElement)).toBe(true)
        expect(visible(dialog().querySelector('#command-actions-label').parentElement)).toBe(true)

        /* ⛔ "খুঁজতে লিখুন" তখন নয় — দেখানোর মতো কিছু আছে */
        expect(dialog().textContent).not.toContain('core.search.type_to_find')

        /* ⓘ প্রথমটা বাছা, আর ঘর জানে কোনটা */
        expect(selected()).toEqual(['true', 'false', 'false', 'false'])
        expect(box().getAttribute('aria-activedescendant')).toBe(rows()[0].id)
        expect(warnings).toEqual([])
    })

    it('↓ সাম্প্রতিক থেকে কাজে পেরোয়, প্রান্তে থামে; ↵ বাছা কাজটা খোলে', async () => {
        await openEmpty()

        const opened = []
        dialog().addEventListener('click', (event) => {
            const link = event.target.closest('a[href]')

            if (link) {
                event.preventDefault()
                opened.push(link.getAttribute('href'))
            }
        })

        for (let i = 0; i < 2; i++) press(box(), 'ArrowDown')
        await tick()
        expect(selected()).toEqual(['false', 'false', 'true', 'false'])
        expect(box().getAttribute('aria-activedescendant')).toBe(rows()[2].id)

        for (let i = 0; i < 9; i++) press(box(), 'ArrowDown')
        await tick()
        expect(selected()).toEqual(['false', 'false', 'false', 'true'])

        press(box(), 'ArrowUp')
        press(box(), 'ArrowUp')
        await tick()

        press(box(), 'Enter')
        await tick()

        expect(opened).toEqual(['/sales/invoices/9'])
    })

    it('ইঁদুর কাজের সারিতে গেলে বাছাইও সেখানে', async () => {
        await openEmpty()

        rows()[3].dispatchEvent(new MouseEvent('mousemove', { bubbles: true }))
        await tick()

        expect(selected()).toEqual(['false', 'false', 'false', 'true'])
    })

    it('লিখলে দুইটা দল সরে খোঁজার ফল আসে; মুছলে আবার ফেরে', async () => {
        await openEmpty()

        await search('sale', HITS)
        expect(hrefs()).toEqual(HITS.map(h => h.url))
        expect(visible(dialog().querySelector('#command-recent-label').parentElement)).toBe(false)

        box().value = ''
        box().dispatchEvent(new Event('input', { bubbles: true }))
        await tick()

        expect(hrefs()).toEqual([...RECENT, ...ACTIONS].map(r => r.url))
        expect(selected()[0]).toBe('true')
    })

    it('দেখানোর মতো কিছু না থাকলে আগের মতোই "খুঁজতে লিখুন"', async () => {
        await openEmpty([], [])

        expect(rows()).toEqual([])
        expect(dialog().textContent).toContain('core.search.type_to_find')
        expect(visible(dialog().querySelector('#command-recent-label').parentElement)).toBe(false)

        /* ⓘ তীর আর ↵ ফাঁকা তালিকায় কিছুই করে না, ভুলও ছোঁড়ে না */
        press(box(), 'ArrowDown')
        press(box(), 'Enter')
        await tick()
        expect(warnings).toEqual([])
    })

    it('এক দলই থাকলে অন্য দলের নাম দেখায় না', async () => {
        await openEmpty([], ACTIONS)

        expect(hrefs()).toEqual(ACTIONS.map(r => r.url))
        expect(visible(dialog().querySelector('#command-recent-label').parentElement)).toBe(false)
        expect(visible(dialog().querySelector('#command-actions-label').parentElement)).toBe(true)
        expect(selected()).toEqual(['true', 'false'])
    })
})
