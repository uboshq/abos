/*
 * ⭐ অনুমোদনের কীবোর্ড আর পপ-আপ — অনুমোদন ভাগ ৩, দফা ২০ (পরিকল্পনার পাতা ৭, সারি ২০)।
 *
 *     ← →     আগের / পরের কাগজ        (`[data-approval-prev]` / `[data-approval-next]`)
 *     A       অনুমোদন দিন              (`[data-approval-decide] [data-key="a"]`)
 *     S       সংশোধনে ফেরত পাঠান       (`… [data-key="s"]`)
 *     R       প্রত্যাখ্যান করুন          (`… [data-key="r"]`)
 *     Ctrl+K  মন্তব্যের ঘর              (`[data-approval-remarks]`)
 *     Esc     লেখার ঘর ছাড়ে
 *
 * ── ⛔ মরা শর্টকাট, যা সারানো হলো ──────────────────────────────────────
 * আগে (ইনবক্সের ভেতরে লেখা স্ক্রিপ্টে) অক্ষরটা পড়া হত `event.key` থেকে। ⚠️ বাংলা লেআউটে (জাতীয়, বিজয়)
 * `key` একটা বাংলা অক্ষর — "A" চাবিতে "ৃ", "S"-এ "ু" — তাই A, S, R আর Ctrl+K কিছুই করত না, আর
 * Ctrl+K মন্তব্যের ঘর না খুলে ওপরের খোঁজা খুলত। ⓘ এখন চাবির **জায়গা** (`event.code`, "KeyA") আগে
 * দেখা হয়, যেভাবে ওপরের বারের Ctrl+K দেখে ([[shell.js]] `hotkey`)।
 *
 * ── ⛔ একটা অক্ষরে কিছু জমা পড়ে না ─────────────────────────────────────
 * A/S/R কেবল **খোলে**: A চাপলে ব্রাউজারের "নিশ্চিত?" (অঙ্কসহ, [[actions.js]]-এর `data-confirm`), S/R
 * চাপলে পপ-আপ। ⚠️ একটা অক্ষরে পাঁচ লাখ টাকা পাস করা দ্রুততা নয়, দুর্ঘটনা।
 * ⓘ লেখার ঘরে কার্সার থাকলে কিছুই নয় — নাহলে মন্তব্যে "a" লিখতেই অনুমোদনের প্রশ্ন উঠত। পপ-আপ খোলা
 * থাকলে পেছনের পাতার চাবিও চুপ — নাহলে প্রত্যাখ্যানের পপ-আপ থেকে "A" চাপলে পেছনে অনুমোদনের প্রশ্ন উঠত।
 *
 * ── ⭐ পপ-আপ পর্দা-পাঠকে (WAI-ARIA-র modal dialog নিয়ম) ─────────────────
 * `[data-dialog-focus]` বসানো পপ-আপ খুললে:
 *   ১. ফোকাস ভেতরের প্রথম ঘরে যায় — পর্দা-পাঠক পপ-আপের নাম (`aria-labelledby`) আর ঘরটা পড়ে।
 *   ২. Tab আর Shift+Tab ভেতরেই ঘোরে — পেছনের পাতায় হারিয়ে যায় না।
 *   ৩. বন্ধ হলে ফোকাস ফেরে যে বোতাম খুলেছিল তাতে — চোখ-না-দেখা মানুষ জায়গা হারান না।
 * ⓘ পপ-আপগুলো Alpine-এর `x-show` — `<dialog>` নয় (কারণ [[sheet.blade.php]]-এ)। তাই খোলা-বন্ধ চেনা হয়
 * `style` বদল দেখে।
 *
 * ── বাছাইয়ের গোনা ───────────────────────────────────────────────────────
 * "বাছাই Nটা · একসাথে অনুমোদন" — কয়টা বাছা হলো তা বোতামেই, আর শূন্য হলে বোতাম বন্ধ।
 */

/** লেখার ঘরে কার্সার — তখন অক্ষর লেখা, শর্টকাট নয় */
function typing(el) {
    if (! el) return false

    if (el.isContentEditable || el.closest?.('[contenteditable]:not([contenteditable="false"])')) return true

    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)
}

/** চাবির অক্ষর, লেআউট যা-ই হোক — "KeyA" থেকে "a"; জায়গা না জানলে লেখা অক্ষর */
export function letterOf(event) {
    if (typeof event.code === 'string' && /^Key[A-Z]$/.test(event.code)) {
        return event.code.slice(3).toLowerCase()
    }

    return typeof event.key === 'string' ? event.key.toLowerCase() : ''
}

function shown(el) {
    return el.isConnected && getComputedStyle(el).display !== 'none'
}

const FOCUSABLE = 'select, textarea, input:not([type="hidden"]), button, a[href], [tabindex]:not([tabindex="-1"])'

