// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './index.js'
import { filePick, megabytesLabel } from './forms.js'

/*
 * ⭐ বড় ফাইল বাছার মুহূর্তেই ফেরানো — fe, ২১ অক্টোবর ২০২৬ (লাইভে post_max_size ৮ MB: তার বেশি ফাইল PHP গোটা ফর্মসহ ফেলে দিত)।
 *
 * ⓘ সীমা সার্ভারের [[UploadLimit]] থেকে আসে (ঘরের `max-bytes`); এখানে দেখা: সীমার বেশি ফাইলে ঘর খালি (তাই জমায় যায় না),
 * বাংলা বার্তা সংখ্যাসহ, `.svg`-র নিজের সীমা, আর সীমার ভেতরের ফাইল আগের মতোই নামসহ থাকে। শেষে আসল CSP-Alpine-এ, পাতার মতো।
 */

const TOO_BIG = 'ফাইলটা :mb MB-র বেশি — ছোট করে আবার দিন'
const MB = 1024 * 1024

/** নির্দিষ্ট মাপের ফাইল — ভেতরটা শূন্য, কেবল আকারটা দরকার */
const sized = (name, bytes) => new File([new Uint8Array(Math.floor(bytes))], name)

describe('megabytesLabel — সার্ভারের সংখ্যার হুবহু', () => {
    it('ইংরেজি অঙ্ক, বড়জোর এক দশমিক, নিচের দিকে', () => {
        expect(megabytesLabel(8 * MB - 256 * 1024)).toBe('7.7')
        expect(megabytesLabel(2 * MB - 256 * 1024)).toBe('1.7')
        expect(megabytesLabel(MB)).toBe('1')
        expect(megabytesLabel(12 * MB - 256 * 1024)).toBe('11.7')
    })
})

describe('filePick — সীমার বাইরের ফাইল', () => {
    const limit = { none: 'কোনো ফাইল বাছা হয়নি', many: ':count টা', max: 2 * MB - 256 * 1024, svgMax: MB, tooBig: TOO_BIG }

    it('সীমার বেশি ছবি: ঘর খালি, বার্তা সংখ্যাসহ; তারপর ছোট ছবি: বার্তা যায়, নাম আসে', () => {
        const box = filePick(limit)
        const input = { files: [sized('photo.jpg', 2 * MB)], value: 'C:\\fakepath\\photo.jpg' }

        box.pick({ target: input })
        expect(input.value).toBe('')
        expect(box.picked).toBe(false)
        expect(box.label).toBe('কোনো ফাইল বাছা হয়নি')
        expect(box.refused).toBe('ফাইলটা 1.7 MB-র বেশি — ছোট করে আবার দিন')

        box.pick({ target: { files: [sized('small.png', 100 * 1024)], value: 'x' } })
        expect(box.refused).toBe('')
        expect(box.label).toBe('small.png')
        expect(box.picked).toBe(true)
    })

    it('SVG-র নিজের সীমা — ১.২ MB-র SVG ফেরত, ১.২ MB-র PNG নয়', () => {
        const box = filePick(limit)

        box.pick({ target: { files: [sized('mark.SVG', 1.2 * MB)], value: 'x' } })
        expect(box.refused).toBe('ফাইলটা 1 MB-র বেশি — ছোট করে আবার দিন')

        box.pick({ target: { files: [sized('mark.png', 1.2 * MB)], value: 'x' } })
        expect(box.refused).toBe('')
    })

    it('সীমা না দিলে (বাকি সব ফাইল-ঘর) কিছুই ফেরত নয় — আগের মতো', () => {
        const box = filePick({ none: '—', many: ':count' })

        box.pick({ target: { files: [sized('huge.pdf', 30 * MB)], value: 'x' } })
        expect(box.refused).toBe('')
        expect(box.label).toBe('huge.pdf')
    })
})

describe('আসল CSP-Alpine-এ, ঘরের মতোই', () => {
    beforeAll(() => {
        window.Alpine = Alpine
        registerComponents(Alpine)
        Alpine.start()
    })

    it('বড় ফাইল বাছলে বার্তা দেখা যায় আর ঘরে কোনো ফাইল থাকে না', async () => {
        const host = document.createElement('div')
        host.innerHTML = `
            <span x-data="filePick({ none: 'none', many: ':count', max: ${2 * MB - 256 * 1024}, svgMax: ${MB}, tooBig: '${TOO_BIG}' })">
                <input type="file" name="logo" @change="pick($event)">
                <span x-text="label"></span>
                <span x-show="refused" x-text="refused" role="alert" data-file-refused></span>
            </span>`
        document.body.appendChild(host)
        await new Promise(r => setTimeout(r, 0))
        await Alpine.nextTick()

        const input = host.querySelector('input')
        Object.defineProperty(input, 'files', { configurable: true, value: [sized('photo.jpg', 2 * MB)] })
        input.dispatchEvent(new Event('change', { bubbles: true }))
        await Alpine.nextTick()

        const alert = host.querySelector('[data-file-refused]')
        expect(alert.textContent).toBe('ফাইলটা 1.7 MB-র বেশি — ছোট করে আবার দিন')
        expect(alert.style.display).not.toBe('none')
        expect(input.value).toBe('')
        host.remove()
    })
})
