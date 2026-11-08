{{-- মাল গ্রহণের (GRN) "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: `update` নীতিতে সম্পাদনা/নিশ্চিত, `delete` নীতিতে বাতিল; বিল এখন গ্রহণেই আপনা থেকে, তাই "বিল বানান" পাতায় --}}
@php($can = auth()->user())
<x-ui.paper-row-menu
    :paper="$receipt"
    :show="route('purchase.receipt.show', $receipt)"
    :state="__('core.status.'.$receipt->status)"
    module="purchase"
    :edit="route('purchase.receipt.edit', $receipt)"
    :editable="(bool) $can?->can('update', $receipt)"
    :confirmable="(bool) $can?->can('update', $receipt)"
    :cancellable="(bool) $can?->can('delete', $receipt)"
    :prints="$can?->can('purchase.receipt.view') ? [[__('core.row_menu.print'), route('purchase.print.receipt', $receipt)]] : []"
    :pdf="$can?->can('purchase.receipt.view') ? route('purchase.print.receipt', ['receipt' => $receipt, 'download' => 1]) : null" />
