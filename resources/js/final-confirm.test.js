// @vitest-environment happy-dom
import { describe, expect, it, vi } from 'vitest'
import { askFinal, guardFinalConfirm, ARM_MS } from './final-confirm.js'

/*
 * ⭐ শেষবার পাকা করা (মালিক, ২৮ নভেম্বর ২০২৬) — "হ্যাঁ" চাপলে তবেই জমা, "ফিরে যান"-এ কিছুই না,
 * খোলার মুহূর্তে "হ্যাঁ" বন্ধ (দুবার Enter পার হয় না), আর খসড়ার বোতাম জমার আগে থামে।
 */
const page = () => {
    document.body.innerHTML = `
      <form data-final-confirm>
        <button type="submit" name="save_as_draft" value="1" id="draft">খসড়া</button>
        <dialog data-final-dialog="confirm"><button data-final-back>ফিরে</button><button data-final-yes>হ্যাঁ</button></dialog>
        <dialog data-final-dialog="draft"><button data-final-back>ফিরে</button><button data-final-yes>হ্যাঁ</button></dialog>
      </form>`
    const form = document.querySelector('form')
    document.querySelectorAll('dialog').forEach((d) => { d.showModal = () => d.setAttribute('open', ''); d.close = () => d.removeAttribute('open') })
    return form
}

describe('askFinal', () => {
    it('submits only after "yes", and "yes" is locked for a moment after opening', () => {
        const form = page()
        const proceed = vi.fn()
        let arm
        askFinal(form, 'confirm', proceed, (fn) => { arm = fn })
        const dialog = form.querySelector('[data-final-dialog="confirm"]')
        const yes = dialog.querySelector('[data-final-yes]')
        expect(dialog.hasAttribute('open')).toBe(true)
        expect(yes.disabled).toBe(true)
        yes.click()
        expect(proceed).not.toHaveBeenCalled()
        arm()
        yes.click()
        expect(proceed).toHaveBeenCalledTimes(1)
        expect(dialog.hasAttribute('open')).toBe(false)
        expect(ARM_MS).toBeGreaterThanOrEqual(300)
    })

    it('"go back" closes and does nothing', () => {
        const form = page()
        const proceed = vi.fn()
        askFinal(form, 'confirm', proceed, (fn) => fn())
        form.querySelector('[data-final-dialog="confirm"] [data-final-back]').click()
        expect(proceed).not.toHaveBeenCalled()
    })

    it('a form without the popup goes straight on (other looks unchanged)', () => {
        document.body.innerHTML = '<form></form>'
        const proceed = vi.fn()
        askFinal(document.querySelector('form'), 'confirm', proceed)
        expect(proceed).toHaveBeenCalledTimes(1)
    })
})

describe('guardFinalConfirm', () => {
    it('stops the draft button and asks first', () => {
        const form = page()
        guardFinalConfirm(document)
        const event = new Event('submit', { bubbles: true, cancelable: true })
        Object.defineProperty(event, 'submitter', { value: form.querySelector('#draft') })
        form.dispatchEvent(event)
        expect(event.defaultPrevented).toBe(true)
        expect(form.querySelector('[data-final-dialog="draft"]').hasAttribute('open')).toBe(true)
    })
})
