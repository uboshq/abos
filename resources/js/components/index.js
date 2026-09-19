import * as shell from './shell.js'
import * as money from './money.js'
import * as forms from './forms.js'
import * as screens from './screens.js'
import * as pos from '../pos.js'
import * as documents from './documents.js'

/*
 * প্রতিটা নামওয়ালা কম্পোনেন্ট এক জায়গায় নিবন্ধিত — ব্লেড কেবল নামটা ডাকে।
 *
 * ⓘ ফাইলের প্রতিটা রপ্তানি করা ফাংশন তার নিজের নামেই নিবন্ধিত হয়
 * (`commandCenter` → `x-data="commandCenter(…)"`)। ⚠️ তাই সহায়ক ফাংশন
 * (যেমন `countNotes`) রপ্তানি করলেও ক্ষতি নেই — কেউ ডাকে না, আর পরীক্ষা
 * ওটাকে সরাসরি পায়।
 */
const modules = [shell, money, forms, screens, pos, documents]

/*
 * ব্রাউজারের কয়েকটা ফাংশন, পর্দা থেকে ডাকার মতো করে।
 *
 * ⛔ CSP-Alpine কম্পোনেন্টের বাইরের কোনো নাম দেখে না — `String(x)`,
 * `Number(x)`, `Math.abs(x)` সবই "Undefined variable"। আর বৈশ্বিক
 * ফাংশনটা সরাসরি ফেরত দিলেও চলে না: ও মানটাকেই চেনে আর আটকায়
 * ("Accessing global variables is prohibited")।
 *
 * ⓘ তাই প্রতিটা একটা নতুন মোড়ক — `$str(row.id)`, `$abs(gap)`। নাম ছোট,
 * কারণ এগুলো পর্দার এক্সপ্রেশনের ভিতরে বসে।
 */
export const magics = {
    str: (v) => String(v),
    num: (v) => Number(v),
    abs: (v) => Math.abs(v),
    round: (v) => Math.round(v),
    max: (...v) => Math.max(...v),
    min: (...v) => Math.min(...v),
    fixed: (v, digits = 2) => Number(v).toFixed(digits),
    reload: () => window.location.reload(),
}

/*
 * কীবোর্ডের শর্টকাট আর ফোকাস — `$refs.paid?.focus()` লেখার বদলে।
 *
 * ⓘ `?.` CSP-Alpine পড়ে না, আর `$nextTick(() => …)`-ও না। ⚠️ তাই তিনটা
 * মোড়ক, আর তিনটাই চুপচাপ কিছু না করে যদি ঘরটা পর্দায় না থাকে — ঠিক
 * যেমন `?.` করত:
 *
 *   $focus($refs.paid)        এখনই ফোকাস
 *   $focus('[name=customer]') নির্বাচক দিয়েও
 *   $press($refs.hold)        বোতামটা চাপা
 *   $focusSoon()              এই ঘরটা, পরের টিকে (x-effect/x-init থেকে)
 *   $focusSoon('search')      এই কম্পোনেন্টের `x-ref="search"`, পরের টিকে
 */
const find = (target) => typeof target === 'string' ? document.querySelector(target) : target

export function registerComponents (Alpine) {
    for (const [name, fn] of Object.entries(magics)) {
        Alpine.magic(name, () => fn)
    }

    Alpine.magic('focus', () => (target) => find(target)?.focus())
    Alpine.magic('press', () => (target) => find(target)?.click())
    Alpine.magic('focusSoon', (el) => (ref) => Alpine.nextTick(() => {
        const node = ref === undefined ? el : Alpine.$data(el).$refs?.[ref]

        node?.focus()
    }))

    for (const module of modules) {
        for (const [name, factory] of Object.entries(module)) {
            if (typeof factory === 'function') {
                Alpine.data(name, factory)
            }
        }
    }
}
