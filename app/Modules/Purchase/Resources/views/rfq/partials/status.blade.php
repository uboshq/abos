{{--
    দরপত্রের অবস্থা।

    ⚠️ "নিশ্চিত" এখানে অর্থ বহন করে না। ⓘ জানার জিনিস একটাই: অনুরোধটা
    পাঠানো হয়েছে কি না, আর সিদ্ধান্ত হয়ে গেছে কি না।
--}}
@php
    [$tone, $label] = match ($rfq->status) {
        \App\Core\Support\DocumentStatus::CONFIRMED => ['pending', __('purchase::status.rfq_waiting')],
        \App\Core\Support\DocumentStatus::CLOSED => ['success', __('purchase::status.rfq_closed')],
        \App\Core\Support\DocumentStatus::CANCELLED => ['danger', __('purchase::status.rfq_cancelled')],
        default => ['draft', __('purchase::status.rfq_draft')],
    };
@endphp

<x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge>
