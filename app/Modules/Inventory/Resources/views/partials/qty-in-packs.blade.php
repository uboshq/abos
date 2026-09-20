{{--
    একটা পরিমাণ — উপরে ভিত্তি এককের সংখ্যা, নিচে প্যাকে ভাঙা।

    ⭐ মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬: কার্টনের মাপ কোম্পানি যখন যেমন
    খুশি বানায়, আর তিনি মনে মনে **কার্টনেই** গোনেন। ⓘ "১৯৩ পিস" পড়ে
    গুদামে গিয়ে মেলানো যায় না; "৮ কার্টন ১ পিস" পড়ে যায়।

    ⚠️ ভিত্তি এককের সংখ্যাটা **থাকেই** — ওটাই সব হিসাবের ভিত্তি, আর
    ওটা সরিয়ে দিলে যোগফলের সাথে মেলানো যেত না। ⓘ ভাঙানিটা তার নিচে,
    ছোট হরফে, পড়ার সুবিধার জন্য।

    ⓘ প্যাক না থাকলে (বা সিঁড়িতে একটাই ধাপ) নিচের লাইনটা আসে না —
    "১৯৩ পিস" আর "১৯৩ পিস" দুইবার লেখা কাউকে কিছু বলে না।
--}}
@php
    $steps = count($ladder) > 1
        ? \App\Modules\Inventory\Support\PackBreakdown::split((string) $qty, $ladder)
        : [];

    // এক ধাপেই শেষ মানে ভাঙার কিছু ছিল না — নিচের লাইনটা বাদ
    $worth = count($steps) > 1 || (count($steps) === 1 && $steps[0]['unit']->id !== ($ladder[array_key_last($ladder)]['unit']->id ?? null));
@endphp

<x-ui.amount :value="$qty" :href="$href ?? null" />

@if ($worth)
    <div class="num text-2xs text-(--color-ink-muted)">
        @foreach ($steps as $step)
            {{-- ⓘ কোড নয়, নাম — পাশের "একক" ঘরটাও নামই দেখায়, আর
                 বাংলা পর্দায় "১০ ব্যাগ" পড়া যায়, "10 BAG" নয় --}}
            {{ $step['qty'] }} {{ $step['unit']->name() }}@if (! $loop->last) <span aria-hidden="true">·</span> @endif
        @endforeach
    </div>
@endif
