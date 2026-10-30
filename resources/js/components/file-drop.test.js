// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './index.js'
import { acceptsFile } from './forms.js'

/*
 * ⭐ ফাইলের ঘরে টেনে আনা — রসিদ-পরিশোধ মিল-তালিকা #৩৭ (নকশা পাতা ২: "ব্যাংক স্লিপ / স্ক্রিনশট টেনে আনুন বা বাছুন"), ২৫ অক্টোবর ২০২৬।
 *
 * দাবি (আসল CSP-Alpine-এ, x-ui.file-input-এর একই নির্দেশে):
 *  ১. ফেলা ফাইল বোতামের **সেই আসল ঘরটাই** ভরে (একই নাম), আর ঘরের নিজের `change` শ্রোতা (স্ক্যানার) একবার চলে; নাম দেখায়।
 *  ২. `accept`-এ না পড়া ধরন ফেরে — ঘর খালি, কারণ নামসহ, কোনো `change` নয়।
 *  ৩. একটার ঘরে দুইটা ফেললে কিছুই বসে না, কারণ বলে।
 *  ৪. বড় ফাইল টেনে আনলেও বোতামের মতোই ফেরে (সীমা পরীক্ষা `change`-এ)।
 *  ৫. "সরান" — একটার ঘর খালি হয়; কয়েকটার ঘরে কেবল সেটাই যায়, বাকিগুলো থাকে।
 */

const MB = 1024 * 1024
const file = (name, type, bytes = 10) => new File([new Uint8Array(bytes)], name, { type })

/** ⓘ x-ui.file-input-এর একই নির্দেশ — x-data-র লেখা একক উদ্ধৃতিতে (পাতার @js-এর মতো নিরাপদ) */
function mount ({ multiple = false, accept = 'image/png,image/jpeg,image/webp,application/pdf', max = 0 } = {}) {
    const host = document.createElement('div')
    host.innerHTML = `
        <span x-data="filePick({ none: 'none', many: ':count files', max: ${max}, svgMax: 0, tooBig: 'BIG :mb', wrongType: 'WRONG :name', oneOnly: 'ONE' })"
              data-file-input data-file-drop
              @dragenter="dragOver($event)" @dragover="dragOver($event)" @dragleave="dragLeave($event)" @drop="drop($event)"
              :class="over ? 'is-over' : ''">
            <input type="file" name="attachment${multiple ? '[]' : ''}" class="sr-only" accept="${accept}" ${multiple ? 'multiple' : ''} @change="pick($event)">
            <span x-text="label"></span>
            <template x-for="(fileName, index) in names" :key="index">
                <button type="button" @click="remove($event, index)" data-file-remove x-text="fileName"></button>
            </template>
            <span x-show="refused" x-text="refused" data-file-refused></span>
        </span>`
    document.body.appendChild(host)

    const input = host.querySelector('input')
    const box = host.querySelector('[data-file-drop]')
    let changes = 0
    input.addEventListener('change', () => { changes++ })

    return { host, input, box, changes: () => changes }
}

async function settle () {
    await new Promise(resolve => setTimeout(resolve, 0))
    await Alpine.nextTick()
}

async function dropOn (box, files) {
    const bag = new DataTransfer()
    files.forEach(f => bag.items.add(f))
    const event = new Event('drop', { bubbles: true, cancelable: true })
    Object.defineProperty(event, 'dataTransfer', { value: bag })
    box.dispatchEvent(event)
    await settle()

    return event
}

const namesIn = (input) => Array.from(input.files || []).map(f => f.name)

describe('acceptsFile — ব্রাউজারের বাছাইয়ের একই নিয়ম', () => {
    it('ধরন, ধরনের দল আর নামের শেষ', () => {
        expect(acceptsFile(file('a.pdf', 'application/pdf'), 'image/*,application/pdf'), '⛔ PDF-এর ঘরে PDF নেওয়া হয় না').toBe(true)
        expect(acceptsFile(file('a.jpg', 'image/jpeg'), 'image/*'), '⛔ image/* ছবি নেয় না').toBe(true)
        expect(acceptsFile(file('rows.CSV', ''), '.csv,text/csv'), '⛔ .csv নামের শেষ (বড় হাতের) মেলে না').toBe(true)
        expect(acceptsFile(file('a.txt', 'text/plain'), 'image/*,application/pdf'), '⛔ লেখার ফাইল ছবির ঘরে ঢুকে গেল').toBe(false)
        expect(acceptsFile(file('a.txt', 'text/plain'), ''), '⛔ accept না থাকলেও ফাইল ফেরে').toBe(true)
    })
})

