// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { foldMenuGroups, groupToOpen, readSaved, STORE_PREFIX } from './menu-fold.js'

/* ⭐ বাঁয়ের মেনুর দল ভাঁজ করা — তিন ABOS রূপে (মালিক, ২০ নভেম্বর ২০২৬)
   ⭐ ২১ নভেম্বর ২০২৬ (মালিকের নমুনা "বাঁয়ের মেনু — ভাঁজ"): প্রতিটা দল নিজের ঘরে (মাথা + সারির ঘর), মাথায় তীর ›,
   আর একবারে একটাই দল খোলা — আগের "কয়েকটা দল একসাথে খোলা, প্রতিটা আলাদা মনে রাখা" এর জায়গায় */

function memoryStore () {
    const data = new Map()

    return {
        getItem: k => (data.has(k) ? data.get(k) : null),
        setItem: (k, v) => { data.set(k, String(v)) },
        removeItem: k => { data.delete(k) },
        data,
    }
}

/* ⓘ সার্ভার যেমন আঁকে: প্রতিটা দল নিজের ঘরে (মাথা + সারির ঘর); এই পাতার দল (orders) খোলা, বাকিরা বন্ধ আর তাদের সারি `hidden` */
function group (key, name, rows, { here = false } = {}) {
    return `
                <div class="menu-fold-grp" data-menu-fold-grp="${key}">
                    <button type="button" class="menu-kind-head menu-fold-head" data-menu-fold="${key}" ${here ? 'data-menu-fold-here' : ''} aria-expanded="${here}">
                        <span class="menu-fold-icon" style="background: #2563eb"><svg data-licon="house"></svg></span><span class="menu-fold-name">${name}</span><svg class="menu-fold-chev" data-menu-fold-chev></svg>
                    </button>
                    <div class="menu-fold-items" data-menu-fold-items="${key}">${rows}</div>
                </div>`
}

function salesMenu (ui = 'rose', { here = true } = {}) {
    document.documentElement.dataset.ui = ui
    document.body.innerHTML = `
        <div data-site-map>
            <input type="search">
            <nav data-menu-fold-nav="sales">${
                group('start-1', 'Start', '<a data-menu-fold-row="start-1" hidden href="/dashboard/sales"><span class="menu-icon"><svg data-licon="gauge"></svg></span>Dashboard</a>')
            }${
                group('orders-1', 'Orders', `<a data-menu-fold-row="orders-1" ${here ? '' : 'hidden'} href="/sales/orders/create">New order</a>`
                    + `<a data-menu-fold-row="orders-1" ${here ? 'aria-current="page"' : 'hidden'} href="/sales/orders">Orders</a>`, { here })
            }${
                group('reports-1', 'Reports', '<a data-menu-fold-row="reports-1" hidden href="/sales/register">Register</a>')
            }
                <a href="/loose">Loose row, no group</a>
            </nav>
        </div>`
}

