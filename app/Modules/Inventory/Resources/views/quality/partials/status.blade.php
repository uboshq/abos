{{--
    পরিদর্শনের রায়।

    ⚠️ কোয়ারেন্টাইন হলুদ, বাতিল লাল — দুইটা আলাদা রং, কারণ দুইটা আলাদা
    ভবিষ্যৎ। ⓘ কোয়ারেন্টাইনের মাল যাচাইয়ের পর ফিরতে পারে, বাতিলের পারে
    না। ⛔ এক রঙে দেখালে কেউ কোয়ারেন্টাইনের মালকেও হারানো ধরে নিত।
--}}
@php
    $result = $inspection->status;

    [$tone, $label] = match ($result) {
        \App\Modules\Inventory\Models\QualityInspection::APPROVED
            => ['success', __('inventory::status.qc_approved')],
        \App\Modules\Inventory\Models\QualityInspection::QUARANTINE
            => ['pending', __('inventory::status.qc_quarantine')],
        \App\Modules\Inventory\Models\QualityInspection::REJECTED
            => ['danger', __('inventory::status.qc_rejected')],
        default => ['draft', __('inventory::status.qc_pending')],
    };
@endphp

<x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge>
