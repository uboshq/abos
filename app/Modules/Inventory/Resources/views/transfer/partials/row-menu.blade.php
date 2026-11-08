{{--
    স্টক ট্রান্সফারের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: খসড়ায় `update` নীতিতে সম্পাদনা আর "রওনা
    করান", পথে থাকলে `inventory.transfer.receive`-এ "মাল পৌঁছেছে", `delete` নীতিতে বাতিল (পৌঁছানোর আগে)। ⓘ রওনা আর
    পৌঁছানো দুইটাই পাতায়, বোতামের পাশে — মজুদ নড়ে, তাই তালিকা থেকে এক চাপে নয়।
--}}
@php($can = auth()->user())
@php($S = \App\Core\Support\DocumentStatus::class)
@php($draft = $transfer->status === $S::DRAFT)
@php($onTheWay = $transfer->status === $S::CONFIRMED)
@php($arrived = $transfer->status === $S::CLOSED)
@php($show = route('inventory.transfer.show', $transfer))
<x-ui.paper-row-menu
    :paper="$transfer"
    :show="$show"
    :state="__('core.row_menu.transfer_state.'.$transfer->status)"
    module="inventory"
    :edit="route('inventory.transfer.edit', $transfer)"
    :editable="$draft && (bool) $can?->can('update', $transfer)"
    :cancellable="! $arrived && (bool) $can?->can('delete', $transfer)"
    :prints="$can?->can('inventory.transfer.view') ? [[__('core.row_menu.print'), route('inventory.transfer.print', $transfer)]] : []"
    :pdf="$can?->can('inventory.transfer.view') ? route('inventory.transfer.print', ['transfer' => $transfer, 'download' => 1]) : null"
    :work="array_values(array_filter([
        $draft && $can?->can('update', $transfer) ? ['label' => __('inventory::action.dispatch'), 'icon' => 'challan', 'url' => $show.'#confirm', 'peek' => false, 'tone' => 'success'] : null,
        $onTheWay && $can?->can('inventory.transfer.receive') ? ['label' => __('inventory::action.receive'), 'icon' => 'check-circle', 'url' => $show.'#confirm', 'peek' => false, 'tone' => 'success'] : null,
    ]))" />