function focusables(dialog) {
    return [...dialog.querySelectorAll(FOCUSABLE)].filter((el) => ! el.disabled && shown(el))
}

function openDialog(root) {
    return [...root.querySelectorAll('[role="dialog"][aria-modal="true"]')].find(shown) ?? null
}

let listening = false

export function approvalKeys(root = document) {
    if (listening) return

    listening = true

    /** যে বোতাম শেষবার পপ-আপ খুলল — বন্ধ হলে ফোকাস সেখানে ফেরে */
    let trigger = null
    const wasOpen = new WeakSet()

    root.addEventListener('click', (event) => {
        const opener = event.target.closest?.('[data-ask], [data-opens-dialog]')

        if (opener) trigger = opener
    }, true)

    root.addEventListener('keydown', (event) => {
        if (event.defaultPrevented) return

        const dialog = openDialog(root)

        // ⓘ Tab পপ-আপের ভেতরেই ঘোরে
        if (event.key === 'Tab' && dialog?.hasAttribute('data-dialog-focus')) {
            const inside = focusables(dialog)

            if (inside.length === 0) return

            const first = inside[0]
            const last = inside[inside.length - 1]
            const at = root.activeElement

            if (! dialog.contains(at)) {
                event.preventDefault()
                first.focus()
            } else if (event.shiftKey && at === first) {
                event.preventDefault()
                last.focus()
            } else if (! event.shiftKey && at === last) {
                event.preventDefault()
                first.focus()
            }

            return
        }

        if (event.key === 'Escape' && typing(event.target)) {
            event.target.blur()

            return
        }

        if (event.ctrlKey || event.metaKey) {
            if (event.altKey || event.shiftKey || letterOf(event) !== 'k') return

            const box = root.querySelector('[data-approval-remarks]')

            // ⓘ ঘর না থাকলে সরে দাঁড়ানো — ওপরের বারের খোঁজা তখন নিজের Ctrl+K পায়
            if (box && ! dialog) {
                event.preventDefault()
                box.focus()
            }

            return
        }

        if (event.altKey || typing(event.target) || dialog) return

        const arrow = { ArrowLeft: '[data-approval-prev]', ArrowRight: '[data-approval-next]' }[event.key]

        if (arrow) {
            const to = root.querySelector(arrow)

            if (to) {
                event.preventDefault()
                to.click()
            }

            return
        }

        if (event.shiftKey) return

        const letter = letterOf(event)

        if (! ['a', 's', 'r'].includes(letter)) return

        const button = root.querySelector('[data-approval-decide] [data-key="' + letter + '"]')

        if (button && ! button.disabled) {
            event.preventDefault()
            trigger = button
            button.click()
        }
    })

    /*
     * ⓘ খোলা-বন্ধ চেনা: `x-show` কেবল `style` বদলায়। ⚠️ পাহারা কেবল `[data-dialog-focus]`-এ — অন্য পাতার
     * পপ-আপ নিজের ফোকাস নিজে সামলায়, তাদের ছোঁয়া হয় না।
     */
    const watch = () => {
        root.querySelectorAll('[data-dialog-focus]').forEach((dialog) => {
            if (shown(dialog)) {
                if (wasOpen.has(dialog)) return

                wasOpen.add(dialog)

                if (! trigger || ! trigger.isConnected) {
                    const active = root.activeElement

                    trigger = active && active !== root.body && ! dialog.contains(active) ? active : null
                }

                dialog.returnTo = trigger
                ;(focusables(dialog)[0] ?? dialog).focus()
            } else if (wasOpen.has(dialog)) {
                wasOpen.delete(dialog)

                const back = dialog.returnTo

                dialog.returnTo = null
                trigger = null

                if (back?.isConnected && (dialog.contains(root.activeElement) || root.activeElement === root.body || ! root.activeElement)) {
                    back.focus()
                }
            }
        })
    }

    // ⓘ যে পাতায় এমন পপ-আপ নেই, সেখানে কোনো পাহারাও নেই — প্রতিটা পাতার প্রতিটা style বদল দেখার খরচ নয়
    if (typeof MutationObserver === 'function' && root.body && root.querySelector('[data-dialog-focus]')) {
        new MutationObserver(watch).observe(root.body, { attributes: true, attributeFilter: ['style', 'x-cloak'], subtree: true })
    }

    const bulk = root.querySelector('[data-bulk-button]')

    if (bulk) {
        const count = () => {
            const picked = root.querySelectorAll('[data-inbox-pick]:checked').length

            bulk.textContent = bulk.dataset.label.replace('__N__', String(picked))
            bulk.disabled = picked === 0
        }

        root.addEventListener('change', (event) => {
            if (event.target.matches?.('[data-inbox-pick]')) count()
        })
        count()
    }
}
