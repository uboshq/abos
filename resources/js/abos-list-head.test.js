import { describe, expect, it } from 'vitest'
import { compactPageHead, mergeMoneyHead } from './abos-list-head.js'

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

    it('leaves every other look untouched', () => {
        for (const ui of ['navy', 'blue', 'tiles', 'dynamic']) {
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

    it('runs once, and leaves every other look untouched', () => {
        budget('rose')
        compactPageHead(document)
        expect(compactPageHead(document)).toBeNull()
        expect(document.querySelectorAll('[data-head-filter]').length).toBe(1)

        for (const ui of ['navy', 'blue', 'tiles', 'dynamic']) {
            budget(ui)

            expect(compactPageHead(document)).toBeNull()
            expect(document.querySelector('main > nav')).not.toBeNull()
            expect(document.querySelector('main > form').hidden).toBe(false)
        }
    })
})
