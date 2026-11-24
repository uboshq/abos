// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { compactPageHead, foldPageNotes, mergeFilterBarHead, mergeMoneyHead } from './abos-list-head.js'

/* ⭐ "ABOS" রূপে টাকার পাতার মাথা টুলবারের এক সারিতে (মালিক, ১৯ নভেম্বর ২০২৬, নমুনা Q2W6mM3S v3) — অন্য রূপে কিছুই নড়ে না */
function page (ui) {
    document.documentElement.dataset.ui = ui
    document.body.innerHTML = `
        <div data-page-kind="chart">
            <div data-money-hero>
                <h1>Chart of Accounts</h1>
                <span data-hero-count>133 accounts</span>
                <div data-hero-actions><a href="/new" class="is-primary">New account</a></div>
            </div>
            <div data-boxed><div class="toolbar-view"><div class="order-2"><input data-quick-find></div></div></div>
        </div>`
}

describe('mergeMoneyHead', () => {
    it('moves the title, count and New button into the toolbar row and hides the band, only in the ABOS look', () => {
        page('rose')

        expect(mergeMoneyHead(document)).toBe(1)

        const toolbar = document.querySelector('.toolbar-view')
        expect(toolbar.querySelector('h1[data-merged-title]')?.textContent).toBe('Chart of Accounts')
        expect(toolbar.querySelector('[data-record-count]')?.textContent).toBe('133 accounts')
        expect(toolbar.querySelector('[data-command-bar] a.is-primary')?.getAttribute('href')).toBe('/new')
        expect(document.querySelector('[data-money-hero]').hidden).toBe(true)
        expect(toolbar.hasAttribute('data-merged-head')).toBe(true)
    })

    it('runs once — a second call moves nothing', () => {
        page('rose')
        mergeMoneyHead(document)

        expect(mergeMoneyHead(document)).toBe(0)
        expect(document.querySelectorAll('.toolbar-view h1').length).toBe(1)
    })

    // ⭐ owner "ok", 24 Nov 2026 (sample 9VVoe6uQ v2): navy and blue merge too — one head row in all three ABOS looks
    it('merges in navy and blue as well', () => {
        for (const ui of ['navy', 'blue']) {
            page(ui)

            expect(mergeMoneyHead(document)).toBe(1)
            expect(document.querySelector('[data-money-hero]').hidden).toBe(true)
        }
    })

    it('leaves every other look untouched', () => {
        for (const ui of ['tiles', 'dynamic', 'apps']) {
            page(ui)

            expect(mergeMoneyHead(document)).toBe(0)
            expect(document.querySelector('[data-money-hero] h1')).not.toBeNull()
            expect(document.querySelector('[data-money-hero]').hidden).toBe(false)
        }
    })
})

/* ⭐ বাকি পাতার মাথাও এক সারিতে (মালিক, ১৯ নভেম্বর ২০২৬ রাত: "ok … যে প্ল্যানটা দিয়েছো সেমভাবে করে ফেলো") — বাজেটের ধরনে */
function budget (ui, tabCount = 3) {
    document.documentElement.dataset.ui = ui
    document.documentElement.lang = 'bn'
    const tabs = Array.from({ length: tabCount }, (_, i) => `<a href="/t${i}" ${i === 1 ? 'aria-current="page"' : ''}>Tab ${i}</a>`).join('')
    document.body.innerHTML = `
        <main>
            <div class="flex">
                <div class="min-w-0"><h1>Budget</h1><p data-page-subtitle>What goes where</p></div>
                <div data-gold-hairline></div>
                <div data-page-actions><a href="/new">New budget</a></div>
            </div>
            <nav aria-label="Budget">${tabs}</nav>
            <form method="GET">
                <div><label for="y">Year</label><input id="y" name="year" type="number" value="2026"></div>
                <div><label for="c">Centre</label><select id="c" name="center"><option value="">All</option><option value="4" selected>Dhaka</option></select></div>
                <button type="submit">Show</button>
            </form>
            <section data-boxed><table></table></section>
        </main>`
}

