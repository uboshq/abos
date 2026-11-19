// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { keepOnScreen } from './keep-on-screen.js'

/* ⭐ Dynamic-এর মডিউল-তালিকা পর্দার ডানে কাটে না (মালিক, ১৯ নভেম্বর ২০২৬ রাত) */
function box (left, width) {
    const el = document.createElement('div')
    el.getBoundingClientRect = () => ({ left, right: left + width, width })
    return el
}

describe('keepOnScreen', () => {
    it('moves a list that runs past the right edge back inside, by exactly the overflow', () => {
        const el = box(1500, 700)

        expect(keepOnScreen(el, { win: { innerWidth: 1920 } })).toBe(1500 + 700 - (1920 - 8))
        expect(el.style.insetInlineStart).toBe('-288px')
    })

    it('leaves a list that fits where it is', () => {
        const el = box(200, 600)

        expect(keepOnScreen(el, { win: { innerWidth: 1920 } })).toBe(0)
        expect(el.style.insetInlineStart).toBe('')
    })

    it('never pushes it past the left edge', () => {
        const el = box(100, 2000)

        expect(keepOnScreen(el, { win: { innerWidth: 1920 } })).toBe(92)
    })
})
