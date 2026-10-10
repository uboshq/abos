// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { wireActions } from './actions.js'

/*
 * "কেবল দেওয়াগুলো" — সিস্টেম পর্দার নকশা §৩, ১০ অক্টোবর ২০২৬।
 *
 * ⓘ রোলের ছকে চারশোর বেশি সারি; টিকটা চাপলে কেবল যে সারিতে অন্তত একটা অনুমতি দেওয়া, সেগুলোই থাকে — খোঁজার সাথে মিলিয়ে।
 * ⚠️ চশমা, কাঁচি নয়: লুকানো সারির টিক জমা হয়, আর কোনো টিক বদলায় না।
 */

const GRID = `
<form>
  <input type="search" data-permission-search>
  <input type="checkbox" data-permission-granted-only>
  <p data-permission-empty hidden>কিছু মিলল না</p>

  <details data-permission-module="sales">
    <summary>বিক্রয়</summary>
    <table>
      <tbody data-permission-section="transactions">
        <tr data-permission-section-head><th>লেনদেন</th></tr>
        <tr data-permission-row data-permission-text="বিক্রয় আদেশ sales.order">
          <td><input type="checkbox" name="permissions[]" value="sales.order.view" checked></td>
        </tr>
        <tr data-permission-row data-permission-text="বিক্রয় চালান sales.challan">
          <td><input type="checkbox" name="permissions[]" value="sales.challan.view"></td>
        </tr>
      </tbody>
      <tbody data-permission-section="reports">
        <tr data-permission-section-head><th>রিপোর্ট</th></tr>
        <tr data-permission-row data-permission-text="মার্জিন sales.margin">
          <td><input type="checkbox" name="permissions[]" value="sales.margin.view"></td>
        </tr>
      </tbody>
    </table>
  </details>

  <details data-permission-module="purchase">
    <summary>ক্রয়</summary>
    <table><tbody data-permission-section="transactions">
      <tr data-permission-section-head><th>লেনদেন</th></tr>
      <tr data-permission-row data-permission-text="ক্রয় বিল purchase.bill">
        <td><input type="checkbox" name="permissions[]" value="purchase.bill.view"></td>
      </tr>
    </tbody></table>
  </details>
</form>`

let root

const row = (text) => root.querySelector(`[data-permission-row][data-permission-text*="${text}"]`)
const module = (code) => root.querySelector(`[data-permission-module="${code}"]`)

function only (on) {
    const box = root.querySelector('[data-permission-granted-only]')
    box.checked = on
    box.dispatchEvent(new window.Event('input', { bubbles: true }))
}

function search (term) {
    const field = root.querySelector('[data-permission-search]')
    field.value = term
    field.dispatchEvent(new window.Event('input', { bubbles: true }))
}

beforeEach(() => {
    document.body.innerHTML = GRID
    root = document.body
    wireActions(root)
})

describe('কেবল দেওয়াগুলো', () => {
    it('keeps only the rows that hold a granted permission', () => {
        only(true)

        expect(row('sales.order').hidden).toBe(false)
        expect(row('sales.challan').hidden).toBe(true)
        expect(row('sales.margin').hidden).toBe(true)
    })

    it('hides a module and a section head with nothing granted, and opens the one that has', () => {
        only(true)

        expect(module('purchase').hidden).toBe(true)
        expect(module('sales').hidden).toBe(false)
        expect(module('sales').open).toBe(true)
        expect(root.querySelector('[data-permission-section="reports"] [data-permission-section-head]').hidden).toBe(true)
    })

    it('brings every row back when switched off', () => {
        only(true)
        only(false)

        for (const r of root.querySelectorAll('[data-permission-row]')) {
            expect(r.hidden).toBe(false)
        }
        expect(module('purchase').hidden).toBe(false)
    })

    it('works together with the search — both must match', () => {
        search('sales')
        only(true)

        expect(row('sales.order').hidden).toBe(false)
        expect(row('sales.challan').hidden).toBe(true)

        search('challan')
        expect(row('sales.challan').hidden).toBe(true)
        expect(root.querySelector('[data-permission-empty]').hidden).toBe(false)
    })

    it('says nothing matched when the role holds nothing', () => {
        root.querySelector('input[value="sales.order.view"]').checked = false
        only(true)

        expect(root.querySelector('[data-permission-empty]').hidden).toBe(false)
    })

    it('changes no tick — a hidden row still submits', () => {
        only(true)

        const sent = new window.FormData(root.querySelector('form')).getAll('permissions[]')
        expect(sent).toEqual(['sales.order.view'])
        expect(root.querySelector('input[value="sales.challan.view"]').checked).toBe(false)
    })
})