describe('compactPageHead', () => {
    it('lifts the tabs and the page\'s own filter into the title row, before the buttons', () => {
        budget('rose')

        expect(compactPageHead(document)).toEqual({ tabs: true, filter: true })

        const head = document.querySelector('[data-head-compact]')
        const order = [...head.children].map(c => c.matches('[data-head-tabs]') ? 'tabs' : c.matches('[data-head-filter]') ? 'filter'
            : c.matches('[data-page-actions]') ? 'actions' : c.matches('.min-w-0') ? 'title' : 'other')
        expect(order.filter(o => o !== 'other')).toEqual(['title', 'tabs', 'filter', 'actions'])
        expect(head.querySelector('[data-head-tabs] a[aria-current="page"]')?.getAttribute('href')).toBe('/t1')
    })

    it('hides the filter fields under the Filter button and shows the chosen values as chips', () => {
        budget('rose')
        compactPageHead(document)

        const wrap = document.querySelector('[data-head-filter]')
        const form = wrap.querySelector('form[data-head-filter-form]')
        expect(form.hidden).toBe(true)
        expect([...wrap.querySelectorAll('[data-head-chips] > span')].map(s => s.textContent)).toEqual(['Year: 2026', 'Centre: Dhaka'])

        wrap.querySelector('button').click()
        expect(form.hidden).toBe(false)
        expect(form.querySelector('[name=year]').value).toBe('2026')
    })

    it('folds more than five tabs into one "chosen tab ▾" button that opens the full list', () => {
        budget('rose', 8)
        compactPageHead(document)

        const menu = document.querySelector('[data-head-tabs-menu]')
        expect(menu).not.toBeNull()
        expect(menu.querySelector('button').textContent).toContain('Tab 1')
        expect(menu.querySelector('[data-head-tabs]').hidden).toBe(true)
        expect(menu.querySelectorAll('[data-head-tabs] a').length).toBe(8)

        menu.querySelector('button').click()
        expect(menu.querySelector('[data-head-tabs]').hidden).toBe(false)
    })

    it('folds the pills only while the row breaks, and gives them back when it fits again', () => {
        let second = 60
        const rect = Element.prototype.getBoundingClientRect
        const tall = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetHeight')
        Object.defineProperty(HTMLElement.prototype, 'offsetHeight', { configurable: true, get: () => 30 })
        Element.prototype.getBoundingClientRect = function () {
            return { top: this.matches('[data-page-actions]') ? second : 10 }
        }

        try {
            budget('rose')
            compactPageHead(document)
            expect(document.querySelector('[data-head-tabs-menu]')).not.toBeNull()

            second = 10
            document.dispatchEvent(new Event('alpine:initialized'))
            expect(document.querySelector('[data-head-tabs-menu]')).toBeNull()
            expect(document.querySelector('[data-head-compact] > [data-head-tabs]').hidden).toBe(false)
        } finally {
            Element.prototype.getBoundingClientRect = rect
            Object.defineProperty(HTMLElement.prototype, 'offsetHeight', tall)
        }
    })

    it('runs once, and leaves every other look untouched', () => {
        budget('rose')
        compactPageHead(document)
        expect(compactPageHead(document)).toBeNull()
        expect(document.querySelectorAll('[data-head-filter]').length).toBe(1)

        // ⭐ navy and blue compact like "ABOS Smart" (owner "ok", 24 Nov 2026)
        for (const ui of ['navy', 'blue']) {
            budget(ui)

            expect(compactPageHead(document)).not.toBeNull()
            expect(document.querySelectorAll('[data-head-filter]').length).toBe(1)
        }

        for (const ui of ['tiles', 'dynamic', 'apps']) {
            budget(ui)

            expect(compactPageHead(document)).toBeNull()
            expect(document.querySelector('main > nav')).not.toBeNull()
            expect(document.querySelector('main > form').hidden).toBe(false)
        }
    })
})

/* ⭐ পাতার নিজের ম্লান ব্যাখ্যার লাইন ⓘ-তে — "ABOS" আর "Dynamic"-এ (মালিক, ১৯ নভেম্বর ২০২৬ রাত) */
function companies (ui) {
    document.documentElement.dataset.ui = ui
    document.body.innerHTML = `
        <main>
            <div role="alert" class="text-(--color-ink-muted)"><p class="text-sm text-(--color-ink-muted)">Saved</p></div>
            <p class="mb-4 text-sm text-(--color-ink-muted)">A new company gets its branch, year and books at once.</p>
            <div data-boxed><div class="toolbar-view"><h2 data-view-selector>Companies</h2></div>
                <p class="text-sm text-(--color-ink-muted)">inside the box</p><table></table></div>
        </main>`
}

describe('foldPageNotes', () => {
    it('hides the page\'s own grey note and puts it behind an ⓘ beside the title, in ABOS and Dynamic', () => {
        for (const ui of ['rose', 'dynamic']) {
            companies(ui)

            expect(foldPageNotes(document)).toBe(1)
            const note = document.querySelector('main > p')
            expect(note.hidden).toBe(true)
            const info = document.querySelector('h2[data-view-selector] [data-page-info]')
            expect(info?.title).toBe('A new company gets its branch, year and books at once.')
            expect(document.querySelector('[role=alert] p').hidden).toBe(false)
            expect(document.querySelector('[data-boxed] > p').hidden).toBe(false)
        }
    })

    it('leaves every other look untouched', () => {
        for (const ui of ['tiles', 'apps']) {
            companies(ui)

            expect(foldPageNotes(document)).toBe(0)
            expect(document.querySelector('main > p').hidden).toBe(false)
            expect(document.querySelector('[data-page-info]')).toBeNull()
        }
    })
})

