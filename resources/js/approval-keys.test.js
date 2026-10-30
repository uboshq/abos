// @vitest-environment happy-dom
import { beforeAll, beforeEach, describe, expect, it } from 'vitest'
import { approvalKeys, letterOf } from './approval-keys.js'

/*
 * ⭐ অনুমোদন ভাগ ৩, দফা ২০ — কীবোর্ড ← → A S R আর পপ-আপ পর্দা-পাঠকে (পরিকল্পনার পাতা ৭, সারি ২০)।
 *
 * ⛔ আগে: অক্ষর পড়া হত `event.key` থেকে, তাই বাংলা লেআউটে (A চাবিতে "ৃ") A, S, R আর Ctrl+K মরা; পপ-আপ খুললে ফোকাস
 * পেছনেই থাকত, Tab পেছনের পাতায় চলে যেত, আর বন্ধ হলে ফোকাস হারাত।
 *
 * ⓘ পাতাটা ইনবক্সের আসল গড়নের ছোট রূপ ([[decide.blade.php]], [[sheet.blade.php]], [[detail.blade.php]])। Alpine-এর `x-show`
 * এখানে হাতে — বোতাম চাপলে পপ-আপের `style.display` বদলায়, ঠিক যেমন Alpine বদলায়।
 */

const PAGE = `
    <a href="/approval/inbox?open=1" data-approval-prev aria-keyshortcuts="ArrowLeft" id="prev">←</a>
    <a href="/approval/inbox?open=3" data-approval-next aria-keyshortcuts="ArrowRight" id="next">→</a>
    <button type="button" data-opens-dialog id="whole">পুরো কাগজ দেখুন</button>
    <input type="checkbox" data-inbox-pick id="pick1"><input type="checkbox" data-inbox-pick id="pick2">
    <button type="submit" data-bulk-button data-label="বাছাই __N__টা · একসাথে অনুমোদন" id="bulk">x</button>
    <section data-approval-decide>
        <form id="approve-form">
            <textarea data-approval-remarks id="remarks"></textarea>
            <button type="button" data-ask="reject" data-key="r" id="reject">প্রত্যাখ্যান করুন</button>
            <button type="button" data-ask="fix" data-key="s" id="fix">সংশোধনে ফেরত পাঠান</button>
            <button type="submit" data-key="a" id="approve">অনুমোদন দিন</button>
        </form>
    </section>
    <div role="dialog" aria-modal="true" aria-labelledby="reject-title" data-dialog-focus id="reject-dialog" style="display: none">
        <h3 id="reject-title">প্রত্যাখ্যান করুন?</h3>
        <select id="reason"><option>দাম ভুল</option></select>
        <textarea id="why"></textarea>
        <button type="button" id="back">ফিরে যান</button>
        <button type="submit" id="reject-go">প্রত্যাখ্যান করুন</button>
    </div>
    <div role="dialog" aria-modal="true" aria-labelledby="sheet-title" data-dialog-focus id="sheet-dialog" style="display: none">
        <p id="sheet-title">JV-0001</p>
        <button type="button" id="sheet-close">বন্ধ করুন</button>
    </div>`

/** কোন বোতাম বা লিংক চাপা পড়ল — ব্রাউজার যেন সত্যিই কোথাও না যায় */
let pressed = []

const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

const $ = (id) => document.getElementById(id)

/** চাবি চাপা — যেখানে ফোকাস আছে সেখান থেকে, আসল ব্রাউজারের মতো */
function press(key, code, extra = {}) {
    const event = new KeyboardEvent('keydown', { key, code, bubbles: true, cancelable: true, ...extra })

    ;(document.activeElement ?? document.body).dispatchEvent(event)

    return event
}

beforeAll(() => {
    document.body.innerHTML = PAGE
    approvalKeys()

    document.addEventListener('click', (event) => {
        const hit = event.target.closest?.('a[href], button')

        if (! hit) return

        pressed.push(hit.id)

        if (hit.matches('a[href], [type="submit"]')) event.preventDefault()

        // ⓘ Alpine-এর `x-show`-এর জায়গায়
        if (hit.id === 'reject') $('reject-dialog').style.display = ''
        if (hit.id === 'whole') $('sheet-dialog').style.display = ''
        if (hit.id === 'back') $('reject-dialog').style.display = 'none'
        if (hit.id === 'sheet-close') $('sheet-dialog').style.display = 'none'
    })
    document.addEventListener('submit', (event) => event.preventDefault())
})

beforeEach(async () => {
    $('reject-dialog').style.display = 'none'
    $('sheet-dialog').style.display = 'none'
    await settle()
    document.activeElement?.blur?.()
    pressed = []
})

