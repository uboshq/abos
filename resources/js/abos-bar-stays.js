/*
 * ⭐ The filter bar stays, the list moves under it — ABOS Smart, Navy, Blue (owner, 24 Nov 2026: "কন্ট্রোল প্যানেলের যে সারিটা আছে
 * ছাঁকনি সহ এটা তো স্থির থাকার কথা … ডান পাশের পর্দাটা স্ক্রল করলে উপরে উঠবে").
 *
 *   1. a page with a list bar: the bar sticks to the top of what scrolls;
 *   2. a page in two sides ([data-control-frame], [data-split]): the page itself does not scroll — each side scrolls on its own.
 * ⓘ The inbox board has its own fit ([[inbox-board.js]]). Phones and narrow screens keep one long page.
 */
import { ABOS_HEAD_LOOKS } from './abos-list-head.js'

const WIDE = 1024

/** what really scrolls: the nearest ancestor with its own scroll, else the page */
export function scrollerOf (el, win = window) {
    for (let up = el.parentElement; up && up !== el.ownerDocument.body; up = up.parentElement) {
        if (/auto|scroll/.test(win.getComputedStyle(up).overflowY)) {
            return up
        }
    }

    return null
}

/** the bar itself is too short a home to stick in: the first box whose parent is taller than it */
export function stickyPartOf (bar, stop, win = window) {
    let part = bar

    while (part.parentElement && part.parentElement !== stop) {
        const home = part.parentElement
        const boxless = win.getComputedStyle(home).display === 'contents'

        if (! boxless && home.offsetHeight > part.offsetHeight + 80) {
            return part
        }

        part = boxless ? part : home

        if (boxless) {
            const next = home.parentElement

            if (next && next !== stop && next.offsetHeight > part.offsetHeight + 80) {
                return part
            }

            part = next ?? part
        }
    }

    return part
}

export function barStays (root = document, win = window) {
    if (! ABOS_HEAD_LOOKS.includes(root.documentElement?.dataset.ui)) {
        return
    }

    const main = root.querySelector('main')

    if (! main || main.querySelector('[data-inbox-board]')) {
        return
    }

    const bar = [...main.querySelectorAll('[data-filter-bar], .toolbar-view')]
        .find((el) => el.offsetHeight > 0 && ! el.closest('dialog, [role="dialog"], [data-control-frame] .toolbar-view'))
    const split = main.querySelector('[data-control-frame], [data-split]')

    const fit = () => {
        if (! split) {
            return
        }

        if (win.innerWidth < WIDE) {
            split.style.removeProperty('--split-h')
            split.removeAttribute('data-split-on')

            return
        }

        const frame = main.getBoundingClientRect()
        const strip = [...root.querySelectorAll('[data-list-totals], [data-list-strip]')].find((el) => win.getComputedStyle(el).position === 'fixed')
        const floor = Math.min(win.innerHeight, frame.bottom > 0 ? frame.bottom : win.innerHeight, strip ? strip.getBoundingClientRect().top : Infinity)
        const room = floor - split.getBoundingClientRect().top - 12

        if (room >= 320) {
            split.style.setProperty('--split-h', Math.round(room) + 'px')
            split.setAttribute('data-split-on', '')
        }
    }

    if (bar && ! split) {
        const scroller = scrollerOf(bar, win)
        const part = stickyPartOf(bar, scroller ?? main.parentElement, win)
        const header = root.querySelector('header')
        const headStays = header && /sticky|fixed/.test(win.getComputedStyle(header).position)

        part.setAttribute('data-bar-stays', '')
        part.style.setProperty('--bar-top', (scroller ? 0 : (headStays ? header.offsetHeight : 0)) + 'px')
    }

    fit()
    win.addEventListener('resize', fit)
}
