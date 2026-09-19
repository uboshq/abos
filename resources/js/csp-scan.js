import { readFileSync, readdirSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join, relative, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

/*
 * প্রতিটা Alpine এক্সপ্রেশন CSP-Alpine-এর **নিজের পার্সার** দিয়ে পড়া।
 *
 * ── ⛔ আগের দুইটা মাপ কেন ভুল ছিল, ১৯ সেপ্টেম্বর ২০২৬ ─────────────────
 * প্রথমটা (`AlpineDebt`, PHP) ধরে নিয়েছিল CSP-Alpine কেবল নাম বোঝে —
 * গুনেছিল ১,৯০২। দ্বিতীয়টা কয়েকটা গঠন ব্রাউজারে চালিয়ে দেখেছিল, কিন্তু
 * ভুলটা চিনত বার্তার কয়েকটা শব্দ দিয়ে (`CSP|Parser|…`) — আর
 * `Undefined variable: Math`-এ ঐ শব্দগুলোর একটাও নেই। ⚠️ ফলে `Math`,
 * `+=`, আর `a; b` "চলে" বলে লেখা হয়েছিল। তিনটাই চলে না।
 *
 * ⭐ তাই অনুমান নয় — ঐ প্যাকেজের `Tokenizer` আর `Parser` হুবহু এখানে
 * চলে, প্রতিটা ব্লেডের প্রতিটা এক্সপ্রেশনে। ব্রাউজার যা পড়তে পারবে না,
 * এই পরীক্ষাও পারবে না।
 *
 * ⓘ পার্সার ছাড়াও দুইটা নিয়ম আছে যা কেবল চালানোর সময় ধরা পড়ে:
 *   • বৈশ্বিক নাম (`Math`, `window`, `Number`…) — কম্পোনেন্টে থাকে না
 *   • DOM-এর ঘরে লেখা (`$el.value = …`) — CSP সংস্করণ নিষেধ করে
 * দুইটাই নিচে AST হেঁটে ধরা হয়।
 */

export const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

const { Tokenizer, Parser } = (() => {
    const file = createRequire(import.meta.url).resolve('@alpinejs/csp/dist/module.esm.js')
    const source = readFileSync(file, 'utf8').replace(/export\s*\{[^}]*\};?\s*$/, '')

    // ⓘ কেবল পরীক্ষায় — প্যাকেজ তার ক্লাস দুইটা রপ্তানি করে না
    return new Function(`${source}\nreturn { Tokenizer, Parser }`)()
})()

const DIRECTIVES = ['x-data', 'x-show', 'x-text', 'x-html', 'x-model', 'x-if', 'x-for',
    'x-init', 'x-effect', 'x-modelable', 'x-intersect', 'x-trap']

const PREFIXES = ['x-on:', 'x-bind:', '@', ':']

/*
 * ⚠️ এই নামগুলো ব্রাউজারের, কম্পোনেন্টের নয়। CSP-Alpine নাম খোঁজে কেবল
 * x-data-র স্তূপে, তাই এগুলো "Undefined variable" — চুপচাপ, কনসোলে।
 */
const GLOBALS = new Set(['Math', 'Number', 'String', 'Boolean', 'Object', 'Array', 'JSON',
    'Date', 'Intl', 'window', 'document', 'console', 'parseInt', 'parseFloat', 'isNaN',
    'localStorage', 'sessionStorage', 'navigator', 'location', 'setTimeout', 'clearTimeout',
    'alert', 'confirm', 'fetch', 'encodeURIComponent', 'abos', 'Alpine', 'history'])

export function blades () {
    const found = []

    for (const dir of ['app', join('resources', 'views')]) {
        for (const entry of readdirSync(join(root, dir), { recursive: true })) {
            if (String(entry).endsWith('.blade.php')) {
                found.push(join(root, dir, String(entry)))
            }
        }
    }

    return found
}

/*
 * ব্লেডের নিজের অংশ সরানো — ওগুলো সার্ভারেই মেটে, ব্রাউজার পায় একটা মান।
 * ⓘ বদলে একটা নাম বসে (`__blade`), সংখ্যা নয়: `{{ $model }} = iso`-এ
 * ব্রাউজার পায় `form.date = iso` — একটা নাম, যাতে লেখা যায়।
 */
