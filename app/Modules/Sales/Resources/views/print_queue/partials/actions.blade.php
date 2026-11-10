{{-- ⭐ "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"): আগের লেখা-বোতামগুলো গ্রাহকের তালিকার মতো মেনুতে, কাজ একই --}}
@php
    $items = array_values(array_filter([
        $job->printUrl() !== null ? ['label' => __('sales::action.print_again'), 'icon' => 'printer', 'url' => $job->printUrl(), 'blank' => true] : null,
        ['label' => __('sales::action.print_settled'), 'url' => route('sales.print_queue.settle', ['job' => $job->id]), 'method' => 'post'],
    ]));
@endphp
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, (string) ($job->document_no ?? $job->label ?? ''), '')" />
