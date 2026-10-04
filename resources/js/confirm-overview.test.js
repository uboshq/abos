// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { guardConfirmOverview } from './confirm-overview.js'

/*
 * ⭐ নিশ্চিতের আগে সারাংশের পপ-আপ — মালিক, ৪ অক্টোবর ২০২৬ ([[confirm-overview.js]])।
 * দাবি: "নিশ্চিত" চাপলে জমা থামে আর সারাংশ আসে; পপ-আপের "নিশ্চিত" আসল জমা দেয়, "খসড়া" save_as_draft=1 দিয়ে,
 * "ফিরে যান" কিছুই না; সীমা পার হলে পপ-আপের "নিশ্চিত" বন্ধ; খসড়ার বোতাম সরাসরি জমা দেয় (সারাংশ নয়)।
 */

let root
let calls
let html

function page () {
    root = document.createElement('div')
    root.innerHTML = `
        <form method="post" data-confirm-overview="/sales/direct/overview">
            <input type="hidden" name="_token" value="tok">
            <input type="hidden" name="save_as_draft" value="0">
            <button type="submit" data-overview-trigger>নিশ্চিত করুন</button>
            <button type="submit" data-draft-direct>খসড়া রাখুন</button>
        </form>
        <dialog data-confirm-overview-dialog data-loading="…" data-failed="ব্যর্থ">
            <div data-confirm-overview-body></div>
            <button type="button" data-overview-confirm>নিশ্চিত</button>
            <button type="button" data-overview-draft>খসড়া</button>
            <button type="button" data-overview-back>ফিরে</button>
        </dialog>`
    document.body.appendChild(root)
    calls = []
    guardConfirmOverview(root, async (url, init) => {
        calls.push({ url, init })
        return { text: async () => html }
    })
    const form = root.querySelector('form')
    form.submitted = []
    form.requestSubmit = (submitter) => form.submitted.push(submitter)
    return form
}

function submitWith (form, button) {
    const event = new Event('submit', { bubbles: true, cancelable: true })
    Object.defineProperty(event, 'submitter', { value: button })
    form.dispatchEvent(event)
    return event
}

const tick = () => new Promise(r => setTimeout(r, 0))

beforeEach(() => {
    document.body.innerHTML = ''
    html = '<div data-overview-blocks="0"><h2>বিক্রির সারাংশ</h2><div>নিট বিল: ৳ 1,000.00</div></div>'
})

describe('নিশ্চিতের আগে সারাংশ', () => {
    it('"নিশ্চিত" জমা থামায়, সারাংশ আনে আর দেখায়', async () => {
        const form = page()
        const trigger = form.querySelector('[data-overview-trigger]')

        const event = submitWith(form, trigger)
        await tick()

        expect(event.defaultPrevented).toBe(true)
        expect(calls).toHaveLength(1)
        expect(calls[0].url).toBe('/sales/direct/overview')
        expect(calls[0].init.headers['X-CSRF-TOKEN']).toBe('tok')
        expect(root.querySelector('[data-confirm-overview-body]').textContent).toContain('নিট বিল')
        expect(form.submitted).toHaveLength(0)
    })

    it('পপ-আপের "নিশ্চিত" আসল জমা দেয়, আর দ্বিতীয় জমা আর থামে না', async () => {
        const form = page()
        const trigger = form.querySelector('[data-overview-trigger]')
        submitWith(form, trigger)
        await tick()

        root.querySelector('[data-overview-confirm]').click()

        expect(form.submitted).toEqual([trigger])
        expect(form.querySelector('[name="save_as_draft"]').value).toBe('0')
        const again = submitWith(form, trigger)
        expect(again.defaultPrevented).toBe(false)
    })

    it('পপ-আপের "খসড়া" save_as_draft=1 দিয়ে জমা দেয়; "ফিরে যান" কিছুই না', async () => {
        const form = page()
        const trigger = form.querySelector('[data-overview-trigger]')
        submitWith(form, trigger)
        await tick()
        root.querySelector('[data-overview-back]').click()
        expect(form.submitted).toHaveLength(0)

        submitWith(form, trigger)
        await tick()
        root.querySelector('[data-overview-draft]').click()
        expect(form.querySelector('[name="save_as_draft"]').value).toBe('1')
        expect(form.submitted).toHaveLength(1)
    })

    it('⛔ সার্ভার "নিশ্চিত হবে না" বললে পপ-আপের "নিশ্চিত" বন্ধ', async () => {
        html = '<div data-overview-blocks="1"><div>সীমা পার — ৳ 300.00</div></div>'
        const form = page()
        submitWith(form, form.querySelector('[data-overview-trigger]'))
        await tick()

        expect(root.querySelector('[data-overview-confirm]').disabled).toBe(true)
    })

    it('কাউন্টারের আকার — খসড়া এক জমা-বোতাম: পপ-আপের "খসড়া" ঐ বোতাম দিয়েই জমা দেয়, "নিশ্চিত" নিশ্চিতের বোতাম দিয়ে', async () => {
        const form = page()
        form.querySelector('input[name="save_as_draft"]').remove()
        const trigger = form.querySelector('[data-overview-trigger]')
        trigger.setAttribute('name', 'save_as_draft')
        trigger.setAttribute('value', '0')
        const draftButton = form.querySelector('[data-draft-direct]')
        draftButton.setAttribute('name', 'save_as_draft')
        draftButton.setAttribute('value', '1')

        submitWith(form, trigger)
        await tick()
        root.querySelector('[data-overview-draft]').click()
        expect(form.submitted).toEqual([draftButton])

        submitWith(form, trigger)
        await tick()
        root.querySelector('[data-overview-confirm]').click()
        expect(form.submitted).toEqual([draftButton, trigger])
    })

    it('খসড়ার বোতাম সরাসরি জমা দেয় — সারাংশ নয়', async () => {
        const form = page()
        const event = submitWith(form, form.querySelector('[data-draft-direct]'))
        await tick()

        expect(event.defaultPrevented).toBe(false)
        expect(calls).toHaveLength(0)
    })
})
