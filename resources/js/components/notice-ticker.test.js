// @vitest-environment happy-dom
import Alpine from '@alpinejs/csp'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { registerComponents } from './index.js'
import { TICKER_KEY, tickerSeconds } from './shell.js'

/*
 * ⭐ নিচের চলন্ত ঘোষণার ⏸/▶ — মালিক, ২১ অক্টোবর ২০২৬ (fe-র মাধ্যমে): *"জরুরি সতর্কতা উপরে স্থির, ঘোষণা নিচে চলন্ত"*।
 *
 * ⓘ WCAG 2.2.2: নড়া লেখা থামানোর পথ থাকতে হয়, আর বাছাইটা এই ব্রাউজারে মনে থাকে ([[noticeTicker]])। ⚠️ আসল ব্লেডটাই
 * পড়া হয় (statusbar.blade.php-এর `data-ticker` অংশ) — হাতে লেখা নকলে পরীক্ষা নকলটাকেই মাপত। Blade-এর অংশ (মন্তব্য,
 * `{{ }}`, লুপ, শর্ত) সরিয়ে আসল CSP-Alpine-এ বসানো হয়; ঘোষণার সারিগুলোর জায়গায় একটা লিংক।
 */

const BLADE = join(process.cwd(), 'resources/views/components/shell/statusbar.blade.php')

function ticker () {
    const source = readFileSync(BLADE, 'utf8').replace(/\{\{--[\s\S]*?--\}\}/g, '')
    const start = source.indexOf('<div data-ticker')
    const end = source.indexOf('@else', source.indexOf('</button>', start))

    return source.slice(start, end)
        .replace(/@foreach[\s\S]*?@endforeach/g, '<a href="/notice/1" data-ticker-item>ঘোষণা</a>')
        .replace(/@if \(\$copy === 0\) data-ticker-copy @else aria-hidden="true" @endif/g, 'data-ticker-copy')
        .replace(/^\s*@(for|endfor)\b.*$/gm, '')
        .replace(/\{\{\s*__\('([^']+)'\)\s*\}\}/g, '$1')
        // ⓘ আঁকা চিহ্ন (x-ui.icon) সার্ভারে SVG হয় — এখানে একটা ফাঁকা svg, একই x-show সহ
        .replace(/<x-ui\.icon name="(\w+)"[^>]*?(x-show="\w+")[^>]*\/>/g, '<svg data-ticker-icon="$1" $2></svg>')
}

const warnings = []

beforeAll(() => {
    Alpine.setErrorHandler((error, el, expression) => {
        warnings.push(`Alpine Expression Error: ${error?.message} — ${expression}`)
    })

    const warn = console.warn
    console.warn = (...args) => {
        warnings.push(args.join(' '))
        warn(...args)
    }

    window.Alpine = Alpine
    registerComponents(Alpine)
    Alpine.start()
})

let host = null

/*
 * ⓘ ভাণ্ডারটা পরীক্ষার নিজের — happy-dom-এর নয়। ⛔ Node ২৫+ নিজের একটা `localStorage` বসায় (`--localstorage-file` ছাড়া
 * undefined), আর সেটা happy-dom-এরটাকে ঢেকে দেয়: Mac-এর Node ২৬-এ পাঁচটা পরীক্ষাই `clear` পড়তে গিয়ে ভাঙল, এই PC-র Node
 * ২৪-এ সবুজ (cb-web21-a, ২১ অক্টোবর ২০২৬)। counter/direct-sale.test.js-এর একই পথ — কোড যেমন পড়ে, `window.localStorage`-এ।
 */
const memoryStore = () => {
    const box = new Map()

    return {
        getItem: (k) => (box.has(k) ? box.get(k) : null),
        setItem: (k, v) => { box.set(k, String(v)) },
        removeItem: (k) => { box.delete(k) },
    }
}

const useStore = (store) => Object.defineProperty(window, 'localStorage', { value: store, configurable: true, writable: true })

beforeEach(() => {
    useStore(memoryStore())
})

afterEach(async () => {
    host?.remove()
    host = null
    await Alpine.nextTick()
    vi.restoreAllMocks()
})

async function mount () {
    warnings.length = 0
    host = document.createElement('div')
    host.innerHTML = ticker()
    document.body.appendChild(host)
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()

    return host
}

const tick = async () => {
    await new Promise(r => setTimeout(r, 0))
    await Alpine.nextTick()
}

const bar = () => host.querySelector('[data-ticker]')
const button = () => host.querySelector('[data-ticker-toggle]')
const shown = (name) => host.querySelector(`[data-ticker-icon="${name}"]`).style.display !== 'none'

describe('চলন্ত ঘোষণা — ⏸/▶ (WCAG 2.2.2)', () => {
    it('শুরুতে চলে, বোতামের নাম "থামান"', async () => {
        await mount()

        expect(bar().getAttribute('data-state')).toBe('running')
        expect(button().getAttribute('aria-label')).toBe('core.notice.ticker_pause')
        // ⭐ আঁকা ⏸ দেখা যায়, ▶ লুকানো (fe, ২১ অক্টোবর ২০২৬: লেখার চিহ্ন বাংলা ফন্টে "॥" হত)
        expect(shown('pause')).toBe(true)
        expect(shown('play')).toBe(false)
        expect(button().textContent.trim()).toBe('')
        expect(warnings).toEqual([])
    })

    it('⏸ চাপলে থামে, নাম "চালান" হয়, আর বাছাইটা মনে থাকে', async () => {
        await mount()

        button().click()
        await tick()

        expect(bar().getAttribute('data-state')).toBe('paused')
        expect(button().getAttribute('aria-label')).toBe('core.notice.ticker_play')
        expect(button().getAttribute('title')).toBe('core.notice.ticker_play')
        expect(shown('play')).toBe(true)
        expect(shown('pause')).toBe(false)
        expect(window.localStorage.getItem(TICKER_KEY)).toBe('1')

        // ⓘ ▶ চাপলে আবার চলে, আর মনে রাখা বাছাইটা মুছে যায়
        button().click()
        await tick()

        expect(bar().getAttribute('data-state')).toBe('running')
        expect(shown('pause')).toBe(true)
        expect(window.localStorage.getItem(TICKER_KEY)).toBe(null)
        expect(warnings).toEqual([])
    })

    it('পরের পাতায় থামানোই থাকে — মনে রাখা বাছাই', async () => {
        window.localStorage.setItem(TICKER_KEY, '1')

        await mount()

        expect(bar().getAttribute('data-state')).toBe('paused')
        expect(button().getAttribute('aria-label')).toBe('core.notice.ticker_play')
    })

    it('localStorage আটকানো থাকলেও পাতা ভাঙে না — চলে, আর বোতাম এই পাতায় কাজ করে', async () => {
        const blocked = () => { throw new Error('blocked') }
        useStore({ getItem: blocked, setItem: blocked, removeItem: blocked })

        await mount()
        expect(bar().getAttribute('data-state')).toBe('running')

        button().click()
        await tick()

        expect(bar().getAttribute('data-state')).toBe('paused')
        expect(warnings).toEqual([])
    })
})

describe('চলার গতি — লেখার দৈর্ঘ্য ধরে, ধীরে', () => {
    it('সেকেন্ডে ~৪০px, আর কখনো ২০ সেকেন্ডের কম নয়', () => {
        expect(tickerSeconds(4000)).toBe(100)
        expect(tickerSeconds(100)).toBe(20)
        expect(tickerSeconds(0)).toBe(20)
    })
})
