{{--
    চুক্তির অবস্থা।

    ⚠️ "নিশ্চিত" এখানে অর্থ বহন করে না। ⓘ জানার জিনিস একটাই: চুক্তিটা
    আজ খাটছে কি না — আর সেটা অবস্থা আর তারিখ, দুইটার উপরেই নির্ভর করে।

    ⛔ তাই চালু চুক্তির মেয়াদ পেরিয়ে গেলে ব্যাজটা "চালু" বলে না;
    ⓘ বলে "মেয়াদ শেষ" — কারণ পাঠকের কাছে সেটাই সত্যি।
--}}
@php
    $over = $contract->status === \App\Core\Support\DocumentStatus::CONFIRMED
        && $contract->daysLeft() < 0;

    [$tone, $label] = match (true) {
        $over => ['danger', __('purchase::status.contract_over')],
        $contract->status === \App\Core\Support\DocumentStatus::CONFIRMED
            => ['success', __('purchase::status.contract_live')],
        $contract->status === \App\Core\Support\DocumentStatus::CLOSED
            => ['muted', __('purchase::status.contract_closed')],
        $contract->status === \App\Core\Support\DocumentStatus::CANCELLED
            => ['danger', __('purchase::status.contract_cancelled')],
        default => ['draft', __('purchase::status.contract_draft')],
    };
@endphp

<x-ui.badge :tone="$tone">{{ $label }}</x-ui.badge>
