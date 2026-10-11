// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { fillField } from './document-scan.js'

/*
 * ⛔ OCR-এর প্রস্তাবিত তারিখ জমার ঘরে পৌঁছায় (১১ অক্টোবর ২০২৬, documents রিভিউ ⛔৬; [[fillField]])।
 *
 * ⓘ `x-ui.date`-এ `data-ocr-field` দেখার লেখার ঘরে বসে, জমা যায় লুকানো ISO ঘর থেকে। আগে মান কেবল দেখার ঘরে বসত — মানুষ তারিখ দেখতেন,
 * জমা যেত খালি। এখন একই কম্পোনেন্টের নিজের `type="date"` ঘরে বসে `change` ছোড়ে — যেটা `abosDate.fromNative()` শোনে।
 */
function dateBox () {
    document.body.innerHTML = `
        <div class="relative">
            <input type="text" data-ocr-field="date">
            <input type="hidden" name="ocr_fields[date]">
            <input type="date" tabindex="-1">
        </div>`

    const native = document.querySelector('input[type="date"]')
    const heard = []
    native.addEventListener('change', (e) => heard.push(e.target.value))

    return { text: document.querySelector('[data-ocr-field="date"]'), native, heard }
}

describe('OCR-এর প্রস্তাব বসানো', () => {
    it('puts a date into the component\'s own date box and fires change', () => {
        const { text, native, heard } = dateBox()

        fillField(text, '2026-10-05')

        expect(native.value).toBe('2026-10-05')
        expect(heard).toEqual(['2026-10-05'])
        // ⓘ দেখার ঘর কম্পোনেন্ট নিজে বসায় (fromNative) — এখানে হাতে বসানো হয় না, তাই দুই রকম লেখা থাকে না
        expect(text.value).toBe('')
    })

    it('leaves a plain field as before', () => {
        document.body.innerHTML = '<div><input type="text" data-ocr-field="party"></div>'
        const input = document.querySelector('input')

        fillField(input, 'Rahim Traders')

        expect(input.value).toBe('Rahim Traders')
    })

    it('does not push a non-ISO value into a date box', () => {
        const { text, native, heard } = dateBox()

        fillField(text, '05/10/2026')

        expect(native.value).toBe('')
        expect(heard).toEqual([])
        expect(text.value).toBe('05/10/2026')
    })
})
