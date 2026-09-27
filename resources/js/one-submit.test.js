// @vitest-environment happy-dom
import { beforeAll, describe, expect, it } from 'vitest'
import { guardOneSubmit } from './one-submit.js'

/*
 * একটা ফর্ম একবারই জমা — কিন্তু যে জমা কেউ থামিয়েছে, সেটা "জমা হয়েছে" নয়।
 *
 * ── ⛔ লাইভে ধরা (hp2 → abos-79, ২৭ সেপ্টেম্বর ২০২৬) ─────────────────
 * "ভাউচার বাতিল"-এ কারণ ফাঁকা রাখলে বোতামটা চিরকালের জন্য বন্ধ হয়ে যেত,
 * কোনো বার্তা ছাড়া। ⓘ কারণ-জিজ্ঞাসা (`reasonPrompt`) জমাটা থামাত, কিন্তু
 * এই পাহারা সেটা দেখত না — ফর্মে "জমা হয়েছে" লিখে বোতাম বন্ধ করত, আর
 * আসল কারণ লিখে আবার চাপলেও জমা আটকে থাকত, পাতা রিফ্রেশ না করা পর্যন্ত।
 */

beforeAll(() => {
    guardOneSubmit()
})

function form (html = '<button type="submit">জমা</button>') {
    const el = document.createElement('form')
    el.method = 'post'
    el.innerHTML = html
    document.body.appendChild(el)

    return el
}

/** ব্রাউজারের মতো জমা — ফর্মের নিজের শ্রোতা আগে, তারপর document-এর */
function submit (el) {
    const event = new Event('submit', { bubbles: true, cancelable: true })
    el.dispatchEvent(event)

    return event
}

const tick = () => new Promise(r => setTimeout(r, 0))

describe('অন্য কেউ থামালে পাহারা সরে দাঁড়ায়', () => {
    it('⛔ কারণ-জিজ্ঞাসা জমা থামালে বোতাম বন্ধ হয় না, আবার চেষ্টা করা যায়', async () => {
        const el = form()
        let reason = ''

        // ⓘ reasonPrompt-এর মতো: কারণ না পেলে থামায়
        el.addEventListener('submit', (event) => {
            if (! reason) event.preventDefault()
        })

        submit(el)
        await tick()

        expect(el.dataset.submitted, 'থামানো জমাকে "জমা হয়েছে" ধরা হয়েছে').toBeUndefined()
        expect(el.querySelector('button').disabled, 'থামানো জমায় বোতাম বন্ধ হয়ে গেছে').toBe(false)

        reason = 'ভুল করে লেখা'
        const second = submit(el)

        expect(second.defaultPrevented, 'কারণ লিখে আবার চাপলেও জমা আটকে আছে').toBe(false)
        expect(el.dataset.submitted).toBe('1')
    })
})

describe('পাহারা এখনো কাজ করে', () => {
    it('সত্যিকারের দ্বিতীয় জমা থামে, আর বোতাম বন্ধ হয়', async () => {
        const el = form()

        expect(submit(el).defaultPrevented).toBe(false)
        await tick()

        expect(el.querySelector('button').disabled).toBe(true)
        expect(submit(el).defaultPrevented, 'দ্বিতীয় জমা সার্ভারে চলে যাচ্ছে').toBe(true)
    })

    it('GET ফর্ম (খোঁজা, ছাঁকনি) ছোঁয়া হয় না', () => {
        const el = form()
        el.method = 'get'

        submit(el)

        expect(submit(el).defaultPrevented).toBe(false)
        expect(el.dataset.submitted).toBeUndefined()
    })
})
