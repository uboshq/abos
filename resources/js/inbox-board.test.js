// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { inboxBoard } from './inbox-board.js'

/*
 * ⭐ The inbox board (owner "ok", 23 Nov 2026, sample YRFmwfF7 v5): a kind's tick picks only that kind's boxes, the pick bar
 * counts and sums, and a click on a row (not on a link) opens the paper.
 * ⛔ The money paper has no box at all — the kind tick must not invent one.
 */
const PAGE = `
    <div data-inbox-board>
        <span data-board-picked data-label="বাছাই __N__ টা">x</span><span data-board-picked-sum>x</span>
        <table>
            <tbody data-board-group="sales.discount">
                <tr><th><input type="checkbox" id="group" data-board-group-pick="sales.discount"></th></tr>
                <tr data-board-open="/approval/inbox?open=1" id="row1">
                    <td><input type="checkbox" id="a" data-inbox-pick data-board-in="sales.discount" data-amount="1500.50"></td>
                    <td id="plain">RCV</td><td><a href="/party/7" id="party">আলম</a></td>
                </tr>
                <tr><td><input type="checkbox" id="b" data-inbox-pick data-board-in="sales.discount" data-amount="100000"></td></tr>
            </tbody>
            <tbody data-board-group="accounts.payment">
                <tr data-board-open="/approval/inbox?open=3"><td>—</td></tr>
            </tbody>
        </table>
    </div>`

describe('inbox board', () => {
    let opened

    beforeEach(() => {
        document.body.innerHTML = PAGE
        opened = []
        inboxBoard(document, { innerHeight: 1080, scrollY: 0, addEventListener () {}, location: { assign: (to) => opened.push(to) } })
    })

    it('ticks the whole kind and sums the picked amounts', () => {
        const group = document.getElementById('group')

        group.checked = true
        group.dispatchEvent(new Event('change', { bubbles: true }))

        expect(document.getElementById('a').checked).toBe(true)
        expect(document.getElementById('b').checked).toBe(true)
        expect(document.querySelector('[data-board-picked]').textContent).toBe('বাছাই 2 টা')
        expect(document.querySelector('[data-board-picked-sum]').textContent).toBe('৳1,01,500.50')
    })

    it('shows a half tick when only some rows of a kind are picked', () => {
        const a = document.getElementById('a')

        a.checked = true
        a.dispatchEvent(new Event('change', { bubbles: true }))

        expect(document.getElementById('group').indeterminate).toBe(true)
        expect(document.querySelector('[data-board-picked]').textContent).toBe('বাছাই 1 টা')
    })

    it('opens the paper on a row click but leaves links alone', () => {
        document.getElementById('plain').click()
        document.getElementById('party').click()

        expect(opened).toEqual(['/approval/inbox?open=1'])
    })
})
