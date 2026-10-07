{{--
    জমার সারিগুলো সার্ভারে যেভাবে যায় — ছয়টা লুকানো ঘর।

    ── ⭐ কেন কম্পোনেন্টে, ১৮ সেপ্টেম্বর ২০২৬ ──────────────────────────
    নিরীক্ষার ধাপ ৪.১: *"দুই পর্দার অভিন্ন HTML অংশ ব্লেড কম্পোনেন্টে
    তুলুন।"*

    ⓘ এই ছয়টা লাইন **হুবহু একই** ছিল বিক্রয় ও ক্রয়ের কাউন্টারে। ⚠️ আর
    নিরীক্ষায় দেখা গেছে একই বাগ ইতিমধ্যে **চার জায়গায়** পাওয়া গেছে
    (কমিট 9197153) — এটাই নকলের সরাসরি দাম।

    ── ⛔ কেন ঠিক এই ব্লকটা আগে ────────────────────────────────────────
    এখানকার ভুল **নীরব**: একটা ঘরের নাম বদলে গেলে (`refDate` → `ref_date`)
    ব্রাউজার কিছু বলে না, সার্ভার কিছু বলে না — কেবল ঐ তথ্যটা আর পৌঁছায়
    না। ⓘ জমার তারিখ ছাড়া একটা আদায় খতিয়ানে বসে, আর কেউ বলতে পারে না
    টাকাটা কবে এলো।

    ⭐ এক জায়গায় থাকলে নামগুলো দুই পর্দায় আলাদা হতে **পারে না**।

    ── ⓘ কেন কোনো prop নেই ────────────────────────────────────────────
    সূচকটা (`i`) আর সারিটা (`row`) দুইটাই Alpine-এর `x-for`-এর ভিতর
    থেকে আসে, তাই ব্লেডের কিছু পাঠানোর নেই। ⚠️ কম্পোনেন্টটা `<template
    x-for="(row, i) in deposits">`-এর **ভিতরেই** বসাতে হবে।
--}}
<input type="hidden" :name="'deposits[' + i + '][amount]'" :value="row.amount">
<input type="hidden" :name="'deposits[' + i + '][payment_method_id]'" :value="row.methodId">
<input type="hidden" :name="'deposits[' + i + '][account_id]'" :value="row.accountId">
<input type="hidden" :name="'deposits[' + i + '][ref_date]'" :value="row.refDate">
<input type="hidden" :name="'deposits[' + i + '][reference]'" :value="row.reference">
<input type="hidden" :name="'deposits[' + i + '][narration]'" :value="row.narration">

{{--
    ── ⭐ আদায় ভাউচারের তিনটা ঘর, ২৫ সেপ্টেম্বর ২০২৬ ───────────────────
    মালিকের নির্দেশ: *"জমা যোগ botam clic korle eirokom 100% same pop up
    open hobe"* — অর্থাৎ কাউন্টারের জমাও আদায় ভাউচারের মতো পূর্ণ হবে।

    ⓘ ঘর তিনটা `vouchers` টেবিলে **আগে থেকেই ছিল** (১৪ নভেম্বরের
    মাইগ্রেশন), কেবল কাউন্টারের পথটা ওগুলো বহন করত না।
--}}
<input type="hidden" :name="'deposits[' + i + '][moved_at]'" :value="row.movedAt">
<input type="hidden" :name="'deposits[' + i + '][carried_by]'" :value="row.carriedBy">

{{--
    ── ⚠️ নোটের গোনা: একটা ঘর নয়, প্রতিটা নোটের নিজের ঘর ────────────
    সার্ভার `deposits[0][note_counts][500]` আকারে চায়, তাই এখানেও
    `x-for`। ⛔ একটা JSON স্ট্রিং পাঠালে যাচাইয়ের নিয়মটা
    (`note_counts.*` → integer) কখনো চলত না, আর যেকোনো লেখা ঢুকে পড়ত।

    ⓘ শূন্যগুলো এখানে ছাঁকা হয় না — সেটা করে
    [[DirectSaleService::notesOf()]], কারণ পর্দার কাজ পাঠানো, সিদ্ধান্ত
    নেওয়া নয়।
--}}
{{-- ⚠️ তালিকাটা কম্পোনেন্ট থেকে, `Object.entries` দিয়ে নয় —
     ২৫ সেপ্টেম্বর ২০২৬।

     ⛔ আগে লেখা ছিল `x-for="[face, count] in Object.entries(...)"`।
     `@alpinejs/csp`-এ **বাইরের নাম ডাকা যায় না** (`Object` নেই), আর
     বিন্যাস ভেঙে নেওয়াও সে পড়ে না।

     ⓘ ফলটা নিখুঁতভাবে নীরব হত: Alpine বাঁধাইটা চুপচাপ ছেড়ে দিত, তাই
     এই লুকানো ঘরগুলো **কখনো আঁকা হত না** — আর নোটের হিসাব সার্ভারে
     পৌঁছাত না, কোনো ত্রুটি ছাড়াই।

     ⭐ ধরা পড়েছে [[csp-expressions.test.js]]-এ, বান্ডিল বাঁধার ঠিক
     আগে। হিসাবটা এখন [[direct-sale.js]]-এর `notesOf()`-এ। --}}
<template x-for="note in notesOf(row)" :key="note.face">
    <input type="hidden" :name="'deposits[' + i + '][note_counts][' + note.face + ']'"
           :value="note.count">
</template>
