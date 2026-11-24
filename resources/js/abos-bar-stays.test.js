// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { barStays, scrollerOf, stickyPartOf } from './abos-bar-stays.js'

/* ⓘ happy-dom মাপে না — উচ্চতা আর display `data-h`, `data-css` থেকে */
const window_ = (innerWidth = 1920) => {
    const onResize = []

    return {
        innerWidth,
        innerHeight: 900,
        getComputedStyle: el => ({ display: el.dataset.css === 'contents' ? 'contents' : 'block', overflowY: el.dataset.css === 'scroll' ? 'auto' : 'visible', position: 'static' }),
        addEventListener: (name, fn) => name === 'resize' && onResize.push(fn),
        resize (width) {
            this.innerWidth = width
            onResize.forEach(fn => fn())
        }
    }
}

const draw = (ui, html) => {
    document.documentElement.dataset.ui = ui
    document.body.innerHTML = html

    for (const el of document.body.querySelectorAll('[data-h]')) {
        Object.defineProperty(el, 'offsetHeight', { value: Number(el.dataset.h), configurable: true })
    }
}

const list = boxHeight => `
    <main data-h="2000">
        <div id="column" data-h="1900">
            <div id="box" data-h="${boxHeight}">
                <form id="form" data-css="contents" data-h="0"><div id="bar" class="toolbar-view" data-h="56"></div></form>
            </div>
            <div data-h="1500"></div>
        </div>
    </main>`

describe('stickyPartOf', () => {
    it('finds the bar itself under a display:contents form when its box holds the whole list', () => {
        draw('blue', list(1600))

        expect(stickyPartOf(document.querySelector('#bar'), document.querySelector('main'), window_()).id).toBe('bar')
    })

    it('finds the box when the box is only as tall as the bar, never the boxless form', () => {
        draw('blue', list(60))

        expect(stickyPartOf(document.querySelector('#bar'), document.querySelector('main'), window_()).id).toBe('box')
    })
})

describe('scrollerOf', () => {
    it('is the nearest ancestor that scrolls, or nothing when the page does', () => {
        draw('blue', '<div id="s" data-css="scroll"><p><b id="in"></b></p></div><i id="out"></i>')

        expect(scrollerOf(document.querySelector('#in'), window_()).id).toBe('s')
        expect(scrollerOf(document.querySelector('#out'), window_())).toBeNull()
    })
})

describe('barStays', () => {
    it('marks the sticky part in the three ABOS looks and in no other', () => {
        for (const [ui, stays] of [['rose', true], ['navy', true], ['blue', true], ['tiles', false], ['dynamic', false], ['apps', false]]) {
            draw(ui, list(1600))
            barStays(document, window_())

            expect(document.querySelector('#bar').hasAttribute('data-bar-stays')).toBe(stays)
        }
    })

    it('fits a two-sided page when wide, takes the fit off under 1024 and puts it back', () => {
        for (const mark of ['data-split', 'data-control-frame']) {
            draw('blue', `<main data-h="2000"><div id="split" ${mark}></div></main>`)
            const win = window_(1920)
            const split = document.querySelector('#split')
            barStays(document, win)

            expect(split.hasAttribute('data-split-on')).toBe(true)
            expect(split.style.getPropertyValue('--split-h')).not.toBe('')

            win.resize(1023)
            expect(split.hasAttribute('data-split-on')).toBe(false)
            expect(split.style.getPropertyValue('--split-h')).toBe('')

            win.resize(1024)
            expect(split.hasAttribute('data-split-on')).toBe(true)
        }
    })

    it('leaves the inbox board alone', () => {
        draw('blue', list(1600).replace('<main', '<main id="m"').replace('</main>', '<div data-inbox-board></div></main>'))
        barStays(document, window_())

        expect(document.querySelector('[data-bar-stays]')).toBeNull()
    })
})
