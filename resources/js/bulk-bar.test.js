// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { bulkBars } from './bulk-bar.js'

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
