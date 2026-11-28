// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { infoTap, prepareMarks } from './info-tap.js'

/*
 * ⓘ শিরোনামের ⓘ — ছোঁয়া আর কীবোর্ডে খোলে, Esc/বাইরে ছোঁয়ায় বন্ধ (WCAG 2.1.1, 1.4.13; impeccable বিষয় ১, ২৮ নভেম্বর ২০২৬)।
 * ⛔ নকল রূপে (SAP/Dynamic/Odoo) কিছুই বদলায় না।
 */
const page = (ui) => {
    document.documentElement.dataset.ui = ui
    document.body.innerHTML = `
        <main>
            <h1 data-page-title>Customers<span data-page-info class="hidden" title="Who buys from us">i</span></h1>
            <h2>Bills<span data-title-note title="Income, receivable and cost of goods sit together">i</span></h2>
            <span data-page-info title="">empty</span>
            <button id="other">other</button>
        </main>`
}

const box = () => document.querySelector('[data-info-box]')
const info = () => document.querySelector('[data-page-info]')
const press = (el, key) => el.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))

describe('info-tap', () => {
    beforeEach(() => { document.body.innerHTML = '' })

    it('copied looks: nothing changes', () => {
        page('tiles')
        expect(infoTap(document)).toBe(false)
        expect(info().hasAttribute('tabindex')).toBe(false)
        expect(info().hasAttribute('role')).toBe(false)
    })

    it('turns every mark with a text into a keyboard button, leaves an empty one alone', () => {
        page('navy')
        prepareMarks(document)
        const marks = document.querySelectorAll('[data-info-ready]')
        expect(marks.length).toBe(2)
        marks.forEach((m) => {
            expect(m.getAttribute('role')).toBe('button')
            expect(m.getAttribute('tabindex')).toBe('0')
            expect(m.getAttribute('aria-expanded')).toBe('false')
        })
        expect(info().getAttribute('aria-label')).toBe('Who buys from us')
        expect(document.querySelector('[data-page-info][title=""]').hasAttribute('role')).toBe(false)
    })

    it('a tap opens the note box, a second tap closes it', () => {
        page('rose')
        infoTap(document)
        info().click()
        expect(box()?.textContent).toBe('Who buys from us')
        expect(box().getAttribute('role')).toBe('tooltip')
        expect(info().getAttribute('aria-expanded')).toBe('true')
        expect(info().getAttribute('aria-describedby')).toBe(box().id)
        info().click()
        expect(box()).toBeNull()
        expect(info().getAttribute('aria-expanded')).toBe('false')
    })

    it('Enter and Space open it from the keyboard; Escape closes it and gives focus back', () => {
        page('blue')
        infoTap(document)
        const note = document.querySelector('[data-title-note]')
        press(note, 'Enter')
        expect(box()?.textContent).toBe('Income, receivable and cost of goods sit together')
        press(note, 'Escape')
        expect(box()).toBeNull()
        expect(document.activeElement).toBe(note)
        press(note, ' ')
        expect(box()).not.toBeNull()
    })

    it('a tap outside closes it, and only one box is ever open', () => {
        page('navy')
        infoTap(document)
        info().click()
        document.querySelector('[data-title-note]').click()
        expect(document.querySelectorAll('[data-info-box]').length).toBe(1)
        document.getElementById('other').click()
        expect(box()).toBeNull()
    })
})
