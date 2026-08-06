{{--
    স্থানান্তরের অবস্থা — সাধারণ ডকুমেন্টের চেয়ে আলাদা শব্দে।

    "নিশ্চিত" শব্দটা এখানে অর্থ বহন করে না: মালটা রাস্তায় আছে, আর সেটাই
    জানার জিনিস। তাই confirmed = "রওনা দিয়েছে", closed = "পৌঁছেছে"।
--}}
@php
    $status = $transfer->status;

    [$tone, $label] = match ($status) {
        \App\Core\Support\DocumentStatus::CONFIRMED => ['pending', __('inventory::status.on_the_way')],
        \App\Core\Support\DocumentStatus::CLOSED => ['success', __('inventory::status.arrived')],
        \App\Core\Support\DocumentStatus::CANCELLED => ['danger', __('inventory::status.cancelled')],
        default => ['draft', __('inventory::status.draft')],
    };
@endphp

<x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge>