/* ⭐ ছাঁকনির সরু বারওয়ালা পাতা — নাম, ট্যাব, ছাঁকনি, বোতাম এক সারিতে (মালিক, ২৪ নভেম্বর ২০২৬) */
function dayBook (ui, { lower = false, submit = false } = {}) {
    document.documentElement.dataset.ui = ui
    const box = '<section data-boxed><table></table></section>'
    const bar = `<form id="list-form" method="GET"><div data-filter-bar><div class="toolbar-view"><div class="order-2">
        <span id="toggle"><button type="button" data-filter-toggle>Filter</button></span><span data-chip-row></span></div></div></div></form>`
    document.body.innerHTML = `
        <main>
            <div class="flex" id="head">
                <div class="min-w-0"><h1>Day book<span data-page-info title="What was written"></span></h1><p data-page-subtitle>What was written</p></div>
                <div data-gold-hairline></div>
                <div data-page-actions><a href="/print">Print</a>${submit ? '<form method="POST"><button type="submit">Go</button></form>' : ''}</div>
            </div>
            <nav><a href="/a" aria-current="page">A</a><a href="/b">B</a></nav>
            ${lower ? box + bar : bar + box}
        </main>`
}

describe('mergeFilterBarHead', () => {
    it('puts the title with its info mark, the tabs and the buttons into the filter bar row and hides the empty head', () => {
        for (const ui of ['rose', 'navy', 'blue']) {
            dayBook(ui)
            compactPageHead(document)

            const row = document.querySelector('[data-filter-bar] .toolbar-view > div')
            expect(row.querySelector(':scope > h1 [data-page-info]')).not.toBeNull()
            expect(row.querySelector(':scope > h1 + nav[data-head-tabs]')).not.toBeNull()
            expect(row.querySelector(':scope > [data-page-actions] a[href="/print"]')).not.toBeNull()
            expect(row.querySelector('#toggle [data-filter-toggle]')).not.toBeNull()
            expect(document.querySelector('#head').hidden).toBe(true)
        }
    })

    it('runs once', () => {
        dayBook('blue')

        expect(mergeFilterBarHead(document)).toBe(true)
        expect(mergeFilterBarHead(document)).toBe(false)
        expect(document.querySelectorAll('h1').length).toBe(1)
    })

    it('gives the title back to the page head while printing', () => {
        dayBook('blue')
        mergeFilterBarHead(document)

        window.dispatchEvent(new Event('beforeprint'))
        expect(document.querySelector('#head h1')).not.toBeNull()
        expect(document.querySelector('#head').hidden).toBe(false)

        window.dispatchEvent(new Event('afterprint'))
        expect(document.querySelector('[data-filter-bar] h1')).not.toBeNull()
        expect(document.querySelector('#head').hidden).toBe(true)
    })

    it('moves nothing in other looks, for a bar of a lower section, or when the buttons hold a form', () => {
        for (const [ui, opts] of [['apps', {}], ['dynamic', {}], ['tiles', {}], ['blue', { lower: true }], ['blue', { submit: true }]]) {
            dayBook(ui, opts)

            expect(mergeFilterBarHead(document)).toBe(false)
            expect(document.querySelector('#head h1')).not.toBeNull()
            expect(document.querySelector('#head').hidden).toBe(false)
        }
    })
})

/* ⛔ কন্ট্রোল প্যানেলের বাঁয়ের তালিকা মাথার ট্যাব নয় (মালিক, ২৪ নভেম্বর ২০২৬) */
describe('a side list is never head tabs', () => {
    const side = (ui, frame, first = '') => {
        document.documentElement.dataset.ui = ui
        document.body.innerHTML = `
            <main>
                <div class="flex" id="head"><div class="min-w-0"><h1>Control panel</h1></div></div>
                ${first}
                <div ${frame ? 'data-control-frame' : ''}>
                    <${frame ? 'div' : 'aside'} id="side">
                        <form method="GET" role="search"><input type="search" name="find"></form>
                        <nav><a href="/m" aria-current="page">Modules</a><a href="/p">Printing</a></nav>
                    </${frame ? 'div' : 'aside'}>
                    <section data-boxed><table></table></section>
                </div>
            </main>`
    }

    it('leaves the list and its search in the left column, in an aside or a control frame', () => {
        for (const ui of ['rose', 'navy', 'blue']) {
            for (const frame of [true, false]) {
                side(ui, frame)
                compactPageHead(document)

                expect(document.querySelector('#side > nav')).not.toBeNull()
                expect(document.querySelector('#side > form').hidden).toBe(false)
                expect(document.querySelector('[data-head-tabs], [data-head-tabs-menu], [data-head-filter]')).toBeNull()
            }
        }
    })

    it('still lifts the real tabs of the same page', () => {
        side('blue', true, '<nav id="real"><a href="/a" aria-current="page">A</a><a href="/b">B</a></nav>')
        compactPageHead(document)

        expect(document.querySelector('#head > #real')).not.toBeNull()
        expect(document.querySelector('#side > nav')).not.toBeNull()
    })
})
