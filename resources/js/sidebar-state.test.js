// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { FLOATS_BELOW, sidebarStart, sidebarStore } from './sidebar-state.js'

/*
 * ⭐ বাঁ মেনু দুই-কলামের টাকার ফর্মে — মালিক আর fe, ২৪ অক্টোবর ২০২৬ (আদায়ের ছবি ১২, ১৩)। আসল ব্রাউজারে মাপা: খোলা মেনুর সাথে
 * বিলের টেবিল ১৩৬৬-এ ৫৪px আর ১৪৪০-এ ৩৪px কম, ১৫৩৬-এ পুরো ধরে; রেলে থাকলে ১৩৬৬-এই ধরে।
 */

function memory (stored = null) {
    const box = { value: stored, writes: 0 }

    return {
        box,
        getItem: () => box.value,
        setItem: (_k, v) => { box.value = v; box.writes++ },
    }
}

function page (twoColumnForm) {
    const doc = document.implementation.createHTMLDocument('x')
    if (twoColumnForm) doc.documentElement.setAttribute('data-two-column-form', '')

    return doc
}

describe('শুরুর অবস্থা', () => {
    it('মানুষের বাছাই না থাকলে দুই-কলামের টাকার ফর্মে ১৫৩৬-এর নিচে মেনু রেলে, তার উপরে আজকের মতো খোলা', () => {
        expect(sidebarStart({ stored: null, twoColumnForm: true, width: 1366 })).toEqual({ collapsed: true, floats: true })
        expect(sidebarStart({ stored: null, twoColumnForm: true, width: 1440 })).toEqual({ collapsed: true, floats: true })
        expect(sidebarStart({ stored: null, twoColumnForm: true, width: FLOATS_BELOW })).toEqual({ collapsed: false, floats: false })
        expect(FLOATS_BELOW).toBe(1536)
    })

    it('মানুষের নিজের বাছাই থাকলে সেটাই — "খোলা" থাকলে খোলা, কিন্তু ভাসমান (পাতা ঠেলে না)', () => {
        expect(sidebarStart({ stored: 'open', twoColumnForm: true, width: 1366 })).toEqual({ collapsed: false, floats: true })
        expect(sidebarStart({ stored: 'collapsed', twoColumnForm: true, width: 1920 })).toEqual({ collapsed: true, floats: false })
    })

    it('অন্য পাতায় আজকের মতোই — রেল কেবল মানুষের বাছাইয়ে, কখনো ভাসে না', () => {
        expect(sidebarStart({ stored: null, twoColumnForm: false, width: 1366 })).toEqual({ collapsed: false, floats: false })
        expect(sidebarStart({ stored: 'collapsed', twoColumnForm: false, width: 1366 })).toEqual({ collapsed: true, floats: false })
    })
})

describe('store', () => {
    it('ভাসমান মেনু খুললে জায়গা রেলেরই, আর পাতার উপরে ভাসে', () => {
        const store = sidebarStore({ doc: page(true), win: { innerWidth: 1366 }, storage: memory('open') })

        expect([store.rail, store.overlay]).toEqual([true, true])
        store.toggle()
        expect([store.collapsed, store.rail, store.overlay]).toEqual([true, true, false])
    })

    it('ভাসমান মেনুর বাইরে চাপ দিলে বন্ধ, কিন্তু মানুষের বাছাই যেমন ছিল; ≡ বোতামের চাপ নিজে কিছু করে না', () => {
        const storage = memory('open')
        const store = sidebarStore({ doc: page(true), win: { innerWidth: 1366 }, storage })
        const toggle = document.createElement('button')
        toggle.setAttribute('data-sidebar-toggle', '')
        document.body.appendChild(toggle)

        store.closeOverlay({ target: toggle })
        expect(store.overlay).toBe(true)

        store.closeOverlay({ target: document.body })
        expect(store.overlay).toBe(false)
        expect([storage.box.value, storage.box.writes]).toEqual(['open', 0])
    })

    it('জানালা চওড়া হলে আর ভাসে না, সরু হলে আবার ভাসে', () => {
        const store = sidebarStore({ doc: page(true), win: { innerWidth: 1366 }, storage: memory('open') })

        store.resized(1600)
        expect([store.floats, store.rail]).toEqual([false, false])
        store.resized(1300)
        expect([store.floats, store.overlay]).toEqual([true, true])
    })
})
