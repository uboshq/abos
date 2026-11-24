// @vitest-environment happy-dom
import { describe, expect, it, vi } from 'vitest'
import { filterPanel } from './forms.js'
import partySearch from '../party-search.js'

/*
 * ⭐ ছাঁকনির প্যানেলে লিখে-খোঁজা পক্ষ (মালিক, ২৩ নভেম্বর ২০২৬: পক্ষ খুঁজে পাওয়া যায় না) — ঘরটা [[x-ui.party-search]], মান
 * লুকানো ঘরে ("customer:7")। ⓘ দুই ফাঁক বন্ধ:
 *   · "সব সরান" পক্ষটাও খালি করে — পিকারের নিজের অবস্থাসহ, শুধু লুকানো ঘর নয়;
 *   · "nটা বাছা" বাছা পক্ষকে গোনে; বাকি লুকানো ঘর ("শুরু থেকে" — `from=all`) নয়।
 * ⓘ Alpine ছাড়া: প্যানেল আর পিকার সরাসরি গড়া, পিকারের ঘটনা-শোনা হাতে বাঁধা (ব্লেডে `x-on:party-search-set`)।
 */
function mount () {
    document.body.innerHTML = `
        <div data-filter-layout data-form="list-form" data-show=":nটা দেখুন" data-show-plain="দেখান" data-no-counts>
            <form id="list-form"></form>
            <aside class="list-filter-drawer">
                <span data-picked="#টা বাছা"></span>
                <section data-filter-section="party">
                    <div data-filter-party="party">
                        <input type="hidden" name="party" value="customer:7" form="list-form">
                    </div>
                </section>
                <section data-filter-section="date">
                    <input type="hidden" name="from" value="all" form="list-form">
                </section>
                <section data-filter-section="cancelled">
                    <input type="checkbox" name="cancelled" value="1" form="list-form">
                </section>
            </aside>
        </div>`

    const root = document.querySelector('[data-filter-layout]')
    const hidden = root.querySelector('input[name="party"]')
    const pickerRoot = root.querySelector('[data-filter-party]')

    const picker = partySearch({ value: 'customer:7', options: [{ id: 'customer:7', label: 'রহিম স্টোর', hint: '', find: 'রহিম 01711' }], uid: 'ps-party' })
    picker.$nextTick = (fn) => fn()
    picker.$refs = { input: hidden, trigger: { focus: vi.fn() }, search: { focus: vi.fn() }, list: { querySelector: () => null } }
    pickerRoot.addEventListener('party-search-set', (event) => picker.setFromOutside(event))

    const panel = filterPanel()
    panel.$root = root
    panel.init()

    return { panel, picker, hidden }
}

describe('filter panel with a searchable party picker', () => {
    it('counts a picked party, but not the fixed "from the start" hidden value', () => {
        const { panel } = mount()

        expect(panel.countPicked()).toBe(1)
        expect(panel.pickedText).toBe('1টা বাছা')
    })

    it('"clear all" empties the picker itself, not only its hidden field', () => {
        const { panel, picker, hidden } = mount()
        const tick = document.querySelector('input[name="cancelled"]')
        tick.checked = true

        panel.clearAll()

        expect(hidden.value).toBe('')
        expect(picker.value).toBe('')
        expect(tick.checked).toBe(false)
        expect(document.querySelector('input[name="from"]').value).toBe('all')
        expect(panel.countPicked()).toBe(0)
    })

    it('a party picked after opening is counted, and "close" puts the earlier party back', () => {
        const { panel, picker, hidden } = mount()

        panel.openPanel()
        picker.setFromOutside({ detail: { value: '' } })
        panel.changed()
        expect(panel.countPicked()).toBe(0)

        picker.pick('customer:7')
        panel.changed()
        expect(panel.countPicked()).toBe(1)

        picker.setFromOutside({ detail: { value: '' } })
        panel.closePanel()
        expect(hidden.value).toBe('customer:7')
        expect(picker.value).toBe('customer:7')
        expect(panel.countPicked()).toBe(1)
    })
})
