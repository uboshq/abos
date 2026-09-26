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
 * ── ⭐ প্রতিটা রূপে, ২৭ সেপ্টেম্বর ২০২৬ (C-10) ───────────────────────
 * ⛔ আগে এই ফাইলটা চালু হত কেবল `[data-look-hints]` থাকলে — আর সেটা
 * আঁকে শুধু নেভি রূপ। বাকি ন'টা রূপে `/`, `N`, ↑ ↓, ↵ একটাও চলত না।
 * ⓘ কীবোর্ড কোনো রূপের সাজ নয়, কাজের গতি; তাই এখন শ্রোতা সবসময় বসে।
 *
 * ⚠️ `[data-look-hints]` এখনো আছে, কিন্তু কেবল **হিন্টের পট্টিটা**
 * দেখানোর সুইচ (`chrome/navy.blade.php`)। হিন্ট না দেখালেও চাবি চলে —
 * আর সেটা মিথ্যা প্রতিশ্রুতি নয়: না-বলা সুবিধা কাউকে ঠকায় না, বলা
 * অথচ অচল সুবিধা ঠকায়।
 *
 * ── ⛔ সবখানে চালু মানে সবখানে সাবধান ────────────────────────────────
 * আগে শর্তটা নিজেই পাহারা দিত: হিন্ট বসত কেবল তালিকার পাতায়। ⚠️ এখন
 * প্রতিটা পাতায় শ্রোতা, তাই প্রতিটা চাবি নিজের ঘর না পেলে **চুপ করে
 * সরে যায়**, আর ব্রাউজারের নিজের আচরণ ছোঁয় না:
 *
 *     /    কেবল টুলবারের খোঁজার ঘর (`data-quick-find`) থাকলে
 *     N    কেবল টুলবারের "নতুন" **লিংক** — কখনো ফর্মের জমা-বোতাম নয়
 *     ↑ ↓  কেবল ঠিকানাওয়ালা সারি থাকলে — নাহলে পাতা আগের মতোই স্ক্রল
 *     ↵    কেবল কোনো সারি বাছা থাকলে, আর ফোকাস কোনো বোতাম/লিংকে না থাকলে
 *
 * ⓘ Ctrl / Alt / ⌘ চাপা থাকলে কিছুই না (ব্রাউজারের শর্টকাট), লেখার
 * ঘরে কার্সর থাকলে কিছুই না, আর আগে কেউ `preventDefault()` করলে কিছুই না।
 *
 * ── কেন রূপের নাম এখানে লেখা নেই ─────────────────────────────────────
 * ফাইলটা কোনো রূপ চেনে না — না `navy`, না `data-look-hints`। ⓘ চেনে
 * কেবল পাতার নিজের চিহ্ন: টুলবার, প্রধান বোতাম, `ui-list` ছক।
 */

/** লেখার ঘরে কার্সর থাকলে শর্টকাট চলবে না — নাহলে "N" টাইপ করা যেত না। */
function typing(el) {
    if (! el) return false

    if (el.isContentEditable) return true

    /*
     * ⚠️ `isContentEditable` সব জায়গায় ভরসার নয় (happy-dom-এ, আর পুরনো
     * কিছু এডিটরের ভিতরের ঘরে) — তাই পূর্বপুরুষের চিহ্নটাও দেখা হয়।
     */
    if (el.closest?.('[contenteditable]:not([contenteditable="false"])')) return true

    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)
}

/*
 * ↵ কেবল তখনই আমাদের, যখন ফোকাস কোনো চাপা-যায় এমন জিনিসে নেই।
 *
 * ⛔ বোতাম বা লিংকে ফোকাস থাকলে ↵ ব্রাউজার নিজেই চালায় — আমরাও সারি
 * খুললে **একই চাপে দুইটা কাজ** হত: বোতামটা চলত, আর পাতাটাও চলে যেত।
 */
function pressable(el) {
    if (! el || el === document.body || el === document.documentElement) return false

    return Boolean(el.closest?.('a[href], button, summary, [role="button"], [role="link"], [role="menuitem"], [role="option"], [role="tab"]'))
}

/*
 * যেসব জায়গা তীরগুলো নিজের কাজে লাগায় — মেনু, তালিকা-বাক্স, ট্যাব।
 * ⓘ ওখানে ↑ ↓ সারি সরালে ড্রপডাউনের ভিতরে চলাফেরাটাই মরত।
 */
function ownsArrows(el) {
    return Boolean(el?.closest?.('[role="menu"], [role="listbox"], [role="tablist"], [role="grid"], [role="tree"], [role="radiogroup"], [role="dialog"]'))
}

/*
 * বাছা সারির চিহ্ন — কোনো রূপের CSS ছাড়াই চোখে পড়ে।
 *
 * ⛔ ২৭ সেপ্টেম্বর ২০২৬ মেপে দেখা: `data-key-row`-এর জন্য কোনো CSS
 * **কোথাও নেই**, নেভিতেও না। ⚠️ তাই কার্সরটা এতদিন অদৃশ্য ছিল — তীর
 * চাপলে কিছু একটা সরত, চোখে পড়ত না, আর ↵ কোন সারি খুলবে তা আন্দাজ।
 *
 * ⓘ inline, কারণ দশটা রূপের প্রতিটায় আলাদা CSS বসাতে হত, আর একটা
 * ক্লাস নীরবে কম্পাইল না হলে ভুলের কোনো বার্তা থাকে না। রংটা টোকেন
 * (`--color-brand-500`) — তাই প্রতিটা রূপ আর অন্ধকার মোড নিজের রং পায়।
 */
