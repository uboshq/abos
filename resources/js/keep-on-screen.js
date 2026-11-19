/*
 * ⭐ ভাসমান তালিকা পর্দার ভেতরে — ডান কিনারা পেরোলে যতটা বেরিয়েছে ততটা বাঁয়ে সরে, বাঁ কিনারার আগে থামে।
 *   মালিক, ১৯ নভেম্বর ২০২৬ রাত (Dynamic, লাইভের ছবি): ডানের মডিউলগুলোর (মালিকের কেন্দ্র, ডকুমেন্ট, বিজ্ঞপ্তি, স্থায়ী সম্পদ, ☰)
 *   তালিকা পর্দার বাইরে কেটে যাচ্ছিল — "এটা হচ্ছে মেইন সমস্যা"।
 * ⓘ তালিকা খোলার পরে একবার মাপা হয় (Alpine `x-init` + `$nextTick`), বোতামের নিচেই থাকে — কেবল আড়াআড়ি সরে।
 */
/*
 * ⓘ Alpine এখানে CSP সংস্করণ — markup-এ ফাংশন ডাকা চলে না। তাই একটা পাতা-জোড়া শ্রোতা: মেনুর বোতাম চাপলে পরের দুই ফ্রেমে
 *   (Alpine তালিকাটা আঁকার পরে) খোলা তালিকাগুলো মেপে সরানো।
 */
export function keepMenusOnScreen (root = document, selector = '[data-dyn-drop], [data-dyn-all-panel]') {
    root.addEventListener('click', e => {
        if (! e.target.closest?.('[data-dyn-menu], [data-dyn-all]')) {
            return
        }

        requestAnimationFrame(() => requestAnimationFrame(() => {
            root.querySelectorAll(selector).forEach(el => keepOnScreen(el))
        }))
    })
}

export function keepOnScreen (el, { gap = 8, win = window } = {}) {
    if (! el) {
        return 0
    }

    el.style.insetInlineStart = ''

    const r = el.getBoundingClientRect()
    const over = r.right - (win.innerWidth - gap)

    if (over <= 0) {
        return 0
    }

    // ⓘ বাঁয়ে সরতে সরতে পর্দার বাঁ কিনারা পেরোবে না
    const shift = Math.min(over, Math.max(0, r.left - gap))
    el.style.insetInlineStart = `${-shift}px`

    return shift
}
