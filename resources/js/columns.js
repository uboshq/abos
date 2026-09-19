/*
 * Columns মেনুর টিক → `?hide=` — টেবিল যেটা বোঝে।
 *
 * ── ⛔ মেনুটা কাজ করত না, ১৯ সেপ্টেম্বর ২০২৬-এ ধরা ─────────────────────
 * টুলবারের Columns মেনু টিক-দেওয়া কলামগুলো পাঠাত `show[]=code&show[]=name`
 * হিসেবে। ⚠️ কিন্তু টেবিল (`Ui\Table`) আর টুলবার দুইজনেই পড়ে কেবল
 * `hide=point,area` — আর `show[]` থেকে `hide` বানানোর কেউ ছিল না। ফলে
 * টিক তুলে "প্রয়োগ" চাপলে পাতা নতুন করে খুলত, আর **সব কলাম যেমন ছিল
 * তেমনই থাকত**। প্রতিটা তালিকায়।
 *
 * ⓘ ধরা পড়েছে গ্রাহক তালিকায় নতুন কলাম বসাতে গিয়ে: `?hide=` দিলে কলাম
 * লুকায়, মেনু দিয়ে দিলে লুকায় না।
 *
 * ── কেন এখানে, সার্ভারে নয় ────────────────────────────────────────────
 * টিক না-দেওয়া বাক্স ফর্মে কিছুই পাঠায় না, তাই সার্ভার জানতেই পারে না
 * কোন কলামগুলো **বাদ** গেল — কেবল কোনগুলো রইল। বাদের তালিকা বানাতে সব
 * কলামের নাম লাগে, আর সেটা আছে কেবল ফর্মেই। ⭐ তাই জমা দেওয়ার মুহূর্তে
 * টিক-না-দেওয়া বাক্সগুলো গুনে একটা `hide` ঘর বসানো হয়, আর `show[]`
 * পাঠানো বন্ধ করা হয় — ঠিকানাটা পরিষ্কার থাকে, আর পাঠানো লিংকে সহকর্মী
 * ঠিক একই কলাম দেখেন।
 */

/** ফর্মে Columns মেনু থাকলে, টিক থেকে লুকানো কলামের তালিকা */
export function hiddenColumnsOf (form) {
    const boxes = [...form.querySelectorAll('input[type="checkbox"][name="show[]"]')]

    if (boxes.length === 0) {
        return null
    }

    return boxes.filter(box => ! box.checked).map(box => box.value)
}

/** জমার আগে `show[]` → `hide` */
export function translateColumnChoice (form) {
    const hidden = hiddenColumnsOf(form)

    if (hidden === null) {
        return
    }

    let field = form.querySelector('input[type="hidden"][name="hide"]')

    if (hidden.length === 0) {
        field?.remove()
    } else {
        if (! field) {
            field = document.createElement('input')
            field.type = 'hidden'
            field.name = 'hide'
            form.appendChild(field)
        }

        field.value = hidden.join(',')
    }

    /*
     * ⓘ `disabled` বাক্স ফর্মে যায় না — তাই ঠিকানায় `show[]` আর বসে না।
     * ⚠️ পরের মুহূর্তে ফিরিয়ে দেওয়া হয়: ব্রাউজার "পেছনে" গেলে পাতাটা
     * ক্যাশ থেকে আসে, আর তখন বাক্সগুলো নিষ্ক্রিয় থেকে গেলে মেনুটা মরা দেখাত।
     */
    const boxes = [...form.querySelectorAll('input[type="checkbox"][name="show[]"]')]

    boxes.forEach(box => { box.disabled = true })
    setTimeout(() => boxes.forEach(box => { box.disabled = false }), 0)
}

export function listenForColumnChoice (root = document) {
    root.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement) {
            translateColumnChoice(event.target)
        }
    }, true)
}
