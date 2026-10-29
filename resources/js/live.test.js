// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import Alpine from '@alpinejs/csp'
import morph from '@alpinejs/morph'
import { EVERY_MS, isFresh, isTouched, placeFresh } from './live.js'

Alpine.plugin(morph)

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

/**
 * ⛔ a6, ২৩ অক্টোবর ২০২৬: আদায়ের "সইয়ে পাঠান"-এর সারাংশ-পপ-আপ খোলার দেড় সেকেন্ড পরে বন্ধ আর খালি — দশ সেকেন্ডের
 * নতুন পাতায় পপ-আপটা বন্ধ, আর morph সেটাই বসাত। ⓘ নকল নয়, আসল Alpine.morph — `open` খুলে নেওয়াটা আসলটাই করে।
 */
it('leaves an open popup open with what it shows, and still updates the page around it', () => {
    document.body.innerHTML = '<main id="main"><p id="due">১,০০০</p><dialog data-confirm-overview-dialog><div data-confirm-overview-body>খাতায় যা যাবে</div></dialog></main>'
    document.querySelector('dialog').setAttribute('open', '')
    const fresh = page('<main id="main"><p id="due">৫,০০০</p><dialog data-confirm-overview-dialog><div data-confirm-overview-body></div></dialog></main>')

    placeFresh(document, fresh, (from, to, options) => Alpine.morph(from, to, options))

    expect(document.querySelector('dialog').open).toBe(true)
    expect(document.querySelector('[data-confirm-overview-body]').textContent).toBe('খাতায় যা যাবে')
    expect(document.querySelector('#due').textContent).toBe('৫,০০০')
})

/**
 * ⛔ a6, ২৪ অক্টোবর ২০২৬, আসল ব্রাউজারে মাপা: খরচের ভাউচারে হাতে খোলা ভাঁজ দশ সেকেন্ডে বন্ধ হত। ভাঁজ মানুষের, ভিতরের লেখা
 * সার্ভারের — খোলা থাকে খোলা, বন্ধ থাকে বন্ধ, লেখা নতুন হয়। ⓘ আসল Alpine.morph।
 */
it('keeps a fold the person opened or shut as it is, and still updates what is inside', () => {
    document.body.innerHTML = '<main id="main"><details id="opened"><summary>কর</summary><p>পুরনো</p></details><details id="shut" open><summary>নোট</summary><p>পুরনো</p></details></main>'
    document.querySelector('#opened').open = true
    document.querySelector('#shut').open = false
    const fresh = page('<main id="main"><details id="opened"><summary>কর</summary><p>নতুন</p></details><details id="shut" open><summary>নোট</summary><p>নতুন</p></details></main>')

    placeFresh(document, fresh, (from, to, options) => Alpine.morph(from, to, options))

    expect(document.querySelector('#opened').open).toBe(true)
    expect(document.querySelector('#shut').open).toBe(false)
    expect(document.querySelector('#opened p').textContent).toBe('নতুন')
    expect(document.querySelector('#shut p').textContent).toBe('নতুন')
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
