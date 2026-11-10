{{-- ⭐ "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): ভাড়ার চুক্তির পাতা খোলার আগের "দেখুন" লিংক এখন মেনুতে —
     দেখুন · প্রিভিউ · ইতিহাস; মাসের ভাড়া, শর্ত বদল, জামানত, চুক্তি শেষ সব চুক্তির নিজের পাতায় --}}
<x-ui.row-actions :label="__('core.table.action_menu')"
    :items="\App\Core\Support\RowMenu::dress([], \App\Core\Support\RowMenu::title($contract), \App\Core\Support\RowMenu::activeState($contract), route('finance.rental.show', $contract), $contract->code ?? null, 'finance')" />
