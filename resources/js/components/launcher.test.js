// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeAll, describe, expect, it } from 'vitest'
import { registerComponents } from './index.js'
import { launcherMatches } from './shell.js'

/*
 * ⊞ সব মডিউল — খোঁজা, আসল CSP-Alpine-এ (মালিক, ২১ অক্টোবর ২০২৬; [[shell.launcher]])।
 *
 * ⓘ ছাঁকা হয় ব্রাউজারে: প্রতিটা টাইলের ঘরে `data-launcher-keys` (বাংলা নাম · ইংরেজি নাম · কোড, ছোট হাতে — সার্ভার বসায়),
 * আর `launcher.filter()` খোঁজার প্রতিটা শব্দ চাবিতে আছে কি না দেখে ঘরটা `hidden` করে। PHPUnit-এর দাবি
 * (TheModuleLauncherIsTheRailOnOnePageTest) চাবিগুলো পাতায় আছে কি না দেখে; এখানে সেই চাবি দিয়ে ছাঁকাটা সত্যিই ঠিক কি না।
 *
 * ⓘ চাবিগুলো আসল মডিউলের নাম — module.php-র `name` থেকে হুবহু (বিক্রয়, ক্রয়, মজুদ, বাকি নিয়ন্ত্রণ)।
 */

const KEYS = {
    sales: 'sales বিক্রয়',
    purchase: 'purchase ক্রয়',
    inventory: 'inventory মজুদ',
    credit_control: 'credit_control বাকি নিয়ন্ত্রণ credit control',
}

const warnings = []

beforeAll(() => {
    Alpine.setErrorHandler((error, el, expression) => {
        warnings.push(`Alpine Expression Error: ${error?.message} — ${expression}`)
    })

    window.Alpine = Alpine
    registerComponents(Alpine)
    Alpine.start()
})

let host = null

afterEach(async () => {
    host?.remove()
    host = null
    await Alpine.nextTick()
})

/** পাতার মতোই: x-data="launcher", খোঁজার ঘর x-model="q", টাইলের ঘরে চাবি */
async function mount () {
    warnings.length = 0
    host = document.createElement('div')
    host.innerHTML = `
        <div x-data="launcher" @keydown.window="hotkey($event)">
            <button type="button" x-ref="button" @click="show()">⊞</button>
            <div x-show="open">
                <input type="search" x-ref="q" x-model="q" @keydown.down.prevent="first()">
                <div role="grid" @keydown="move($event)">
                    <div role="row">
                        ${Object.entries(KEYS).map(([code, keys]) => `
                            <div role="gridcell" data-launcher-cell data-launcher-keys="${keys}">
                                <a href="/dashboard/${code}" data-launcher-tile="${code}">${code}</a>
                            </div>`).join('')}
                    </div>
                </div>
                <p x-show="none" data-launcher-none>none</p>
            </div>
        </div>`
    document.body.appendChild(host)
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()

    return host
}

async function type (text) {
    const box = host.querySelector('input')
    box.value = text
    box.dispatchEvent(new Event('input', { bubbles: true }))
    await Alpine.nextTick()
    await Alpine.nextTick()
}

const shown = () => [...host.querySelectorAll('[data-launcher-cell]')].filter(c => ! c.hidden).map(c => c.querySelector('a').dataset.launcherTile)

describe('launcherMatches — খোঁজার নিয়ম', () => {
    it('"বিক্রয়" কেবল বিক্রয়ে মেলে, ক্রয়ে নয়', () => {
        const hits = Object.entries(KEYS).filter(([, keys]) => launcherMatches(keys, 'বিক্রয়')).map(([code]) => code)

        expect(hits).toEqual(['sales'])
    })

    it('"য়" এক অক্ষরে লিখলেও (U+09DF) দুই অক্ষরের নামে মেলে, উল্টোটাও', () => {
        const composed = 'বিক্রয়'
        const decomposed = 'বিক্রয়'

        expect(launcherMatches('sales ' + decomposed, composed)).toBe(true)
        expect(launcherMatches('sales ' + composed, decomposed)).toBe(true)
    })

    it('ইংরেজি নাম আর বড়-ছোট হাত — "SALES" বিক্রয়ে মেলে', () => {
        expect(launcherMatches(KEYS.sales, 'SALES')).toBe(true)
        expect(launcherMatches(KEYS.purchase, 'SALES')).toBe(false)
    })

    it('কয়েক শব্দ মানে সবগুলোই — "বাকি নিয়ন্ত্রণ" মেলে, "বাকি বিক্রয়" নয়', () => {
        expect(launcherMatches(KEYS.credit_control, 'বাকি নিয়ন্ত্রণ')).toBe(true)
        expect(launcherMatches(KEYS.credit_control, 'বাকি বিক্রয়')).toBe(false)
    })

    it('খালি খোঁজা মানে সব', () => {
        expect(Object.values(KEYS).every(keys => launcherMatches(keys, '  '))).toBe(true)
    })
})

describe('⊞ পাতা, আসল CSP-Alpine-এ', () => {
    it('"বিক্রয়" লিখলে কেবল বিক্রয়ের টাইল থাকে; মুছলে সব ফেরে', async () => {
        await mount()
        await type('বিক্রয়')

        expect(shown()).toEqual(['sales'])
        expect(host.querySelector('[data-launcher-none]').style.display).toBe('none')

        await type('')
        expect(shown()).toEqual(Object.keys(KEYS))
        expect(warnings).toEqual([])
    })

    it('কিছু না মিললে "কোনো মডিউল নেই" দেখায়', async () => {
        await mount()
        await type('zzz')

        expect(shown()).toEqual([])
        expect(host.querySelector('[data-launcher-none]').style.display).not.toBe('none')
    })

    it('Alt+M খোলে আর খোঁজায় ফোকাস দেয়; Esc বন্ধ করে, ফোকাস বোতামে ফেরে', async () => {
        await mount()
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'm', code: 'KeyM', altKey: true, bubbles: true }))
        await Alpine.nextTick()
        await Alpine.nextTick()

        expect(Alpine.$data(host.firstElementChild).open).toBe(true)
        expect(document.activeElement).toBe(host.querySelector('input'))

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
        await Alpine.nextTick()
        await Alpine.nextTick()

        expect(Alpine.$data(host.firstElementChild).open).toBe(false)
        expect(document.activeElement).toBe(host.querySelector('button'))
        expect(warnings).toEqual([])
    })

    it('↓ খোঁজা থেকে প্রথম মেলা টাইলে, → পরের টাইলে', async () => {
        await mount()
        host.querySelector('button').click()
        await Alpine.nextTick()
        await type('ক্রয়')

        host.querySelector('input').dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
        expect(document.activeElement?.dataset.launcherTile).toBe('sales')

        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))
        expect(document.activeElement?.dataset.launcherTile).toBe('purchase')
    })
})

describe('পাতার ব্লেড এই নামগুলোই ডাকে', () => {
    it('launcher.blade.php — x-data="launcher", x-model="q", চাবির ঘর আর টাইলের চিহ্ন', () => {
        const blade = readFileSync(join(process.cwd(), 'resources/views/components/shell/launcher.blade.php'), 'utf8')

        for (const mark of ['x-data="launcher"', 'x-model="q"', '@keydown.window="hotkey($event)"', '@keydown="move($event)"',
            'data-launcher-cell data-launcher-keys="{{ $tile[\'keys\'] }}"', 'data-launcher-tile="{{ $tile[\'code\'] }}"', 'x-show="none"']) {
            expect(blade).toContain(mark)
        }
    })
})
