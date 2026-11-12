{{--
    ⭐ দর তালিকার সারির "অ্যাকশন ▾" — মালিক, ১০ নভেম্বর ২০২৬ ("বাকি তালিকায় অ্যাকশন ▾", কাজের ফাঁকে): গ্রাহকের তালিকার একই মেনু
    ([[RowMenu::dress()]]) — দেখা · কাজ। ⓘ দর বসানো আর মোছা তালিকার নিজের পাতায় (পণ্যপ্রতি দর, মেয়াদসহ); এখানে কেবল খোলা আর সম্পাদনা।
    ⛔ সম্পাদনার চাবি না থাকলে সারিটা ধূসর, কারণসহ — পাতার বোতামের একই শর্ত (`sales.price_list.manage`)।
--}}
@php
    $items = [[
        'label' => __('core.row_menu.edit'), 'url' => route('sales.price_book.edit', $list), 'icon' => 'edit',
        'off' => auth()->user()?->can('sales.price_list.manage') ? null : __('core.row_menu.why_no_key'),
    ]];
@endphp
<x-ui.row-actions :label="__('core.table.action_menu')"
    :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($list), \App\Core\Support\RowMenu::activeState($list),
        route('sales.price_book.show', $list), $list->code ?: null, 'sales')" />
