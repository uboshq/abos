// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { bottomNav, hiddenAfter } from './bottom-nav.js'

/*
 * ⭐ ফোনের নিচের বার — মালিক, ৩১ অক্টোবর ২০২৬ ("smart kajer kichu daw int standard onuzayi"; Material 3: নিচে স্ক্রলে সরে, উপরে ফেরে)।
 */
describe('স্ক্রলে বার', () => {
    it('নিচে গেলে সরে, উপরে এলে ফেরে, মাথায় সবসময় দেখা যায়, ছোট নড়াচড়ায় যেমন ছিল', () => {
        expect(hiddenAfter({ hidden: false, last: 100, now: 300 }), '⛔ নিচে স্ক্রল করলেও বার সরে না').toBe(true)
        expect(hiddenAfter({ hidden: true, last: 300, now: 200 }), '⛔ উপরে স্ক্রল করলেও বার ফেরে না').toBe(false)
        expect(hiddenAfter({ hidden: true, last: 50, now: 0 }), '⛔ পাতার মাথায় বার লুকানো').toBe(false)
        expect(hiddenAfter({ hidden: true, last: 300, now: 305 }), '⛔ আঙুলের সামান্য কাঁপুনিতে বার লাফায়').toBe(true)
    })

    it('ছোট মেনু খোলা থাকলে বার লুকায় না', () => {
        const win = { scrollY: 500 }
        const nav = bottomNav({ win, doc: document })
        nav.sheet = 'sales'
        nav.scrolled()
        expect(nav.hidden, '⛔ ছোট মেনু খোলা অথচ বার সরে গেল').toBe(false)
    })
})

describe('চালু মডিউলে আবার চাপ', () => {
    it('পাতা নিচে থাকলে মাথায় ফেরে আর লিংকে যায় না; মাথায় থাকলে লিংকেই যায়', () => {
        const calls = []
        const win = { scrollY: 400, scrollTo: (o) => calls.push(o) }
        const nav = bottomNav({ win, doc: document })
        let stopped = false
        nav.again({ preventDefault: () => { stopped = true } })
        expect([stopped, calls.length], '⛔ নিচে থাকা পাতায় আবার চাপ মাথায় ফেরায় না').toEqual([true, 1])

        win.scrollY = 0
        stopped = false
        nav.again({ preventDefault: () => { stopped = true } })
        expect(stopped, '⛔ মাথায় থাকা পাতায় আবার চাপ মডিউলের প্রথম পাতায় যায় না').toBe(false)
    })
})
