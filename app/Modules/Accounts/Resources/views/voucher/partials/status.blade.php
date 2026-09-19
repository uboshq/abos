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

{{--
    ⭐ "খসড়া" আর "অনুমোদনের অপেক্ষায়" আলাদা — ১৯ সেপ্টেম্বর ২০২৬, মালিক:
    *"Status ki Draft thakbe naki pending, ba waiting for approval likha dekhabe?"*
    ⓘ দুইটাই এখনো খাতায় নেই, কিন্তু একটা কেউ রেখে দিয়েছেন, অন্যটা সইয়ের
    জন্য পাঠানো হয়েছে। এক নামে দেখালে পাঠানো কাগজটা ভুলে যাওয়া খসড়ার মতো
    দেখাত, আর কেউ সই করতে যেতেন না।
--}}
@php
    /* ⓘ তালিকা আগে থেকে জানলে (`$awaiting`) সেটাই; নাহলে — সব ভাউচার, একক পাতা —
       কেবল খসড়ার বেলায় একটা প্রশ্ন। */
    $awaiting ??= $voucher->status === \App\Core\Support\DocumentStatus::DRAFT
        && \App\Models\Approval::query()
            ->where('approvable_type', $voucher::class)
            ->where('approvable_id', $voucher->id)
            ->pending()
            ->exists();
@endphp
@if ($awaiting && $voucher->status === \App\Core\Support\DocumentStatus::DRAFT)
    <x-ui.badge tone="pending">{{ __('core.status.awaiting_approval') }}</x-ui.badge>
@else
    <x-ui.badge :tone="$tone">{{ __('core.status.' . $voucher->status) }}</x-ui.badge>
@endif
