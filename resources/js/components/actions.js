/*
 * ছোট কাজগুলো — ছাপা, আর বদলালেই জমা।
 *
 * ── ⛔ কেন এই ফাইলটা লাগল, ২১ সেপ্টেম্বর ২০২৬ ────────────────────────
 * মালিকের অভিযোগ: *"print buton kaj kore na kintu"*। ⓘ বোতামটায় লেখা
 * ছিল `onclick="window.print()"` — একটা **ইনলাইন হ্যান্ডলার**।
 *
 * ⚠️ লাইভের CSP-তে `script-src 'self' 'nonce-…'`, আর সেখানে
 * `'unsafe-inline'` নেই। ⛔ CSP-র নিয়ম অনুযায়ী তখন প্রতিটা `on*=`
 * অ্যাট্রিবিউট **ব্রাউজারই চালাতে দেয় না** — বোতামটা দেখা যায়, চাপা
 * যায়, আর কিচ্ছু হয় না।
 *
 * ⓘ একই কারণে "সাজাও" ড্রপডাউনটাও কাজ করত না (`onchange=
 * "this.form.submit()"`)। ⭐ অর্থাৎ মালিকের *"কোনোটাই কাজ করে না"*
 * কথাটা সঠিক ছিল, আর কারণটা Alpine নয় — CSP।
 *
 * ── ⭐ কেন delegated শ্রোতা, প্রতিটা ঘরে একটা করে নয় ────────────────
 * টুলবার পাতার ভিতরে বহু জায়গায় আঁকা হয়, আর কিছু অংশ Alpine পরে
 * বসায়। ⓘ `document`-এ একটা শ্রোতা বসালে পরে আসা ঘরগুলোও আপনা থেকেই
 * কাজ করে; প্রতিটা ঘরে আলাদা শ্রোতা বসালে ঠিক ঐগুলোই বাদ পড়ত।
 *
 * ── ⚠️ `requestSubmit()`, `submit()` নয় ─────────────────────────────
 * পুরনো লেখাটা ছিল `this.form.submit()`। ⛔ ওটা submit ঘটনাটাই ঘটায়
 * না, তাই [[OneSubmitPerForm]]-এর মতো যারা ঘটনাটা শোনে তারা কিছুই
 * টের পেত না। ⓘ `requestSubmit()` আসল বোতাম চাপার মতোই আচরণ করে।
 */

/*
 * একটা মডিউলে কয়টা অনুমতি দেওয়া আছে — ব্যাজটা নতুন করে লেখা।
 *
 * ⓘ লেখার ছাঁচটা **অনুবাদ থেকেই** আসে (`data-permission-label`), তাই
 * JS-এ কোনো লেখা নকল করা হয় না। ⚠️ প্রথম চালে লেখাটার ভিতরের সংখ্যা
 * regex দিয়ে বদলানো হয়েছিল — ⛔ ওটা ভুল ছিল: বাংলা অঙ্ক (১২ / ৪৩)
 * এলে `\d` কিছুই মিলত না, আর ব্যাজটা চিরকাল পুরনো সংখ্যা দেখাত।
 */
function countPermissions (module) {
    const badge = module.querySelector('[data-permission-count]')

    if (! badge) {
        return
    }

    const total = badge.dataset.permissionTotal
    const on = module.querySelectorAll('input[name="permissions[]"]:checked').length

    badge.textContent = (badge.dataset.permissionLabel ?? ':on / :all')
        .replace(':on', String(on))
        .replace(':all', String(total))

    const all = module.querySelector('[data-permission-all]')

    if (all) {
        all.checked = on > 0 && String(on) === String(total)
        all.indeterminate = on > 0 && String(on) !== String(total)
    }
}

const PRINT = '[data-action="print"]'
const SUBMIT = '[data-action="submit-form"]'
const CONFIRM = '[data-confirm]'

