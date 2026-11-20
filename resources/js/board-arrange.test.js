// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { arrangeBoards, BOARD_ORDERS } from './board-arrange.js'

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

/* ⛔ লাইভ 1.3.7 (২১ নভেম্বর ২০২৬): অর্থের প্রথম পর্দায় 4 + 3, তিন রকম চওড়া — এখন দুই সারি, সারিতে তিনটা সমান কার্ড */
describe('finance board', () => {
    const keys = ['fin-receivable', 'fin-payable', 'fin-ratios', 'fin-cycle', 'fin-loans', 'fin-fund-trend', 'fin-calendar',
        'fin-margins', 'fin-banks', 'fin-owners', 'fund', 'fund-news', 'fin-forecast', 'fin-cash-flow', 'fin-wc', 'fin-budget',
        'fin-cheques', 'fin-fdr', 'fin-health', 'fin-flows']

    function finance () {
        document.documentElement.dataset.ui = 'rose'
        document.body.innerHTML = '<main><div><div data-fin-board-position>'
            + keys.map(k => `<section data-${k}>${k}</section>`).join('') + '</div></div></main>'
    }

    it('opens on two rows of three equal cards, and loses no box', () => {
        finance()
        const res = arrangeBoards(document, BOARD_ORDERS)
        const first = [...document.querySelectorAll('[data-board-first]')]

        expect(first).toHaveLength(2)
        for (const row of first) {
            expect(row.children).toHaveLength(3)
            expect(row.style.gridTemplateColumns).toBe('repeat(3, minmax(0, 1fr))')
            expect(row.hasAttribute('data-board-equal')).toBe(true)
        }
        expect(first.map(r => [...r.children].map(s => s.textContent))).toEqual([
            ['fin-receivable', 'fin-payable', 'fin-ratios'],
            ['fin-cycle', 'fin-fund-trend', 'fin-calendar'],
        ])
        expect(res.placed).toBe(keys.length)
        expect(document.querySelectorAll('[data-board-row] > section')).toHaveLength(keys.length)
        expect(document.querySelector('[data-board-row="rest"]')).toBeNull()
    })

    it('rows below the first screen keep their own heights', () => {
        finance()
        arrangeBoards(document, BOARD_ORDERS)

        expect(document.querySelectorAll('[data-board-equal]')).toHaveLength(2)
    })
})

/* ⭐ মজুদ আর বিক্রয় — প্রথম পর্দায় দুই সারি, সারিতে তিনটা সমান কার্ড; সমস্যা কেন্দ্র সবার শেষে, কোনো বক্স হারায় না (মালিক, ২১ নভেম্বর ২০২৬) */
describe.each([
    ['data-inv-board-trend', 'inv-problems', [['inv-trend', 'inv-wh', 'inv-cover'], ['inv-roll', 'inv-brand', 'inv-ageing']]],
    ['data-sales-board-profit', 'sales-problems', [['sales-ladder', 'sales-target', 'sales-path'], ['sales-branches', 'sales-brands', 'sales-pareto']]],
])('%s', (mark, problems, firstRows) => {
    const keys = BOARD_ORDERS[mark].rows.flatMap(r => (Array.isArray(r) ? r : r.keys))

    function page () {
        document.documentElement.dataset.ui = 'rose'
        // ⓘ সার্ভারের ক্রমের মতো — সমস্যা কেন্দ্র প্রথমে আঁকা
        const order = [problems, ...keys.filter(k => k !== problems)]
        document.body.innerHTML = `<main><div><div ${mark}>`
            + order.map(k => `<section data-${k}>${k}</section>`).join('') + '</div></div></main>'
    }

    it('opens on two rows of three equal cards, the problem centre last, no box lost', () => {
        page()
        const res = arrangeBoards(document, BOARD_ORDERS)
        const first = [...document.querySelectorAll('[data-board-first]')]

        expect(first).toHaveLength(2)
        for (const row of first) {
            expect(row.children).toHaveLength(3)
            expect(row.style.gridTemplateColumns).toBe('repeat(3, minmax(0, 1fr))')
            expect(row.hasAttribute('data-board-equal')).toBe(true)
        }
        expect(first.map(r => [...r.children].map(s => s.textContent))).toEqual(firstRows)
        expect(document.querySelectorAll('[data-board-equal]')).toHaveLength(2)

        const placed = [...document.querySelectorAll('[data-board-row] > section')].map(s => s.textContent)
        expect(placed).toHaveLength(keys.length)
        expect(placed.at(-1)).toBe(problems)
        expect(res.placed).toBe(keys.length)
    })
})
