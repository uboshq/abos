// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { MOVES, nextMove, startLoadingScreen } from './live-logo.js'

/*
 * ⭐ জীবন্ত লোগো — দশটা নড়াচড়া এলোমেলো ক্রমে, বিরতি ছাড়া, একই নড়াচড়া পরপর দুবার নয়;
 * লোড হওয়ার পর্দা কেবল সত্যিকারের পাতা-বদলে (মালিক, ১ নভেম্বর ২০২৬)।
 */
describe('পরের নড়াচড়া', () => {
    it('দশটাই আসে, আর একই নড়াচড়া পরপর দুবার কখনো নয়', () => {
        expect(MOVES.length, '⛔ নড়াচড়া দশটা নয়').toBe(10)
        const seen = new Set()
        let previous = null
        for (let i = 0; i < 400; i++) {
            const move = nextMove(previous)
            expect(move, '⛔ একই নড়াচড়া পরপর দুবার — লোগো থেমে আছে মনে হয়').not.toBe(previous)
            seen.add(move)
            previous = move
        }
        expect(seen.size, '⛔ দশটার কয়েকটা কখনো আসে না').toBe(10)
    })

    it('বাছাই সত্যিই এলোমেলো — ভাগ্যের সংখ্যা বদলালে নড়াচড়া বদলায়', () => {
        expect(nextMove('rise', () => 0), '⛔ এলোমেলো সংখ্যা মানা হয় না').toBe('type')
        expect(nextMove('rise', () => 0.999), '⛔ এলোমেলো সংখ্যা মানা হয় না').toBe('flip')
    })
})

describe('লোড হওয়ার পর্দা', () => {
    afterEach(() => { vi.useRealTimers(); document.body.innerHTML = '' })

    const setup = () => {
        document.body.innerHTML = '<div data-abos-loading></div><a id="go" href="/sales">বিক্রি</a>'
            + '<a id="dl" href="/report.pdf" download>ডাউনলোড</a><a id="out" href="https://example.org/">বাইরে</a>'
            + '<a id="tab" href="/sales" target="_blank">নতুন ট্যাব</a>'
        const screen = document.querySelector('[data-abos-loading]')
        startLoadingScreen(document, window)
        return screen
    }
    const click = (id) => document.getElementById(id).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }))

    it('অন্য পাতার লিংকে ৩০০ms পরে দেখায় — তার আগে নয়', () => {
        vi.useFakeTimers()
        const screen = setup()
        click('go')
        vi.advanceTimersByTime(299)
        expect(screen.hasAttribute('data-on'), '⛔ দ্রুত পাতাতেও পর্দা ঝলক দেয়').toBe(false)
        vi.advanceTimersByTime(2)
        expect(screen.hasAttribute('data-on'), '⛔ দেরি হওয়া পাতা-বদলে লোগোর পর্দা আসে না').toBe(true)
    })

    it('ডাউনলোড, বাইরের সাইট আর নতুন ট্যাবে দেখায় না — নইলে পর্দা আটকে থাকত', () => {
        vi.useFakeTimers()
        const screen = setup()
        for (const id of ['dl', 'out', 'tab']) {
            click(id)
            vi.advanceTimersByTime(400)
            expect(screen.hasAttribute('data-on'), `⛔ ${id}: পাতা বদলায় না, অথচ লোডের পর্দা এল`).toBe(false)
        }
    })

    it('ব্রাউজারের "পেছনে" ফিরলে পর্দা সরে যায়', () => {
        vi.useFakeTimers()
        const screen = setup()
        screen.setAttribute('data-on', '')
        window.dispatchEvent(new Event('pageshow'))
        expect(screen.hasAttribute('data-on'), '⛔ পেছনে ফিরেও লোগোর পর্দা পাতা ঢেকে রাখে').toBe(false)
    })
})
