// @vitest-environment happy-dom
import { beforeAll, beforeEach, describe, expect, it } from 'vitest'
import { listKeys } from './list-keys.js'

/*
 * তালিকার কীবোর্ড — প্রতিটা রূপে, কেবল নেভিতে নয় (C-10)।
 *
 * ── ⛔ কী ছিল, ২৭ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
 * `listKeys()` প্রথমেই দেখত পাতায় `[data-look-hints]` আছে কি না, আর
 * সেটা আঁকে কেবল নেভি রূপ। ⚠️ ফলে বাকি ন'টা রূপে `/`, `N`, ↑ ↓, ↵
 * — একটাও চলত না, অথচ নেভিতে চলত বলে "তালিকার শর্টকাট আছে" কথাটা
 * সত্যি শোনাত।
 *
 * ⓘ তাই এখানে পাতায় `[data-look-hints]` **নেই** — ঠিক অন্য ন'টা রূপের
 * মতো। শ্রোতা একবারই বসে (`beforeAll`), আর প্রতিটা দাবি নতুন পাতা পায়।
 */

const PAGE = `
    <aside><input type="search" id="menu-filter"></aside>
    <div data-command-bar>
        <a data-page-primary href="/customers/create" id="create">নতুন</a>
    </div>
    <input type="search" name="q" data-quick-find id="find">
    <button type="button" id="plain">বোতাম</button>
    <textarea id="note"></textarea>
    <select id="pick"><option>১</option></select>
    <div contenteditable="true" id="rich">লেখা</div>
    <table class="ui-list">
        <thead><tr><th>নাম</th></tr></thead>
        <tbody>
            <tr><td><a href="/customers/1">এক</a></td></tr>
            <tr><td><a href="/customers/2">দুই</a></td></tr>
            <tr><td><a href="/customers/3">তিন</a></td></tr>
            <tr><td>যোগফল</td></tr>
        </tbody>
    </table>`

/** কোন লিংকে ক্লিক পড়ল — ব্রাউজার যেন সত্যিই কোথাও না যায় */
const clicked = []

beforeAll(() => {
    document.body.innerHTML = PAGE

    // ⛔ অন্য ন'টা রূপের মতো — চিহ্নটা নেই
    expect(document.querySelector('[data-look-hints]')).toBeNull()

    listKeys()

    document.addEventListener('click', (event) => {
        const link = event.target.closest?.('a[href]')

        if (link) {
            event.preventDefault()
            clicked.push(link.getAttribute('href'))
        }
    }, true)
})

beforeEach(() => {
    document.body.innerHTML = PAGE
    clicked.length = 0
})

function press (key, options = {}, target = document.activeElement ?? document.body) {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...options })

    ;(target ?? document.body).dispatchEvent(event)

    return event
}

const marked = () => [...document.querySelectorAll('table.ui-list tbody tr')]
    .map(tr => tr.dataset.keyRow === 'on')

describe('তালিকার কীবোর্ড — নেভির হিন্ট ছাড়াও', () => {
    it('`/` তালিকার খোঁজার ঘরে যায় — মেনুর ছাঁকনিতে নয়', () => {
        const event = press('/', {}, document.body)

        expect(document.activeElement?.id).toBe('find')
        expect(event.defaultPrevented).toBe(true)
    })

    it('`n` পাতার "নতুন" লিংকটা খোলে', () => {
        const event = press('n', {}, document.body)

        expect(clicked).toEqual(['/customers/create'])
        expect(event.defaultPrevented).toBe(true)
    })

    it('↓ ↑ সারি বাছে, দুই প্রান্তে থামে, আর ↵ বাছা সারিটা খোলে', () => {
        press('ArrowDown', {}, document.body)
        expect(marked()).toEqual([true, false, false, false])

        for (let i = 0; i < 5; i++) press('ArrowDown', {}, document.body)
        expect(marked()).toEqual([false, false, true, false])

        press('ArrowUp', {}, document.body)
        expect(marked()).toEqual([false, true, false, false])

        for (let i = 0; i < 5; i++) press('ArrowUp', {}, document.body)
        expect(marked()).toEqual([true, false, false, false])

        press('ArrowDown', {}, document.body)
        press('Enter', {}, document.body)

        expect(clicked).toEqual(['/customers/2'])
    })

    /*
     * ⛔ `data-key-row`-এর কোনো CSS কোথাও নেই — নেভিতেও না। ⚠️ তাই
     * কার্সরটা এতদিন **অদৃশ্য** ছিল: তীর চাপলে কিছু একটা সরত, চোখে পড়ত না।
     */
    it('বাছা সারিটা চোখে দেখা যায় — কোনো রূপের CSS ছাড়াই', () => {
        press('ArrowDown', {}, document.body)
        press('ArrowDown', {}, document.body)

        const rows = document.querySelectorAll('table.ui-list tbody tr')

        expect(rows[1].style.outline).not.toBe('')
        expect(rows[0].style.outline).toBe('')
    })
})

