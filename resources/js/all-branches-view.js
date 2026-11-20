/*
 * ⛔ "সব শাখা" = কেবল দেখা — মালিকের চূড়ান্ত সিদ্ধান্ত, ২০ নভেম্বর ২০২৬ ([[AllBranchesView]])।
 *
 * ── কী ঘটেছিল ────────────────────────────────────────────────────────
 * "সব শাখা" বাছা অবস্থায় আদায় লেখা হলো — বসল লুকানো কাজের শাখায় (Super-এর বদলে TD), তারপর আবার লেখা হলো।
 *
 * ── ⭐ যা করে ──────────────────────────────────────────────────────────
 * খোলস `<html data-all-branches-view>` আঁকলে (কেবল তখনই — বাকি সময় একটা শ্রোতাও বসে না), লেখার প্রতিটা নিয়ন্ত্রণ ধূসর,
 * লুকানো নয়: `aria-disabled`, কারণের `title` ("লিখতে হলে ওপর থেকে একটা শাখা বাছুন"), আর চাপলে কিছু হয় না।
 *   · `*\/create` আর `*\/edit`-এর লিংক;
 *   · POST/PUT/PATCH/DELETE ফর্ম (আর `form="id"` দিয়ে তাকে ডাকা বাইরের বোতাম) — খোলা দরজা ছাড়া (`data-all-branches-allow`,
 *     সার্ভারের সেই একই তালিকার ঠিকানার ছাঁচ) আর `data-allow-all-branches` চিহ্নের ভিতরের ফর্ম ছাড়া।
 * ⓘ পরে আসা অংশও (Alpine-এর আঁকা তালিকা, পপআপ) — একটা MutationObserver।
 *
 * ⚠️ `pointer-events: none` নয়, ইচ্ছাকৃত: তাতে কারণের `title`-ও আর দেখা যেত না, কার্সরও না। ক্লিক আর জমা থামে
 * শ্রোতায় (capture — অন্য সব শ্রোতার আগে)। ⛔ এটা কেবল সাহায্য — আসল পাহারা সার্ভারে ([[RefusesWritesInAllBranchesView]])।
 */

const OFF = 'data-all-branches-off'
const WRITE_LINK = /\/(create|edit)\/?$/

/** খোলা দরজার ছাঁচগুলো — সার্ভার JSON পাঠায়; ভাঙা লেখা হলে খালি (তখন সব লেখা ধূসর — নিরাপদ দিক)। */
export function parseAllow (raw) {
    try {
        const list = JSON.parse(raw || '[]')

        return Array.isArray(list) ? list.map(p => new RegExp(p)) : []
    } catch {
        return []
    }
}

function pathOf (url, base) {
    try {
        return new URL(url, base).pathname
    } catch {
        return ''
    }
}

/** এই ফর্ম কিছু লেখে, আর "সব শাখা"-তে তার দরজা খোলা নয়। */
export function blocksForm (form, allow, base = 'http://localhost/') {
    if (!form || (form.getAttribute('method') || 'get').toLowerCase() === 'get') {
        return false
    }

    if (form.closest('[data-allow-all-branches]')) {
        return false
    }

    const path = pathOf(form.getAttribute('action') || base, base)

    return !allow.some(re => re.test(path))
}

/** নতুন কাগজ বা সম্পাদনার পাতার লিংক। */
export function blocksLink (a, base = 'http://localhost/') {
    const href = a.getAttribute('href')

    if (!href || href.startsWith('#') || a.closest('[data-allow-all-branches]')) {
        return false
    }

    return WRITE_LINK.test(pathOf(href, base))
}

function turnOff (el, hint) {
    if (el.hasAttribute(OFF)) {
        return
    }

    el.setAttribute(OFF, '')
    el.setAttribute('aria-disabled', 'true')

    if (hint) {
        el.setAttribute('title', hint)
    }
}

/** এক অংশের ভিতরের সব লেখার নিয়ন্ত্রণ ধূসর — বারবার ডাকা নিরাপদ। */
export function markWrites (scope, { allow, hint, base }) {
    scope.querySelectorAll('a[href]').forEach(a => {
        if (blocksLink(a, base)) {
            turnOff(a, hint)
        }
    })

    scope.querySelectorAll('form').forEach(form => {
        if (!blocksForm(form, allow, base)) {
            return
        }

        form.setAttribute(OFF, '')
        form.querySelectorAll('button:not([type]), button[type="submit"], input[type="submit"]').forEach(b => turnOff(b, hint))

        // ⓘ সারি-মেনুর বোতাম ফর্মের বাইরে বসে, `form="id"` দিয়ে ডাকে (`detached-forms`)
        if (form.id) {
            form.ownerDocument.querySelectorAll('[form]').forEach(b => {
                if (b.getAttribute('form') === form.id) {
                    turnOff(b, hint)
                }
            })
        }
    })
}

/**
 * চালু করা — খোলস চিহ্ন না আঁকলে কিছুই নয়, `null` ফেরে।
 *
 * @returns {{ allow: RegExp[], hint: string } | null}
 */
export function guardAllBranchesView (doc = document) {
    const html = doc.documentElement

    if (!html || !html.hasAttribute('data-all-branches-view')) {
        return null
    }

    // ⓘ `about:blank`-এর মতো ঠিকানায় আপেক্ষিক ঠিকানা খোলে না — তখন একটা কাল্পনিক ভিত্তি (কেবল পথটাই মাপা হয়)
    const here = doc.location?.href || ''
    const base = /^https?:/.test(here) ? here : 'http://localhost/'
    const guard = {
        allow: parseAllow(html.getAttribute('data-all-branches-allow')),
        hint: html.getAttribute('data-all-branches-hint') || '',
        base,
    }

    const run = () => markWrites(doc, guard)
    run()

    // ⓘ চাপা থামানো — capture, তাই পাতার নিজের শ্রোতা (পপ-আপ, Alpine) কিছু শোনার আগেই
    doc.addEventListener('click', (event) => {
        const off = event.target?.closest?.(`[${OFF}]`)

        if (off && off.tagName !== 'FORM') {
            event.preventDefault()
            event.stopImmediatePropagation()
        }
    }, true)

    // ⓘ Enter চেপে জমা, বা পরে আঁকা ফর্ম — জমার মুহূর্তে আবার মাপা
    doc.addEventListener('submit', (event) => {
        if (blocksForm(event.target, guard.allow, base)) {
            event.preventDefault()
            event.stopImmediatePropagation()
        }
    }, true)

    if (typeof MutationObserver !== 'undefined' && doc.body) {
        let queued = false

        new MutationObserver(() => {
            if (queued) {
                return
            }

            queued = true
            queueMicrotask(() => {
                queued = false
                run()
            })
        }).observe(doc.body, { childList: true, subtree: true })
    }

    return guard
}