export function wireActions (root = document) {
    /*
     * ⛔ আর এগারোটা `confirm()`ও মরে ছিল — আর ওটা নিরাপত্তার কথা।
     *
     * ⓘ লেখা ছিল `onsubmit="return confirm('…')"`: পণ্য নিষ্ক্রিয় করা,
     * ক্যাশ বাক্স বন্ধ করা, সংরক্ষিত দৃশ্য মোছা, পোর্টাল বন্ধ করা।
     * ⚠️ CSP ওগুলো চালাতে দিত না, তাই **প্রশ্নটাই আসত না** — একটা ভুল
     * ক্লিকেই কাজটা হয়ে যেত।
     *
     * ⭐ `capture` ধাপে শোনা হয়, কারণ ফর্মের নিজের অন্য শ্রোতারা যেন
     * থামানো জমার উপর কাজ না করে।
     */
    root.addEventListener('submit', (event) => {
        const form = event.target.closest?.(CONFIRM)

        if (! form || form.tagName !== 'FORM') {
            return
        }

        if (! window.confirm(form.dataset.confirm)) {
            event.preventDefault()
            event.stopPropagation()
        }
    }, true)

    root.addEventListener('click', (event) => {
        const el = event.target.closest?.(CONFIRM)

        if (! el || el.tagName === 'FORM') {
            return
        }

        if (! window.confirm(el.dataset.confirm)) {
            event.preventDefault()
            event.stopPropagation()
        }
    }, true)

    root.addEventListener('click', (event) => {
        const button = event.target.closest?.(PRINT)

        if (! button) {
            return
        }

        event.preventDefault()
        window.print()
    })

    /*
     * ⓘ `change`, `input` নয়: ড্রপডাউনে বাছাই শেষ হলে তবেই পাতা যাক।
     * ⚠️ `input` হলে কিবোর্ডে তীর চাপতে চাপতে প্রতিটা ধাপেই পাতা
     * বদলাত, আর তালিকার শেষ বিকল্পটায় পৌঁছানোই যেত না।
     */
    root.addEventListener('change', (event) => {
        const field = event.target.closest?.(SUBMIT)

        if (! field) {
            return
        }

        const form = field.form ?? field.closest('form')

        if (! form) {
            return
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit()
        } else {
            form.submit()
        }
    })

    /*
     * ⓘ বাছলেই অন্য ঠিকানায় — ক্রয় ও বিক্রয় ফেরতের বিল বাছাই।
     *
     * ⚠️ ওখানে আগে লেখা ছিল `onchange="if (this.value) { window.location
     * = '…?bill=' + this.value }"`, আর পাশের মন্তব্যে কারণও লেখা ছিল:
     * *"সাধারণ onchange, Alpine-এর @change নয়: এই ফর্মে কোনো x-data
     * নেই"*। ⛔ যুক্তিটা ঠিক ছিল, কিন্তু CSP ইনলাইন হ্যান্ডলারও চালাতে
     * দেয় না — তাই বিল বাছলে **কিচ্ছুই হত না**, আর ফেরতের কাগজ
     * বানানোই যেত না।
     *
     * ⭐ ঠিকানাটা `URLSearchParams` দিয়ে গড়া, স্ট্রিং জোড়া দিয়ে নয়:
     * ঠিকানায় আগে থেকে একটা `?` থাকলে জোড়া-দেওয়া লেখাটা ভাঙত।
     */
    root.addEventListener('change', (event) => {
        const field = event.target.closest?.('[data-go-to]')

        if (! field || ! field.value) {
            return
        }

        const url = new URL(field.dataset.goTo, window.location.origin)
        url.searchParams.set(field.dataset.goParam ?? field.name, field.value)

        window.location = url.toString()
    })

    /*
     * ⭐ অনুমতির পর্দায় "সব" টিক — মালিকের নমুনা, ২২ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ কেন লাগল ─────────────────────────────────────────────────
     * একটা নতুন ভূমিকা বানাতে **দুইশোর বেশি ক্লিক** লাগত। ⚠️ মানুষ
     * তখন যা করে: সবচেয়ে কাছাকাছি একটা পুরনো ভূমিকা বেছে নেয়। ⓘ ফলে
     * মানুষ পায় দরকারের চেয়ে **বেশি** অনুমতি, আর কাগজে ব্যবস্থাটা
     * নিখুঁত দেখায়।
     *
     * ── ⓘ দুইটা স্তর ───────────────────────────────────────────────
     * `[data-permission-all]`    — গোটা মডিউল
     * `[data-permission-column]` — ঐ মডিউলের একটা কলাম (দেখা · যোগ …)
     *
     * ⚠️ `manage` ঘরগুলো কলামের টিকে পড়ে না: ওরা তিন কলাম জুড়ে বসে,
     * তাই "সব দেখা" চাপলে ওগুলোও টিক হলে মানুষ **মুছতেও** পারতেন।
     * ⓘ মডিউলের টিক ওদের ধরে, কারণ ওখানে উদ্দেশ্যটা স্পষ্ট।
     */
    root.addEventListener('change', (event) => {
        const box = event.target
        const module = box?.closest?.('[data-permission-module]')

        if (! module) {
            return
        }

        const bulk = box.matches('[data-permission-all], [data-permission-column]')

        /*
         * ⛔ একটাই শ্রোতা, দুইটা নয় — আর এই লাইনটা দুঃখ করে শেখা।
         *
         * ℹ প্রথম চালে গোনাটা আলাদা একটা শ্রোতায় ছিল, মডিউলের গায়ে।
         * ⚠️ ঘটনাটা আগে মডিউলে পৌঁছায়, পরে root-এ — তাই গোনাটা চলত
         * **আগে**, আর সে `all.checked` ওই মুহূর্তেই `false` করে দিত
         * (আংশিক বলে)। ⛔ তারপর এই শ্রোতা ওই `false`-টা পড়ে **সব টিক
         * তুলে দিত** — টিক দিলে সব খালি হয়ে যেত।
         */
        if (bulk) {
            const column = box.dataset.permissionColumn
            const wanted = column === undefined
                ? 'input[name="permissions[]"]'
                : `input[data-permission-cell="${column}"]`

            for (const cell of module.querySelectorAll(wanted)) {
                cell.checked = box.checked
            }
        } else if (! box.matches('input[name="permissions[]"]')) {
            return
        }

        countPermissions(module)
    })

    /*
     * ⚠️ `<summary>`-এর ভিতরে ক্লিক করলে ব্রাউজার বাক্সটা ভাঁজ করে —
     * টিক দিতে গিয়ে মডিউলটা বন্ধ হয়ে যেত।
     */
    root.addEventListener('click', (event) => {
        if (event.target?.closest?.('summary [data-permission-all]')) {
            event.stopPropagation()
        }
    })


    /*
     * ⭐ ছাপার লিংক থেকে এসে নিজে থেকেই ছাপা — খতিয়ানের ক্রমের জন্য।
     *
     * ⓘ মালিকের নিয়ম: পর্দায় নতুন আগে, কাগজে পুরনো আগে। ⛔ ছাপার
     * বোতামটা পর্দারটাই ছাপে, তাই খতিয়ানে সে বোতাম নয় — লিংক, যে আগে
     * `?ledger=asc&print=1`-এ নিয়ে যায়। এই ঘরটা শেষ জোড়াটা লাগায়।
     *
     * ⚠️ `load`, `DOMContentLoaded` নয়: হরফ আর ছকের প্রস্থ বসার **আগেই**
     * ছাপা শুরু হলে কাগজে কলামগুলো সরে যেত। ⓘ পাতাটা ইতিমধ্যেই পুরো
     * এসে গেলে (bfcache, বা দেরিতে বসানো স্ক্রিপ্ট) `load` আর আসে না,
     * তাই সেই বেলায় সরাসরি ডাকা হয়।
     */
    const printOnLoad = root.querySelector?.('[data-print-on-load]')

    if (printOnLoad) {
        if (document.readyState === 'complete') {
            window.print()
        } else {
            window.addEventListener('load', () => window.print(), { once: true })
        }
    }
}
