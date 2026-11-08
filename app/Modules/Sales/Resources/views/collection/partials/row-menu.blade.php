{{-- আদায়ের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: `update` নীতিতে সম্পাদনা/নিশ্চিত, `delete` নীতিতে বাতিল; ছাপা = টাকার রসিদ --}}
@php($can = auth()->user())
<x-ui.paper-row-menu
    :paper="$collection"
    :show="route('sales.collection.show', $collection)"
    :state="__('core.status.'.$collection->status)"
    module="sales"
    :edit="route('sales.collection.edit', $collection)"
    :editable="(bool) $can?->can('update', $collection)"
    :confirmable="(bool) $can?->can('update', $collection)"
    :cancellable="(bool) $can?->can('delete', $collection)"
    :prints="$can?->can('sales.collection.view') ? [[__('core.row_menu.print_receipt'), route('sales.print.receipt', $collection)]] : []"
    :pdf="$can?->can('sales.collection.view') ? route('sales.print.receipt', ['collection' => $collection, 'download' => 1]) : null"
    :share="$can?->can('sales.collection.view') ? ['route' => 'sales.print.receipt', 'param' => 'collection', 'type' => 'sales_collection'] : null" />
