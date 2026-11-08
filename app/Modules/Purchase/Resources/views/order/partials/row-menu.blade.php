{{-- ক্রয় আদেশের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: `update` নীতিতে সম্পাদনা/নিশ্চিত, `delete` নীতিতে বাতিল; নিশ্চিত হলে মাল গ্রহণ আর বিল --}}
@php($can = auth()->user())
@php($confirmed = $order->status === \App\Core\Support\DocumentStatus::CONFIRMED)
@php($ended = $order->status === \App\Core\Support\DocumentStatus::CANCELLED)
<x-ui.paper-row-menu
    :paper="$order"
    :show="route('purchase.order.show', $order)"
    :state="__('core.status.'.$order->status)"
    module="purchase"
    :edit="route('purchase.order.edit', $order)"
    :editable="(bool) $can?->can('update', $order)"
    :confirmable="(bool) $can?->can('update', $order)"
    :cancellable="(bool) $can?->can('delete', $order)"
    :prints="$can?->can('purchase.order.view') ? [[__('core.row_menu.print'), route('purchase.print.order', $order)]] : []"
    :pdf="$can?->can('purchase.order.view') ? route('purchase.print.order', ['order' => $order, 'download' => 1]) : null"
    :share="$can?->can('purchase.order.view') ? ['route' => 'purchase.print.order', 'param' => 'order', 'type' => 'purchase_order'] : null"
    :work="array_values(array_filter([
        $can?->can('create', \App\Modules\Purchase\Models\PurchaseReceipt::class) ? ['label' => __('core.row_menu.make_grn'), 'icon' => 'inbox',
            'url' => route('purchase.receipt.create', ['purchase_order_id' => $order->id]), 'peek' => false,
            'off' => $confirmed ? null : ($ended ? __('core.row_menu.why_cancelled') : __('core.row_menu.why_need_confirmed'))] : null,
        $can?->can('create', \App\Modules\Purchase\Models\PurchaseBill::class) ? ['label' => __('core.row_menu.make_bill'), 'icon' => 'receipt',
            'url' => route('purchase.bill.create', ['purchase_order_id' => $order->id]), 'peek' => false,
            'off' => $confirmed ? null : ($ended ? __('core.row_menu.why_cancelled') : __('core.row_menu.why_need_confirmed'))] : null,
    ]))" />
