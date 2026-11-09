{{-- তার নিজের পাতায় — ওখানেই টাকা দেওয়া-নেওয়া আর পুরো ইতিহাস --}}
{{-- ⭐ "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): আগের লেখা-লিংকগুলো গ্রাহকের তালিকার মতো মেনুতে --}}
@php
    $items = [];
@endphp
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($row['account']), \App\Core\Support\RowMenu::activeState($row['account']), route('finance.hand_loan.show', $row['account']), $row['account']->code ?? null, 'finance')" />