const head = key => document.querySelector(`[data-menu-fold="${key}"]`)
const rows = key => [...document.querySelectorAll(`[data-menu-fold-row="${key}"]`)]
const isOpen = key => head(key).getAttribute('aria-expanded') === 'true' && rows(key).every(r => ! r.hidden)
const isShut = key => head(key).getAttribute('aria-expanded') === 'false' && rows(key).every(r => r.hidden)
const openKeys = () => [...document.querySelectorAll('[data-menu-fold][aria-expanded="true"]')].map(h => h.dataset.menuFold)

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

    it('opening a group closes the one that was open — one group at a time (owner, 21 Nov 2026)', () => {
        salesMenu()
        foldMenuGroups(document, { store })

        head('reports-1').click()
        expect(isOpen('reports-1')).toBe(true)
        expect(isShut('orders-1')).toBe(true)
        expect(openKeys()).toEqual(['reports-1'])

        head('start-1').click()
        expect(openKeys()).toEqual(['start-1'])
        expect(isShut('reports-1')).toBe(true)
    })

    it('a click on the open group closes it, leaving none open', () => {
        salesMenu()
        foldMenuGroups(document, { store })

        head('orders-1').click()
        expect(openKeys()).toEqual([])
        expect(isShut('orders-1')).toBe(true)
    })

    it('remembers only the one open group per module', () => {
        salesMenu()
        foldMenuGroups(document, { store })

        head('reports-1').click()
        expect(readSaved(store, 'sales')).toEqual({ 'reports-1': true })

        head('start-1').click()
        expect(JSON.parse(store.data.get(STORE_PREFIX + 'sales'))).toEqual({ 'start-1': true })

        head('start-1').click()
        expect(readSaved(store, 'sales')).toEqual({})
    })

    it('the current page\'s group wins over the remembered one, so still only one is open', () => {
        store.setItem(STORE_PREFIX + 'sales', JSON.stringify({ 'reports-1': true }))
        salesMenu()
        foldMenuGroups(document, { store })

        expect(openKeys()).toEqual(['orders-1'])
        expect(isShut('reports-1')).toBe(true)
    })

    it('with no current-page group, the remembered group opens alone', () => {
        store.setItem(STORE_PREFIX + 'sales', JSON.stringify({ 'reports-1': true, 'start-1': true }))
        salesMenu('rose', { here: false })
        foldMenuGroups(document, { store })

        expect(openKeys()).toEqual(['start-1'])
        expect(groupToOpen([head('start-1'), head('orders-1'), head('reports-1')], { 'reports-1': true })).toBe('reports-1')
        expect(groupToOpen([head('start-1')], { gone: true })).toBe(null)
    })

    it('Enter and Space work because the heading is a real button', () => {
        salesMenu()
        foldMenuGroups(document, { store })

        expect(head('start-1').tagName).toBe('BUTTON')
        expect(head('start-1').getAttribute('type')).toBe('button')
        // ⓘ ব্রাউজার Enter/Space-এ বোতামের click ছোঁড়ে; happy-dom তা করে না, তাই click দিয়েই সেই পথ
        head('start-1').dispatchEvent(new MouseEvent('click', { bubbles: true }))
        expect(isOpen('start-1')).toBe(true)
        expect(openKeys()).toEqual(['start-1'])
    })

    it('a click on the icon or the name inside the heading toggles too', () => {
        salesMenu()
        foldMenuGroups(document, { store })

        head('start-1').querySelector('.menu-fold-icon').click()
        expect(isOpen('start-1')).toBe(true)

        head('reports-1').querySelector('.menu-fold-name').click()
        expect(isOpen('reports-1')).toBe(true)

        head('reports-1').querySelector('[data-menu-fold-chev]').dispatchEvent(new MouseEvent('click', { bubbles: true }))
        expect(isShut('reports-1')).toBe(true)
    })

    it('each group\'s rows sit in its own items box, right after its head', () => {
        salesMenu()
        foldMenuGroups(document, { store })

        for (const key of ['start-1', 'orders-1', 'reports-1']) {
            const box = document.querySelector(`[data-menu-fold-grp="${key}"]`)
            expect(box.firstElementChild).toBe(head(key))
            expect(head(key).nextElementSibling.dataset.menuFoldItems).toBe(key)
            rows(key).forEach(r => expect(r.parentElement.dataset.menuFoldItems).toBe(key))
        }
        expect(document.querySelector('a[href="/loose"]').closest('[data-menu-fold-grp]')).toBe(null)
    })

    it('works without storage — no throw, current group open, toggling still works', () => {
        salesMenu()
        const broken = { getItem: () => { throw new Error('blocked') }, setItem: () => { throw new Error('blocked') } }

        expect(() => foldMenuGroups(document, { store: broken })).not.toThrow()
        expect(isOpen('orders-1')).toBe(true)
        head('reports-1').click()
        expect(isOpen('reports-1')).toBe(true)
        expect(isShut('orders-1')).toBe(true)

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
