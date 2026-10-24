import { taka } from './components/money.js'

/*
 * ⭐ টাকার ঘর — দেখায় লাখ-কমায়, পাঠায় কাঁচা অঙ্ক (fe, ২৪ অক্টোবর ২০২৬; আদায়, ভাউচার আর সরবরাহকারীর পরিশোধ একসাথে)।
 *
 *   <input x-money-display="amount" data-money-empty="0.00">   ← দেখানোর ঘর, name নেই
 *   <input type="hidden" name="amount" :value="amount">         ← পাঠানোর ঘর, কাঁচা মান
 *
 * ── নিয়ম ────────────────────────────────────────────────────────────────
 *   · লেখার সময় যা লেখা হচ্ছে তাই — কমা বসে না, কার্সর লাফায় না;
 *   · ঘর ছাড়লে [[taka]]: "48,440.49" (লাখ-কমা, দুই দশমিক);
 *   · বাংলা অঙ্কও চলে ("৪৮৪৪০.৪৯"), আর কমা বা ফাঁকা লিখলেও মান কেবল অঙ্ক আর একটা বিন্দু ([[plainMoney]]);
 *   · ফাঁকা থাকলে `data-money-empty` (যেমন ব্যাংক চার্জে "0.00"), নইলে ফাঁকাই।
 * ⛔ আগে ঘরটাই কমাওয়ালা লেখা পাঠাত (`tidyAmount()` ঘরে "48,440.49" বসাত) — সার্ভার ছেঁটে পড়ত, কিন্তু প্রতিটা অনুরোধকে মনে
 *   রাখতে হত; এখন পাঠানো মানে কমা যায়ই না — সাধারণ post, নিশ্চিতের পপ-আপের FormData, দুবার জমা — সবই hidden ঘর পড়ে।
 * ⓘ মডেল পর্দার নিজের ঘর (`amount`, `charge`, `gross`…) — নতুন x-data নয়, তাই যোগ-বিয়োগের getter-গুলো আগের মতোই পড়ে।
 */

const BANGLA = '০১২৩৪৫৬৭৮৯'

/** লেখা টাকা → কাঁচা অঙ্ক: বাংলা অঙ্ক ইংরেজিতে, অঙ্ক আর প্রথম বিন্দু ছাড়া সব বাদ ("৪৮,৪৪০.৪৯" → "48440.49") */
export function plainMoney (text) {
    const latin = String(text ?? '').replace(/[০-৯]/g, (d) => String(BANGLA.indexOf(d)))
    const kept = latin.replace(/[^0-9.]/g, '')
    const dot = kept.indexOf('.')

    return dot === -1 ? kept : kept.slice(0, dot + 1) + kept.slice(dot + 1).replace(/\./g, '')
}

/** দেখানোর লেখা — ফাঁকা হলে `empty`, নইলে লাখ-কমায় */
export function shownMoney (raw, empty = '') {
    const plain = plainMoney(raw)

    return plain === '' || plain === '.' ? empty : taka(plain)
}

export function registerMoneyDisplay (Alpine) {
    Alpine.directive('money-display', (el, { expression }, { effect, cleanup }) => {
        const scope = () => Alpine.$data(el)
        const empty = el.dataset.moneyEmpty ?? ''
        const paint = () => {
            // ⓘ লেখার সময় হাত দেওয়া নয় — কার্সর লাফাত, আর "12." লিখতেই "12.00" হয়ে যেত
            if (document.activeElement !== el) {
                el.value = shownMoney(scope()[expression], empty)
            }
        }

        // ⓘ শুরুর লেখায় কমা থাকলে (ফেরত-আসা পুরনো লেখা, আগের ট্যাব) মানটা কাঁচা করে নেওয়া — পাঠানো ঘর তখনো কমা পায় না
        const start = scope()[expression]
        if (typeof start === 'string' && start !== '' && plainMoney(start) !== start) {
            scope()[expression] = plainMoney(start)
        }

        effect(() => {
            void scope()[expression]
            paint()
        })

        const onInput = () => { scope()[expression] = plainMoney(el.value) }
        // ⓘ শূন্যে ঢুকলে ফাঁকা ঘর — "0" মুছে লিখতে হয় না; কিছু না লিখে বেরোলে মান আগের মতোই
        const onFocus = () => {
            const plain = plainMoney(scope()[expression])
            el.value = plain !== '' && Number(plain) === 0 ? '' : plain
        }
        const onBlur = () => paint()

        el.addEventListener('input', onInput)
        el.addEventListener('focus', onFocus)
        el.addEventListener('blur', onBlur)

        cleanup(() => {
            el.removeEventListener('input', onInput)
            el.removeEventListener('focus', onFocus)
            el.removeEventListener('blur', onBlur)
        })
    })
}
