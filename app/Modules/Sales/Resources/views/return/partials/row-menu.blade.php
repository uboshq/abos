{{-- বিক্রয় ফেরতের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: `update` নীতিতে সম্পাদনা/নিশ্চিত, `delete` নীতিতে বাতিল; ফেরতের নিজের ছাপা নেই --}}
@php($can = auth()->user())
<x-ui.paper-row-menu
    :paper="$return"
    :show="route('sales.return.show', $return)"
    :state="__('core.status.'.$return->status)"
    module="sales"
    :edit="route('sales.return.edit', $return)"
    :editable="(bool) $can?->can('update', $return)"
    :confirmable="(bool) $can?->can('update', $return)"
    :cancellable="(bool) $can?->can('delete', $return)" />
