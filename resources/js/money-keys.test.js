// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './components/index.js'
import { moneyKeyAction } from './money-keys.js'

/*
 * ⭐ টাকার পর্দায় Enter কিছুই পাঠায় না, বড় লেখার ঘরে নতুন লাইন, Ctrl+Enter মূল বোতাম (fe, ২৪ অক্টোবর ২০২৬) — আসল CSP-Alpine-এ,
 * `x-money-keys` directive দিয়ে ([[money-keys.js]])। ⓘ চার পর্দায় চিহ্নটা আছে কি না PHP-র দাবিতে
 * ([[TheReceiptAndPaymentMirrorEachOtherTest]])।
 */

beforeAll(() => {
    window.Alpine = Alpine
    registerComponents(Alpine)
    Alpine.start()
})

async function mount () {
    const host = document.createElement('div')
    host.innerHTML = `
        <form x-money-keys>
            <input name="amount" id="amount">
            <textarea name="narration" id="narration"></textarea>
            <a href="#" id="cancel">বাতিল</a>
            <button type="submit" name="save_as_draft" value="1" id="draft">খসড়া রাখুন</button>
            <button type="submit" data-page-primary id="primary">পোস্ট</button>
        </form>`
    document.body.appendChild(host)
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()

    return host
}

function press (el, extra = {}) {
    const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true, ...extra })
    el.dispatchEvent(event)

    return event
}

describe('টাকার পর্দায় Enter আর Ctrl+Enter', () => {
    it('নিয়ম: ঘরে Enter থামে, বড় লেখায় আর বোতামে নিজের মতো, Ctrl/⌘+Enter মূল বোতাম', () => {
        const at = tagName => ({ key: 'Enter', target: { tagName } })
        expect(moneyKeyAction(at('INPUT')), '⛔ লেখার ঘরে Enter থামে না').toBe('block')
        expect(moneyKeyAction(at('SELECT')), '⛔ বাছাইয়ের ঘরে Enter থামে না').toBe('block')
        expect(moneyKeyAction(at('TEXTAREA')), '⛔ বড় লেখার ঘরে Enter নতুন লাইন দেয় না').toBe('native')
        expect(moneyKeyAction(at('BUTTON')), '⛔ বোতামে Enter বোতামটা চাপে না').toBe('native')
        expect(moneyKeyAction({ ...at('INPUT'), ctrlKey: true }), '⛔ Ctrl+Enter মূল বোতাম নয়').toBe('primary')
        expect(moneyKeyAction({ ...at('TEXTAREA'), metaKey: true }), '⛔ ⌘+Enter মূল বোতাম নয়').toBe('primary')
        expect(moneyKeyAction({ key: 'a', target: { tagName: 'INPUT' } }), '⛔ অন্য চাবিও থামছে').toBe('none')
    })

    it('আসল পর্দায়: ঘরে Enter কিছু জমা দেয় না, Ctrl+Enter মূল বোতাম চাপে, বড় লেখায় Enter নতুন লাইনের জন্য ছাড়া', async () => {
        const host = await mount()
        let primary = 0
        let draft = 0
        host.querySelector('#primary').addEventListener('click', (e) => { e.preventDefault(); primary++ })
        host.querySelector('#draft').addEventListener('click', (e) => { e.preventDefault(); draft++ })

        const enter = press(host.querySelector('#amount'))
        expect(enter.defaultPrevented, '⛔ টাকার ঘরে Enter থামল না — ব্রাউজার প্রথম জমার বোতাম (খসড়া) চাপত').toBe(true)
        expect(primary + draft, '⛔ ঘরে Enter একটা বোতাম চাপল').toBe(0)

        const newline = press(host.querySelector('#narration'))
        expect(newline.defaultPrevented, '⛔ বিবরণের ঘরে Enter আটকানো — নতুন লাইন হয় না').toBe(false)

        const ctrl = press(host.querySelector('#amount'), { ctrlKey: true })
        expect(ctrl.defaultPrevented, '⛔ Ctrl+Enter-এর ব্রাউজারের নিজের কাজ থামেনি').toBe(true)
        expect(primary, '⛔ Ctrl+Enter মূল বোতাম চাপল না').toBe(1)
        expect(draft, '⛔ Ctrl+Enter খসড়ার বোতাম চাপল').toBe(0)
        host.remove()
    })
})
