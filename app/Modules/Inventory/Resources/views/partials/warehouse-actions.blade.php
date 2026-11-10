{{--
    ⭐ "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): আগের দুইটা লেখা-লিংক
    (সম্পাদনা, জায়গা) এখন গ্রাহকের তালিকার মতো মেনুতে। ⚠️ জায়গার (ব্লক ▸ র‍্যাক ▸ শেলফ) পথটা আগের মতোই এখানেই —
    আলাদা মেনু-সারিতে নয়, কারণ তাক গুদামের সংজ্ঞার অংশ।
--}}
@php
    $items = [];
    if (auth()->user()?->can('inventory.warehouse.view')) {
        $items[] = ['label' => __('inventory::action.places'), 'icon' => 'grid', 'url' => route('inventory.warehouse.place.index', $warehouse)];
    }
    if (auth()->user()?->can('update', $warehouse)) {
        $items[] = ['label' => __('core.action.edit'), 'url' => route('inventory.warehouse.edit', $warehouse)];
    }
@endphp
@if ($items !== [])
    <x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($warehouse), \App\Core\Support\RowMenu::activeState($warehouse), null, $warehouse->code ?? null, 'inventory')" />
@endif
