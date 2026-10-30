{{--
    ⭐ টাকার ফর্মের দুই কলাম — আদায়, রসিদ আর পরিশোধ ভাউচার, সরবরাহকারী-পরিশোধ একই ছাঁচে (মালিক আর fe, ২৪ অক্টোবর ২০২৬; আদায়ের ছবি ১৩)।

    <x-layouts.app :menu="$menu" :two-column-form="true">          ← ১৫৩৬-এর নিচে মেনু রেলে বা ভাসমান ([[sidebar-state.js]])
        <x-ui.money-form>
            <x-slot:strip> ১৩৬৬-এর নিচে সারাংশের এক লাইন (ঐচ্ছিক) </x-slot:strip>
            বাঁ কলাম — ঘর আর খোলা বিলের টেবিল (মূল slot)
            <x-slot:right> ডান কলাম, এক ব্লকে — মাথার অঙ্ক, সারাংশ, খাতা, নিয়ন্ত্রণ </x-slot:right>
            <x-slot:bar> নিচের বোতামের পট্টি (ঐচ্ছিক) </x-slot:bar>
        </x-ui.money-form>
    </x-layouts.app>

    ⓘ দুই কলাম ১৩৬৬ থেকে (মালিকের ল্যাপটপ; মেনু রেলে থাকলে বিলের টেবিল ঠিক ধরে, আসল ব্রাউজারে মাপা), তার নিচে এক কলাম আর উপরে
      আটকানো সারাংশ-পট্টি — কোনো পর্দায় সারাংশ চোখের বাইরে যায় না। ডান কলাম আটকানো, পর্দার চেয়ে লম্বা হলে নিজের ভিতরে সরে।
    ⛔ `two-column-form` layout-এ দিতে হয় — এই ছাঁচ একা মেনু সরায় না (layout আগে আঁকা হয়, তাই পাতাই বলে)।
--}}
@props(['strip' => null, 'right' => null, 'bar' => null])

<div data-money-form {{ $attributes->class('flex flex-col gap-4') }}>
    <div class="grid gap-4 min-[1366px]:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]">
        @if ($strip !== null && $strip->isNotEmpty())
            <div data-summary-strip role="status"
                 class="sticky top-(--spacing-header) z-10 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-(--radius-card) border
                        border-(--color-border) bg-(--color-surface-card) px-4 py-2 text-sm min-[1366px]:hidden">
                {{ $strip }}
            </div>
        @endif

        <div data-left-column class="flex min-w-0 flex-col gap-4">
            {{ $slot }}
        </div>

        {{-- ⓘ পর্দার চেয়ে লম্বা হলে নিজের ভিতরে সরে — নইলে আটকানো কলামের নিচের নিয়ন্ত্রণ পাতার শেষ না আসা পর্যন্ত দেখা যেত না --}}
        <div data-right-column
             class="flex min-w-0 flex-col gap-4 min-[1366px]:sticky min-[1366px]:top-[calc(var(--spacing-header)+1rem)]
                    min-[1366px]:max-h-[calc(100dvh-var(--spacing-header)-var(--spacing-status-bar)-2rem)] min-[1366px]:self-start
                    min-[1366px]:overflow-y-auto">
            {{ $right }}
        </div>
    </div>

    {{ $bar }}
</div>
