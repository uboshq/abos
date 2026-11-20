// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest'
import { blocksForm, blocksLink, guardAllBranchesView, markWrites, parseAllow } from './all-branches-view.js'

/* ⛔ "সব শাখা" = কেবল দেখা — লেখার বোতাম ধূসর, চাপা আর জমা থামে; খোলা দরজা আর দেখা চলে (মালিক, ২০ নভেম্বর ২০২৬) */

const BASE = 'http://abos.test/sales/collection'
const ALLOW = JSON.stringify(['^/branch/switch/?$', '^/approvals/[^/]+/approve/?$', '^/logout/?$'])

function page (html, { on = true } = {}) {
    const root = document.documentElement

    if (on) {
        root.setAttribute('data-all-branches-view', '')
        root.setAttribute('data-all-branches-hint', 'লিখতে হলে ওপর থেকে একটা শাখা বাছুন')
        root.setAttribute('data-all-branches-allow', ALLOW)
    }

    document.body.innerHTML = html
}

afterEach(() => {
    const root = document.documentElement
    root.removeAttribute('data-all-branches-view')
    root.removeAttribute('data-all-branches-hint')
    root.removeAttribute('data-all-branches-allow')
    document.body.innerHTML = ''
})

describe('parseAllow', () => {
    it('reads the server patterns and falls back to none on bad input', () => {
        expect(parseAllow(ALLOW)).toHaveLength(3)
        expect(parseAllow('{oops')).toEqual([])
        expect(parseAllow(null)).toEqual([])
    })
})

describe('blocksForm / blocksLink', () => {
    const allow = parseAllow(ALLOW)

    it('stops a writing form, lets an allowed door, a GET filter and a marked form through', () => {
        page(`
            <form id="w" method="POST" action="/sales/collection"><button>Save</button></form>
            <form id="sw" method="POST" action="/branch/switch"><button>Switch</button></form>
            <form id="ok" method="POST" action="/approvals/12/approve"><button>Approve</button></form>
            <form id="f" method="GET" action="/sales/collection"><button>Filter</button></form>
            <div data-allow-all-branches><form id="m" method="POST" action="/views"><button>Keep view</button></form></div>
        `)

        expect(blocksForm(document.getElementById('w'), allow, BASE)).toBe(true)
        expect(blocksForm(document.getElementById('sw'), allow, BASE)).toBe(false)
        expect(blocksForm(document.getElementById('ok'), allow, BASE)).toBe(false)
        expect(blocksForm(document.getElementById('f'), allow, BASE)).toBe(false)
        expect(blocksForm(document.getElementById('m'), allow, BASE)).toBe(false)
    })

    it('greys only create and edit links', () => {
        page(`
            <a id="c" href="/sales/collection/create">New</a>
            <a id="e" href="/sales/collection/abc/edit">Edit</a>
            <a id="s" href="/sales/collection/abc">Show</a>
            <a id="h" href="#create">Anchor</a>
        `)

        expect(blocksLink(document.getElementById('c'), BASE)).toBe(true)
        expect(blocksLink(document.getElementById('e'), BASE)).toBe(true)
        expect(blocksLink(document.getElementById('s'), BASE)).toBe(false)
        expect(blocksLink(document.getElementById('h'), BASE)).toBe(false)
    })
})

describe('guardAllBranchesView', () => {
    it('does nothing at all when the shell did not draw the marker', () => {
        page('<a id="c" href="/sales/collection/create">New</a>', { on: false })

        expect(guardAllBranchesView(document)).toBeNull()
        expect(document.getElementById('c').hasAttribute('aria-disabled')).toBe(false)
    })

    it('greys the write controls with the hint, and leaves the allowed ones alone', () => {
        page(`
            <a id="c" href="/sales/collection/create">New</a>
            <form id="w" method="POST" action="/sales/collection"><button id="save">Save</button></form>
            <button id="outside" form="w" type="submit">Save from the row menu</button>
            <form method="POST" action="/approvals/7/approve"><button id="approve">Approve</button></form>
        `)

        guardAllBranchesView(document)

        for (const id of ['c', 'save', 'outside']) {
            const el = document.getElementById(id)
            expect(el.getAttribute('aria-disabled'), id).toBe('true')
            expect(el.hasAttribute('data-all-branches-off'), id).toBe(true)
            expect(el.getAttribute('title'), id).toBe('লিখতে হলে ওপর থেকে একটা শাখা বাছুন')
        }

        expect(document.getElementById('approve').hasAttribute('aria-disabled')).toBe(false)
    })

    it('stops the click on a greyed link and the submit of a writing form', () => {
        page(`
            <a id="c" href="/sales/collection/create">New</a>
            <form id="w" method="POST" action="/sales/collection"><input name="amount"></form>
            <form id="ok" method="POST" action="/logout"></form>
        `)
        guardAllBranchesView(document)

        const click = new MouseEvent('click', { bubbles: true, cancelable: true })
        document.getElementById('c').dispatchEvent(click)
        expect(click.defaultPrevented).toBe(true)

        const submit = new Event('submit', { bubbles: true, cancelable: true })
        document.getElementById('w').dispatchEvent(submit)
        expect(submit.defaultPrevented).toBe(true)

        const logout = new Event('submit', { bubbles: true, cancelable: true })
        let reached = false
        document.addEventListener('submit', (e) => { reached = true; e.preventDefault() }, { once: true })
        document.getElementById('ok').dispatchEvent(logout)
        expect(reached).toBe(true)
    })

    it('greys a write control drawn later (Alpine lists, popups)', async () => {
        page('<div id="host"></div>')
        guardAllBranchesView(document)

        document.getElementById('host').innerHTML = '<a id="late" href="/purchase/bill/9/edit">Edit</a>'
        await new Promise(resolve => setTimeout(resolve, 0))

        expect(document.getElementById('late').getAttribute('aria-disabled')).toBe('true')
    })

    it('marks again safely — calling twice changes nothing', () => {
        page('<a id="c" href="/sales/collection/create" title="old">New</a>')
        const guard = { allow: parseAllow(ALLOW), hint: 'hint', base: BASE }

        markWrites(document, guard)
        markWrites(document, guard)

        expect(document.getElementById('c').getAttribute('title')).toBe('hint')
    })
})
