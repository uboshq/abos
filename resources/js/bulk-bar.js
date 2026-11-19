/*
 * ⭐ তালিকার "একসাথে কাজ" বার — মালিক, ১৯ নভেম্বর ২০২৬ (নমুনা Q2W6mM3S v3, "ok"): সারির বাঁয়ে টিক-বাক্স, মাথায় "সব বাছুন", আর কিছু
 *   বাছলে টেবিলের ওপরে গাঢ় নীল বার — "৩টা বাছা · <এই তালিকার কাজ>"। কিছু না বাছলে বারটা নেই।
 *
 * ⓘ পাতা কেবল দুইটা জিনিস দেয়: একটা ফর্ম `data-bulk-form` (id সহ, ভেতরে কাজের বোতাম — যেমন ভাউচারের "পোস্টের আগে দেখুন"), আর
 *   সারির টিক-বাক্স `form="<সেই id>"`। কাজ, অনুমতি আর যাচাই সব সার্ভারের — এখানে কেবল দেখানো।
 * ⛔ কেবল "ABOS"-এ বার লুকায়/দেখায় আর "সব বাছুন" বসে — বাকি রূপে পাতা আগের মতোই।
 */
export function bulkBars (root = document) {
    if (root.documentElement?.dataset.ui !== 'rose') {
        return 0
    }

    let wired = 0

    for (const form of root.querySelectorAll('form[data-bulk-form][id]')) {
        if (form.hasAttribute('data-bulk-wired')) {
            continue
        }

        const picks = () => [...root.querySelectorAll(`input[type=checkbox][form="${form.id}"]`)]
        const boxes = picks()

        if (boxes.length === 0) {
            continue
        }

        form.setAttribute('data-bulk-wired', '')

        const count = root.createElement('span')
        count.setAttribute('data-bulk-count', '')
        form.prepend(count)

        // ⓘ "সব বাছুন" — টিক-বাক্সের কলামের মাথায়
        const cell = boxes[0].closest('td')
        const table = cell?.closest('table')
        const index = cell ? [...cell.parentElement.children].indexOf(cell) : -1
        const head = table?.querySelector('thead tr')?.children[index]
        let all = null

        if (head) {
            all = root.createElement('input')
            all.type = 'checkbox'
            all.className = 'size-4'
            all.setAttribute('data-bulk-all', '')
            all.setAttribute('aria-label', form.dataset.allLabel || 'Select all')
            head.textContent = ''
            head.append(all)
            all.addEventListener('change', () => {
                picks().forEach(box => { box.checked = all.checked; box.closest('tr')?.toggleAttribute('data-picked', all.checked) })
                sync()
            })
        }

        const sync = () => {
            const list = picks()
            const n = list.filter(b => b.checked).length
            form.hidden = n === 0
            count.textContent = (form.dataset.countLabel || ':n selected').replace(':n', String(n))

            if (all) {
                all.checked = n > 0 && n === list.length
                all.indeterminate = n > 0 && n < list.length
            }
        }

        root.addEventListener('change', e => {
            if (e.target.matches?.(`input[type=checkbox][form="${form.id}"]`)) {
                e.target.closest('tr')?.toggleAttribute('data-picked', e.target.checked)
                sync()
            }
        })

        sync()
        wired++
    }

    return wired
}
