{{-- জমার নিজের পাতায় নিয়ে যায় — কিস্তি, মুনাফা, ভাঙা, আর ইতিহাস।

     ── কেন কাজগুলো এখানে নয় ─────────────────────────────────────────
     কিস্তি দিতে চাই তারিখ, টাকা আর কোন খাত। তিনটা ঘর এই কলামে গুঁজলে
     ছোট পর্দায় একটার ঘাড়ে আরেকটা পড়ত, আর টেবিলের স্ক্রলার প্যানেলটা
     কেটে দিত। --}}
{{-- ⭐ "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): আগের লেখা-লিংকগুলো গ্রাহকের তালিকার মতো মেনুতে --}}
@php
    $items = [];
@endphp
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($deposit), \App\Core\Support\RowMenu::activeState($deposit), route('finance.deposit.show', ['issuer' => $issuer, 'deposit' => $deposit->id]), $deposit->document_no ?? null, 'finance')" />
