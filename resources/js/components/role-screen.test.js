// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { countPermissions, wireActions } from './actions.js'
import { changedCells, keepOneOpen, showRoleTab, wireRoleScreen } from './role-screen.js'

/*
 * ⭐ রোল ও অনুমতির নতুন পাতা — মালিকের অনুমোদিত নমুনা, ২০ নভেম্বর ২০২৬ ("ok")।
 * এক-খোলা ভাঁজ, আংশিক টিক মাঝামাঝি, রোলের ট্যাব, বদলের গোনা আর সংরক্ষণের আগে কারণ।
 */

const SCREEN = `
<div data-role-screen data-role-tab-start="permissions">
  <form method="POST" data-role-form data-role-reason-needed>
    <input name="name" value="Accountant">
    <button type="button" role="tab" data-role-tab="permissions">অনুমতি</button>
    <button type="button" role="tab" data-role-tab="members">সদস্য</button>
    <section data-role-panel="permissions">
      <details name="role-modules-perm" data-accordion="perm" data-permission-module="sales">
        <summary>
          <i data-permission-bar style="width: 0%"></i>
          <span data-permission-count data-permission-total="3" data-permission-label=":on / :all">1 / 3</span>
          <label><input type="checkbox" data-permission-all></label>
        </summary>
        <table>
          <thead><tr><th><input type="checkbox" data-permission-column="view"></th></tr></thead>
          <tbody data-permission-section="transactions">
            <tr data-permission-section-head><th><input type="checkbox" data-permission-section-all></th></tr>
            <tr><td><input type="checkbox" name="permissions[]" value="sales.order.view" data-permission-cell="view" data-cell-label="অর্ডার — দেখা" checked></td></tr>
            <tr><td><input type="checkbox" name="permissions[]" value="sales.invoice.view" data-permission-cell="view" data-cell-label="চালান — দেখা"></td></tr>
            <tr><td><input type="checkbox" name="permissions[]" value="sales.invoice.create" data-permission-cell="create" data-cell-label="চালান — তৈরি"></td></tr>
          </tbody>
        </table>
      </details>
      <details name="role-modules-perm" data-accordion="perm" data-permission-module="purchase">
        <summary><span data-permission-count data-permission-total="1" data-permission-label=":on / :all">0 / 1</span></summary>
        <input type="checkbox" name="permissions[]" value="purchase.bill.view" data-permission-cell="view" data-cell-label="বিল — দেখা">
      </details>
      <span data-role-dirty hidden data-label="● :count টা ঘর বদলেছে"></span>
      <button type="submit" data-bar-save>সংরক্ষণ</button>
      <dialog data-role-reason>
        <ul data-role-reason-list data-add="দেওয়া হলো" data-remove="তুলে নেওয়া হলো" data-rename="নাম বদল"></ul>
        <textarea name="reason" data-role-reason-text></textarea>
        <button type="button" data-role-reason-cancel>বাতিল</button>
        <button type="submit" data-role-reason-ok>সংরক্ষণ</button>
      </dialog>
    </section>
    <section data-role-panel="members" hidden>সদস্য</section>
  </form>
</div>`

let root

function mount () {
    document.body.innerHTML = '<div data-test-root></div>'
    root = document.body.firstElementChild
    root.innerHTML = SCREEN
    wireActions(root)
    wireRoleScreen(root)
}

const $ = (selector) => root.querySelector(selector)
const tick = (value) => {
    const box = $(`input[value="${value}"]`)
    box.checked = ! box.checked
    box.dispatchEvent(new window.Event('change', { bubbles: true }))
}

describe('এক-খোলা ভাঁজ', () => {
    beforeEach(mount)

    it('শুরুতে সব বন্ধ', () => {
        expect([...root.querySelectorAll('details[open]')]).toHaveLength(0)
    })

    it('একটা খুললে আগেরটা বন্ধ হয়', () => {
        const sales = $('[data-permission-module="sales"]')
        const purchase = $('[data-permission-module="purchase"]')

        sales.open = true
        keepOneOpen(sales, root)
        purchase.open = true
        keepOneOpen(purchase, root)

        expect(purchase.open).toBe(true)
        expect(sales.open, '⛔ আগের মডিউলটা খোলাই থাকল।').toBe(false)
    })

    it('বন্ধ করা ভাঁজ অন্যটা খোলে না', () => {
        const sales = $('[data-permission-module="sales"]')
        sales.open = false
        keepOneOpen(sales, root)

        expect([...root.querySelectorAll('details[open]')]).toHaveLength(0)
    })
})

