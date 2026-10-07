// @vitest-environment happy-dom
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { SEARCH_DELAY, searchAsYouType } from './search-as-you-type.js'

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
    it('শেষ চাপের ৪০০ মিলিসেকেন্ড পরে একবারই জমা পড়ে', () => {
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

describe('ফেরা পাতা', () => {
    it('খোঁজের পরে কার্সর লেখার শেষে', () => {
        document.body.innerHTML = PAGE('রহিম').replace('data-quick-find', 'data-quick-find autofocus')
        searchAsYouType()

        expect(box().selectionStart).toBe('রহিম'.length)
        expect(box().selectionEnd).toBe('রহিম'.length)
    })
})
