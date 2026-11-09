{{-- এক দরের কাজ — ইতিহাস, আর (চাবি থাকলে) তুলে দেওয়া। --}}
{{-- ⭐ "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): আগের লেখা-বোতামগুলো গ্রাহকের তালিকার মতো মেনুতে, কাজ একই --}}
@php
    $items = [];
    if ($canManage) {
        $items[] = ['label' => __('sales::price_book.remove'), 'url' => route('sales.price_book.items.destroy', [$list, $item]), 'method' => 'delete', 'tone' => 'danger', 'icon' => 'trash'];
    }
    $menu = \App\Core\Support\RowMenu::dress($items, (string) ($item->product?->name() ?? ''), '') ?: [\App\Core\Support\RowMenu::head((string) ($item->product?->name() ?? ''), '')];
    // ⓘ দামের ইতিহাস "দেখা" ভাগে — আগের লেখা-লিংকটাই
    array_splice($menu, 1, 0, [\App\Core\Support\RowMenu::group('look'), ['label' => __('sales::price_book.history'), 'icon' => 'clock', 'url' => route('sales.price_book.history', [$list, $item->product_id]), 'peek' => false]]);
@endphp
<x-ui.row-actions :label="__('core.table.action_menu')" :items="$menu" />
