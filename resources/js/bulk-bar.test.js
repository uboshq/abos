// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { bulkBars, pickColumns } from './bulk-bar.js'

/* ⭐ তালিকার একসাথে কাজের বার — কেবল "ABOS"-এ, কিছু বাছলে তবেই (মালিক, ১৯ নভেম্বর ২০২৬, নমুনা Q2W6mM3S v3) */
function vouchers (ui) {
    document.documentElement.dataset.ui = ui
    document.body.innerHTML = `
        <form id="voucher-bulk" data-bulk-form data-count-label=":n selected" data-all-label="Select all">
            <button type="submit">Review before posting</button>
        </form>
        <table>
            <thead><tr><th>Pick</th><th>No</th></tr></thead>
            <tbody>
                <tr><td><input type="checkbox" form="voucher-bulk" name="ids[]" value="1"></td><td>V-1</td></tr>
                <tr><td><input type="checkbox" form="voucher-bulk" name="ids[]" value="2"></td><td>V-2</td></tr>
                <tr><td></td><td>V-3 (posted)</td></tr>
            </tbody>
        </table>`
}

const tick = (box, on = true) => { box.checked = on; box.dispatchEvent(new Event('change', { bubbles: true })) }

describe('bulkBars', () => {
    it('hides the bar until something is picked, then counts and marks the row', () => {
        vouchers('rose')

        expect(bulkBars(document)).toBe(1)
        const form = document.getElementById('voucher-bulk')
        expect(form.hidden).toBe(true)

        tick(document.querySelector('input[value="1"]'))
        expect(form.hidden).toBe(false)
        expect(form.querySelector('[data-bulk-count]').textContent).toBe('1 selected')
        expect(document.querySelector('input[value="1"]').closest('tr').hasAttribute('data-picked')).toBe(true)

        tick(document.querySelector('input[value="1"]'), false)
        expect(form.hidden).toBe(true)
    })

    it('puts "select all" in the pick column\'s head; it picks every box on the page and shows half when some are picked', () => {
        vouchers('rose')
        bulkBars(document)

        const all = document.querySelector('thead [data-bulk-all]')
        expect(all).not.toBeNull()
        expect(all.closest('th')).toBe(document.querySelector('thead th'))

        all.checked = true
        all.dispatchEvent(new Event('change'))
        expect([...document.querySelectorAll('input[form="voucher-bulk"]')].every(b => b.checked)).toBe(true)
        expect(document.querySelector('[data-bulk-count]').textContent).toBe('2 selected')

        tick(document.querySelector('input[value="2"]'), false)
        expect(all.checked).toBe(false)
        expect(all.indeterminate).toBe(true)
    })

    it('leaves every other look untouched', () => {
        for (const ui of ['navy', 'blue', 'dynamic', 'tiles']) {
            vouchers(ui)

            expect(bulkBars(document)).toBe(0)
            expect(document.getElementById('voucher-bulk').hidden).toBe(false)
            expect(document.querySelector('[data-bulk-all]')).toBeNull()
        }
    })
})

/* ⭐ সব রেকর্ডের তালিকায় সারির টিক-বাক্স — মালিক, ২০ নভেম্বর ২০২৬ ("ei box gulo to dawni") */
function customers (ui, { rowMenu = true } = {}) {
    document.documentElement.dataset.ui = ui
    document.documentElement.lang = 'bn'
    document.body.innerHTML = `
        <main><div class="table-responsive"><table class="ui-list">
            <thead><tr><th>Code</th><th>Name</th>${rowMenu ? '<th data-row-menu>Action</th>' : ''}</tr></thead>
            <tbody>
                <tr><td>C-1</td><td>Alam Store</td>${rowMenu ? '<td data-row-menu>⋯</td>' : ''}</tr>
                <tr><td>C-2</td><td>Rahim Traders</td>${rowMenu ? '<td data-row-menu>⋯</td>' : ''}</tr>
            </tbody>
            <tfoot><tr><td>Total</td><td></td>${rowMenu ? '<td></td>' : ''}</tr></tfoot>
        </table></div></main>`
}

describe('pickColumns', () => {
    it('puts a box at the start of every row of a record list, select-all in the head, and an empty cell in the footer', () => {
        customers('rose')

        expect(pickColumns(document)).toBe(1)
        expect(document.querySelectorAll('tbody [data-pick]').length).toBe(2)
        expect(document.querySelector('thead tr').firstElementChild.querySelector('[data-bulk-all]')).not.toBeNull()
        expect(document.querySelector('tfoot tr').children.length).toBe(4)
        expect(document.querySelector('[data-bulk-bar]').hidden).toBe(true)
    })

    it('shows the bar with the count once something is picked, and select-all picks every row', () => {
        customers('rose')
        pickColumns(document)

        tick(document.querySelectorAll('[data-pick]')[1])
        const bar = document.querySelector('[data-bulk-bar]')
        expect(bar.hidden).toBe(false)
        expect(bar.querySelector('[data-bulk-count]').textContent).toBe('1টা বাছা')
        expect(document.querySelector('[data-bulk-all]').indeterminate).toBe(true)

        const all = document.querySelector('[data-bulk-all]')
        all.checked = true
        all.dispatchEvent(new Event('change'))
        expect([...document.querySelectorAll('[data-pick]')].every(b => b.checked)).toBe(true)
        expect(bar.querySelector('[data-bulk-count]').textContent).toBe('2টা বাছা')

        bar.querySelector('[data-bulk-act="clear"]').click()
        expect(bar.hidden).toBe(true)
    })

    it('leaves reports and ledgers (no action column), lists that pick on their own, and every other look alone', () => {
        customers('rose', { rowMenu: false })
        expect(pickColumns(document)).toBe(0)

        customers('rose')
        document.querySelector('tbody td').innerHTML = '<input type="checkbox" form="voucher-bulk">'
        expect(pickColumns(document)).toBe(0)

        for (const ui of ['navy', 'blue', 'dynamic', 'tiles']) {
            customers(ui)
            expect(pickColumns(document)).toBe(0)
            expect(document.querySelector('[data-pick]')).toBeNull()
        }
    })
})
