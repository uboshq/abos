// @vitest-environment happy-dom
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { MIN_CHARS, SEARCH_DELAY, searchAsYouType, searched, worthSearching } from './search-as-you-type.js'

/*
 * তালিকার খোঁজ — টাইপ করতেই (মালিক, ১ অক্টোবর ২০২৬)।
 *
 * ⓘ happy-dom চলে, কারণ এখানে কোনো ছক নেই (ছকের ভিতরের `<template>` ওটা ভাঙে; প্রকল্পে jsdom নেই)।
 * ⓘ ফর্মের আসল জমা এখানে হয় না, তাই প্রতিটা `submit` ধরে থামানো হয় আর গোনা হয় — কতবার, আর
 * কোন লেখা নিয়ে।
 */

const PAGE = (q = '') => `
    <form method="GET" id="list">
        <button type="submit" name="compact" value="1" id="compact">ঘন</button>
        <input type="search" name="q" value="${q}" data-quick-find data-live-search="${q}" id="find">
        <button type="submit" id="go">খুঁজুন</button>
    </form>`

let sent = []

beforeAll(() => {
    // ⓘ সবার শেষে, বুদবুদে — আমাদের শ্রোতা (ধরার পর্বে) আগে চলে, থামালে এটা দেখে
    document.addEventListener('submit', (event) => {
        if (! event.defaultPrevented) sent.push(new FormData(event.target).get('q'))
        event.preventDefault()
    })
    searchAsYouType()
})

beforeEach(() => {
    vi.useFakeTimers()
    sent = []
    document.body.innerHTML = PAGE()
})

afterEach(() => {
    vi.useRealTimers()
})

const box = () => document.getElementById('find')

function type(value, init = {}) {
    box().value = value
    box().dispatchEvent(new InputEvent('input', { bubbles: true, ...init }))
}

function enter() {
    // ⓘ ব্রাউজারের "অন্তর্নিহিত জমা" — ফর্মের প্রথম সাবমিট বোতাম নয়, Enter চালায় খোঁজার নামহীন বোতামটা
    document.getElementById('list').requestSubmit(document.getElementById('go'))
}

describe('টাইপ করতেই খোঁজ', () => {
    it('শেষ চাপের ৬০০ মিলিসেকেন্ড পরে একবারই জমা পড়ে', () => {
        type('র')
        vi.advanceTimersByTime(SEARCH_DELAY - 50)
        type('রহি')
        vi.advanceTimersByTime(SEARCH_DELAY - 50)
        type('রহিম')

        expect(sent).toEqual([])

        vi.advanceTimersByTime(SEARCH_DELAY)

        expect(sent).toEqual(['রহিম'])
    })

    it('ঘর খালি করলে ছাঁকনি উঠে যায়', () => {
        document.body.innerHTML = PAGE('রহিম')
        type('')
        vi.advanceTimersByTime(SEARCH_DELAY)

        expect(sent).toEqual([''])
    })

    it('লেখা যা খোঁজা আছে তাই হলে জমা নয়', () => {
        document.body.innerHTML = PAGE('রহিম')
        type('রহিমা')
        type('রহিম ')
        vi.advanceTimersByTime(SEARCH_DELAY * 2)

        expect(sent).toEqual([])
    })

    it('বাংলা লেখার মাঝে কিছু নয়, লেখা শেষ হলে তবেই', () => {
        box().dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }))
        type('ক', { isComposing: true })
        type('ক্ষ', { isComposing: true })
        vi.advanceTimersByTime(SEARCH_DELAY * 3)

        expect(sent).toEqual([])

        box().value = 'ক্ষমা'
        box().dispatchEvent(new CompositionEvent('compositionend', { bubbles: true }))
        vi.advanceTimersByTime(SEARCH_DELAY)

        expect(sent).toEqual(['ক্ষমা'])
    })

    it('অপেক্ষার মাঝে Enter — খোঁজ একবারই যায়', () => {
        type('করিম')
        vi.advanceTimersByTime(SEARCH_DELAY / 2)
        enter()
        vi.advanceTimersByTime(SEARCH_DELAY * 2)

        expect(sent).toEqual(['করিম'])
    })

    it('জমা পড়ার পরে পাতা ফেরার আগে Enter — দ্বিতীয়বার নয়', () => {
        type('করিম')
        vi.advanceTimersByTime(SEARCH_DELAY)
        enter()

        expect(sent).toEqual(['করিম'])
    })

    it('জমা পড়ার পরে লিখে মুছে একই লেখায় ফিরলে দ্বিতীয়বার নয়', () => {
        type('করিম')
        vi.advanceTimersByTime(SEARCH_DELAY)
        type('করিমা')
        type('করিম')
        vi.advanceTimersByTime(SEARCH_DELAY)

        expect(sent).toEqual(['করিম'])
    })

    it('নামওয়ালা বোতাম (ঘনত্ব) আটকায় না', () => {
        type('করিম')
        vi.advanceTimersByTime(SEARCH_DELAY)
        document.getElementById('list').requestSubmit(document.getElementById('compact'))

        expect(sent).toEqual(['করিম', 'করিম'])
    })
})

