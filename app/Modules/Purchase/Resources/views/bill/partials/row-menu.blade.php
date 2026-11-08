{{-- ক্রয় বিলের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: `update` নীতিতে সম্পাদনা/নিশ্চিত, `delete` নীতিতে বাতিল; নিশ্চিত বিলে টাকা দিন আর ফেরত --}}
@php($can = auth()->user())
@php($confirmed = $bill->status === \App\Core\Support\DocumentStatus::CONFIRMED)
@php($ended = $bill->status === \App\Core\Support\DocumentStatus::CANCELLED)
<x-ui.paper-row-menu
    :paper="$bill"
    :show="route('purchase.bill.show', $bill)"
    :state="__('core.status.'.$bill->status)"
    module="purchase"
    :edit="route('purchase.bill.edit', $bill)"
    :editable="(bool) $can?->can('update', $bill)"
    :confirmable="(bool) ($can?->can('update', $bill) && $bill->status === \App\Core\Support\DocumentStatus::DRAFT)"
    :cancellable="(bool) $can?->can('delete', $bill)"
    :prints="$can?->can('purchase.bill.view') ? [[__('core.row_menu.print'), route('purchase.print.bill', $bill)]] : []"
    :pdf="$can?->can('purchase.bill.view') ? route('purchase.print.bill', ['bill' => $bill, 'download' => 1]) : null"
    :work="array_values(array_filter([
        $can?->can('purchase.payment.create') ? ['label' => __('core.row_menu.pay'), 'icon' => 'cash',
            'url' => route('purchase.payment.create', ['purchase_bill_id' => $bill->id]), 'peek' => false,
            'off' => $confirmed ? null : ($ended ? __('core.row_menu.why_cancelled') : __('core.row_menu.why_need_confirmed'))] : null,
        $can?->can('purchase.return.create') ? ['label' => __('core.row_menu.return_out'), 'icon' => 'swap',
            'url' => route('purchase.return.create', ['purchase_bill_id' => $bill->id]), 'peek' => false,
            'off' => $confirmed ? null : ($ended ? __('core.row_menu.why_cancelled') : __('core.row_menu.why_need_confirmed'))] : null,
    ]))" />
