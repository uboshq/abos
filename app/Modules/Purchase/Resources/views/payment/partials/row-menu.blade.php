{{-- সরবরাহকারীকে পরিশোধের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: `update` নীতিতে সম্পাদনা/নিশ্চিত, `delete` নীতিতে বাতিল; পরিশোধের নিজের ছাপা নেই --}}
@php($can = auth()->user())
<x-ui.paper-row-menu
    :paper="$payment"
    :show="route('purchase.payment.show', $payment)"
    :state="__('core.status.'.$payment->status)"
    module="purchase"
    :edit="route('purchase.payment.edit', $payment)"
    :editable="(bool) $can?->can('update', $payment)"
    :confirmable="(bool) $can?->can('update', $payment)"
    :cancellable="(bool) $can?->can('delete', $payment)" />
