// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { installSelectSearch, matches, MIN, searches } from './select-search.js'

const page = (n) => `<main><form><label for="p">পণ্য</label><select id="p"><option value="">—</option>${
    Array.from({ length: n }, (_, i) => `<option value="${i + 1}">P-${i + 1} তেল ${i + 1} লিটার</option>`).join('')}</select></form></main>`

describe('a long choice can be searched', () => {
    beforeEach(() => {
        document.documentElement.dataset.ui = 'blue'
        document.documentElement.lang = 'bn'
    })

    it('finds every word in any order, and both digits', () => {
        expect(matches('P-5 তেল 5 লিটার', 'লিটার তেল')).toBe(true)
        expect(matches('P-5 তেল 5 লিটার', '৫ তেল')).toBe(true)
        expect(matches('P-5 তেল 5 লিটার', 'চাল')).toBe(false)
    })

    it('a short list keeps the browser list; another look is untouched', () => {
        document.body.innerHTML = page(MIN - 2)
        expect(searches(document.querySelector('select'))).toBe(false)
        document.body.innerHTML = page(MIN)
        expect(searches(document.querySelector('select'))).toBe(true)
        document.documentElement.dataset.ui = 'sap'
        expect(installSelectSearch()).toBe(null)
    })

    it('choosing sets the real select and tells the page', () => {
        document.body.innerHTML = page(20)
        const select = document.querySelector('select')
        const heard = []
        select.addEventListener('input', () => heard.push('input'))
        select.addEventListener('change', () => heard.push('change'))
        const tool = installSelectSearch()
        tool.open(select)
        const input = document.querySelector('[data-select-search] input')
        input.value = 'P-17'
        input.dispatchEvent(new Event('input'))
        expect(document.querySelectorAll('[data-select-search] [role=option]').length).toBe(1)
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
        expect(select.value).toBe('17')
        expect(heard).toEqual(['input', 'change'])
        expect(document.querySelector('[data-select-search]')).toBe(null)
    })
})
