// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { arrangeBoards } from './board-arrange.js'

/* ⭐ ড্যাশবোর্ডের প্রথম পর্দায় গ্রাফ, বাকি নিচে, কেউ হারায় না (মালিক, ২০ নভেম্বর ২০২৬, নমুনা 5gynumii "ok") */
const ORDERS = {
    'data-x-board': { first: 1, rows: [['x-chart-a', 'x-chart-b'], ['x-list'], ['x-problems']] },
}

function board (ui) {
    document.documentElement.dataset.ui = ui
    document.body.innerHTML = `
        <main><div data-sections>
            <div data-x-board><section data-x-problems>Problems</section><section data-x-list>List</section></div>
            <div><section data-x-chart-b>Chart B</section><section data-x-new>Brand new box</section><section data-x-chart-a>Chart A</section></div>
        </div></main>`
}

const order = () => [...document.querySelectorAll('[data-board-row] > section')].map(s => s.textContent)

describe('arrangeBoards', () => {
    it('puts the chart row first, the rest below and the problem centre last', () => {
        board('rose')
        const res = arrangeBoards(document, { 'data-x-board': ORDERS['data-x-board'] })

        expect(order()).toEqual(['Chart A', 'Chart B', 'List', 'Brand new box', 'Problems'])
        expect(document.querySelector('[data-board-row="1"]').hasAttribute('data-board-first')).toBe(true)
        expect(res.placed).toBe(res.boxes)
    })

    it('never loses a box: one the order does not name sits just before the last row', () => {
        board('rose')
        arrangeBoards(document, ORDERS)

        expect(document.querySelectorAll('section').length).toBe(5)
        expect(document.querySelector('[data-board-row="rest"]').textContent).toContain('Brand new box')
    })

    it('runs once, and leaves every other look as it was', () => {
        board('rose')
        arrangeBoards(document, ORDERS)
        expect(arrangeBoards(document, ORDERS)).toBeNull()

        for (const ui of ['navy', 'blue', 'dynamic', 'tiles']) {
            board(ui)
            expect(arrangeBoards(document, ORDERS)).toBeNull()
            expect(document.querySelector('[data-board-row]')).toBeNull()
        }
    })
})
