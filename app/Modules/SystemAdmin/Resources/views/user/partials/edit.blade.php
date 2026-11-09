{{-- ⭐ "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): আগের লেখা-লিংকগুলো গ্রাহকের তালিকার মতো মেনুতে --}}
@php
    $items = [['label' => __('core.action.edit'), 'url' => route('system_admin.user.edit', $user)]];
@endphp
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($user), \App\Core\Support\RowMenu::activeState($user), null, $user->email ?? null, 'system_admin')" />
