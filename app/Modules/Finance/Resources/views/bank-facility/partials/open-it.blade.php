{{-- ⓘ সুবিধার নিজের পাতায় — জামানত, শর্ত, খাতায় কোথায়, আর বন্ধ করা (১৯ সেপ্টেম্বর ২০২৬) --}}
{{-- ⭐ "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): আগের লেখা-লিংকগুলো গ্রাহকের তালিকার মতো মেনুতে --}}
@php
    $items = [];
@endphp
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($facility), \App\Core\Support\RowMenu::activeState($facility), route('finance.bank_facility.show', $facility->id), $facility->code ?? null, 'finance')" />
