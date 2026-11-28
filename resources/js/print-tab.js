/*
 * ⭐ ছাপা নতুন ট্যাবে — কাজের পাতা যেখানে ছিল সেখানেই থাকে (মালিক, ২৩ নভেম্বর ২০২৬: কাউন্টারে বিল নিশ্চিত করলে চালান/বিলের
 * ছাপা একই ট্যাবে খুলত, আর কাউন্টার হারিয়ে যেত)।
 *
 * ── কীভাবে ──────────────────────────────────────────────────────────
 * ① যে জমা-বোতামে `data-opens-print` (কাউন্টারের "নিশ্চিত করুন", POS-এর "সম্পূর্ণ"), সেটা চাপার মুহূর্তেই একটা নামওয়ালা
 *    ফাঁকা ট্যাব খোলে ("ছাপা তৈরি হচ্ছে…")। ⚠️ ব্রাউজার নতুন ট্যাব দেয় কেবল ব্যবহারকারীর চাপের ভিতরে — সার্ভারের উত্তরের পরে
 *    খুলতে চাইলে পপ-আপ আটকানো হত। তাই আগে খোলা, পরে ঠিকানা।
 * ② সার্ভার কাউন্টারেই ফেরে (নতুন বিক্রির জন্য খালি), সাথে ছাপার ঠিকানা (`[data-print-tab-url]`, [[x-ui.print-ready]])।
 *    পাতা খুলেই ঠিকানাটা ঐ নামের ট্যাবে বসে — আগে খোলা ট্যাব থাকায় এটা নতুন পপ-আপ নয়, তাই আটকায় না।
 * ③ ছাপা না হলে (খসড়া, সইয়ের অপেক্ষা, ভুল) ফাঁকা ট্যাবটা বন্ধ হয়।
 * ⓘ ট্যাব না খুললেও (পপ-আপ বন্ধ) পাতায় "ছাপা খুলুন" লিংক থাকে — `target="_blank"`, চাপলেই নতুন ট্যাব।
 */

export const PRINT_TAB = 'abos-print'
export const PRINT_FLAG = 'abos.print-tab.held'

function remember (win, held) {
    try {
        if (held) {
            win.sessionStorage.setItem(PRINT_FLAG, '1')
        } else {
            win.sessionStorage.removeItem(PRINT_FLAG)
        }
    } catch {
        // ব্যক্তিগত উইন্ডো — মনে রাখা না গেলেও ছাপা চলে; কেবল ফাঁকা ট্যাবটা নিজে বন্ধ হবে না
    }
}

function wasHeld (win) {
    try {
        return win.sessionStorage.getItem(PRINT_FLAG) === '1'
    } catch {
        return false
    }
}

/** ① চাপের মুহূর্তে ফাঁকা ট্যাব — ফেরত দেয় ট্যাবটা, না খুললে null */
export function holdPrintTab (win = window, text = '') {
    let tab = null

    try {
        tab = win.open('', PRINT_TAB)
    } catch {
        tab = null
    }

    if (!tab) {
        return null
    }

    try {
        const doc = tab.document
        doc.title = text || '…'
        doc.body.style.font = '16px system-ui, sans-serif'
        doc.body.style.padding = '32px'
        doc.body.textContent = text || '…'
    } catch {
        // ট্যাবটা আগে থেকে অন্য ঠিকানায় (আগের ছাপা) — লেখা যায় না, তাতে ক্ষতি নেই
    }

    remember(win, true)

    return tab
}

/** ③ ধরে রাখা ট্যাব ছেড়ে দেওয়া — ছাপা আসেনি */
export function dropPrintTab (win = window) {
    if (!wasHeld(win)) {
        return
    }

    remember(win, false)

    try {
        win.open('', PRINT_TAB)?.close()
    } catch {
        // ব্যবহারকারী নিজেই বন্ধ করেছেন
    }
}

/** ② ছাপার ঠিকানা নতুন (বা ধরে রাখা) ট্যাবে — কাজের পাতা নড়ে না */
export function sendToPrintTab (url, win = window) {
    remember(win, false)

    try {
        return win.open(url, PRINT_TAB) ?? null
    } catch {
        return null
    }
}

/**
 * জমার শ্রোতা — `document`-এ, বুদবুদের পথে: ফর্মের নিজের শ্রোতা (কাউন্টারের সীমার পাহারা, [[direct-sale.js]] `guardSubmit`)
 * আগে চলে, তাই থামানো জমায় ট্যাব খোলে না। ⓘ সারাংশের পপ-আপ ([[confirm-overview.js]]) প্রথম চাপ থামায় — ট্যাব খোলে পপ-আপের
 * "নিশ্চিত করুন" চাপে, যা নিজেও ব্যবহারকারীর চাপ।
 */
export function holdPrintTabOnSubmit (root = document, win = window) {
    root.addEventListener('submit', (event) => {
        const trigger = event.submitter

        if (event.defaultPrevented || !trigger || !trigger.hasAttribute('data-opens-print')) {
            return
        }

        const form = event.target
        const text = form?.dataset?.printWaiting ?? trigger.dataset.printWaiting ?? ''

        holdPrintTab(win, text)

        // ⓘ পরের কোনো শ্রোতা (এক-জমার পাহারা) থামালে ট্যাবটাও ছাড়া
        win.setTimeout(() => {
            if (event.defaultPrevented) {
                dropPrintTab(win)
            }
        }, 0)
    })
}

/** পাতা খোলার মুহূর্ত — ছাপার ঠিকানা থাকলে নতুন ট্যাবে, না থাকলে ধরে রাখা ফাঁকা ট্যাব বন্ধ */
export function handPrintToTab (root = document, win = window) {
    const mark = root.querySelector('[data-print-tab-url]')
    const url = mark?.getAttribute('data-print-tab-url') ?? ''

    if (url !== '') {
        const tab = sendToPrintTab(url, win)

        if (tab && mark) {
            mark.setAttribute('data-print-opened', '1')
        }

        return tab
    }

    dropPrintTab(win)

    return null
}
