/*
 * ⭐ "ABOS" রূপে তালিকার মাথা এক সারিতে — মালিক, ১৯ নভেম্বর ২০২৬ (নমুনা Q2W6mM3S v3, "ok"): "মডিউলের বারটা ফিল্টারের বারটা দুইটা মিলে
 *   একটা" — রঙহীন সাদা বক্সে: নাম · সংখ্যা · ছাঁকনি · খোঁজা · দৃশ্য/কলাম/নামান · "+ নতুন"।
 *
 * ⓘ টাকার পাতাগুলোতে ([[x-money.page]]) নাম, সংখ্যা আর "নতুন" আলাদা রঙিন পট্টিতে (`data-money-hero`), আর ছাঁকনির সারি নিচের বাক্সে
 *   ([[x-ui.toolbar]]) — দুইটা আলাদা ধারকে, তাই কেবল CSS-এ এক সারি হয় না। এখানে পট্টির তিনটা জিনিস টুলবারে তুলে আনা হয়, আর
 *   পট্টিটা লুকায়। বাকি সাজ (এক সারি, রং, ক্রম) [[abos-look.css]]-এ।
 * ⛔ কেবল `rose`-এ — অন্য রূপে কিছুই নড়ে না। জাভাস্ক্রিপ্ট ছাড়া পট্টিটা সাদা হয়েই থাকে, কাজ সব চলে।
 */
export function mergeMoneyHead (root = document) {
    if (root.documentElement?.dataset.ui !== 'rose') {
        return 0
    }

    let merged = 0

    for (const page of root.querySelectorAll('[data-page-kind]')) {
        const hero = page.querySelector(':scope > [data-money-hero]')
        const toolbar = page.querySelector('.toolbar-view')

        if (! hero || ! toolbar || toolbar.hasAttribute('data-merged-head')) {
            continue
        }

        const title = hero.querySelector('h1')
        const count = hero.querySelector('[data-hero-count]')
        const actions = hero.querySelector('[data-hero-actions]')

        // ⓘ নাম আর সংখ্যা সারির শুরুতে, "নতুন" শেষে — ক্রম CSS-এর `order`-এ ([[abos-look.css]])
        if (count) {
            count.setAttribute('data-record-count', '')
            toolbar.prepend(count)
        }

        if (title) {
            title.setAttribute('data-merged-title', '')
            toolbar.prepend(title)
        }

        if (actions && actions.children.length) {
            actions.setAttribute('data-command-bar', '')
            toolbar.append(actions)
        }

        hero.hidden = true
        toolbar.setAttribute('data-merged-head', '')
        merged++
    }

    return merged
}
