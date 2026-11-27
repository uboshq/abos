/*
 * ⭐ তালিকার "একসাথে কাজ" বার — মালিক, ১৯ নভেম্বর ২০২৬ (নমুনা Q2W6mM3S v3, "ok"): সারির বাঁয়ে টিক-বাক্স, মাথায় "সব বাছুন", আর কিছু
 *   বাছলে টেবিলের ওপরে গাঢ় নীল বার — "৩টা বাছা · <এই তালিকার কাজ>"। কিছু না বাছলে বারটা নেই।
 *
 * ⓘ পাতা কেবল দুইটা জিনিস দেয়: একটা ফর্ম `data-bulk-form` (id সহ, ভেতরে কাজের বোতাম — যেমন ভাউচারের "পোস্টের আগে দেখুন"), আর
 *   সারির টিক-বাক্স `form="<সেই id>"`। কাজ, অনুমতি আর যাচাই সব সার্ভারের — এখানে কেবল দেখানো।
 * ⛔ কেবল "ABOS"-এ বার লুকায়/দেখায় আর "সব বাছুন" বসে — বাকি রূপে পাতা আগের মতোই।
 */
/*
 * ⭐ সব রেকর্ডের তালিকায় সারির বাঁয়ে টিক-বাক্স — মালিক, ২০ নভেম্বর ২০২৬ (SAP-এর তালিকার ছবি দিয়ে: "ei box gulo to dawni")।
 *   কিছু বাছলে টেবিলের ওপরে বার: "৩টা বাছা · কপি · ছাপুন · Excel · বাছাই মুছুন"। সব কাজ পাতাতেই — সার্ভারে কিছু লেখা হয় না,
 *   তাই কোনো অনুমতি পেরোনো যায় না; যা পর্দায় দেখা যাচ্ছে কেবল তা-ই।
 * ⓘ কেবল রেকর্ডের তালিকা — যে টেবিলের শেষে "অ্যাকশন" কলাম ([[x-ui.table]]-এর `data-row-menu`)। রিপোর্ট, খাতা আর গাছের তালিকায়
 *   ওই কলাম নেই, তাই বাক্সও নেই। যে পাতার নিজের বাছাই-ফর্ম আছে (ভাউচারের "পোস্টের আগে দেখুন"), সেখানে এটা বসে না।
 * ⛔ কেবল "ABOS"-এ।
 */
const WORDS = {
    bn: { count: ':nটা বাছা', all: 'এই পাতার সব বাছুন', row: 'এই সারি বাছুন', copy: 'কপি', print: 'ছাপুন', excel: 'Excel', clear: 'বাছাই মুছুন', copied: 'কপি হয়েছে' },
    en: { count: ':n selected', all: 'Select all on this page', row: 'Select this row', copy: 'Copy', print: 'Print', excel: 'Excel', clear: 'Clear', copied: 'Copied' },
}

const cellText = td => (td?.innerText ?? td?.textContent ?? '').replace(/\s+/g, ' ').trim()

export function pickColumns (root = document) {
    if (root.documentElement?.dataset.ui !== 'rose') {
        return 0
    }

    const w = WORDS[root.documentElement.lang === 'en' ? 'en' : 'bn']
    let added = 0

    for (const table of root.querySelectorAll('main table.ui-list')) {
        const headRow = table.querySelector('thead tr')
        const bodyRows = [...table.querySelectorAll(':scope > tbody > tr')]

        if (! headRow || bodyRows.length === 0 || table.hasAttribute('data-picks') || ! headRow.querySelector('th[data-row-menu]')
            || table.querySelector('tbody input[type=checkbox][form]') || table.closest('[data-no-pick]')) {
            continue
        }

        table.setAttribute('data-picks', '')

        const th = root.createElement('th')
        th.setAttribute('data-pick-col', '')
        th.style.width = '2.5rem'
        const all = root.createElement('input')
        all.type = 'checkbox'
        all.className = 'size-4'
        all.setAttribute('data-bulk-all', '')
        all.setAttribute('aria-label', w.all)
        th.append(all)
        headRow.prepend(th)

        for (const tr of bodyRows) {
            const td = root.createElement('td')
            td.setAttribute('data-pick-col', '')
            const box = root.createElement('input')
            box.type = 'checkbox'
            box.className = 'size-4'
            box.setAttribute('data-pick', '')
            box.setAttribute('aria-label', `${w.row}: ${cellText(tr.cells[0]).slice(0, 40)}`)
            td.append(box)
            // ⓘ বাক্সের ঘরে চাপ সারি খোলার চাপ নয়
            td.addEventListener('click', e => e.stopPropagation())
            tr.prepend(td)
        }

        for (const tr of table.querySelectorAll(':scope > tfoot > tr')) {
            tr.prepend(root.createElement('td'))
        }

        // ⓘ বার — টেবিলের ঠিক ওপরে, কিছু না বাছলে লুকানো
        const bar = root.createElement('div')
        bar.setAttribute('data-bulk-bar', '')
        bar.hidden = true
        const count = root.createElement('span')
        count.setAttribute('data-bulk-count', '')
        bar.append(count)

        const button = (label, act) => {
            const b = root.createElement('button')
            b.type = 'button'
            b.textContent = label
            b.setAttribute('data-bulk-act', act)
            bar.append(b)
            return b
        }

        const picked = () => bodyRows.filter(tr => tr.querySelector('[data-pick]')?.checked)
        const columns = () => [...headRow.children].map((h, i) => ({ i, h }))
            .filter(({ h }) => ! h.hasAttribute('data-pick-col') && ! h.hasAttribute('data-row-menu'))
        const matrix = () => [columns().map(({ h }) => cellText(h)), ...picked().map(tr => columns().map(({ i }) => cellText(tr.cells[i])))]

        button(w.copy, 'copy').addEventListener('click', async e => {
            const text = matrix().map(r => r.join('\t')).join('\n')
            try {
                await navigator.clipboard.writeText(text)
                e.target.textContent = w.copied
                setTimeout(() => { e.target.textContent = w.copy }, 1500)
            } catch {
                // ⓘ ক্লিপবোর্ড না দিলে চুপ — পাতা ভাঙে না
            }
        })

        button(w.print, 'print').addEventListener('click', () => {
            root.documentElement.setAttribute('data-print-picked', '')
            const done = () => root.documentElement.removeAttribute('data-print-picked')
            window.addEventListener('afterprint', done, { once: true })
            window.print()
        })

        button(w.excel, 'excel').addEventListener('click', () => {
            const csv = '﻿' + matrix().map(r => r.map(v => `"${v.replace(/"/g, '""')}"`).join(',')).join('\r\n')
            const a = root.createElement('a')
            a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
            a.download = `${(root.title || 'list').split('|')[0].trim()}.csv`
            a.click()
            setTimeout(() => URL.revokeObjectURL(a.href), 2000)
        })

        button(w.clear, 'clear').addEventListener('click', () => {
            bodyRows.forEach(tr => { const b = tr.querySelector('[data-pick]'); if (b) b.checked = false; tr.removeAttribute('data-picked') })
            sync()
        })

        const sync = () => {
            const n = picked().length
            bar.hidden = n === 0
            count.textContent = w.count.replace(':n', String(n))
            all.checked = n > 0 && n === bodyRows.length
            all.indeterminate = n > 0 && n < bodyRows.length
        }

        all.addEventListener('change', () => {
            bodyRows.forEach(tr => { const b = tr.querySelector('[data-pick]'); if (b) b.checked = all.checked; tr.toggleAttribute('data-picked', all.checked) })
            sync()
        })

        table.addEventListener('change', e => {
            if (e.target.matches?.('[data-pick]')) {
                e.target.closest('tr')?.toggleAttribute('data-picked', e.target.checked)
                sync()
            }
        })


        const holder = table.closest('.table-responsive') ?? table
        holder.before(bar)
        sync()
        added++
    }

    return added
}

