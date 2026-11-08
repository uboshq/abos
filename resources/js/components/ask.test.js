// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { ask, piecesOf, toneOf } from './ask.js'
import { wireActions } from './actions.js'

/*
 * ⭐ "নিশ্চিত?" পর্দার মাঝখানে, বড় আর রঙিন — মালিক, ৮ নভেম্বর ২০২৬: অনুমোদনে "পপ আপটা একদম উপরে চলে যায় … স্কিনের
 * মাঝামাঝি" · "কালারফুল সুন্দর ডিজাইন"। ⛔ ব্রাউজারের `confirm()` আর ডাকা হয় না।
 */
describe('ask', () => {
    it('picks the colour from the work — approve green, cancel or delete red, else blue', () => {
        expect(toneOf('34,000.00 টাকার কাগজে অনুমোদন দেওয়া হবে। নিশ্চিত?')).toBe('ok')
        expect(toneOf('এই খসড়া বাতিল হবে। নিশ্চিত?')).toBe('danger')
        expect(toneOf('এই দৃশ্যটা মুছবেন?')).toBe('danger')
        expect(toneOf('আবার পাঠাবেন?')).toBe('info')
        expect(toneOf('অনুমোদন', 'danger')).toBe('danger')
    })

    it('lifts the money out of the sentence, and keeps the rest as plain text', () => {
        expect(piecesOf('34,000.00 টাকার কাগজে অনুমোদন দেওয়া হবে।')).toEqual([
            { money: true, text: '34,000.00' },
            { money: false, text: ' টাকার কাগজে অনুমোদন দেওয়া হবে।' },
        ])
        expect(piecesOf('<b>x</b>').every((p) => ! p.money)).toBe(true)
    })

    it('shows in the middle as its own box with the figure big, and answers no or yes', async () => {
        document.body.innerHTML = ''
        const answer = ask('34,000.00 টাকার কাগজে অনুমোদন দেওয়া হবে। নিশ্চিত?')
        const box = document.querySelector('dialog[data-ask-dialog]')

        expect(box).not.toBeNull()
        expect(box.className).toContain('abos-ask--ok')
        expect(box.querySelector('.abos-ask__money').textContent).toBe('34,000.00')

        box.querySelector('[data-ask-yes]').click()
        expect(await answer).toBe(true)
        expect(document.querySelector('dialog[data-ask-dialog]')).toBeNull()

        const second = ask('মুছবেন?')
        document.querySelector('[data-ask-no]').click()
        expect(await second).toBe(false)
    })
})

describe('data-confirm forms', () => {
    const flush = () => new Promise((r) => setTimeout(r, 0))

    it('a "no" stops the paper, a "yes" sends it once — and the browser confirm is never called', async () => {
        document.body.innerHTML = '<form id="f" data-confirm="34,000.00 টাকার কাগজে অনুমোদন দেওয়া হবে। নিশ্চিত?"><button id="go" type="submit">অনুমোদন দিন</button></form>'
        window.confirm = () => { throw new Error('browser confirm called') }
        wireActions(document)
        const form = document.querySelector('#f')
        let sent = 0
        form.addEventListener('submit', (e) => { e.preventDefault(); sent++ })

        document.querySelector('#go').click()
        await flush()
        expect(document.querySelectorAll('dialog[data-ask-dialog]').length).toBe(1)
        expect(sent).toBe(0)
        document.querySelector('[data-ask-no]').click()
        await flush()
        expect(sent).toBe(0)

        document.querySelector('#go').click()
        await flush()
        document.querySelector('[data-ask-yes]').click()
        await flush()
        await flush()
        expect(sent).toBe(1)
        expect(document.querySelectorAll('dialog[data-ask-dialog]').length).toBe(0)
    })
})
