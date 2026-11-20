{{--
    ⭐ অবশিষ্ট সীমা — তালিকার ঘর (মালিক, ১৮ নভেম্বর ২০২৬; [[SupplierCreditWall]])।
    ⓘ সীমা নেই = "—" (দেয়াল নেই); জায়গা শেষ = লাল (নতুন বিল/মাল গ্রহণ নিশ্চিত হবে না); ৯০% ভরা = হলুদ।
    ⓘ অঙ্ক সারির `credit_used` থেকে — তালিকা একবারে আনে, সারি প্রতি কোয়েরি নয়।
--}}
@php
    $wall = app(\App\Modules\Supplier\Services\SupplierCreditWall::class);
    $room = $wall->room($supplier);
    $state = $wall->state($supplier);
@endphp
@if ($room === null)
    <span class="text-(--color-ink-muted)">—</span>
@else
    <span @class([
            'num rounded-(--radius-field) px-1',
            'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => $state === 'over',
            'bg-(--color-badge-pending-bg) text-(--color-badge-pending-ink)' => $state === 'near',
        ])
          data-credit-state="{{ $state }}"
          title="{{ $state === 'over' ? __('supplier::credit_request.over') : ($state === 'near' ? __('supplier::credit_request.near') : '') }}">{{ \App\Core\Support\Money::format($room) }}</span>
@endif
