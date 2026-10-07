// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest'
import { hiddenColumnsOf, translateColumnChoice } from './columns.js'

/*
 * Columns মেনু — টিক থেকে `?hide=`। কেন, [[columns.js]]-এ: মেনুটা `show[]`
 * পাঠাত, আর টেবিল পড়ে কেবল `hide` — কোনো তালিকাতেই কিছু লুকাত না।
 */
function form (html) {
    document.body.innerHTML = `<form method="GET">${html}</form>`

    return document.querySelector('form')
}

const menu = `
    <input type="checkbox" name="show[]" value="code" checked>
    <input type="checkbox" name="show[]" value="point">
    <input type="checkbox" name="show[]" value="area">
    <input type="checkbox" name="show[]" value="phone" checked>`

describe('Columns মেনু', () => {
    it('টিক-না-দেওয়া কলামগুলোই লুকায়', () => {
        const f = form(menu)

        expect(hiddenColumnsOf(f)).toEqual(['point', 'area'])

        translateColumnChoice(f)

        const sent = new URLSearchParams(new FormData(f))

        expect(sent.get('hide')).toBe('point,area')
        expect(sent.getAll('show[]')).toEqual([])
    })

    it('সব টিক থাকলে `hide` যায় না — আগের লুকানোও মুছে যায়', () => {
        const f = form(menu.replaceAll('value="point">', 'value="point" checked>').replaceAll('value="area">', 'value="area" checked>')
            + '<input type="hidden" name="hide" value="point">')

        translateColumnChoice(f)

        expect(new URLSearchParams(new FormData(f)).has('hide')).toBe(false)
    })

    it('মেনু নেই এমন ফর্ম ছোঁয়া হয় না', () => {
        const f = form('<input name="q" value="rahim"><input type="hidden" name="hide" value="point">')

        translateColumnChoice(f)

        const sent = new URLSearchParams(new FormData(f))

        expect(sent.get('hide')).toBe('point')
        expect(sent.get('q')).toBe('rahim')
    })
})
