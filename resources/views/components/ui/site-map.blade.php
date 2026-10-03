@props([
    /** @var \App\Core\Engines\Map\DrawnMap — [[MapEngine::draw()]]-এর ফল */
    'map',
    'title' => '',
    'subtitle' => null,
    /** অগ্রগতির সংখ্যার পাশের লেখা — না দিলে সাধারণটা */
    'doneLabel' => null,
    /** শেষ কবে হাতে মিলিয়ে দেখা (Y-m-d) — না দিলে লেখাটাই নেই */
    'reconciledOn' => null,
])

{{--
    মানচিত্র — একটাই আঁকা, সব মডিউলের জন্য (রিপোর্ট সেন্টার ধাপ ১, ২ অক্টোবর ২০২৬)।

    ⭐ মালিক, ১ অক্টোবর: "ফিন্যান্স মানচিত্রের মতো সব জায়গায়"। ⓘ গড়নটা অর্থের মানচিত্রের পাতা থেকে হুবহু তুলে আনা
    (`finance::plan.index`) — সে এখন এটাই ডাকে, আর রিপোর্ট সেন্টারও। কী আঁকা হবে তা [[MapEngine]] ঠিক করে; এখানে কেবল
    চেহারা।

    ── কেন মেনুতে দুইশো মৃত সারি নয় ───────────────────────────────────
    একটা মানচিত্র একটা সৎ তালিকা: যেটা হয়েছে তার লিংক আসল পর্দা খোলে; যেটা হয়নি তার পাশে "বাকি" (○) — আর ⛔ সেই
    লাইন কেবল মালিক/অ্যাডমিন দেখেন। কোনো বোতাম মিথ্যা বলে না।
--}}
<div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
            bg-(--color-surface-card)">
    <form method="GET" class="contents">
        <x-ui.toolbar :title="$title"
                      :subtitle="$subtitle"
                      :search="false"
                      :density="false"
                      :export="false"
                      :share="false" />
    </form>

    {{-- কত দূর এল — লাইন গুনে, বিভাগ গুনে নয়। ⛔ কেবল যিনি "বাকি" লাইনও দেখেন: বাকিদের কাছে সংখ্যাটা এমন
         লাইন গুনত যা তাঁর চোখেই পড়ে না। --}}
    @if ($map->seesPending)
        @php
            $pct = $map->percent();
        @endphp

        <section class="p-4" data-map-tally>
            <div class="flex flex-wrap items-baseline gap-3">
                <span class="num text-2xl font-bold">{{ $map->done }}</span>
                <span class="text-(--color-ink-muted)">/</span>
                <span class="num text-lg">{{ $map->total }}</span>
                <span class="text-sm text-(--color-ink-muted)">{{ $doneLabel ?? __('map.lines_done') }}</span>

                <span class="flex-1"></span>

                <span class="num text-lg font-semibold">{{ $pct }}%</span>

                {{-- ⭐ শেষ কবে হাতে মিলিয়ে দেখা — ছয় মাসের পুরনো তারিখ নিজেই বলে সংখ্যাটা কতটা বিশ্বাস করা যায় --}}
                @if ($reconciledOn)
                    <span class="text-2xs text-(--color-ink-muted)">
                        {{ __('map.reconciled_on', ['date' => \App\Core\Support\DateFormat::format($reconciledOn)]) }}
                    </span>
                @endif
            </div>

            <div class="mt-2 h-2 overflow-hidden rounded-full bg-(--color-surface-sunken)">
                <div class="h-full bg-(--color-brand-600)" style="width: {{ $pct }}%"></div>
            </div>
        </section>
    @endif
</div>

{{ $slot }}

@if ($map->isEmpty())
    <p class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm
              text-(--color-ink-muted)" data-map-empty>
        {{ __('map.empty') }}
    </p>
@endif

<div class="grid gap-3 lg:grid-cols-2">
    @foreach ($map->sections as $section)
        <section data-boxed data-map-section="{{ $section['code'] ?? $section['no'] ?? $loop->index }}"
                 class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="flex items-baseline gap-2 border-b border-(--color-border)
                       bg-(--color-section-head) px-3 py-2">
                @if ($section['no'] !== null)
                    <span class="num text-2xs text-(--color-ink-muted)">§{{ $section['no'] }}</span>
                @endif
                <span class="min-w-0 font-semibold">{{ $section['title'] }}</span>

                <span class="flex-1"></span>

                {{-- ⓘ ভাগের ভেতরে কত হয়েছে — কেবল "বাকি" দেখা মানুষের জন্য; বাকিদের কাছে সব লাইনই তৈরি --}}
                @if ($map->seesPending)
                    <span @class([
                        'num rounded-(--radius-field) px-2 py-0.5 text-2xs',
                        'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                            => $section['done'] === $section['total'],
                        'bg-(--color-surface-sunken) text-(--color-ink-muted)'
                            => $section['done'] !== $section['total'],
                    ])>{{ $section['done'] }}/{{ $section['total'] }}</span>
                @endif
            </h2>

            <ul class="divide-y divide-(--color-border)">
                @foreach ($section['items'] as $item)
                    {{-- ⓘ সরু পর্দায় নাম আর টীকা দুই লাইনে — মালিকের ফোন ডান দিক কাটে --}}
                    <li class="flex flex-wrap items-baseline gap-x-2 gap-y-1 px-3 py-1.5 text-sm">
                        {{-- হয়েছে হলে লিংক, বাকি হলে সাদামাটা লেখা — ⛔ যা "হয়েছে" বলা হয় তা ক্লিকে সত্যিই খোলে --}}
                        @if ($item['url'])
                            <span class="text-(--color-success)" aria-hidden="true">✓</span>
                            <a href="{{ $item['url'] }}" data-map-done
                               class="min-w-0 flex-1 truncate text-(--color-link) hover:underline">
                                {{ $item['label'] }}
                            </a>
                        @else
                            <span class="text-(--color-ink-disabled)" aria-hidden="true">○</span>
                            <span class="min-w-0 flex-1 truncate text-(--color-ink-muted)" data-map-pending
                                  title="{{ __('map.pending') }}">
                                {{ $item['label'] }}<span class="sr-only"> — {{ __('map.pending') }}</span>
                            </span>
                        @endif

                        @if ($item['note'])
                            <span class="shrink-0 text-2xs text-(--color-ink-muted)">{{ $item['note'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
