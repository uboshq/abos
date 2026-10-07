// @vitest-environment happy-dom
import { writeFileSync } from 'node:fs'
import { describe, it } from 'vitest'
import { debt } from './csp-scan.js'

/*
 * ⭐ কোন এক্সপ্রেশনগুলো CSP-Alpine পড়তে পারে না — নাম ধরে ছাপে।
 *
 * ── ⛔ কেন এটা আলাদা একটা ফাইল ──────────────────────────────────────
 * [[csp-expressions.test.js]] কেবল **সংখ্যা** বলে (`expected 2 to be 0`),
 * আর নামগুলো লেখে `CSP_DEBT_OUT` ঠিকানায়। ⚠️ কোনো কোনো শেলে ঐ
 * ভেরিয়েবল বসানো যায় না, আর তখন লাল দেখা যায় কিন্তু **কোনটা লাল তা
 * জানা যায় না**।
 *
 * ⓘ স্ক্যানারটা আসল Alpine পার্সার চালায়, আর সে `MutationObserver`
 * চায় — তাই খালি node-এ চলে না, jsdom লাগে।
 *
 * ⚠️ এটা কোনো দাবি করে না, কেবল ছাপে — ⛔ দাবি করলে এটাই দ্বিতীয় একটা
 * পাহারা হত, আর দুইটা পাহারা একই জিনিস মাপলে একদিন তারা আলাদা উত্তর দেয়।
 */
describe('CSP-এর দেনা — নাম ধরে', () => {
    it('ছাপে, দাবি করে না', () => {
        const { failures } = debt()

        /*
         * ⚠️ ফাইলে লেখা, `console.log`-এ নয় — ⓘ vitest চুপচাপ চলা
         * পরীক্ষার ছাপা গিলে ফেলে, আর তখন এই ফাইলটার কোনো মানেই থাকে না।
         */
        writeFileSync('storage/app/csp-debt.txt', `failures: ${failures.length}\n`
            + failures
                .map(f => `${f.file}:${f.line}  ${f.name}="${String(f.value).replace(/\s+/g, ' ').slice(0, 160)}"\n    why: ${f.why}`)
                .join('\n'))
    }, 120000)
})
