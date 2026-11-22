// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { refreshMatchTotal } from './match-total.js'

/*
 * ⭐ এক ব্যাংক-সারি, কয়েকটা কাগজ — টিকের চলতি মোট (মালিক, ২১ নভেম্বর ২০২৬)।
 * ⓘ মোট সারির অঙ্কের সমান হলে তবেই "মেলান" চালু; মোট ইংরেজি অঙ্কে।
 */
function form () {
    const el = document.createElement('form')
    el.dataset.matchTotal = ''
    el.dataset.lineAmount = '100000.0000'
    el.innerHTML = `
        <input type="checkbox" data-share="50000.0000">
        <input type="checkbox" data-share="50000.0000">
        <input type="checkbox" data-share="40000.0000">
        <b data-ticked-total></b>
        <button type="submit" data-match-submit>Match</button>`
    document.body.appendChild(el)

    return el
}

describe('match total', () => {
    it('enables Match only when the ticked total equals the line', () => {
        const el = form()
        const boxes = el.querySelectorAll('input')

        expect(refreshMatchTotal(el).state).toBe('unequal')
        expect(el.querySelector('[data-match-submit]').disabled).toBe(true)

        boxes[0].checked = true
        boxes[2].checked = true
        expect(refreshMatchTotal(el).state).toBe('unequal')
        expect(el.querySelector('[data-ticked-total]').textContent).toBe('90,000.00')

        boxes[2].checked = false
        boxes[1].checked = true
        const result = refreshMatchTotal(el)
        expect(result.state).toBe('equal')
        expect(result.ticked).toBe(2)
        expect(el.querySelector('[data-ticked-total]').textContent).toBe('1,00,000.00')
        expect(el.querySelector('[data-match-submit]').disabled).toBe(false)
    })
})
