import { describe, expect, it } from 'vitest'
import { EVERY_MS, isFresh, isTouched, placeFresh } from './live.js'

// ⭐ রিয়েল-টাইম সিঙ্ক — খোলা পাতা কখন নতুন তথ্য আনবে (মালিক, ১০ অক্টোবর ২০২৬)
describe('isFresh', () => {
    it('says so only when the company wrote after the page was drawn', () => {
        expect(isFresh('1000', 1001)).toBe(true)
        expect(isFresh('1000', 1000)).toBe(false)
        expect(isFresh('1000', 999)).toBe(false)
    })

    it('a company nobody has written to yet is not news', () => {
        expect(isFresh('0', 0)).toBe(false)
        expect(isFresh(undefined, undefined)).toBe(false)
    })
})

// ⭐ মালিক, ১১ অক্টোবর ২০২৬: "10 second e koro"
it('asks every ten seconds', () => {
    expect(EVERY_MS).toBe(10000)
})

/**
 * ⓘ Alpine.morph-এর বদলে একটা সরল নকল: প্রতিটা উপাদান-জোড়ায় `updating` ডাকে, skip না হলে লেখা আর মান নতুনটায় বদলায়।
 * আসল morph-এর হুকের চুক্তি একই — (from, to, childrenOnly, skip)।
 */
function fakeMorph (from, to, options) {
    const walk = (a, b) => {
        let skipped = false
        options.updating(a, b, false, () => { skipped = true })
        if (skipped) return
        if (a.children.length === 0) {
            a.textContent = b.textContent
            if ('value' in a && 'value' in b) a.value = b.value
            return
        }
        Array.from(a.children).forEach((child, i) => {
            if (b.children[i]) walk(child, b.children[i])
        })
    }
    walk(from, to)
}

function page (html) {
    return new DOMParser().parseFromString(html, 'text/html')
}

// ⭐ মালিক: "ze infota update hoyeche seta sei jaygay auto bosbe keu ter pabena"
describe('placeFresh', () => {
    it('puts the new figures in place, in the main part, the footer and the topbar counts', () => {
        const doc = page('<main id="main"><p id="due">১,০০০</p></main><footer data-footer><span>পুরনো</span></footer><a data-live="approvals"><span>২</span></a>')
        const fresh = page('<main id="main"><p id="due">৫,০০০</p></main><footer data-footer><span>নতুন</span></footer><a data-live="approvals"><span>৩</span></a>')

        expect(placeFresh(doc, fresh, fakeMorph)).toBe(3)
        expect(doc.querySelector('#due').textContent).toBe('৫,০০০')
        expect(doc.querySelector('footer span').textContent).toBe('নতুন')
        expect(doc.querySelector('[data-live="approvals"] span').textContent).toBe('৩')
    })

    it('never touches a box the person is typing in, and still updates the rest of the form', () => {
        const doc = page('<main id="main"><form><input id="qty" value="10"><span id="stock">৫০</span></form></main>')
        const fresh = page('<main id="main"><form><input id="qty" value=""><span id="stock">৪০</span></form></main>')
        const qty = doc.querySelector('#qty')
        qty.value = '12'
        qty.dataset.liveTouched = '1'

        placeFresh(doc, fresh, fakeMorph)

        expect(doc.querySelector('#qty').value).toBe('12')
        expect(doc.querySelector('#stock').textContent).toBe('৪০')
    })

    it('matches topbar counts by name, so a missing icon does not shift the others', () => {
        const doc = page('<a data-live="approvals"><span>১</span></a><a data-live="signed-unposted"><span>১</span></a>')
        const fresh = page('<a data-live="signed-unposted"><span>৪</span></a>')

        placeFresh(doc, fresh, fakeMorph)

        expect(doc.querySelector('[data-live="approvals"] span').textContent).toBe('১')
        expect(doc.querySelector('[data-live="signed-unposted"] span').textContent).toBe('৪')
    })
})

describe('isTouched', () => {
    it('is true for a box with focus or one changed by hand, never for plain text', () => {
        const doc = page('<input id="a"><input id="b"><span id="c">x</span>')
        const b = doc.querySelector('#b')
        b.dataset.liveTouched = '1'

        expect(isTouched(doc.querySelector('#a'), doc)).toBe(false)
        expect(isTouched(b, doc)).toBe(true)
        expect(isTouched(doc.querySelector('#c'), doc)).toBe(false)
    })
})
