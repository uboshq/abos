{{--
    ⭐ দায়িত্বে কে, আর বিল কে করলেন — দুইটা আলাদা মানুষ হতে পারেন (মালিক, ২৩ নভেম্বর ২০২৬)।

    বিক্রয়ের কৃতিত্ব দায়িত্বে থাকা মানুষের, বিল যিনি করলেন তাঁর নয়। কাগজে
    বসানো ছবিটাই (`sales_person_id`) আগে; ফাঁকা থাকলে আজকের নিয়মে কে পড়েন
    ([[SalesResponsibility::explain()]]), পাশে "(এখনকার হিসাবে)" — কারণ সেটা
    কাগজের দিনের ছবি নয়।

    বিল ও ফেরত দুই পাতাতেই — `$paper`।
--}}
@php
    $responsibleName = $paper->salesPerson?->name;
    $responsibleNow = false;

    if ($responsibleName === null && $paper->customer_id !== null) {
        $explained = app(\App\Modules\Sales\Services\SalesResponsibility::class)
            ->explain((int) $paper->customer_id, $paper->trx_date);

        if (($explained['user_id'] ?? null) !== null) {
            // ⓘ এক জনের নাম, তালিকা নয় — আইডিটা [[SalesResponsibility]] এই কোম্পানির বাঁধন থেকেই দেয়
            $responsibleName = \App\Models\User::find((int) $explained['user_id'], ['id', 'name'])?->name;
            $responsibleNow = $responsibleName !== null;
        }
    }
@endphp

<div data-responsible>
    <dt class="text-(--color-ink-muted)">{{ __('sales::field.responsible') }}</dt>
    <dd class="mt-0.5">
        @if ($responsibleName !== null)
            {{ $responsibleName }}
            @if ($responsibleNow)
                <span class="text-(--color-ink-muted)">{{ __('sales::field.responsible_as_of_now') }}</span>
            @endif
        @else
            <span class="text-(--color-ink-muted)">{{ __('sales::field.no_responsible') }}</span>
        @endif
    </dd>
</div>

<div data-billed-by>
    <dt class="text-(--color-ink-muted)">{{ __('sales::field.billed_by') }}</dt>
    <dd class="mt-0.5">{{ $paper->creator?->name ?? '-' }}</dd>
</div>
