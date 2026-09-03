{{--
    যেকোনো মডিউলের ড্যাশবোর্ড — একটাই পর্দা।

    ── কেন একটাই ব্লেড, বারোটা নয় ───────────────────────────────────────
    বারোটা ব্লেড মানে বারো রকম ফাঁক, বারো রকম শিরোনামের মাপ, আর ছয় মাস
    পরে এমন কয়েকটা পর্দা যেগুলো এক পরিবারের মনে হয় না। কেউ ইচ্ছে করে
    সেটা করত না — তবু হত, কারণ প্রতিটা নতুন পর্দা আগেরটা কপি করে শুরু
    হয় আর একটু করে সরে যায়।

    এখানে মডিউল কেবল বলে **কোন সংখ্যাগুলো**; সাজানোটা কোরের।

    ── ক্রমটা বাঁধা: সংখ্যা → পট → তালিকা ───────────────────────────────
    পর্দায় ঢুকে প্রথম প্রশ্ন "আজ কেমন আছি" (সংখ্যা), তারপর "কেন এমন"
    (ধারা ও ভাগ), শেষে "এখন কী করব" (তালিকা)। উল্টো সাজালে পাতাটা
    কাজের তালিকা দিয়ে শুরু হত, অথচ সেটা তৃতীয় প্রশ্ন।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $dashboard->title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$dashboard->title" :subtitle="$dashboard->subtitle" />
    </x-slot:header>

    {{-- ── দ্রুত কাজ ───────────────────────────────────────────────
         সবার উপরে: মানুষ প্রায়ই ড্যাশবোর্ডে আসেন কিছু **করতে**, আর
         সংখ্যাগুলো পেরিয়ে নামতে হলে পরেরবার তিনি সোজা মেনুতে যান।
         যে কাজের চাবি নেই সেই টাইল ইঞ্জিনেই বাদ পড়ে। --}}
    @if ($dashboard->tiles !== [])
        <div class="mb-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($dashboard->tiles as $tile)
                <a href="{{ $tile->href }}" data-boxed
                   class="flex items-center gap-3 rounded-(--radius-card) border border-(--color-brand-500)
                          bg-(--color-surface-hover) px-4 py-3 text-sm font-semibold text-(--color-brand-700)">
                    @if ($tile->icon)
                        <x-ui.icon :name="$tile->icon" :size="18" />
                    @endif
                    {{ $tile->label }}
                </a>
            @endforeach
        </div>
    @endif

    {{-- ── সংখ্যা ─────────────────────────────────────────────────── --}}
    @if ($dashboard->stats !== [])
        <div class="mb-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($dashboard->stats as $stat)
                <{{ $stat->href ? 'a' : 'div' }}
                    @if ($stat->href) href="{{ $stat->href }}" @endif
                    data-boxed
                    class="block rounded-(--radius-card) border border-(--color-border)
                           bg-(--color-surface-card) px-4 py-3">
                    <div class="text-xs text-(--color-ink-muted)">{{ $stat->label }}</div>
                    <div @class([
                        'mt-1 text-2xl font-semibold tabular-nums',
                        'text-(--color-badge-success-ink)' => $stat->tone === \App\Core\Engines\Dashboard\Stat::GOOD,
                        'text-(--color-badge-warning-ink)' => $stat->tone === \App\Core\Engines\Dashboard\Stat::WARN,
                        'text-(--color-badge-danger-ink)' => $stat->tone === \App\Core\Engines\Dashboard\Stat::BAD,
                    ])>{{ $stat->value ?? '—' }}</div>

                    @php $change = $stat->change(); @endphp
                    @if ($change !== null)
                        {{--
                            তুলনাটা সংখ্যার ঠিক নিচে, ব্যাখ্যার উপরে।

                            ⚠️ **উপরের তীর সবসময় সবুজ নয়।** বিক্রয় বাড়া
                            ভালো, বকেয়া বাড়া খারাপ — তাই রংটা আসে
                            সংখ্যাটার নিজের `tone` থেকে, দিক থেকে নয়।
                            দিক দেখে রং দিলে "বকেয়া ▲২১%" সবুজ হয়ে
                            যেত, আর পর্দাটা খারাপ খবরকে ভালো দেখাত।
                        --}}
                        <div @class([
                            'mt-1 text-2xs',
                            'text-(--color-badge-danger-ink)' => $stat->tone === \App\Core\Engines\Dashboard\Stat::BAD,
                            'text-(--color-badge-warning-ink)' => $stat->tone === \App\Core\Engines\Dashboard\Stat::WARN,
                            'text-(--color-badge-success-ink)' => $stat->tone !== \App\Core\Engines\Dashboard\Stat::BAD
                                && $stat->tone !== \App\Core\Engines\Dashboard\Stat::WARN && $change >= 0,
                            'text-(--color-ink-muted)' => $stat->tone === \App\Core\Engines\Dashboard\Stat::NEUTRAL
                                && $change < 0,
                        ])>
                            {{ $change >= 0 ? '▲' : '▼' }} {{ number_format(abs($change), 1) }}%
                            @if ($stat->previousLabel) <span class="text-(--color-ink-muted)">{{ $stat->previousLabel }}</span> @endif
                        </div>
                    @endif

                    {{--
                        ব্যাখ্যাটা বাধ্যতামূলক ([[Stat]]-এর কনস্ট্রাক্টরে),
                        তাই এখানে শর্ত নেই — শর্ত থাকলে কেউ ভাবত ওটা
                        ঐচ্ছিক, আর একদিন ব্যাখ্যাহীন একটা সংখ্যা বসে যেত।
                    --}}
                    <div class="mt-1 text-2xs text-(--color-ink-muted)">{{ $stat->hint }}</div>
                </{{ $stat->href ? 'a' : 'div' }}>
            @endforeach
        </div>
    @endif

    {{-- ── পট: ধারা ও ভাগ ─────────────────────────────────────────── --}}
    @if ($dashboard->panels !== [])
        <div class="mb-3 grid gap-3 lg:grid-cols-3">
            @foreach ($dashboard->panels as $panel)
                @if ($panel instanceof \App\Core\Engines\Dashboard\Series)
                    @php $peak = $panel->peak(); @endphp
                    <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                                           bg-(--color-surface-card) lg:col-span-2">
                        <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold
                                   text-(--color-ink-muted)">{{ $panel->label }}</h2>

                        <div class="flex items-end gap-4 px-4 pt-6 pb-2" style="height: var(--spacing-chart)">
                            @foreach ($panel->points as $point)
                                <div class="flex flex-1 items-end justify-center gap-1" style="height:100%">
                                    <div class="w-1/2 rounded-t bg-(--color-brand-500)"
                                         style="height:{{ max(2, (int) round((float) $point['first'] / $peak * 100)) }}%"
                                         title="{{ $panel->firstLabel }}: {{ $point['first'] }}"></div>
                                    <div class="w-1/2 rounded-t bg-(--color-brand-700)/25"
                                         style="height:{{ max(2, (int) round((float) $point['second'] / $peak * 100)) }}%"
                                         title="{{ $panel->secondLabel }}: {{ $point['second'] }}"></div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex gap-4 px-4 pb-2">
                            @foreach ($panel->points as $point)
                                <div class="flex-1 text-center text-2xs text-(--color-ink-muted)">{{ $point['label'] }}</div>
                            @endforeach
                        </div>

                        <div class="flex items-center gap-4 border-t border-(--color-border) px-4 py-2
                                    text-2xs text-(--color-ink-muted)">
                            <span class="flex items-center gap-1.5">
                                <span class="inline-block size-2.5 rounded-sm bg-(--color-brand-500)"></span>
                                {{ $panel->firstLabel }}
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span class="inline-block size-2.5 rounded-sm bg-(--color-brand-700)/25"></span>
                                {{ $panel->secondLabel }}
                            </span>
                        </div>
                    </div>
                @else
                    @php $total = $panel->total(); @endphp
                    <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                                           bg-(--color-surface-card)">
                        <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold
                                   text-(--color-ink-muted)">{{ $panel->label }}</h2>

                        <div class="space-y-3 p-4">
                            @foreach ($panel->parts as $part)
                                <div>
                                    <div class="mb-1 flex items-baseline justify-between text-xs">
                                        <span class="text-(--color-ink-muted)">{{ $part['label'] }}</span>
                                        <span class="font-semibold tabular-nums">{{ $part['value'] }}</span>
                                    </div>
                                    <div class="h-2 overflow-hidden rounded-full bg-(--color-surface-hover)">
                                        <div class="h-full bg-(--color-brand-500)"
                                             style="width:{{ min(100, max(0, (int) round((float) $part['value'] / $total * 100))) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <p class="border-t border-(--color-border) px-4 py-2 text-2xs text-(--color-ink-muted)">
                            {{ $panel->hint }}
                        </p>
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    {{-- ── করণীয় ──────────────────────────────────────────────────
         মডিউলের নিজের উইজেট থেকেই আসে, তাই হোম পর্দা আর এই পর্দা
         কোনোদিন দুই রকম বলবে না। --}}
    @if ($dashboard->reminders !== [])
        <div data-boxed class="mb-3 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                               bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('core.dashboard.needs_doing') }}
            </h2>
            <ul>
                @foreach ($dashboard->reminders as $item)
                    <li class="border-b border-(--color-border) last:border-0">
                        <a href="{{ $item->href }}" class="flex items-center gap-3 px-4 py-2.5 text-sm">
                            <span @class([
                                'min-w-8 rounded-full px-2 py-0.5 text-center text-xs font-bold tabular-nums',
                                'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => $item->tone === 'warn',
                                'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => $item->tone === 'bad',
                                'bg-(--color-surface-hover) text-(--color-ink-muted)' => ! in_array($item->tone, ['warn', 'bad'], true),
                            ])>{{ $item->value }}</span>
                            <span>{{ $item->label }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ── তালিকা ─────────────────────────────────────────────────── --}}
    @if ($dashboard->listings !== [])
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($dashboard->listings as $listing)
                <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                                       bg-(--color-surface-card)">
                    <h2 class="flex items-baseline gap-2 border-b border-(--color-border) px-4 py-3
                               text-xs font-semibold text-(--color-ink-muted)">
                        {{ $listing->label }}
                        @if ($listing->href)
                            <a href="{{ $listing->href }}"
                               class="ms-auto font-normal text-(--color-link)">{{ __('core.action.see_all') }}</a>
                        @endif
                    </h2>
                    <x-ui.table :empty="$listing->empty" :rows="$listing->rows" :columns="$listing->columns" />
                </div>
            @endforeach
        </div>
    @endif
</x-layouts.app>
