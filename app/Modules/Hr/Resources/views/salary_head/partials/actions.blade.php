{{-- মূল বেতনের খাতে নিষ্ক্রিয় করার বোতামটাই নেই।

     সেবা স্তর এমনিতেই আটকায়, কিন্তু চাপা যায় অথচ ব্যর্থ হয় এমন বোতাম
     রাখা মানে ব্যবহারকারীকে দিয়ে ভুল করানো। --}}
{{-- ⭐ "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): আগের লেখা-বোতামগুলো গ্রাহকের তালিকার মতো মেনুতে, কাজ একই --}}
@php
    $items = $head->is_active && ! $head->is_basic
        ? [['label' => __('hr::action.deactivate'), 'url' => route('hr.salary_head.destroy', $head->id), 'method' => 'delete', 'tone' => 'danger']]
        : [];
@endphp
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($head), \App\Core\Support\RowMenu::activeState($head), null, $head->code ?? null, 'hr')" />