describe('আংশিক টিক মাঝামাঝি', () => {
    beforeEach(mount)

    it('শুরুতেই মডিউলের "সব" আর "পুরো কলাম" মাঝামাঝি, দাগ ঠিক চওড়া', () => {
        expect($('[data-permission-all]').indeterminate).toBe(true)
        expect($('[data-permission-column="view"]').indeterminate).toBe(true)
        expect($('[data-permission-section-all]').indeterminate).toBe(true)
        expect($('[data-permission-bar]').style.width).toBe('33%')
    })

    it('কলামের সব ঘর টিক দিলে কলামের টিক পুরো', () => {
        tick('sales.invoice.view')

        expect($('[data-permission-column="view"]').checked).toBe(true)
        expect($('[data-permission-column="view"]').indeterminate).toBe(false)
        expect($('[data-permission-all]').indeterminate, 'ⓘ তৈরির ঘর এখনো ফাঁকা').toBe(true)
    })

    it('শেষ ঘরটাও দিলে মডিউলের টিক পুরো', () => {
        tick('sales.invoice.view')
        tick('sales.invoice.create')
        countPermissions($('[data-permission-module="sales"]'))

        expect($('[data-permission-all]').checked).toBe(true)
        expect($('[data-permission-all]').indeterminate).toBe(false)
        expect($('[data-permission-count]').textContent).toBe('3 / 3')
    })
})

describe('রোলের ট্যাব', () => {
    beforeEach(mount)

    it('ট্যাবে চাপলে কেবল তার অংশ দেখা যায়', () => {
        $('[data-role-tab="members"]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }))

        expect($('[data-role-panel="members"]').hidden).toBe(false)
        expect($('[data-role-panel="permissions"]').hidden).toBe(true)
        expect($('[data-role-tab="members"]').getAttribute('aria-selected')).toBe('true')
    })

    it('অচেনা ট্যাবে কিছুই বদলায় না', () => {
        expect(showRoleTab($('[data-role-screen]'), 'nope')).toBe(false)
        expect($('[data-role-panel="permissions"]').hidden).toBe(false)
    })
})

describe('বদলের গোনা আর কারণ', () => {
    beforeEach(mount)

    it('ঘর বদলালে পট্টিতে "● n টা ঘর বদলেছে"', () => {
        expect($('[data-role-dirty]').hidden).toBe(true)

        tick('sales.invoice.view')
        tick('purchase.bill.view')

        expect($('[data-role-dirty]').hidden).toBe(false)
        expect($('[data-role-dirty]').textContent).toBe('● 2 টা ঘর বদলেছে')
    })

    it('আবার আগের মতো করলে গোনা শূন্য', () => {
        tick('sales.invoice.view')
        tick('sales.invoice.view')

        expect(changedCells($('form'))).toHaveLength(0)
        expect($('[data-role-dirty]').hidden).toBe(true)
    })

    it('কিছু বদলালে জমার আগে সারাংশ আর কারণ চায়, জমা থামে', () => {
        tick('sales.order.view')

        const form = $('form')
        const event = new window.Event('submit', { bubbles: true, cancelable: true })
        form.dispatchEvent(event)

        expect(event.defaultPrevented, '⛔ কারণ ছাড়াই জমা গেল।').toBe(true)
        expect($('[data-role-reason]').open).toBe(true)
        expect($('[data-role-reason-text]').required).toBe(true)
        expect([...root.querySelectorAll('[data-role-reason-list] li')].map((li) => li.textContent)).toEqual(['তুলে নেওয়া হলো: অর্ডার — দেখা'])
    })

    it('কিছু না বদলালে প্রশ্ন নেই', () => {
        const event = new window.Event('submit', { bubbles: true, cancelable: true })
        $('form').dispatchEvent(event)

        expect(event.defaultPrevented).toBe(false)
    })

    it('নাম বদলও বদল', () => {
        const name = $('input[name="name"]')
        name.value = 'Senior Accountant'

        expect(changedCells($('form'))).toEqual([{ label: 'Accountant → Senior Accountant', rename: true }])
    })

    it('বাতিলে সংলাপ বন্ধ, কারণ আর বাধ্যতামূলক নয়', () => {
        tick('sales.order.view')
        $('form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }))
        $('[data-role-reason-cancel]').dispatchEvent(new window.MouseEvent('click', { bubbles: true }))

        expect($('[data-role-reason]').open).toBe(false)
        expect($('[data-role-reason-text]').required).toBe(false)
    })
})
