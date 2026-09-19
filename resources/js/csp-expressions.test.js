// @vitest-environment happy-dom
import { readFileSync, writeFileSync } from 'node:fs'
import { relative } from 'node:path'
import { describe, expect, it } from 'vitest'
import { blades, debt as scan, expressions, problem, root } from './csp-scan.js'

// ⓘ গোটা স্ক্যান একবারই — দুইশো ব্লেড পড়া ধীর, আর ব্যস্ত মেশিনে ৫ সেকেন্ড ছাড়াত
let memo = null
const debt = () => (memo ??= scan())
const SLOW = 60_000

/*
 * প্রতিটা Alpine এক্সপ্রেশন CSP-Alpine-এর নিজের পার্সার দিয়ে পড়া —
 * কেন আর কীভাবে, [[csp-scan.js]]-এর মাথায়।
 */
describe('CSP-Alpine-এর পার্সার', () => {
    it('যা চলে আর যা চলে না — পার্সারটা সত্যিই আসল', () => {
        expect(problem({ name: 'x-show', value: "method === 'cash' && ! open" })).toBeNull()
        expect(problem({ name: '@click', value: 'save(row, $event)' })).toBeNull()
        expect(problem({ name: ':class', value: "{ 'on': open, 'off': ! open }" })).toBeNull()
        expect(problem({ name: 'x-for', value: '(row, i) in rows' })).toBeNull()

        expect(problem({ name: 'x-text', value: 'rows.map(r => r.id)' })).not.toBeNull()
        expect(problem({ name: '@click', value: 'a = 1; b = 2' })).not.toBeNull()
        expect(problem({ name: '@click', value: 'n += 1' })).not.toBeNull()
        expect(problem({ name: 'x-text', value: 'Math.max(a, b)' })).toMatch('Math')
        expect(problem({ name: '@input', value: '$el.value = x' })).toMatch('DOM')
    })

    it('স্ক্যানারটা সত্যিই ব্লেড পড়ছে', () => {
        const { total } = debt()

        expect(total).toBeGreaterThan(1000)
    }, SLOW)

    /*
     * ⚠️ একটা ট্যাগ হারালে তার সব এক্সপ্রেশন হারায়, আর দাবিটা সবুজ থাকে —
     * প্রথম সংস্করণে ঠিক তাই ঘটেছিল। ⓘ তাই প্রতিটা ফাইলে গুনে মেলানো:
     * লেখায় যতবার ` x-data=` আছে, স্ক্যানার ততগুলো x-data পেয়েছে কি না।
     */
    it('কোনো x-data স্ক্যানারের চোখ এড়ায় না', () => {
        const missed = []

        for (const path of blades()) {
            const source = readFileSync(path, 'utf8')
                .replace(/\{\{--[\s\S]*?--\}\}/g, '')
                .replace(/<!--[\s\S]*?-->/g, '')
                .replace(/@php(?!\s*\()[\s\S]*?@endphp/g, '')
            const written = (source.match(/\sx-data=/g) ?? []).length
            const seen = expressions(path).filter(e => e.name === 'x-data').length

            if (written !== seen) {
                missed.push(`${relative(root, path)}: লেখা ${written}, পাওয়া ${seen}`)
            }
        }

        expect(missed).toEqual([])
    }, SLOW)

    it('কোনো ব্লেডে এমন এক্সপ্রেশন নেই যা CSP-Alpine পড়তে পারে না', () => {
        const { failures } = debt()

        if (process.env.CSP_DEBT_OUT) {
            writeFileSync(process.env.CSP_DEBT_OUT, failures
                .map(f => `${f.file}:${f.line}  ${f.name}="${f.value.replace(/\s+/g, ' ').slice(0, 200)}"\n    ⛔ ${f.why}`)
                .join('\n'))
        }

        expect(failures.length).toBe(0)
    }, SLOW)
})
