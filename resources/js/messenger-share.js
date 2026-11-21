/*
 * ⭐ "বার্তায় পাঠান" — কাগজের ⋯ মেনু থেকে, কাকে পাঠাবেন তার পপআপ (মালিকের ওয়েব নমুনা M1UddRxK v7, ট্যাব ঘ)।
 *
 *  · মাথায় কাগজের এক লাইন: নাম + নম্বর, নিচে টাকা · পক্ষ
 *  · "কাকে পাঠাবেন? নাম খুঁজুন" — আর "সাম্প্রতিক": কথোপকথনের ক্রমে মানুষ ও গ্রুপ, তারপর বাকি মানুষ
 *  · যাঁর কাগজটা খোলার অনুমতি নেই, তাঁর সারিতে "এই কাগজ খোলার অনুমতি নেই — শুধু নম্বর দেখবেন" (সারির নিজের অবস্থা)
 *  · কয়েকজন বাছা, ইচ্ছা হলে একটা কথা, "পাঠান · N জন" → POST messaging.x.papers
 *
 * ⓘ মেনুর সারি [[x-ui.row-actions]] আঁকে (`data-msgr-share` = { type, id, title, no, amount, party }); শোনা এখানে, document-এ —
 *   তাই পাতার জীবন্ত বদল (morph) বা পিকের ভেতরের মেনুও কাজ করে।
 */
import { api, paintAvatar, refusal, toast, urlFor, word } from './messenger-call.js'

const GROUP_COLOURS = 3

let root = null
let dialog = null
let paper = null
let rows = []
const chosen = new Set()
let lastFocus = null
let searchTimer = null
let sending = false

function q (selector) {
    return dialog.querySelector(selector)
}

function icon (name) {
    const t = root.querySelector('template[data-msgr-icon="' + name + '"]')
    return t ? t.content.firstElementChild?.cloneNode(true) || null : null
}

function personRow (p) {
    return {
        key: 'person:' + p.id,
        name: p.name || '',
        sub: [p.designation, p.branch].filter(Boolean).join(' · '),
        person: p,
        group: false,
        noOpen: p.can_open_paper === false,
    }
}

function groupRow (t) {
    return {
        key: 'thread:' + t.id,
        name: t.title || '',
        sub: t.subtitle || '',
        person: null,
        group: true,
        tone: (Math.abs(hash(String(t.id))) % GROUP_COLOURS) + 1,
        noOpen: false,
    }
}

function hash (text) {
    let h = 0
    for (let i = 0; i < text.length; i++) h = (h * 31 + text.charCodeAt(i)) | 0
    return h
}

/** সাম্প্রতিক — কথোপকথনের ক্রমে (একজনের সাথে হলে মানুষ, গ্রুপ হলে গ্রুপ), তারপর বাকি মানুষ */
export function arrange (threads, people) {
    const byId = new Map(people.map((p) => [String(p.id), p]))
    const out = []
    const seen = new Set()

    for (const t of threads) {
        if (t.kind === 'group' || (!t.person && t.group_icon)) {
            const row = groupRow(t)
            if (!seen.has(row.key)) { seen.add(row.key); out.push(row) }
        } else if (t.person) {
            const p = byId.get(String(t.person.id)) || t.person
            const row = personRow(p)
            if (!seen.has(row.key)) { seen.add(row.key); out.push(row) }
        }
    }
    for (const p of people) {
        const row = personRow(p)
        if (!seen.has(row.key)) { seen.add(row.key); out.push(row) }
    }

    return out
}

function paintSend () {
    const n = chosen.size
    q('[data-msgr-share-send-label]').textContent = word('share.send', { count: n })
    q('[data-msgr-share-send]').disabled = n === 0 || sending
}

function drawRow (row) {
    const b = document.createElement('button')
    b.type = 'button'
    b.className = 'msgr-share__row'
    b.setAttribute('role', 'option')
    b.setAttribute('aria-selected', chosen.has(row.key) ? 'true' : 'false')
    b.dataset.key = row.key

    const av = document.createElement('span')
    av.className = 'msgr-av'
    av.setAttribute('aria-hidden', 'true')
    if (row.group) {
        av.classList.add('msgr-av--g' + row.tone)
        const g = icon('group')
        if (g) av.appendChild(g)
    } else {
        paintAvatar(av, row.person)
    }

    const tx = document.createElement('span')
    tx.className = 'msgr-share__rtx'
    const name = document.createElement('b')
    name.textContent = row.name
    tx.appendChild(name)
    const sub = document.createElement('small')
    if (row.noOpen) {
        sub.className = 'msgr-share__no'
        const lock = icon('lock')
        if (lock) sub.appendChild(lock)
        sub.appendChild(document.createTextNode(word('share.no_open')))
    } else {
        sub.textContent = row.sub
    }
    tx.appendChild(sub)

    const ck = document.createElement('span')
    ck.className = 'msgr-ck'
    ck.setAttribute('aria-hidden', 'true')
    const tick = icon('check')
    if (tick) ck.appendChild(tick)

    b.append(av, tx, ck)
    return b
}

function draw () {
    const text = q('[data-msgr-share-q]').value.trim().toLocaleLowerCase()
    const list = q('[data-msgr-share-list]')
    const shown = text === '' ? rows : rows.filter((r) => r.name.toLocaleLowerCase().includes(text))
    list.textContent = ''
    for (const r of shown) list.appendChild(drawRow(r))
    q('[data-msgr-share-recent]').hidden = text !== '' || shown.length === 0
    q('[data-msgr-share-empty]').hidden = shown.length > 0
    paintSend()
}

