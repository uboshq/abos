{{-- ক্রয় ফেরতের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: `update` নীতিতে সম্পাদনা/নিশ্চিত, `delete` নীতিতে বাতিল --}}
@php($can = auth()->user())
<x-ui.paper-row-menu
    :paper="$return"
    :show="route('purchase.return.show', $return)"
    :state="__('core.status.'.$return->status)"
    module="purchase"
    :edit="route('purchase.return.edit', $return)"
    :editable="(bool) $can?->can('update', $return)"
    :confirmable="(bool) $can?->can('update', $return)"
    :cancellable="(bool) $can?->can('delete', $return)"
    :prints="$can?->can('purchase.return.view') ? [[__('core.row_menu.print'), route('purchase.print.return', $return)]] : []"
    :pdf="$can?->can('purchase.return.view') ? route('purchase.print.return', ['return' => $return, 'download' => 1]) : null"
    :share="$can?->can('purchase.return.view') ? ['route' => 'purchase.print.return', 'param' => 'return', 'type' => 'purchase_return'] : null" />