function mark(tr, on) {
    if (on) {
        tr.dataset.keyRow = 'on'
        tr.style.outline = '2px solid var(--color-brand-500)'
        tr.style.outlineOffset = '-2px'
    } else {
        delete tr.dataset.keyRow
        tr.style.outline = ''
        tr.style.outlineOffset = ''
    }
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

/** সারি সরানো গেল কি না — না গেলে তীরটা ব্রাউজারের (পাতা স্ক্রল) */
function move(step) {
    const all = rows()

    if (all.length === 0) return false

    const at = all.findIndex((tr) => tr.dataset.keyRow === 'on')

    /*
     * ⓘ প্রথমবার নিচের তীর চাপলে প্রথম সারি — শেষেরটা নয়। আর উপরের
     * তীর চাপলে শেষ সারি, তাই লম্বা তালিকার নিচ থেকে শুরু করা যায়।
     */
    const next = at === -1
        ? (step > 0 ? 0 : all.length - 1)
        : Math.min(Math.max(at + step, 0), all.length - 1)

    all.forEach((tr) => mark(tr, false))

    mark(all[next], true)

    /*
     * ⚠️ `block: 'nearest'` — নাহলে প্রতিটা চাপে পাতা লাফাত, আর ঠিক
     * যে জিনিসটা দ্রুত করার কথা সেটাই চোখের জন্য ক্লান্তিকর হত।
     */
    all[next].scrollIntoView?.({ block: 'nearest' })

    return true
}

/** বাছা সারির লিংক — কোনো সারি বাছা না থাকলে `null` */
function chosenLink() {
    const row = rows().find((tr) => tr.dataset.keyRow === 'on')

    return row ? row.querySelector('a[href]') : null
}

/*
 * ⚠️ একবারই — `listKeys()` দুইবার ডাকা হলে (যেমন Vite-এর হট-রিলোডে)
 * দুইটা শ্রোতা বসত, আর প্রতিটা তীর দুই ঘর সরাত।
 */
let listening = false

export function listKeys() {
    /*
     * ⭐ ২৭ সেপ্টেম্বর ২০২৬ থেকে কোনো শর্ত নেই — প্রতিটা রূপে শ্রোতা
     * বসে। ⓘ আগে এখানে `[data-look-hints]` না থাকলে ফিরে যাওয়া হত, আর
     * তাতে বাকি ন'টা রূপ কীবোর্ড পেত না (মাথার মন্তব্য)।
     */
    if (listening) return

    listening = true

    document.addEventListener('keydown', (event) => {
        // ⓘ আগে কেউ ধরেছে (পাতার নিজের শর্টকাট, খোলা প্যালেট) — তারটাই
        if (event.defaultPrevented) return

        if (event.altKey || event.ctrlKey || event.metaKey) return

        if (typing(event.target)) {
            // ⓘ Escape লেখার ঘর থেকে বেরোনোর পথ — তাই এটা টাইপ করার
            // মধ্যেও চলে
            if (event.key === 'Escape') event.target.blur()

            return
        }

        switch (event.key) {
            case '/': {
                /*
                 * ⚠️ টুলবারের খোঁজার ঘরটাই — `data-quick-find`
                 * (`ui/toolbar.blade.php`)। ⛔ আগে খোঁজা হত যেকোনো
                 * `input[type="search"]`, আর সাইডবারের মেনু-ছাঁকনিটা DOM-এ
                 * আগে বসে — তাই `/` তালিকা নয়, মেনু ছাঁকত।
                 */
                const box = document.querySelector('input[data-quick-find]')

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
                 *
                 * ⛔ ২৭ সেপ্টেম্বর ২০২৬ থেকে কেবল টুলবারের কমান্ড বারের
                 * (`data-command-bar`) **লিংক**। `data-page-primary` যেকোনো
                 * `tone="primary"` বোতামেও বসে — ফর্মের "সংরক্ষণ" সহ। ⚠️
                 * শ্রোতা এখন প্রতিটা পাতায়, তাই ঢালাও খুঁজলে ফর্মের পাতায়
                 * একটা `n` চাপলেই ফর্ম জমা পড়ত।
                 */
                const create = document.querySelector('[data-command-bar] a[data-page-primary][href]')

                if (! create) return

                event.preventDefault()
                create.click()

                return
            }

            case 'ArrowDown':
            case 'ArrowUp':
                /*
                 * ⓘ Shift+তীর লেখা বাছাইয়ের, আর মেনু/ট্যাবের ভিতরে তীর
                 * তাদের নিজের। ⚠️ আর সারি না থাকলে `preventDefault()`
                 * নয় — নাহলে ড্যাশবোর্ড আর ফর্মের পাতায় তীর দিয়ে স্ক্রল
                 * করা মরত।
                 */
                if (event.shiftKey || ownsArrows(event.target)) return

                if (move(event.key === 'ArrowDown' ? 1 : -1)) event.preventDefault()

                return

            case 'Enter': {
                // ⓘ Shift+↵ লিংককে নতুন জানালায় খোলে — ব্রাউজারের কাজ
                if (event.shiftKey || pressable(event.target)) return

                const link = chosenLink()

                if (! link) return

                event.preventDefault()
                link.click()
            }
        }
    })
}