/*
 * ⭐ ধাপ ২ — সার্ভারের কাজের বার ([[x-ui.bulk-form]], `data-bulk-kind="papers"`): তিন ABOS রূপেই (সার্ভার কেবল ওখানেই আঁকে)।
 *   ⓘ "ছাপুন"/"Excel" তালিকার নিজের ঠিকানায় (একই ছাঁকনি) `export` + `picked=1` + বাছা `ids[]` — নতুন ট্যাবে; সার্ভার তালিকার প্রশ্নেই সারি তোলে।
 *   ⓘ পাঠানোর আগে একবার জিজ্ঞাসা (`data-bulk-confirm`); বাতিলে কারণের ঘর খালি হলে পাঠায় না, ঘরে নিয়ে যায় — সার্ভারও আবার চায়।
 */
export const BULK_LOOKS = ['rose', 'navy', 'blue']

export function exportUrl (href, format, ids) {
    const url = new URL(href)
    for (const key of [...url.searchParams.keys()]) {
        if (key === 'export' || key === 'picked' || key === 'ids[]' || key === 'page') {
            url.searchParams.delete(key)
        }
    }
    url.searchParams.set('export', format)
    url.searchParams.set('picked', '1')
    ids.forEach(id => url.searchParams.append('ids[]', id))
    return url.toString()
}

function wirePapers (form, root, picks) {
    form.addEventListener('click', e => {
        const exp = e.target.closest?.('[data-bulk-export]')
        if (exp) {
            e.preventDefault()
            const ids = picks().filter(b => b.checked).map(b => b.value)
            if (ids.length > 0) {
                window.open(exportUrl(root.defaultView?.location?.href ?? window.location.href, exp.dataset.bulkExport, ids), '_blank', 'noopener')
            }
            return
        }

        const act = e.target.closest?.('[data-bulk-do]')
        if (! act) {
            return
        }

        const reason = form.querySelector('[data-bulk-reason]')
        if (act.dataset.bulkDo === 'cancel' && reason && reason.value.trim() === '') {
            e.preventDefault()
            reason.focus()
            return
        }

        if (act.dataset.bulkConfirm && ! window.confirm(act.dataset.bulkConfirm)) {
            e.preventDefault()
        }
    })
}

export function bulkBars (root = document) {
    const look = root.documentElement?.dataset.ui

    if (! BULK_LOOKS.includes(look)) {
        return 0
    }

    let wired = 0

    for (const form of root.querySelectorAll('form[data-bulk-form][id]')) {
        if (form.hasAttribute('data-bulk-wired')) {
            continue
        }

        const papers = form.dataset.bulkKind === 'papers'

        // ⓘ ভাউচারের পুরনো বার (ধাপ ১) কেবল "ABOS" (rose)-এ, আগের মতো
        if (! papers && look !== 'rose') {
            continue
        }

        const picks = () => [...root.querySelectorAll(`input[type=checkbox][form="${form.id}"]`)]
        const boxes = picks()

        if (boxes.length === 0) {
            continue
        }

        form.setAttribute('data-bulk-wired', '')

        if (papers) {
            wirePapers(form, root, picks)
        }

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
