{{--
    যেকোনো মডিউলের ড্যাশবোর্ড — নতুন রূপ (মালিকের অনুমোদিত নকশা, ২ অক্টোবর ২০২৬)।

    ⓘ `config('abos.dashboards_v2')` চালু হলে এটা, নাহলে পুরনো `dashboard.module` হুবহু।
    ⓘ মডিউল আগের মতোই কেবল বলে **কোন সংখ্যাগুলো** ([[DashboardDefinition]]); সাজানোটা এখানে:
    কাজের বোতাম → সূচকের কার্ড (অবস্থার চিহ্ন + তুলনা) → চার্ট → তালিকা → ব্যতিক্রম ও সতর্কতা।
    ⚠️ অবস্থার চিহ্ন রঙে একা নয় — আইকন আর লেখা দুইটাই থাকে।
--}}
@php
    $chips = [
        \App\Core\Engines\Dashboard\Stat::GOOD => ['bg-(--color-badge-success-bg) text-(--color-badge-success-ink)', 'check_circle', __('home.status_good')],
        \App\Core\Engines\Dashboard\Stat::WARN => ['bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)', 'bell', __('home.severity_warn')],
        \App\Core\Engines\Dashboard\Stat::BAD => ['bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)', 'bell', __('home.status_bad')],
        \App\Core\Engines\Dashboard\Stat::NEUTRAL => ['bg-(--color-badge-info-bg) text-(--color-badge-info-ink)', 'clock', __('home.status_info')],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $dashboard->title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$dashboard->title" :subtitle="$dashboard->subtitle" />
    </x-slot:header>

    <div data-dashboard-v2 class="flex flex-col gap-4">

        {{-- ── কাজের বোতাম ─────────────────────────────────────────── --}}
        @if ($dashboard->tiles !== [])
            <div class="flex flex-wrap gap-2">
                @foreach ($dashboard->tiles as $tile)
                    <a href="{{ $tile->href }}" data-tile
                       class="inline-flex h-11 items-center gap-2 rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-card) px-4 text-sm font-semibold text-(--color-brand-700)
                              hover:bg-(--color-surface-hover)">
                        @if ($tile->icon)
                            <x-ui.icon :name="$tile->icon" :size="16" />
                        @endif
                        {{ $tile->label }}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- ── সূচকের কার্ড — চারটা করে এক লাইনে ───────────────────── --}}
        @if ($dashboard->stats !== [])
            @php
                /*
                 * ⭐ মালিকের নিয়ম (১ অক্টোবর ২০২৬, পুরনো পাতায়): ছয়টা পর্যন্ত এক লাইনে, নয়-দশটা দুই লাইনে (৫ + ৪) — কম লাইন।
                 * ⓘ তাই সারি যত কম সম্ভব, প্রতি সারিতে পাঁচটার বেশি নয় (ছয়টা একাই এক সারি); শেষ সারিতে একা একটা ঘর নয়।
                 */
                $statCount = count($dashboard->stats);
                $statCols = $statCount <= 6 ? max(1, $statCount) : (int) ceil($statCount / ceil($statCount / 5));
            @endphp
            <div data-stat-grid data-stat-cols="{{ $statCols }}" @class([
                'grid gap-3 sm:grid-cols-2',
                'xl:grid-cols-2' => $statCols === 2,
                'xl:grid-cols-3' => $statCols === 3,
                'xl:grid-cols-4' => $statCols === 4,
                'xl:grid-cols-5' => $statCols === 5,
                'xl:grid-cols-6' => $statCols === 6,
            ])>
                @foreach ($dashboard->stats as $stat)
                    @php
                        [$chipClass, $chipIcon, $chipText] = $chips[$stat->tone] ?? $chips[\App\Core\Engines\Dashboard\Stat::NEUTRAL];
                        $change = $stat->value === \App\Core\Engines\Dashboard\Stat::HIDDEN ? null : $stat->change();
                    @endphp
                    <a @if ($stat->href) href="{{ $stat->href }}" @endif data-stat
                       class="flex min-w-0 flex-col gap-1.5 rounded-(--radius-card) border border-(--color-border)
                              bg-(--color-surface-card) p-4 shadow-(--shadow-card) hover:bg-(--color-surface-hover)"
                       title="{{ $stat->hint }}">
                        <span class="flex items-start justify-between gap-2">
                            <span class="min-w-0 text-sm font-medium text-(--color-ink-muted)">{{ $stat->label }}</span>
                            <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-semibold {{ $chipClass }}">
                                <x-ui.icon :name="$chipIcon" :size="11" />{{ $chipText }}
                            </span>
                        </span>
                        <span class="tabular break-words text-2xl font-bold leading-tight text-(--color-ink)">{{ $stat->value ?? '—' }}</span>
                        @if ($change !== null)
                            <span @class([
                                'text-2xs',
                                'text-(--color-badge-success-ink)' => $change >= 0,
                                'text-(--color-badge-danger-ink)' => $change < 0,
                            ])>
                                {{ $change >= 0 ? '▲' : '▼' }} {{ number_format(abs($change), 1) }}%
                                <span class="text-(--color-ink-muted)">{{ $stat->previousLabel }}</span>
                            </span>
                        @else
                            <span class="truncate text-2xs text-(--color-ink-muted)">{{ $stat->hint }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif

        {{-- ⭐ গাঢ় বাক্সের তালিকা — হোমের "হাতে ও ব্যাংকে মোট"-এর রূপে, পুরো সারি জুড়ে, সূচকের ঠিক নিচে (মালিক, ৬ অক্টোবর ২০২৬) --}}
        @foreach ($dashboard->listings as $listing)
            @if ($listing->hero)
                <x-dashboard.hero-listing :listing="$listing" />
            @endif
        @endforeach

        {{-- ── চার্ট — তিনটা করে; সময়ের ধারা দুই ঘর জুড়ে ─────────── --}}
        @if ($dashboard->panels !== [])
            @php
                /*
                 * ⓘ তিন ঘরের সারিতে সাজানো: সময়ের ধারা দুই ঘর, বাকিগুলো এক ঘর। ⭐ সারির শেষে ফাঁকা থাকলে সেই সারির
                 * শেষ বাক্সটা বাকি জায়গা নেয় — একা একটা বাক্স আর পাশে খালি জায়গা নয় (৬ অক্টোবর ২০২৬-এর ১০৮০p যাচাই)।
                 */
                $spans = [];
                $used = 0;
                foreach (array_values($dashboard->panels) as $i => $panel) {
                    $want = $panel instanceof \App\Core\Engines\Dashboard\Series ? 2 : 1;
                    if ($used > 0 && $used + $want > 3) {
                        $spans[$i - 1] += 3 - $used;
                        $used = 0;
                    }
                    $spans[$i] = $want;
                    $used = ($used + $want) % 3;
                }
                if ($used > 0 && $spans !== []) {
                    $spans[array_key_last($spans)] += 3 - $used;
                }
            @endphp
            <div class="grid gap-4 xl:grid-cols-3">
                @foreach (array_values($dashboard->panels) as $i => $panel)
                    @php $series = $panel instanceof \App\Core\Engines\Dashboard\Series; @endphp
                    <section data-boxed data-panel data-span="{{ $spans[$i] }}" @class([
                        'min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)',
                        'xl:col-span-2' => $spans[$i] === 2,
                        'xl:col-span-3' => $spans[$i] === 3,
                    ])>
                        <h2 class="border-b border-(--color-border) px-4 py-3 text-sm font-semibold text-(--color-ink)">{{ $panel->label }}</h2>

                        {{-- ⭐ ধরন অনুযায়ী আঁকা, প্রতিটা দাগে মান ([[x-dashboard.chart]], মালিক ৪ অক্টোবর ২০২৬) --}}
                        <x-dashboard.chart :panel="$panel" :wide="$spans[$i] === 3" />
                        @if (! $series && $panel->hint)
                            <p class="border-t border-(--color-border) px-4 py-2 text-2xs text-(--color-ink-muted)">{{ $panel->hint }}</p>
                        @endif
                    </section>
                @endforeach
            </div>
        @endif

        {{-- ── তালিকা ──────────────────────────────────────────────── --}}
        @if (collect($dashboard->listings)->reject(fn ($l) => $l->hero)->isNotEmpty())
            <div class="grid gap-4 xl:grid-cols-2">
                @php $plain = collect($dashboard->listings)->reject(fn ($l) => $l->hero)->values(); @endphp
                @foreach ($plain as $listing)
                    {{-- ⓘ শেষেরটা একা পড়লে পুরো সারি — পাশে খালি জায়গা নয় --}}
                    <section data-boxed data-listing @class([
                        'min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)',
                        'xl:col-span-2' => $loop->last && $plain->count() % 2 === 1,
                    ])>
                        <h2 class="flex items-baseline gap-2 border-b border-(--color-border) px-4 py-3 text-sm font-semibold text-(--color-ink)">
                            {{ $listing->label }}
                            @if ($listing->href)
                                <a href="{{ $listing->href }}" class="ms-auto text-xs font-normal text-(--color-link)">{{ __('core.action.see_all') }} →</a>
                            @endif
                        </h2>
                        <x-ui.table :empty="$listing->empty" :rows="$listing->rows" :columns="$listing->columns" />
                    </section>
                @endforeach
            </div>
        @endif

        {{-- ── ব্যতিক্রম ও সতর্কতা — যা আটকে আছে, প্রতিটা একটা কার্ড ── --}}
        @php
            /*
             * ⓘ কেবল করণীয় দল (`todo`) — আজ, মাস, বছর আর হোমের মূল সূচক উপরের ঘরগুলোতেই আছে। ⛔ ৬ অক্টোবর ২০২৬-এর
             * যাচাইয়ে বিক্রয়ের পাতায় "আজকের বিক্রয়" তিনবার দেখা গেল (উপরের ঘর, দিনের দল, মূল সূচক) — একই সংখ্যা একবারই।
             */
            $waiting = collect($dashboard->reminders)->filter(fn ($item) => $item->group === 'todo')->filter(function ($item) {
                $latin = strtr((string) $item->value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

                return (int) preg_replace('/\D/', '', $latin) > 0;
            });
        @endphp
        @if ($waiting->isNotEmpty())
            <section data-dashboard-exceptions data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 text-sm font-semibold text-(--color-ink)">{{ __('home.exceptions_title') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($waiting as $item)
                        @php $warn = in_array($item->tone, ['warn', 'bad'], true); @endphp
                        <div @class([
                            'flex min-w-0 flex-col gap-2 rounded-(--radius-card) border border-(--color-border) p-3',
                            'bg-(--color-badge-warning-bg)/40' => $warn,
                            'bg-(--color-surface-sunken)' => ! $warn,
                        ])>
                            <span @class([
                                'inline-flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold',
                                'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => $warn,
                                'bg-(--color-badge-info-bg) text-(--color-badge-info-ink)' => ! $warn,
                            ])>
                                <x-ui.icon :name="$warn ? 'bell' : 'clock'" :size="12" />
                                {{ $warn ? __('home.severity_warn') : __('home.severity_attention') }}
                            </span>
                            <span class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 text-sm font-semibold">{{ $item->label }}</span>
                                <span class="tabular shrink-0 text-xl font-semibold">{{ $item->value }}</span>
                            </span>
                            <a href="{{ $item->href }}"
                               class="inline-flex w-fit items-center gap-1 rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-3 py-1.5 text-xs font-semibold text-(--color-link) hover:bg-(--color-surface-hover)">
                                {{ __('home.open') }} <x-ui.icon name="chevron_right" :size="14" class="rtl:rotate-180" />
                            </a>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.app>