async function load () {
    const paperQuery = { paper_type: paper.type, paper_id: paper.id }
    const [people, threads] = await Promise.all([
        api(urlFor('people'), { query: paperQuery }),
        api(urlFor('threads'), { query: { filter: 'all' } }),
    ])
    if (!dialog || dialog.hidden) return
    rows = arrange(threads.ok ? (threads.data?.data || []) : [], people.ok ? (people.data?.data || []) : [])
    draw()
}

/** খোঁজা — হাতে থাকা তালিকায় সাথে সাথে, আর সার্ভারেও (নতুন নাম এলে যোগ হয়) */
function search () {
    draw()
    clearTimeout(searchTimer)
    const text = q('[data-msgr-share-q]').value.trim()
    if (text.length < 2) return
    searchTimer = setTimeout(async () => {
        const res = await api(urlFor('people'), { query: { q: text, paper_type: paper.type, paper_id: paper.id } })
        if (!res.ok || !dialog || dialog.hidden) return
        const have = new Set(rows.map((r) => r.key))
        for (const p of res.data?.data || []) {
            const row = personRow(p)
            if (!have.has(row.key)) rows.push(row)
        }
        draw()
    }, 300)
}

function clientId () {
    try { if (crypto?.randomUUID) return crypto.randomUUID() } catch { /* ⓘ পুরনো ব্রাউজার */ }
    return 'web-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)
}

async function send () {
    if (sending || chosen.size === 0) return
    sending = true
    paintSend()
    const to = Array.from(chosen)
    const res = await api(urlFor('papers'), {
        method: 'POST',
        body: {
            paper_type: paper.type,
            paper_id: paper.id,
            to,
            note: q('[data-msgr-share-note]').value.trim() || null,
            client_id: clientId(),
        },
    })
    sending = false
    if (res.ok) {
        close()
        toast(word('share.sent', { count: res.data?.data?.sent ?? to.length }))
        return
    }
    paintSend()
    toast(refusal(res, 'share.failed'))
}

/** পপআপ খোলা — একটা কাগজের জন্য */
export function open (info) {
    if (!dialog || !info?.type || !info?.id) return
    paper = info
    rows = []
    chosen.clear()
    sending = false
    lastFocus = document.activeElement

    q('[data-msgr-share-paper]').textContent = [info.title, info.no].filter(Boolean).join(' ')
    const meta = q('[data-msgr-share-meta]')
    meta.textContent = ''
    if (info.amount) {
        const n = document.createElement('span')
        n.className = 'msgr-n'
        n.textContent = info.amount
        meta.appendChild(n)
    }
    if (info.party) meta.appendChild(document.createTextNode((info.amount ? ' · ' : '') + info.party))
    meta.hidden = !info.amount && !info.party

    q('[data-msgr-share-q]').value = ''
    q('[data-msgr-share-note]').value = ''
    q('[data-msgr-share-list]').textContent = ''
    q('[data-msgr-share-empty]').hidden = true
    q('[data-msgr-share-recent]').hidden = false
    paintSend()

    dialog.hidden = false
    q('[data-msgr-share-q]').focus()
    load()
}

export function close () {
    if (!dialog || dialog.hidden) return
    dialog.hidden = true
    clearTimeout(searchTimer)
    if (lastFocus && typeof lastFocus.focus === 'function' && document.contains(lastFocus)) lastFocus.focus()
}

function trapTab (event) {
    const box = q('.msgr-share__dlg')
    const items = Array.from(box.querySelectorAll('button:not([disabled]), input'))
        .filter((el) => el.offsetParent !== null)
    if (items.length === 0) return
    const first = items[0]
    const last = items[items.length - 1]
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
}

export function bootShare (doc = document) {
    root = doc.querySelector('[data-msgr-root]')
    dialog = root?.querySelector('[data-msgr-share-dialog]') || null
    if (!dialog || dialog.dataset.msgrBooted === '1') return dialog
    dialog.dataset.msgrBooted = '1'

    // ⓘ মেনুর "বার্তায় পাঠান" — যেকোনো তালিকা, যেকোনো সময় আঁকা
    doc.addEventListener('click', (event) => {
        const item = event.target instanceof Element ? event.target.closest('[data-msgr-share]') : null
        if (!item) return
        event.preventDefault()
        let info = null
        try { info = JSON.parse(item.getAttribute('data-msgr-share') || 'null') } catch { info = null }
        open(info)
    })

    dialog.addEventListener('click', (event) => {
        const t = event.target instanceof Element ? event.target : null
        if (!t) return
        if (t.closest('[data-msgr-share-close]')) { event.preventDefault(); close(); return }
        if (t.closest('[data-msgr-share-send]')) { event.preventDefault(); send(); return }
        const row = t.closest('.msgr-share__row')
        if (row) {
            event.preventDefault()
            const key = row.dataset.key
            if (chosen.has(key)) chosen.delete(key)
            else chosen.add(key)
            row.setAttribute('aria-selected', chosen.has(key) ? 'true' : 'false')
            paintSend()
        }
    })

    q('[data-msgr-share-q]').addEventListener('input', search)
    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { event.preventDefault(); close() }
        else if (event.key === 'Tab') trapTab(event)
        else if (event.key === 'Enter' && event.target === q('[data-msgr-share-note]')) { event.preventDefault(); send() }
    })

    return dialog
}
