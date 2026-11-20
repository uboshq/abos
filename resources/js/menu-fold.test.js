// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { foldMenuGroups, readSaved, STORE_PREFIX } from './menu-fold.js'

/* ⭐ বাঁয়ের মেনুর দল ভাঁজ করা — তিন ABOS রূপে (মালিক, ২০ নভেম্বর ২০২৬) */

function memoryStore () {
    const data = new Map()

    return {
        getItem: k => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => { data.set(k, String(v)) },
        removeItem: k => { data.delete(k) },
        data,
    }
}

/* ⓘ সার্ভার যেমন আঁকে: এই পাতার দল (orders) খোলা, বাকিরা বন্ধ আর তাদের সারি `hidden` */
function salesMenu (ui = 'rose') {
    document.documentElement.dataset.ui = ui
    document.body.innerHTML = `
        <div data-site-map>
            <input type="search">
            <nav data-menu-fold-nav="sales">
                <button type="button" data-menu-fold="start-1" aria-expanded="false"><svg data-licon="house"></svg><span>Start</span></button>
                <a data-menu-fold-row="start-1" hidden href="/dashboard/sales">Dashboard</a>
                <button type="button" data-menu-fold="orders-1" data-menu-fold-here aria-expanded="true"><span>Orders</span></button>
                <a data-menu-fold-row="orders-1" href="/sales/orders/create">New order</a>
                <a data-menu-fold-row="orders-1" href="/sales/orders" aria-current="page">Orders</a>
                <button type="button" data-menu-fold="reports-1" aria-expanded="false"><span>Reports</span></button>
                <a data-menu-fold-row="reports-1" hidden href="/sales/register">Register</a>
                <a href="/loose">Loose row, no group</a>
            </nav>
        </div>`
}

const head = key => document.querySelector(`[data-menu-fold="${key}"]`)
const rows = key => [...document.querySelectorAll(`[data-menu-fold-row="${key}"]`)]
const isOpen = key => head(key).getAttribute('aria-expanded') === 'true' && rows(key).every(r => ! r.hidden)
const isShut = key => head(key).getAttribute('aria-expanded') === 'false' && rows(key).every(r => r.hidden)

describe('foldMenuGroups', () => {
    let store

    beforeEach(() => {
        store = memoryStore()
    })

    it('opens only the group of the current page on a first visit', () => {
        salesMenu()
        expect(foldMenuGroups(document, { store })).toBe(1)

        expect(isOpen('orders-1')).toBe(true)
        expect(isShut('start-1')).toBe(true)
        expect(isShut('reports-1')).toBe(true)
        expect(document.querySelector('a[href="/loose"]').hidden).toBe(false)
    })

    it('toggles a group on click and remembers it per module', () => {
        salesMenu()
        foldMenuGroups(document, { store })

        head('reports-1').click()
        expect(isOpen('reports-1')).toBe(true)
        expect(readSaved(store, 'sales')).toEqual({ 'reports-1': true })

        head('reports-1').click()
        expect(isShut('reports-1')).toBe(true)
        expect(readSaved(store, 'sales')).toEqual({ 'reports-1': false })

        head('start-1').click()
        expect(JSON.parse(store.data.get(STORE_PREFIX + 'sales'))).toEqual({ 'reports-1': false, 'start-1': true })
    })

    it('reopens the remembered groups on the next page, and the current page\'s group is always open', () => {
        store.setItem(STORE_PREFIX + 'sales', JSON.stringify({ 'reports-1': true, 'orders-1': false }))
        salesMenu()
        foldMenuGroups(document, { store })

        expect(isOpen('reports-1')).toBe(true)
        expect(isOpen('orders-1')).toBe(true)
        expect(isShut('start-1')).toBe(true)
    })

    it('Enter and Space work because the heading is a real button', () => {
        salesMenu()
        foldMenuGroups(document, { store })

        expect(head('start-1').tagName).toBe('BUTTON')
        expect(head('start-1').getAttribute('type')).toBe('button')
        // ⓘ ব্রাউজার Enter/Space-এ বোতামের click ছোঁড়ে; happy-dom তা করে না, তাই click দিয়েই সেই পথ
        head('start-1').dispatchEvent(new MouseEvent('click', { bubbles: true }))
        expect(isOpen('start-1')).toBe(true)
    })

    it('a click on the icon or the name inside the heading toggles too', () => {
        salesMenu()
        foldMenuGroups(document, { store })

        head('start-1').querySelector('span').click()
        expect(isOpen('start-1')).toBe(true)
    })

    it('works without storage — no throw, current group open, toggling still works', () => {
        salesMenu()
        const broken = { getItem: () => { throw new Error('blocked') }, setItem: () => { throw new Error('blocked') } }

        expect(() => foldMenuGroups(document, { store: broken })).not.toThrow()
        expect(isOpen('orders-1')).toBe(true)
        head('reports-1').click()
        expect(isOpen('reports-1')).toBe(true)

        salesMenu()
        expect(foldMenuGroups(document, { store: null })).toBe(1)
        expect(isOpen('orders-1')).toBe(true)
    })

    it('ignores rubbish in storage', () => {
        store.setItem(STORE_PREFIX + 'sales', '{not json')
        salesMenu()
        foldMenuGroups(document, { store })
        expect(isShut('reports-1')).toBe(true)

        store.setItem(STORE_PREFIX + 'sales', '[1,2]')
        expect(readSaved(store, 'sales')).toEqual({})
    })

    it('shows every row while the menu filter has text, and folds back when it is cleared', () => {
        salesMenu()
        foldMenuGroups(document, { store })
        const input = document.querySelector('input[type="search"]')

        input.value = 'reg'
        input.dispatchEvent(new Event('input'))
        expect(document.querySelectorAll('[data-menu-fold-row][hidden]').length).toBe(0)

        input.value = ''
        input.dispatchEvent(new Event('input'))
        expect(isShut('reports-1')).toBe(true)
        expect(isOpen('orders-1')).toBe(true)
    })

    it('wakes in all three ABOS looks', () => {
        for (const ui of ['rose', 'navy', 'blue']) {
            salesMenu(ui)
            expect(foldMenuGroups(document, { store })).toBe(1)
        }
    })

    it('leaves every other look untouched', () => {
        for (const ui of ['tiles', 'dynamic', 'apps', 'suite', 'redwood', 'salesforce', 'linear', 'classic']) {
            salesMenu(ui)
            const before = document.body.innerHTML
            expect(foldMenuGroups(document, { store })).toBe(0)
            head('reports-1').click()
            expect(document.body.innerHTML).toBe(before)
        }
    })

    it('wakes a menu only once', () => {
        salesMenu()
        foldMenuGroups(document, { store })
        expect(foldMenuGroups(document, { store })).toBe(0)

        head('reports-1').click()
        expect(isOpen('reports-1')).toBe(true)
    })
})