describe('দফা ২০ — চাবির জায়গা, লেআউট নয়', () => {
    it('বাংলা লেআউটেও A, S, R নিজের বোতাম খোলে', () => {
        // ⓘ জাতীয় লেআউটে A, S, R চাবি লেখে "ৃ", "ু", "প" — `code` তবু KeyA, KeyS, KeyR
        for (const [key, code, id] of [['ৃ', 'KeyA', 'approve'], ['ু', 'KeyS', 'fix'], ['প', 'KeyR', 'reject']]) {
            pressed = []
            press(key, code)
            expect(pressed, `⛔ দফা ২০: বাংলা লেআউটে ${code} চাপলে "${id}" খোলে না — মরা শর্টকাট।`).toEqual([id])
            $('reject-dialog').style.display = 'none'
        }

        expect(letterOf({ key: 'A', code: 'KeyA' })).toBe('a')
        expect(letterOf({ key: 'a' }), '⛔ দফা ২০: জায়গা না জানলে লেখা অক্ষরে ফেরে না।').toBe('a')
    })

    it('বাংলা লেআউটে Ctrl+K মন্তব্যের ঘরে নেয়, ওপরের খোঁজায় নয়', () => {
        const event = press('ন', 'KeyK', { ctrlKey: true })

        expect(document.activeElement, '⛔ দফা ২০: বাংলা লেআউটে Ctrl+K মন্তব্যের ঘরে যায় না।').toBe($('remarks'))
        expect(event.defaultPrevented, '⛔ দফা ২০: Ctrl+K ধরেও ওপরের বারের খোঁজাকে থামায়নি — দুইটাই খুলত।').toBe(true)
    })

    it('← → আগের আর পরের কাগজ খোলে', () => {
        press('ArrowRight', 'ArrowRight')
        press('ArrowLeft', 'ArrowLeft')

        expect(pressed, '⛔ দফা ২০: ← বা → আগের/পরের কাগজ খোলে না।').toEqual(['next', 'prev'])
    })

    it('লেখার ঘরে বা খোলা পপ-আপে অক্ষর কেবল অক্ষর', async () => {
        $('remarks').focus()
        press('a', 'KeyA')
        press('ArrowRight', 'ArrowRight')
        expect(pressed, '⛔ দফা ২০: মন্তব্য লিখতে "a" চাপলেই অনুমোদনের প্রশ্ন বা পরের কাগজ।').toEqual([])

        $('reject').click()
        await settle()
        pressed = []
        $('why').focus()
        $('why').blur()
        press('a', 'KeyA')
        expect(pressed, '⛔ দফা ২০: প্রত্যাখ্যানের পপ-আপ খোলা থাকতে "A" পেছনে অনুমোদন চাপে।').toEqual([])
    })

    it('Shift বা Alt-এর সাথে অক্ষর কিছু করে না', () => {
        press('A', 'KeyA', { shiftKey: true })
        press('a', 'KeyA', { altKey: true })

        expect(pressed, '⛔ দফা ২০: Shift/Alt-সহ অক্ষরে অনুমোদন চাপা পড়ে — ব্রাউজারের শর্টকাট কাড়ে।').toEqual([])
    })
})

describe('দফা ২০ — পপ-আপ পর্দা-পাঠকে', () => {
    it('খুললে ফোকাস ভেতরের প্রথম ঘরে, বন্ধ হলে যে বোতাম খুলেছিল তাতে', async () => {
        press('প', 'KeyR')
        await settle()
        expect(document.activeElement, '⛔ দফা ২০: পপ-আপ খুললেও ফোকাস পেছনের পাতায় — পর্দা-পাঠক পপ-আপটা পড়ে না।').toBe($('reason'))

        $('back').click()
        await settle()
        expect(document.activeElement, '⛔ দফা ২০: পপ-আপ বন্ধের পরে ফোকাস হারায় — "প্রত্যাখ্যান" বোতামে ফেরে না।').toBe($('reject'))

        $('whole').focus()
        $('whole').click()
        await settle()
        expect(document.activeElement, '⛔ দফা ২০: "পুরো কাগজ" খুললেও ফোকাস পেছনে।').toBe($('sheet-close'))
        $('sheet-close').click()
        await settle()
        expect(document.activeElement, '⛔ দফা ২০: "পুরো কাগজ" বন্ধের পরে ফোকাস বোতামে ফেরে না।').toBe($('whole'))
    })

    it('Tab আর Shift+Tab পপ-আপের ভেতরেই ঘোরে', async () => {
        $('reject').click()
        await settle()

        $('reject-go').focus()
        const forward = press('Tab', 'Tab')
        expect(forward.defaultPrevented && document.activeElement === $('reason'),
            '⛔ দফা ২০: শেষ বোতাম থেকে Tab পপ-আপ ছেড়ে পেছনের পাতায় যায়।').toBe(true)

        const backward = press('Tab', 'Tab', { shiftKey: true })
        expect(backward.defaultPrevented && document.activeElement === $('reject-go'),
            '⛔ দফা ২০: প্রথম ঘর থেকে Shift+Tab পপ-আপ ছেড়ে যায়।').toBe(true)

        $('why').focus()
        const middle = press('Tab', 'Tab')
        expect(middle.defaultPrevented, '⛔ দফা ২০: মাঝের ঘরে Tab আটকে যায় — ব্রাউজারের নিজের ক্রম চলে না।').toBe(false)
    })
})

describe('বাছাইয়ের গোনা', () => {
    it('কয়টা বাছা তা বোতামে, শূন্যে বোতাম বন্ধ', () => {
        expect($('bulk').disabled, '⛔ দফা ২০: কিছু বাছা না থাকলেও "একসাথে অনুমোদন" চালু — মরা বোতাম।').toBe(true)

        $('pick1').checked = true
        $('pick1').dispatchEvent(new Event('change', { bubbles: true }))

        expect($('bulk').textContent).toBe('বাছাই 1টা · একসাথে অনুমোদন')
        expect($('bulk').disabled).toBe(false)
    })
})
