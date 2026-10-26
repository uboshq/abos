@props([
    /*
     * ⓘ টাকা কথায় — হোস্টের Alpine নাম (যেমন `sumWords`), লেখাটা সার্ভারের [[AmountInWords]] থেকে আসা ([[VoucherController::simulate()]]);
     * না দিলে লাইনটাই আঁকা হয় না (সরবরাহকারী-পরিশোধের নকশায় নেই)।
     */
    'words' => null,
])
{{--
    ⭐ হিসাবের সারাংশ — টাকা নেওয়া আর দেওয়ার তিন পর্দায় একটাই কার্ড (fe, ২৪ অক্টোবর ২০২৬, মালিকের প্রশ্ন: "হিসাবের সারাংশ ভাউচারে
    এখন নেই কেন?"; নকশা: টাকা-নেওয়া-দেওয়া-চূড়ান্ত-নকশা-১০০-সংশোধিত-২২-অক্টোবর.pdf, পাতা ২ আর ৪, ডানের কলামের মাথায়)।

    ⓘ কার্ড, শিরোনাম আর দুই কলামের সারি এখানে; সারিগুলো (`<dt>`/`<dd>`, হোস্টের Alpine নামে) হোস্ট দেয় — সরবরাহকারী-পরিশোধে বিলে
    প্রয়োগ · AIT · VDS · নিট · চার্জ, রসিদ-পরিশোধ ভাউচারে টাকা · চার্জ · নিট। মিলের আর সতর্কতার লাইন `after`-এ।
    ⓘ চিহ্ন (`data-pay-summary`, `data-voucher-summary`) হোস্ট দেয় — দাবিগুলো সেটাই খোঁজে।
--}}
<section data-boxed {{ $attributes->merge(['class' => 'rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4']) }}>
    <h2 class="mb-3 font-semibold">{{ __('money.screen.summary') }}</h2>
    <dl class="grid grid-cols-[1fr_auto] gap-y-2 text-sm">
        {{ $slot }}
    </dl>
    @if ($words)
        {{-- ⓘ টাকা কথায় — নকশার রসিদের মতো, সার্ভারের লেখা; প্রিভিউর আগে ফাঁকা --}}
        <p class="mt-2 text-xs text-(--color-ink-muted)" data-sum-words x-show="{{ $words }} !== ''" x-cloak x-text="{{ $words }}"></p>
    @endif
    {{ $after ?? '' }}
</section>
