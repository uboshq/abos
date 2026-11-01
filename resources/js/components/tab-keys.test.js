// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { wireActions } from './actions.js'

/*
 * ট্যাবের সারিতে তীর-চাবি — কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ১৪ (WAI-ARIA tabs)।
 * ⓘ ← → পাশের ট্যাব (ঘুরে আসে), Home/End প্রথম আর শেষ; ট্যাবটা চাপা হয় আর তাতে মনোযোগ যায়।
 */

let clicked

beforeEach(() => {
    document.body.innerHTML = `
      <nav role="tablist">
        <button type="button" role="tab" data-k="a">ক</button>
        <button type="button" role="tab" data-k="b">খ</button>
        <button type="button" role="tab" data-k="c">গ</button>
      </nav>
      <input id="outside">`
    clicked = []
    document.querySelectorAll('[role="tab"]').forEach(b => b.addEventListener('click', () => clicked.push(b.dataset.k)))
    wireActions(document.body)
})

const press = (key, el) => el.dispatchEvent(new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }))
const tab = (k) => document.querySelector(`[data-k="${k}"]`)

describe('ট্যাবের তীর-চাবি', () => {
    it('moves to the next and previous tab, wrapping around, and opens it', () => {
        press('ArrowRight', tab('a'))
        expect(clicked, '⛔ → পাশের ট্যাব খোলে না।').toEqual(['b'])
        expect(document.activeElement, '⛔ → চাপলে মনোযোগ পাশের ট্যাবে যায় না।').toBe(tab('b'))

        press('ArrowLeft', tab('a'))
        expect(clicked.at(-1), '⛔ প্রথম ট্যাবে ← শেষেরটায় ঘুরে যায় না।').toBe('c')
    })

    it('jumps to the first and last tab with Home and End', () => {
        press('End', tab('a'))
        expect(clicked.at(-1), '⛔ End শেষ ট্যাবে যায় না।').toBe('c')
        press('Home', tab('c'))
        expect(clicked.at(-1), '⛔ Home প্রথম ট্যাবে যায় না।').toBe('a')
    })

    it('leaves arrows alone outside a tab row', () => {
        const event = new window.KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true })
        document.getElementById('outside').dispatchEvent(event)
        expect(clicked, '⛔ ট্যাবের বাইরেও তীর-চাবি ট্যাব বদলায়।').toEqual([])
        expect(event.defaultPrevented, '⛔ ট্যাবের বাইরের তীর-চাবি থামানো হলো।').toBe(false)
    })
})
