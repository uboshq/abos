{{--
    গোনার অবস্থা — সাধারণ ডকুমেন্টের চেয়ে আলাদা শব্দে।

    ⚠️ "নিশ্চিত" শব্দটা এখানে অর্থ বহন করে না। ⓘ জানার জিনিস একটাই:
    পার্থক্যটা **মেনে নেওয়া হয়েছে কি না** — অর্থাৎ খাতা বদলেছে কি না।
    ⛔ খসড়া মানে গোনা হয়েছে, কিন্তু খাতা এখনো আগের কথাই বলছে।
--}}
@php
    [$tone, $label] = match ($count->status) {
        \App\Core\Support\DocumentStatus::CONFIRMED => ['success', __('inventory::status.count_settled')],
        \App\Core\Support\DocumentStatus::CANCELLED => ['danger', __('inventory::status.cancelled')],
        default => ['pending', __('inventory::status.count_waiting')],
    };
@endphp

<x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge>
