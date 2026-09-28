// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { afterEach, beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './index.js'

/*
 * বাতিলের কারণ — "বাতিল চাপা" আর "ফাঁকা কারণ" দুইটা আলাদা অবস্থা।
 *
 * ── ⛔ লাইভে ধরা (hp2 → abos-79, ২৭ সেপ্টেম্বর ২০২৬) ─────────────────
 * "ভাউচার বাতিল"-এ কারণ ফাঁকা রাখলে কিছুই হত না — কোনো বার্তা নেই।
 * ⓘ `prompt()` বাতিল চাপলে দেয় `null` (মানুষ মত বদলেছেন — চুপ থাকাই ঠিক),
 * আর ফাঁকা রেখে ঠিক চাপলে দেয় `''` (মানুষ বাতিল করতেই চান — কেন আটকাল
 * সেটা বলতে হবে)। ⚠️ দুইটাকে এক ধরলে দ্বিতীয়জন নীরবে আটকে থাকেন।
 */

const warnings = []
let answer = null
const told = []

beforeAll(() => {
    Alpine.setErrorHandler((error, el, expression) => {
        warnings.push(`Alpine Expression Error: ${error?.message} — ${expression}`)
    })

    window.prompt = () => answer
    window.alert = (text) => told.push(text)

    window.Alpine = Alpine
    registerComponents(Alpine)
    Alpine.start()
})

afterEach(() => {
    told.length = 0
})

async function mount (config) {
    warnings.length = 0
    const host = document.createElement('div')
    host.innerHTML = `
        <form method="post" x-data="reasonPrompt(${config})" x-on:submit="ask($event)">
            <input type="hidden" name="cancel_reason" x-ref="reason">
            <button type="submit">বাতিল</button>
        </form>`
    document.body.appendChild(host)
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()

    return host.querySelector('form')
}

function submit (form) {
    const event = new Event('submit', { bubbles: false, cancelable: true })
    form.dispatchEvent(event)

    return event
}

describe('বাতিলের কারণ', () => {
    it('⛔ ফাঁকা কারণে থামে আর বলে কেন', async () => {
        const form = await mount("{ question: 'কেন বাতিল?', empty: 'কারণ লিখতেই হবে।' }")
        answer = '   '

        expect(submit(form).defaultPrevented).toBe(true)
        expect(told, 'ফাঁকা কারণে কোনো বার্তা আসেনি — মানুষ নীরবে আটকে').toEqual(['কারণ লিখতেই হবে।'])
        expect(warnings).toEqual([])
    })

    it('পাতা নিজের বার্তা না দিলে প্রশ্নটাই আবার দেখায় — তবু নীরব নয়', async () => {
        const form = await mount("{ question: 'কেন বাতিল?' }")
        answer = ''

        expect(submit(form).defaultPrevented).toBe(true)
        expect(told).toEqual(['কেন বাতিল?'])
    })

    it('বাতিল চাপলে (null) চুপ — মানুষ মত বদলেছেন', async () => {
        const form = await mount("{ question: 'কেন বাতিল?', empty: 'কারণ লিখতেই হবে।' }")
        answer = null

        expect(submit(form).defaultPrevented).toBe(true)
        expect(told).toEqual([])
    })

    it('কারণ লিখলে জমা যায়, আর কারণটা ঘরে বসে', async () => {
        const form = await mount("{ question: 'কেন বাতিল?', empty: 'কারণ লিখতেই হবে।' }")
        answer = 'ভুল গ্রাহক'

        expect(submit(form).defaultPrevented).toBe(false)
        expect(form.querySelector('input[name="cancel_reason"]').value).toBe('ভুল গ্রাহক')
        expect(told).toEqual([])
    })
})
