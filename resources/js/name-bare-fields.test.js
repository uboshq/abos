// @vitest-environment happy-dom
import { expect, it } from 'vitest'
import { nameBareFields } from './name-bare-fields.js'

it('a bare box in a grid is named by its column and row; a named one is left alone', () => {
    document.body.innerHTML = `<main><table><thead><tr><th>নোট</th><th>কয়টা</th></tr></thead><tbody>
        <tr><td>1000</td><td><input id="a" type="number"></td></tr>
        <tr><td>500</td><td><input id="b" aria-label="নিজের নাম"></td></tr></tbody></table>
        <div><span>গ্রহীতার নাম</span><input id="c"></div></main>`
    expect(nameBareFields()).toBe(2)
    expect(document.querySelector('#a').getAttribute('aria-label')).toBe('কয়টা — 1000')
    expect(document.querySelector('#b').getAttribute('aria-label')).toBe('নিজের নাম')
    expect(document.querySelector('#c').getAttribute('aria-label')).toBe('গ্রহীতার নাম')
})
