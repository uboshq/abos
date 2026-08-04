{{--
    খসড়া, পোস্ট করা, বাতিল — তিনটার পার্থক্য মৌলিক।

    খসড়া কোনো হিসাবে নেই। পোস্ট করাটা লেজারে বসে গেছে। বাতিলটা বসেছিল,
    তারপর বিপরীত এন্ট্রি দিয়ে ফেরানো হয়েছে — মুছে ফেলা হয়নি (নিয়ম ৫)।
--}}
@php
    $tone = match ($voucher->status) {
        \App\Core\Support\DocumentStatus::CONFIRMED => 'success',
        \App\Core\Support\DocumentStatus::CANCELLED => 'danger',
        default => 'warning',
    };
@endphp

<x-ui.badge :tone="$tone">{{ __('core.status.' . $voucher->status) }}</x-ui.badge>