/*
 * ⭐ মালিক, ২৩ নভেম্বর ২০২৬: তিন বা বেশি অক্ষরে, বা ঘর খালি করলে — শেষ চাপের ৬০০ মিলিসেকেন্ড পরে; Enter তখনই, যত অক্ষরই।
 */
describe('তিন অক্ষর, ৬০০ মিলিসেকেন্ড, Enter তখনই', () => {
    it('সময় ৬০০, সীমা তিন', () => {
        expect(SEARCH_DELAY).toBe(600)
        expect(MIN_CHARS).toBe(3)
    })

    it('এক বা দুই অক্ষরে জমা নয়, যতক্ষণই অপেক্ষা', () => {
        type('a')
        vi.advanceTimersByTime(SEARCH_DELAY * 3)
        type('ab')
        vi.advanceTimersByTime(SEARCH_DELAY * 3)
        type('  ab  ')
        vi.advanceTimersByTime(SEARCH_DELAY * 3)

        expect(sent).toEqual([])
    })

    it('তিন অক্ষরে ঠিক ৬০০ মিলিসেকেন্ডে জমা — তার আগে নয়', () => {
        type('abc')
        vi.advanceTimersByTime(SEARCH_DELAY - 1)

        expect(sent).toEqual([])

        vi.advanceTimersByTime(1)

        expect(sent).toEqual(['abc'])
    })

    it('তিন থেকে দুইয়ে নামলে অপেক্ষার ঘড়ি বাতিল', () => {
        type('abc')
        vi.advanceTimersByTime(SEARCH_DELAY / 2)
        type('ab')
        vi.advanceTimersByTime(SEARCH_DELAY * 2)

        expect(sent).toEqual([])
    })

    it('Enter এক অক্ষরেও তখনই জমা দেয়', () => {
        type('a')
        enter()

        expect(sent).toEqual(['a'])
    })

    it('ঘর খালি করলে জমা — খালি লেখা সীমার ব্যতিক্রম', () => {
        document.body.innerHTML = PAGE('ab')
        type('')
        vi.advanceTimersByTime(SEARCH_DELAY)

        expect(sent).toEqual([''])
    })

    it('বাংলা লেখা চলার সময় তিন অক্ষর হলেও কিছু নয়', () => {
        box().dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true }))
        type('রহিম', { isComposing: true })
        vi.advanceTimersByTime(SEARCH_DELAY * 3)

        expect(sent).toEqual([])
    })

    it('worthSearching — ফাঁক বাদে গোনে', () => {
        expect(worthSearching('')).toBe(true)
        expect(worthSearching('   ')).toBe(true)
        expect(worthSearching('ab')).toBe(false)
        expect(worthSearching(' ab ')).toBe(false)
        expect(worthSearching('abc')).toBe(true)
        expect(worthSearching('3344')).toBe(true)
    })

    it('অংশ বদলের পরে (searched) — নতুন খোঁজা মনে থাকে, একই লেখা আবার যায় না', () => {
        type('karim')
        vi.advanceTimersByTime(SEARCH_DELAY)
        searched(box(), 'karim')
        type('karim')
        vi.advanceTimersByTime(SEARCH_DELAY)

        expect(sent).toEqual(['karim'])
        expect(box().dataset.liveSearch).toBe('karim')
    })
})

describe('ফেরা পাতা', () => {
    it('খোঁজের পরে কার্সর লেখার শেষে', () => {
        document.body.innerHTML = PAGE('রহিম').replace('data-quick-find', 'data-quick-find autofocus')
        searchAsYouType()

        expect(box().selectionStart).toBe('রহিম'.length)
        expect(box().selectionEnd).toBe('রহিম'.length)
    })
})
