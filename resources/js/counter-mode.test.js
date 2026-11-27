import { describe, expect, it } from 'vitest'
import { MAIL_WINDOW, MAIN_WINDOW, SIGN_OUT_KEY, installSignOutSync, isMailUrl, nameThisTab, openMail } from './counter-mode.js'

// ⭐ মেইল একটাই নিজের ট্যাবে (মালিক, ২৭ নভেম্বর ২০২৬; ABOS ও NEXUS-এর যৌথ PLAN.md §1)
const O = 'https://demo.adi.com.bd'

function fakeWin (href, name = '', existing = null) {
    const opened = []
    const win = {
        name,
        location: { href, origin: O, assign (u) { win.assigned = u } },
        open (url, target) { opened.push([url, target]); return existing },
        opened,
    }

    return win
}

function fakeTab (href) {
    return { location: { href }, focused: false, focus () { this.focused = true } }
}

describe('mail opens in its own tab', () => {
    it('knows every mail page and nothing else', () => {
        expect(isMailUrl('/mail', O)).toBe(true)
        expect(isMailUrl('/mail?counter=1', O)).toBe(true)
        expect(isMailUrl('/mail/signature', O)).toBe(true)
        expect(isMailUrl('/mailbox', O)).toBe(false)
        expect(isMailUrl('/sales/invoices/5', O)).toBe(false)
    })

    it('a second click brings the open mail tab forward without reloading it', () => {
        const tab = fakeTab(O + '/mail?counter=1')
        const win = fakeWin(O + '/dashboard', MAIN_WINDOW, tab)

        openMail(O + '/mail?counter=1', win)

        expect(win.opened).toEqual([['', MAIL_WINDOW]])
        expect(tab.location.href).toBe(O + '/mail?counter=1')
        expect(tab.focused).toBe(true)
    })

    it('a new tab, or a click that brings work (compose a paper), goes to the address', () => {
        const blank = fakeTab('about:blank')
        openMail(O + '/mail?counter=1', fakeWin(O + '/dashboard', MAIN_WINDOW, blank))
        expect(blank.location.href).toBe(O + '/mail?counter=1')

        const busy = fakeTab(O + '/mail?counter=1')
        openMail(O + '/mail?counter=1&compose=1&paper=invoice&id=9', fakeWin(O + '/sales/invoices/9', MAIN_WINDOW, busy))
        expect(busy.location.href).toContain('paper=invoice')

        const other = fakeTab(O + '/mail/signature')
        openMail(O + '/mail?counter=1', fakeWin(O + '/dashboard', MAIN_WINDOW, other))
        expect(other.location.href).toBe(O + '/mail?counter=1')
    })

    it('a blocked pop-up falls back to the same tab', () => {
        const win = fakeWin(O + '/dashboard', MAIN_WINDOW, null)

        openMail(O + '/mail?counter=1', win)

        expect(win.assigned).toBe(O + '/mail?counter=1')
    })

    it('names the mail tab and the main tab, never a counter tab', () => {
        const mail = fakeWin(O + '/mail?counter=1')
        nameThisTab(mail)
        expect(mail.name).toBe(MAIL_WINDOW)

        const main = fakeWin(O + '/dashboard')
        nameThisTab(main)
        expect(main.name).toBe(MAIN_WINDOW)

        const counter = fakeWin(O + '/sales/direct?counter=1')
        nameThisTab(counter)
        expect(counter.name).toBe('')

        const left = fakeWin(O + '/dashboard', MAIL_WINDOW)
        nameThisTab(left)
        expect(left.name).toBe(MAIN_WINDOW)
    })
})

describe('signing out in one tab signs out the others', () => {
    function bus () {
        const on = {}
        return { on, addEventListener (type, fn) { (on[type] = on[type] || []).push(fn) }, fire (type, ev) { (on[type] || []).forEach(fn => fn(ev)) } }
    }

    it('the sign-out form leaves a note, and another tab goes to the sign-in page', () => {
        const store = {}
        const doc = bus()
        const win = Object.assign(bus(), { location: { pathname: '/mail', assign (u) { win.went = u } }, localStorage: { setItem (k, v) { store[k] = v } } })
        installSignOutSync(doc, win)

        doc.fire('submit', { target: { matches: (s) => s === 'form[action$="/logout"]' } })
        expect(store[SIGN_OUT_KEY]).toBeTruthy()

        win.fire('storage', { key: SIGN_OUT_KEY, newValue: store[SIGN_OUT_KEY] })
        expect(win.went).toBe('/login')
    })

    it('other notes, and the sign-in page itself, do nothing', () => {
        const doc = bus()
        const win = Object.assign(bus(), { location: { pathname: '/login', assign (u) { win.went = u } }, localStorage: { setItem () {} } })
        installSignOutSync(doc, win)

        win.fire('storage', { key: 'something-else', newValue: '1' })
        win.fire('storage', { key: SIGN_OUT_KEY, newValue: '1' })
        expect(win.went).toBeUndefined()
    })
})
