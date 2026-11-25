// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { foldRowActs, spot } from './fold-row-acts.js'

const page = (ui, cell, wrap = (t) => t) => {
    document.documentElement.dataset.ui = ui
    document.documentElement.lang = 'bn'
    document.body.innerHTML = `<main>${wrap(`<table><thead><tr><th>নাম</th><th class="text-start"></th></tr></thead>
        <tbody><tr><td>ক</td><td>${cell}</td></tr></tbody></table>`)}</main>`
}

describe('row acts fold into the one small menu', () => {
    beforeEach(() => { document.body.innerHTML = '' })

    it('moves the form whole, and names the column', () => {
        page('rose', '<form method="POST" action="/x"><input type="hidden" name="_token" value="t"><button>মুছুন</button></form>')
        expect(foldRowActs()).toBe(1)
        expect(document.querySelector('[data-folded-acts] form input[name=_token]').value).toBe('t')
        expect(document.querySelectorAll('td')[1].firstElementChild.matches('[data-folded-acts-button]')).toBe(true)
        expect(document.querySelectorAll('th')[1].textContent).toBe('অ্যাকশন')
    })

    it('leaves the other looks as they are', () => {
        for (const ui of ['fiori', 'dynamic', 'odoo']) {
            page(ui, '<button>মুছুন</button>')
            expect(foldRowActs()).toBe(0)
        }
    })

    it('never touches an entry grid, a row that has its menu, or a lone sign', () => {
        page('rose', '<button>সরান</button>', (t) => `<form>${t}</form>`)
        expect(foldRowActs()).toBe(0)
        page('rose', '<div x-data="rowActions"><button>…</button></div>')
        expect(foldRowActs()).toBe(0)
        page('rose', '<a href="/n">+</a>')
        expect(foldRowActs()).toBe(0)
        page('rose', 'শুধু লেখা')
        expect(foldRowActs()).toBe(0)
    })

    it('opens inside the screen', () => {
        const view = { width: 1000, height: 600 }
        expect(spot({ top: 100, bottom: 130, right: 990 }, { width: 200, height: 150 }, view)).toEqual({ top: 134, left: 790 })
        expect(spot({ top: 500, bottom: 530, right: 100 }, { width: 200, height: 150 }, view)).toEqual({ top: 346, left: 8 })
    })
})
