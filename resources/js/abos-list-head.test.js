import { describe, expect, it } from 'vitest'
import { mergeMoneyHead } from './abos-list-head.js'

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