export function withoutBlade (value) {
    // ⓘ ব্লেডের মন্তব্য (`{{-- --}}`) কিছুই রাখে না — মান নয়, শূন্যতা
    value = value.replace(/\{\{--[\s\S]*?--\}\}/g, '')
    value = value.replace(/\{\{.*?\}\}|\{!!.*?!!\}/gs, '__blade')

    for (let m; (m = /@(js|json)\(/.exec(value));) {
        let depth = 0
        let end = value.length

        for (let i = m.index + m[0].length - 1; i < value.length; i++) {
            depth += value[i] === '(' ? 1 : value[i] === ')' ? -1 : 0

            if (depth === 0) {
                end = i + 1
                break
            }
        }

        value = value.slice(0, m.index) + '__blade' + value.slice(end)
    }

    return value.replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
}

function isAlpine (name) {
    return DIRECTIVES.includes(name.split('.')[0]) || PREFIXES.some(p => name.startsWith(p))
}

/*
 * একটা ব্লেডের প্রতিটা ট্যাগের প্রতিটা অ্যাট্রিবিউট — হাতে পড়া, রেগুলার
 * এক্সপ্রেশনে নয়।
 *
 * ⛔ প্রথম সংস্করণ একটা রেগুলার এক্সপ্রেশন ছিল, আর যে ট্যাগের কোনো মানে
 * `"` ছিল (`{{ __("…") }}`-এর ভিতরে), সেটা সে **গোটাটাই** হারাত — চুপচাপ।
 * ⚠️ খরচের ভাউচারের x-data-তে `...{{ $texts }}` ছিল, আর সেটা গোনাতেই আসেনি।
 *
 * ⓘ তাই এখানে ব্রাউজার যেভাবে পড়ে সেভাবে: উদ্ধৃতির ভিতরে উদ্ধৃতি শেষ
 * হয় কেবল উদ্ধৃতিতে — তবে `{{ … }}` আর `@x(…)` সার্ভারেই মেটে, তাই ওদের
 * ভিতরের `"` মান শেষ করে না।
 */
export function attributes (source) {
    const found = []
    const skip = [['{{--', '--}}'], ['<!--', '-->'], ['@php', '@endphp'], ['<?php', '?>'],
        ['<script', '</script>'], ['<style', '</style>']]
    let i = 0

    const skipBlade = (at) => {
        if (source.startsWith('{{', at) || source.startsWith('{!!', at)) {
            const close = source.startsWith('{{', at) ? '}}' : '!!}'
            const end = source.indexOf(close, at + 2)

            return end === -1 ? source.length : end + close.length
        }

        const directive = /^@[a-zA-Z]+\s*\(/.exec(source.slice(at, at + 40))

        if (directive) {
            let depth = 0

            for (let j = at + directive[0].length - 1; j < source.length; j++) {
                const c = source[j]

                if (c === "'" || c === '"') {
                    j = source.indexOf(c, j + 1)
                    if (j === -1) return source.length
                    continue
                }

                depth += c === '(' ? 1 : c === ')' ? -1 : 0
                if (depth === 0) return j + 1
            }
        }

        return null
    }

    while (i < source.length) {
        const block = skip.find(([open]) => source.startsWith(open, i))

        // ⓘ `<script` ট্যাগটা নিজেও পড়া হয় না — এখানে কোনো Alpine নেই
        if (block && ! (block[0] === '@php' && /^@php\s*\(/.test(source.slice(i, i + 8)))) {
            const end = source.indexOf(block[1], i + block[0].length)
            i = end === -1 ? source.length : end + block[1].length
            continue
        }

        const open = /^<([a-zA-Z][\w.:-]*)/.exec(source.slice(i, i + 80))

        if (! open) {
            i++
            continue
        }

        const tag = open[1]
        const line = source.slice(0, i).split('\n').length
        i += open[0].length

        while (i < source.length) {
            while (/\s/.test(source[i])) i++

            if (source[i] === '>' || source.startsWith('/>', i)) {
                i += source[i] === '>' ? 1 : 2
                break
            }

            const blade = skipBlade(i)

            if (blade !== null) {
                i = blade
                continue
            }

            const name = /^[^\s=>/]+/.exec(source.slice(i, i + 200))?.[0] ?? source[i]
            i += name.length

            while (/[ \t]/.test(source[i])) i++

            if (source[i] !== '=') {
                continue
            }

            i++
            while (/\s/.test(source[i])) i++

            const quote = source[i] === '"' || source[i] === "'" ? source[i] : null
            let value = ''
            const start = quote ? i + 1 : i

            if (quote) {
                i++

                while (i < source.length && source[i] !== quote) {
                    const skipped = skipBlade(i)

                    if (skipped !== null) {
                        value += source.slice(i, skipped)
                        i = skipped
                    } else {
                        value += source[i++]
                    }
                }

                i++
            } else {
                value = /^[^\s>]*/.exec(source.slice(i))[0]
                i += value.length
            }

            found.push({ tag, name, value, line, start, end: start + value.length })
        }
    }

    return found
}

export function expressions (path) {
    const source = readFileSync(path, 'utf8')
    const found = []

    for (const { tag, name: raw, value, line, start, end } of attributes(source)) {
        let name = raw

        if (tag.startsWith('x-') && name.startsWith(':')) {
            if (! name.startsWith('::')) {
                continue // PHP-র prop
            }

            name = name.slice(1)
        }

        if (! isAlpine(name)) {
            continue
        }

        found.push({ name, line, start, end, raw: value, value: withoutBlade(value).trim() })
    }

    return found
}

function parse (expression) {
    return new Parser(new Tokenizer(expression).tokenize()).parse()
}

function walk (node, visit, parent = null) {
    if (! node || typeof node !== 'object') {
        return
    }

    visit(node, parent)

    for (const [key, child] of Object.entries(node)) {
        if (key === 'type') {
            continue
        }

        for (const c of Array.isArray(child) ? child : [child]) {
            if (c && typeof c === 'object' && c.type) {
                walk(c, visit, node)
            }
        }
    }
}

/** এক্সপ্রেশনটা CSP-Alpine-এ চলবে না কেন — চললে null */
export function problem ({ name, value, raw = '' }) {
    /*
     * ⚠️ `Js::from()` লেখে `JSON.parse('ব…')` — `JSON` বৈশ্বিক, আর
     * CSP-Alpine `\u`-কে চেনে না, বাংলা হয় `u09ac`। ⓘ `@js` নিরাপদ, কারণ
     * ওটা এখন [[AlpineLiteral]]।
     */
    if (raw.includes('Js::from(')) {
        return 'Js::from() — @js লিখুন'
    }

    if (value === '') {
        return null
    }

    let source = value

    if (name.split('.')[0] === 'x-for') {
        const m = source.match(/^\s*\(?\s*[\w$]+(\s*,\s*[\w$]+)*\s*\)?\s+(in|of)\s+([\s\S]+)$/)

        if (! m) {
            return 'x-for-এর রূপ অচেনা'
        }

        source = m[3]
    }

    let ast

    try {
        ast = parse(source)
    } catch (e) {
        return e.message
    }

    const issues = []

    walk(ast, (node, parent) => {
        if (node.type === 'Identifier' && GLOBALS.has(node.name)) {
            const isProperty = parent?.type === 'MemberExpression' && parent.property === node && ! parent.computed
            const isKey = parent?.type === 'Property' && parent.key === node

            if (! isProperty && ! isKey) {
                issues.push(`বৈশ্বিক নাম: ${node.name}`)
            }
        }

        if ((node.type === 'AssignmentExpression' || node.type === 'UpdateExpression')) {
            const target = node.left ?? node.argument
            let base = target

            while (base?.type === 'MemberExpression') {
                base = base.object
            }

            if (target?.type === 'MemberExpression' && ['$el', '$refs', '$event'].includes(base?.name)) {
                issues.push('DOM-এর ঘরে লেখা')
            }
        }
    })

    return issues.length ? [...new Set(issues)].join('; ') : null
}

export function debt () {
    const failures = []
    let total = 0

    for (const path of blades()) {
        for (const expression of expressions(path)) {
            total++
            const why = problem(expression)

            if (why) {
                failures.push({
                    file: relative(root, path).split(sep).join('/'),
                    ...expression,
                    why,
                })
            }
        }
    }

    return { total, failures }
}
