{{--
    ⭐ নজরে রাখুন — মালিকের রঙিন নমুনার তালিকা (৩১ অক্টোবর ২০২৬; পরিকল্পনা §৪.৪), [[dashboard.module-v2]] আঁকে।
    ⓘ প্রতিটা সারির সামনে মানের রঙের বিন্দু আর তার হালকা জমিন (লাল এখনই, হলুদ নজর, নীল তথ্য), ডানে অঙ্ক, চাপলে সেই তালিকা বা কাগজ।
    ⓘ জায়গা ঠিক করে ডাকার পাতা: গ্রাফের বোর্ড থাকলে সবার নিচে, নাহলে প্রথম চার্টের পাশে। গড়ন দুই জায়গাতেই এক।

    @param  iterable  $waiting  [[WatchList::of()]]
    @param  string    $edge     মাথার দাগের রং
--}}
<section data-dashboard-exceptions data-boxed class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 pb-3 pt-2"
         style="border-top: 4px solid {{ $edge }}">
    <h2 class="mb-2 flex items-baseline justify-between gap-2 text-sm font-semibold text-(--color-ink)">
        {{ __('home.watch_title') }}
        <small class="font-normal text-(--color-ink-muted)">{{ __('home.watch_hint') }}</small>
    </h2>
    <div class="grid gap-1.5">
        @forelse ($waiting as $item)
            @php $c = \App\Core\Engines\Dashboard\CardColours::watch($item->tone); @endphp
            <a href="{{ $item->href }}" data-watch-row="{{ $item->tone }}"
               class="grid min-w-0 grid-cols-[auto_1fr_auto] items-center gap-2 rounded-(--radius-field) px-2.5 py-1.5 hover:brightness-95"
               style="background: color-mix(in srgb, {{ $c }} 12%, transparent)">
                <i class="size-2.5 rounded-full" style="background: {{ $c }}" aria-hidden="true"></i>
                <span class="min-w-0 text-sm font-semibold">{{ $item->label }}</span>
                <b class="tabular shrink-0 text-sm font-semibold">{{ $item->value }}</b>
            </a>
        @empty
            {{-- ⓘ কিছু বাকি নেই — নমুনার সবুজ সারি ("সব ঠিক"), খালি বাক্স নয় --}}
            @php $c = \App\Core\Engines\Dashboard\CardColours::watch(\App\Core\Engines\Dashboard\Stat::GOOD); @endphp
            <div data-watch-row="good" class="grid min-w-0 grid-cols-[auto_1fr] items-center gap-2 rounded-(--radius-field) px-2.5 py-1.5"
                 style="background: color-mix(in srgb, {{ $c }} 12%, transparent)">
                <i class="size-2.5 rounded-full" style="background: {{ $c }}" aria-hidden="true"></i>
                <span class="min-w-0 text-sm font-semibold">{{ __('home.watch_all_clear') }}</span>
            </div>
        @endforelse
    </div>
</section>
