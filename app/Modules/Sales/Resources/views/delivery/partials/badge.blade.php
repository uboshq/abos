{{-- ডেলিভারির ধাপ — এক নজরে। রং [[DeliveryStage::badge()]]-এর পুরো ক্লাস, জোড়া লাগানো নয়। --}}
<span class="inline-flex items-center rounded-full px-2 py-0.5 text-2xs {{ \App\Modules\Sales\Services\DeliveryStage::badge((string) $stage) }}">
    {{ \App\Modules\Sales\Services\DeliveryStage::label((string) $stage) }}
</span>
