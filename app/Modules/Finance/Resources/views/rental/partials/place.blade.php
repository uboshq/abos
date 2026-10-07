{{--
    কী ভাড়া নেওয়া — আর সেটা নিজের পাতায় নামে।

    ── ⓘ মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────
    *“গোডাউনের সাথে এটার একটা লিংক করা উচিত হেড অফিসের সাথে লিংক করা
    উচিত”*। ⓘ ঘরটা আগে ছিল একটা বাক্য, তাই ভাড়ার সংখ্যাটা কোনো জায়গার
    সাথে মেলানো যেত না।

    ⚠️ পুরনো চুক্তিতে জোড়া নেই — তখন আগের মতোই কেবল লেখা বসে, আর সেটাই
    ঠিক: যা টাইপ করা ছিল সেটাই ঐ চুক্তির সত্যি।
--}}
@php
    $place = $contract->subject_type === null
        ? ['label' => null, 'route' => null]
        : app(\App\Modules\Finance\Services\RentalSubjects::class)
            ->describe($contract->subject_type, $contract->subject_id);
@endphp

<span class="inline-flex flex-col">
    @if ($place['route'] !== null)
        <a href="{{ route($place['route'][0], $place['route'][1] ?? []) }}"
           class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $place['label'] }}</a>
    @elseif ($place['label'] !== null)
        <span>{{ $place['label'] }}</span>
    @else
        <span class="text-(--color-ink-muted)">{{ $contract->subject }}</span>
    @endif

    {{-- ⓘ জোড়া থাকলে ধরনটা ছোট করে নিচে — "হেড অফিস" গুদাম না শাখা,
         নাম দেখে সবসময় বোঝা যায় না। --}}
    @if ($contract->subject_type !== null)
        <a href="{{ route('finance.rental.index', [
                'subject' => $contract->subject_type.':'.$contract->subject_id,
            ]) }}"
           class="text-2xs text-(--color-ink-muted) underline-offset-2 hover:underline">
            {{ __('finance::field.rental_subject_'.$contract->subject_type) }}
        </a>
    @endif
</span>