describe('⛔ লেখার সময় আর মডিফায়ার চাপা থাকলে — কিছুই না', () => {
    for (const id of ['find', 'note', 'pick', 'rich']) {
        it(`#${id}-এ কার্সর থাকলে n / ↓ ↵ চুপ`, () => {
            const field = document.getElementById(id)
            field.focus()

            const n = press('n', {}, field)
            const slash = press('/', {}, field)
            const down = press('ArrowDown', {}, field)
            press('Enter', {}, field)

            expect(clicked).toEqual([])
            expect(marked()).toEqual([false, false, false, false])
            expect([n.defaultPrevented, slash.defaultPrevented, down.defaultPrevented])
                .toEqual([false, false, false])
        })
    }

    it('Ctrl / Alt / ⌘ চাপা থাকলে চুপ — ব্রাউজারের নিজের শর্টকাট বাঁচে', () => {
        const events = [
            press('n', { ctrlKey: true }, document.body),
            press('n', { metaKey: true }, document.body),
            press('/', { altKey: true }, document.body),
            press('ArrowDown', { ctrlKey: true }, document.body),
            press('ArrowDown', { altKey: true }, document.body),
        ]

        expect(clicked).toEqual([])
        expect(marked()).toEqual([false, false, false, false])
        expect(events.map(e => e.defaultPrevented)).toEqual([false, false, false, false, false])
    })

    it('Shift+↵ নতুন জানালার জন্য ব্রাউজারের — সারি খোলে না', () => {
        press('ArrowDown', {}, document.body)
        press('Enter', { shiftKey: true }, document.body)

        expect(clicked).toEqual([])
    })

    it('বোতামে ফোকাস থাকলে ↵ বোতামেরই — সারি খোলে না', () => {
        press('ArrowDown', {}, document.body)

        const button = document.getElementById('plain')
        button.focus()
        press('Enter', {}, button)

        expect(clicked).toEqual([])
    })

    it('অন্য কেউ আগে ধরলে (defaultPrevented) চুপ', () => {
        const event = new KeyboardEvent('keydown', { key: 'n', bubbles: true, cancelable: true })
        event.preventDefault()
        document.body.dispatchEvent(event)

        expect(clicked).toEqual([])
    })
})

describe('⚠️ তালিকা নয় এমন পাতায় কিছু ভাঙে না', () => {
    it('সারি না থাকলে ↓ পাতা স্ক্রল করে — আটকানো হয় না', () => {
        document.body.innerHTML = '<p>ড্যাশবোর্ড</p>'

        expect(press('ArrowDown', {}, document.body).defaultPrevented).toBe(false)
        expect(press('ArrowUp', {}, document.body).defaultPrevented).toBe(false)
    })

    it('`n` ফর্মের "সংরক্ষণ" বোতাম কখনো চাপে না', () => {
        let submitted = 0

        document.body.innerHTML = `
            <form id="f"><button type="submit" data-page-primary>সংরক্ষণ</button></form>`
        document.getElementById('f').addEventListener('submit', (e) => { e.preventDefault(); submitted++ })
        document.querySelector('[data-page-primary]').addEventListener('click', () => { submitted++ })

        const event = press('n', {}, document.body)

        expect(submitted).toBe(0)
        expect(event.defaultPrevented).toBe(false)
    })

    it('নেভির হিন্ট থাকলেও আগের মতোই চলে', () => {
        document.body.insertAdjacentHTML('beforeend', '<span data-look-hints></span>')

        press('ArrowDown', {}, document.body)

        expect(marked()).toEqual([true, false, false, false])
    })

    /*
     * ⓘ মিউট্যান্ট দিয়ে মাপা: `listening` সরালেও এটা সবুজ থাকে, কারণ
     * দ্বিতীয় শ্রোতা প্রথমটার `preventDefault()` দেখে সরে যায়। ⚠️ অর্থাৎ
     * পাহারা দুইটা — এই দাবি দুইটার **ফল** মাপে, কোনো একটার নয়।
     */
    it('`listKeys()` দুইবার ডাকলেও শ্রোতা একটাই — এক চাপে এক ঘর', () => {
        listKeys()

        press('ArrowDown', {}, document.body)
        press('ArrowDown', {}, document.body)

        expect(marked()).toEqual([false, true, false, false])
    })
})
