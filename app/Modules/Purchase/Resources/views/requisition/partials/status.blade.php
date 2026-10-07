{{--
    চাহিদার অবস্থা — সাধারণ ডকুমেন্টের চেয়ে আলাদা শব্দে।

    ⚠️ "নিশ্চিত" এখানে অর্থ বহন করে না। ⓘ জানার জিনিস একটাই: চাওয়াটা
    কোন ধাপে — কেউ দেখেনি, মঞ্জুর হয়েছে, নাকি আদেশ হয়ে গেছে।
--}}
@php
    [$tone, $label] = match ($requisition->status) {
        \App\Core\Support\DocumentStatus::CONFIRMED => ['success', __('purchase::status.requisition_approved')],
        \App\Core\Support\DocumentStatus::CLOSED => ['info', __('purchase::status.requisition_ordered')],
        \App\Core\Support\DocumentStatus::CANCELLED => ['danger', __('purchase::status.requisition_cancelled')],
        default => ['pending', __('purchase::status.requisition_waiting')],
    };
@endphp

<x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge>
