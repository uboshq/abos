{{--
    ডেলিভারি — এক কথায়, তালিকার ঘরে (মালিকের পরিকল্পনা, ধাপ ৪, ২৮ সেপ্টেম্বর ২০২৬)।
    চাই: $summary — [[DeliveryStage::summary()]]-এর ফল, বা null ("—")।
    ⓘ `data-delivery` পরীক্ষার জন্য — লেখা ভাষা ধরে বদলায়, চিহ্নটা নয়।
--}}
@if ($summary === null)
    <span class="text-(--color-ink-muted)">—</span>
@else
    <span data-delivery="{{ $summary }}"
          class="inline-flex items-center rounded-full px-2 py-0.5 text-2xs {{ \App\Modules\Sales\Services\DeliveryStage::summaryBadge($summary) }}">
        {{ __('sales::delivery.summary.'.$summary) }}
    </span>
@endif
