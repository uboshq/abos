// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PRINT_FLAG, PRINT_TAB, handPrintToTab, holdPrintTabOnSubmit } from './print-tab.js'
import directSale from './counter/direct-sale.js'

/*
 * ⭐ কাউন্টারে নিশ্চিত করলে ছাপা নতুন ট্যাবে, কাউন্টার যেখানে ছিল সেখানেই (মালিক, ২৩ নভেম্বর ২০২৬; [[print-tab.js]])।
 * দাবি: "নিশ্চিত করুন" চাপের মুহূর্তেই নামওয়ালা ট্যাব খোলে (পপ-আপ আটকায় না), কাজের পাতা নড়ে না; থামানো জমা আর "খসড়া রাখুন"
 * ট্যাব খোলে না; ফিরে আসা কাউন্টার ছাপার ঠিকানা ঐ ট্যাবে বসায়, আর ছাপা না এলে ফাঁকা ট্যাবটা বন্ধ করে।
 */

function fakeWindow () {
    const tabs = {}
    const store = {}
    const win = {
        open: vi.fn((url, name) => {
            if (url === '' && name in tabs && tabs[name] === null) return null
            const tab = tabs[name] ?? { url: '', closed: false, close () { this.closed = true }, document: { title: '', body: { style: {}, textContent: '' } } }
            if (url !== '') tab.url = url
            tabs[name] = tab
            return tab
        }),
        sessionStorage: {
            getItem: (k) => (k in store ? store[k] : null),
            setItem: (k, v) => { store[k] = String(v) },
            removeItem: (k) => { delete store[k] },
        },
        setTimeout: (fn) => fn(),
        tabs,
        store,
    }
    return win
}

function counter () {
    const root = document.createElement('div')
    root.innerHTML = `
        <form method="post" action="/sales/direct" data-confirm-overview="/sales/direct/overview">
            <button type="submit" name="save_as_draft" value="1">খসড়া রাখুন</button>
            <button type="submit" name="save_as_draft" value="0" data-overview-trigger data-opens-print data-print-waiting="ছাপা তৈরি হচ্ছে…">নিশ্চিত করুন</button>
        </form>`
    document.body.appendChild(root)
    return root
}

function submitWith (form, button) {
    const event = new Event('submit', { bubbles: true, cancelable: true })
    Object.defineProperty(event, 'submitter', { value: button })
    form.dispatchEvent(event)
    return event
}

describe('ছাপা নতুন ট্যাবে — কাউন্টার যেখানে ছিল সেখানেই', () => {
    let win
    let root
    let form

    beforeEach(() => {
        document.body.innerHTML = ''
        win = fakeWindow()
        root = counter()
        form = root.querySelector('form')
        holdPrintTabOnSubmit(root, win)
    })

    it('"নিশ্চিত করুন" চাপের মুহূর্তেই নামওয়ালা নতুন ট্যাব খোলে, আর কাজের পাতা নড়ে না', () => {
        const before = window.location.href
        submitWith(form, form.querySelector('[data-opens-print]'))

        expect(win.open).toHaveBeenCalledWith('', PRINT_TAB)
        expect(win.tabs[PRINT_TAB].document.body.textContent).toBe('ছাপা তৈরি হচ্ছে…')
        expect(win.store[PRINT_FLAG]).toBe('1')
        expect(window.location.href).toBe(before)
    })

    it('"খসড়া রাখুন" ট্যাব খোলে না — ছাপা নেই', () => {
        submitWith(form, form.querySelector('[value="1"]'))
        expect(win.open).not.toHaveBeenCalled()
    })

    it('থামানো জমা (সীমার দেয়াল, সারাংশের পপ-আপ) ট্যাব খোলে না', () => {
        form.addEventListener('submit', (e) => e.preventDefault())
        submitWith(form, form.querySelector('[data-opens-print]'))
        expect(win.open).not.toHaveBeenCalled()
    })

    it('কাউন্টারের নিজের পাহারা (guardSubmit) কার্ট আটকালে ট্যাব খোলে না', () => {
        const screen = directSale({ catalogue: [], customers: [], walkinId: 0, vatEnabled: false, packs: {}, paymentTermDefault: '', carriers: [],
            drivers: [], depositMethods: [], moneyAccounts: [], draftKey: 'k', hasErrors: false, texts: {}, freeAllowedUrl: '', warehouseId: 1,
            creditRules: { enabled: false }, lots: {}, pendingDrafts: [], resume: null, pendingUrl: '', transferModes: [], approvalNotice: '' })
        screen.viewOnly = true
        form.addEventListener('submit', (e) => screen.guardSubmit(e))
        submitWith(form, form.querySelector('[data-opens-print]'))
        expect(win.open).not.toHaveBeenCalled()
    })

    it('ফিরে আসা কাউন্টার ছাপার ঠিকানা ঐ ট্যাবেই বসায় — একই ট্যাবে নয়', () => {
        submitWith(form, form.querySelector('[data-opens-print]'))
        const page = document.createElement('div')
        page.innerHTML = '<a href="/sales/print/invoice/7?paper=80mm" target="_blank" data-print-tab-url="/sales/print/invoice/7?paper=80mm">ছাপা</a>'
        const before = window.location.href

        const tab = handPrintToTab(page, win)

        expect(win.open).toHaveBeenLastCalledWith('/sales/print/invoice/7?paper=80mm', PRINT_TAB)
        expect(tab.url).toBe('/sales/print/invoice/7?paper=80mm')
        expect(page.querySelector('a').getAttribute('data-print-opened')).toBe('1')
        expect(win.store[PRINT_FLAG]).toBeUndefined()
        expect(window.location.href).toBe(before)
    })

    it('ছাপা না এলে (খসড়া, সইয়ের অপেক্ষা, ভুল) ফাঁকা ট্যাবটা বন্ধ হয়', () => {
        submitWith(form, form.querySelector('[data-opens-print]'))
        const tab = win.tabs[PRINT_TAB]

        handPrintToTab(document.createElement('div'), win)

        expect(tab.closed).toBe(true)
        expect(win.store[PRINT_FLAG]).toBeUndefined()
    })

    it('কিছু ধরে রাখা না থাকলে সাধারণ পাতা কোনো ট্যাব খোলে না', () => {
        handPrintToTab(document.createElement('div'), win)
        expect(win.open).not.toHaveBeenCalled()
    })
})
