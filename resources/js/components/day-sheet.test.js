// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { daySheet } from './day-sheet.js'

let s
beforeEach(() => {
    document.body.innerHTML = '<div id="r"><form id="attendance-form"><table><tbody>'
        + '<tr data-day-row data-find="E1 Karim" data-department="3"><td><select name="rows[1][status]"><option value="">-</option><option value="present">p</option></select></td></tr>'
        + '<tr data-day-row data-find="E2 Salma" data-department="4"><td><input name="rows[2][remarks]" value="old"></td></tr>'
        + '</tbody></table></form></div>'
    s = Object.assign(daySheet(), { $root: document.getElementById('r') })
    s.init()
})

const row = (n) => document.querySelectorAll('[data-day-row]')[n]
const leave = () => { const e = new Event('beforeunload', { cancelable: true }); s.guard(e); return e.defaultPrevented }

describe('day sheet', () => {
    it('hides rows by name and department, and a hidden row is still in the form', () => {
        document.querySelector('[name="rows[2][remarks]"]').value = 'typed'
        s.term = 'karim'
        s.narrow()
        expect([row(0).style.display, row(1).style.display, s.shown]).toEqual(['', 'none', 1])
        expect(new FormData(document.getElementById('attendance-form')).get('rows[2][remarks]')).toBe('typed')
        s.term = ''
        s.department = '4'
        s.narrow()
        expect([row(0).style.display, row(1).style.display]).toEqual(['none', ''])
    })

    it('asks before leaving only while something is written and not saved', () => {
        expect(leave()).toBe(false)
        const f = document.querySelector('[name="rows[2][remarks]"]')
        f.value = 'typed'
        expect(leave()).toBe(true)
        f.value = 'old'
        expect(leave()).toBe(false)
        f.value = 'typed'
        s.saved()
        expect(leave()).toBe(false)
    })

    it('asks at once on a page that came back from a stopped save', () => {
        document.getElementById('attendance-form').setAttribute('data-unsaved', '')
        s.init()
        expect(leave()).toBe(true)
    })
})
