/*
 * তালিকার কীবোর্ড — "Linear-এর মতো Fast"।
 *
 * ── কেন এটা লাগল ─────────────────────────────────────────────────────
 * মালিকের Design Language-এর একটা লাইন: *Linear-এর মতো Fast*। ⓘ আর
 * Linear-এ দ্রুততা মানে অ্যানিমেশন নয় — **হাত কীবোর্ড ছাড়ে না**।
 *
 * ⛔ আজ পর্যন্ত এই অ্যাপে তালিকার কোনো শর্টকাট ছিল না। মেপে দেখা
 * (৪ সেপ্টেম্বর ২০২৬): F-কী আছে কেবল দুইটা কাউন্টার পর্দায়; `/`, `N`,
 * তীর — কোথাও নেই।
 *
 * ── ⚠️ হিন্ট আর শর্টকাট একই সুইচে ────────────────────────────────────
 * ফুটারে হিন্টের সারিটা আঁকা হয় `hints=keys` হলে, আর এই ফাইলটাও ওই
 * একই চিহ্ন দেখে চালু হয়। ⛔ একটা ছাড়া অন্যটা নয়: হিন্ট ছাড়া শর্টকাট
 * কেউ খুঁজে পান না, আর শর্টকাট ছাড়া হিন্ট একটা **মিথ্যা প্রতিশ্রুতি** —
 * আর ওটা না থাকার চেয়ে খারাপ।
 *
 * ── কেন রূপের নাম এখানে লেখা নেই ─────────────────────────────────────
 * ফাইলটা `data-look-hints` খোঁজে, `navy` নয়। ⓘ কাল দশম রূপ কীবোর্ড
 * চাইলে সে কেবল `Ui::all()`-এ ঘরটা বসাবে; এই ফাইলে হাত পড়বে না।
 */

/** লেখার ঘরে কার্সর থাকলে শর্টকাট চলবে না — নাহলে "N" টাইপ করা যেত না। */
function typing(el) {
    if (! el) return false

    if (el.isContentEditable) return true

    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)
}

/**
 * তালিকার সারিগুলো — যেগুলোর নিজের ঠিকানা আছে।
 *
 * ⚠️ প্রতিটা `<tr>` নয়: হেডার, খালি-অবস্থার সারি, যোগফলের সারি —
 * এগুলোয় যাওয়ার কিছু নেই। ⓘ ঠিকানা থাকাটাই সীমারেখা, আর সেটাই
 * "খুলুন" কাজটাকেও সম্ভব করে।
 */
function rows() {
    return [...document.querySelectorAll('table.ui-list tbody tr')]
        .filter((tr) => tr.querySelector('a[href]'))
}

function move(step) {
    const all = rows()

    if (all.length === 0) return

    const at = all.findIndex((tr) => tr.dataset.keyRow === 'on')

    /*
     * ⓘ প্রথমবার নিচের তীর চাপলে প্রথম সারি — শেষেরটা নয়। আর উপরের
     * তীর চাপলে শেষ সারি, তাই লম্বা তালিকার নিচ থেকে শুরু করা যায়।
     */
    const next = at === -1
        ? (step > 0 ? 0 : all.length - 1)
        : Math.min(Math.max(at + step, 0), all.length - 1)

    all.forEach((tr) => delete tr.dataset.keyRow)

    all[next].dataset.keyRow = 'on'

    /*
     * ⚠️ `block: 'nearest'` — নাহলে প্রতিটা চাপে পাতা লাফাত, আর ঠিক
     * যে জিনিসটা দ্রুত করার কথা সেটাই চোখের জন্য ক্লান্তিকর হত।
     */
    all[next].scrollIntoView({ block: 'nearest' })
}

function openRow() {
    const row = rows().find((tr) => tr.dataset.keyRow === 'on')

    row?.querySelector('a[href]')?.click()
}

export function listKeys() {
    /*
     * ⓘ চিহ্নটা খোলস আঁকে (`chrome/navy.blade.php`)। না থাকলে এই
     * ফাইলটা কিছুই করে না — অন্য ন'টা রূপে একটা `keydown` শ্রোতাও
     * বসে না।
     */
    if (! document.querySelector('[data-look-hints]')) return

    document.addEventListener('keydown', (event) => {
        if (event.altKey || event.ctrlKey || event.metaKey) return

        if (typing(event.target)) {
            // ⓘ Escape লেখার ঘর থেকে বেরোনোর পথ — তাই এটা টাইপ করার
            // মধ্যেও চলে
            if (event.key === 'Escape') event.target.blur()

            return
        }

        switch (event.key) {
            case '/': {
                const box = document.querySelector('[data-toolbar-search] input, input[type="search"]')

                if (! box) return

                event.preventDefault()
                box.focus()
                box.select()

                return
            }

            case 'n':
            case 'N': {
                /*
                 * ⚠️ "নতুন" মানে পাতার **প্রধান** বোতাম, যেকোনো লিংক
                 * নয়। ⓘ শিরোনামের ডানের বোতামটাই সেটা, আর সে নিজেকে
                 * চিহ্নিত করে — অনুমান করে খোঁজা হয় না।
                 */
                const create = document.querySelector('[data-page-primary]')

                if (! create) return

                event.preventDefault()
                create.click()

                return
            }

            case 'ArrowDown':
                event.preventDefault()
                move(1)

                return

            case 'ArrowUp':
                event.preventDefault()
                move(-1)

                return

            case 'Enter':
                openRow()
        }
    })
}
