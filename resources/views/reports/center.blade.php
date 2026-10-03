{{--
    রিপোর্ট সেন্টার — সব মডিউলের রিপোর্ট এক পাতায়, মডিউল ধরে (রিপোর্ট সেন্টার ধাপ ১, ২ অক্টোবর ২০২৬)।

    ⓘ পাতাটা একটা মানচিত্র (`x-ui.site-map`, [[MapEngine]]) — অর্থের মানচিত্রের সাথে একই আঁকা। উপরে নিজের প্রিয়
    রিপোর্ট, তারপর মডিউলের কার্ড। ⛔ "বাকি" লাইন কেবল মালিক/অ্যাডমিন দেখেন; বাকিরা কেবল যা খোলে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('report_center.title') }}</x-slot:title>

    <x-ui.site-map :map="$map"
                   :title="__('report_center.title')"
                   :subtitle="__('report_center.subtitle')"
                   :done-label="__('report_center.lines_done')"
                   :reconciled-on="$reconciledOn">

        {{-- ⭐ আমার প্রিয় — রিপোর্টের পাতায় "এই দৃশ্যটা রেখে দিন" দিয়ে রাখা, ছাঁকনিসহ --}}
        <section data-boxed data-favourites
                 class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="flex items-baseline gap-2 border-b border-(--color-border) bg-(--color-section-head) px-3 py-2">
                <span class="font-semibold">{{ __('report_center.favourites') }}</span>
            </h2>

            @if ($favourites === [])
                <p class="px-3 py-2 text-sm text-(--color-ink-muted)">{{ __('report_center.no_favourites') }}</p>
            @else
                <ul class="divide-y divide-(--color-border)">
                    @foreach ($favourites as $favourite)
                        {{-- ⓘ এক লাইনে নাম, পরের লাইনে কোন রিপোর্ট — সরু পর্দায় কিছুই কাটে না --}}
                        <li class="flex flex-wrap items-baseline gap-x-2 gap-y-1 px-3 py-1.5 text-sm">
                            <span class="text-(--color-brand-500)" aria-hidden="true">★</span>
                            <a href="{{ $favourite['url'] }}" data-favourite
                               class="min-w-0 flex-1 truncate text-(--color-link) hover:underline">{{ $favourite['name'] }}</a>
                            <span class="shrink-0 text-2xs text-(--color-ink-muted)">{{ $favourite['report'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </x-ui.site-map>
</x-layouts.app>