describe('টেনে আনার বাক্স — আসল CSP-Alpine-এ', () => {
    beforeAll(() => {
        window.Alpine = Alpine
        registerComponents(Alpine)
        Alpine.start()
    })

    it('১ — ফেলা ফাইল বোতামের সেই আসল ঘরটাই ভরে, আর ঘরের শ্রোতা চলে', async () => {
        const { host, input, box, changes } = mount()
        await settle()

        const event = await dropOn(box, [file('slip.pdf', 'application/pdf')])

        expect(event.defaultPrevented, '⛔ ব্রাউজার ফেলা ফাইলটা নিজে খুলে বসত — বাক্স ঘটনাটা থামায় না').toBe(true)
        expect(namesIn(input), '⛔ ফেলা ফাইল আসল ঘরে বসেনি — জমায় যেত না').toEqual(['slip.pdf'])
        expect(input.name, '⛔ ঘরের নাম বদলেছে — সার্ভার অন্য নাম পড়ে').toBe('attachment')
        expect(changes(), '⛔ ফেলা ফাইলে ঘরের change চলেনি — স্ক্যানার আর সীমা পরীক্ষা বাদ পড়ত').toBe(1)
        expect(host.querySelector('[x-text="label"]').textContent, '⛔ ফেলা ফাইলের নাম দেখায় না').toBe('slip.pdf')
        host.remove()
    })

    it('২ — accept-এ না পড়া ধরন ফেরে, ঘর অবিকল', async () => {
        const { host, input, box, changes } = mount()
        await settle()

        await dropOn(box, [file('notes.txt', 'text/plain')])

        expect(namesIn(input), '⛔ ঘরের ধরনের বাইরের ফাইল টেনে এনে বসানো গেল').toEqual([])
        expect(changes(), '⛔ ফেরানো ফাইলেও change ছোঁড়া হল').toBe(0)
        expect(host.querySelector('[data-file-refused]').textContent, '⛔ ভুল ধরনের কারণ নামসহ বলা হয় না').toBe('WRONG notes.txt')
        host.remove()
    })

    it('৩ — একটার ঘরে দুইটা ফেললে কিছুই বসে না', async () => {
        const { host, input, box } = mount()
        await settle()

        await dropOn(box, [file('a.pdf', 'application/pdf'), file('b.pdf', 'application/pdf')])

        expect(namesIn(input), '⛔ একটার ঘরে কয়েকটা ফাইল বসে গেল').toEqual([])
        expect(host.querySelector('[data-file-refused]').textContent, '⛔ একটার ঘরে কয়েকটা ফেলার কারণ বলা হয় না').toBe('ONE')
        host.remove()
    })

    it('৪ — বড় ফাইল টেনে আনলেও বোতামের মতোই ফেরে', async () => {
        const { host, input, box } = mount({ max: MB })
        await settle()

        await dropOn(box, [file('huge.pdf', 'application/pdf', 2 * MB)])

        expect(namesIn(input), '⛔ সীমার বেশি ফাইল টেনে এনে বসানো গেল — PHP গোটা ফর্ম ফেলে দিত').toEqual([])
        expect(host.querySelector('[data-file-refused]').textContent, '⛔ টেনে আনা বড় ফাইলের কারণ বলা হয় না').toBe('BIG 1')
        host.remove()
    })

    it('৫ — "সরান": একটার ঘর খালি; কয়েকটার ঘরে কেবল সেটাই যায়', async () => {
        const single = mount()
        await settle()
        await dropOn(single.box, [file('slip.pdf', 'application/pdf')])
        single.host.querySelector('[data-file-remove]').click()
        await settle()

        expect(namesIn(single.input), '⛔ "সরান" চাপার পরেও ফাইল ঘরে রয়ে গেল — জমায় যেত').toEqual([])
        expect(single.host.querySelector('[x-text="label"]').textContent, '⛔ সরানোর পরেও পুরনো নাম দেখায়').toBe('none')
        single.host.remove()

        const many = mount({ multiple: true })
        await settle()
        await dropOn(many.box, [file('a.pdf', 'application/pdf'), file('b.png', 'image/png')])
        expect(namesIn(many.input), '⛔ কয়েকটার ঘরে ফেলা ফাইলগুলো বসেনি').toEqual(['a.pdf', 'b.png'])

        many.host.querySelector('[data-file-remove]').click()
        await settle()

        expect(namesIn(many.input), '⛔ একটা সরাতে গিয়ে অন্যগুলোও গেল, বা সেটা রয়ে গেল').toEqual(['b.png'])
        expect(Array.from(many.host.querySelectorAll('[data-file-remove]')).map(b => b.textContent), '⛔ বাকি ফাইলের নাম দেখায় না').toEqual(['b.png'])
        many.host.remove()
    })
})
