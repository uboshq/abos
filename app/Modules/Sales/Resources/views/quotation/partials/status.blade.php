{{--
    উদ্ধৃতির অবস্থা — তালিকা আর পাতা দুই জায়গায় একই ব্যাজ।

    ⚠️ সারির `status` নয়, `effectiveStatus()`: মেয়াদ পেরোনো দর সারিতে
    এখনো "পাঠানো" বা "গৃহীত" লেখা থাকে ([[SalesQuotation::isExpired()]])।
    সারিটা দেখালে ডিলারের পুরনো দর পর্দায় "চালু" দেখাত।

    ⓘ `x-ui.badge` — রং ইনলাইন স্টাইলে, তাই বান্ডেলে নতুন ক্লাস লাগে না।
--}}
@php
    $state = $quotation->effectiveStatus();

    $tone = match ($state) {
        \App\Modules\Sales\Models\SalesQuotation::ACCEPTED,
        \App\Modules\Sales\Models\SalesQuotation::CONVERTED => 'success',
        \App\Modules\Sales\Models\SalesQuotation::SUBMITTED => 'pending',
        \App\Modules\Sales\Models\SalesQuotation::APPROVED,
        \App\Modules\Sales\Models\SalesQuotation::SENT => 'info',
        \App\Modules\Sales\Models\SalesQuotation::REJECTED,
        \App\Modules\Sales\Models\SalesQuotation::EXPIRED,
        \App\Modules\Sales\Models\SalesQuotation::CANCELLED => 'danger',
        default => 'draft',
    };
@endphp

<x-ui.badge :tone="$tone">{{ __('sales::quotation.status.'.$state) }}</x-ui.badge>
