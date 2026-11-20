{{-- আদায়ের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — `delete` নীতিতে বাতিল; ছাপা = টাকার রসিদ --}}
{{-- ⛔ সম্পাদনা/নিশ্চিত নয় — আদায় আর লেখা বা বদলানো হয় না, টাকা হিসাবের রসিদ ভাউচারে (মালিক, ২০ নভেম্বর ২০২৬); দেখা, ছাপা, বাতিল থাকে --}}
@php($can = auth()->user())
<x-ui.paper-row-menu
    :paper="$collection"
    :show="route('sales.collection.show', $collection)"
    :state="__('core.status.'.$collection->status)"
    module="sales"
    :editable="false"
    :confirmable="false"
    :cancellable="(bool) $can?->can('delete', $collection)"
    :prints="$can?->can('sales.collection.view') ? [[__('core.row_menu.print_receipt'), route('sales.print.receipt', $collection)]] : []"
    :pdf="$can?->can('sales.collection.view') ? route('sales.print.receipt', ['collection' => $collection, 'download' => 1]) : null"
    :share="$can?->can('sales.collection.view') ? ['route' => 'sales.print.receipt', 'param' => 'collection', 'type' => 'sales_collection'] : null" />
