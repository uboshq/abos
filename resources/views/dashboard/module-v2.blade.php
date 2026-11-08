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

    @php
        /*
         * ⭐ উপরের চার — মডিউলের কঠিন রঙে বড় করে; বাকি সব নিচে "আরও অঙ্ক"-এ, একটাও হারায় না (পরিকল্পনা §১, §৪.২, §৪.৫)।
         * ⓘ কোন চারটা: মডিউল যে ক্রমে ঘোষণা করে তার প্রথম চারটা — ক্রমটা মডিউলের মালিকের গুরুত্বের ক্রম ([[DashboardDefinition]])।
         */
        $topStats = array_slice($dashboard->stats, 0, 4);
        $moreStats = array_slice($dashboard->stats, 4);

        // ⭐ রং মালিকের নমুনা থেকে হুবহু — নিয়ম [[CardColours]]-এ, মডিউল ধরে রং `config/abos.php`-এর `dashboard_colours`-এ
        $head = \App\Core\Engines\Dashboard\CardColours::head($module);
        $cardColour = \App\Core\Engines\Dashboard\CardColours::for($module, array_map(fn ($s) => $s->tone, $topStats));
    @endphp

    {{-- ⭐ মাথার পট্টি মডিউলের রঙে — ড্যাশবোর্ডের পরিকল্পনা §৪.১ (মালিক, ২৪ অক্টোবর ২০২৬: "full erp RGB ekta color dibe")।
         ⓘ রং কেবল পরিচয়ে: পট্টি আর উপরের চার কার্ড; লেখা, বোতাম, চার্ট আর তালিকায় নয়। সাদা লেখা প্রতিটা মডিউল-রঙে ≥ ৪.৯২ঃ১
         ([[TheModuleColoursCarryWhiteTextTest]]); রঙিন সুইচ বন্ধে ব্র্যান্ড ৭০০ — একই কনট্রাস্ট। --}}
    <x-slot:header>
        {{-- ⓘ নমুনার মতো: বাঁয়ে মডিউলের নাম · ছোট করে উপশিরোনাম, ডানে কোম্পানি আর শাখার চিপ (তাই পাতার পথের সারিতে এরা আর
               দুইবার নয় — [[x-shell.page-path]]) --}}
        @php
            $moduleName = collect($menu)->firstWhere('code', $module)['label'] ?? $dashboard->title;
            $shellUser = auth()->user();
            $scope = array_filter([
                $shellUser?->currentCompany?->name(),
                app(\App\Core\Services\ShellFacts::class)->branchLabel($shellUser, $shellUser?->currentBranch),
            ]);
            view()->share('scopeInHead', $scope !== []);
        @endphp
        <div data-dashboard-head="{{ $module }}"
             class="flex flex-wrap items-center gap-x-3.5 gap-y-1.5 rounded-(--radius-card) px-4 py-3 text-white"
             style="background: linear-gradient(100deg, {{ $head[0] }}, {{ $head[1] }})">
            <h1 class="min-w-0 flex-[1_1_220px] text-lg font-bold leading-tight">
                {{ $moduleName }}
                @if ($dashboard->subtitle)
                    <span class="text-sm font-normal text-white/90">· {{ $dashboard->subtitle }}</span>
                @endif
            </h1>
            @foreach ($scope as $chip)
                <span data-dashboard-scope class="rounded-full border border-white/75 px-2.5 py-0.5 text-xs">{{ $chip }}</span>
            @endforeach
        </div>
    </x-slot:header>


    <div data-dashboard-v2 class="flex flex-col gap-4">

        {{-- ── কাজের বোতাম ─────────────────────────────────────────── --}}
        {{-- ⭐ সব সেটিং জুড়ে খোঁজা — ড্যাশবোর্ড চাইলে (কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ৩) --}}
        @if ($dashboard->search)
            <form method="GET" action="{{ $dashboard->search['action'] }}" data-dashboard-search role="search" class="mb-4 flex max-w-xl gap-2">
                <label class="sr-only" for="dashboard-search">{{ $dashboard->search['placeholder'] }}</label>
                <input id="dashboard-search" type="search" name="{{ $dashboard->search['name'] }}" placeholder="{{ $dashboard->search['placeholder'] }}"
                       class="h-(--spacing-field) min-w-0 flex-1 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-3 text-sm">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.search') }}</x-ui.button>
            </form>
        @endif

        @if ($dashboard->tiles !== [])
            <div class="flex flex-wrap gap-2">
                @foreach ($dashboard->tiles as $tile)
                    <a href="{{ $tile->href }}" data-tile
                       class="inline-flex h-(--spacing-field) items-center gap-2 rounded-(--radius-field) border border-(--color-border)
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

        {{-- ── ⭐ উপরের চার কার্ড — মডিউলের কঠিন রঙে, সাদা লেখা (পরিকল্পনা §৪.২) ─────────────
             ⓘ অবস্থার চিপ নিজের অর্থের রঙেই (সবুজ/হলুদ/লাল/নীল, হালকা জমিনে গাঢ় লেখা) — মডিউলের রঙে মেশে না; তুলনা ▲/▼ সাদায়,
               রং নয়, কারণ কঠিন জমিনে সবুজ-লাল লেখা ৪.৫ঃ১ পায় না। --}}
        @if ($topStats !== [])
            {{-- ⓘ ক্লাসগুলো পুরো লেখা — Tailwind জোড়া-দেওয়া নাম দেখতে পায় না --}}
            <div data-stat-top="{{ $module }}" @class([
                'grid gap-3 sm:grid-cols-2',
                // ⓘ lg (১০২৪) থেকে এক সারিতে — মালিকের ১৩৬৬-এ চারটাই পাশাপাশি (এই অ্যাপের xl ১৪৪০, তাতে ১৩৬৬-এ ২+২ হত; ছবিতে দেখা)
                'lg:grid-cols-2' => count($topStats) === 2,
                'lg:grid-cols-3' => count($topStats) === 3,
                'lg:grid-cols-4' => count($topStats) === 4,
            ])>
                @foreach ($topStats as $stat)
                    @php
                        [$chipClass, $chipIcon, $chipText] = $chips[$stat->tone] ?? $chips[\App\Core\Engines\Dashboard\Stat::NEUTRAL];
                        $change = $stat->value === \App\Core\Engines\Dashboard\Stat::HIDDEN ? null : $stat->change();
                    @endphp
                    {{-- ⓘ নমুনার মতো ঢাল: নিজের রং → তার ৬৮% (কালোর দিকে) — সাদা লেখা দুই প্রান্তেই ৪.৫ঃ১-এর উপরে --}}
                    <a @if ($stat->href) href="{{ $stat->href }}" @endif data-stat data-stat-solid data-stat-colour="{{ $cardColour[$loop->index] }}"
                       class="flex min-w-0 flex-col gap-1.5 rounded-(--radius-card) p-4 text-white shadow-(--shadow-card) hover:brightness-110"
                       style="background: linear-gradient(135deg, {{ $cardColour[$loop->index] }}, color-mix(in srgb, {{ $cardColour[$loop->index] }} 68%, black))"
                       title="{{ $stat->hint }}">
                        <span class="flex items-start justify-between gap-2">
                            <span class="min-w-0 text-sm font-medium text-white/90">{{ $stat->label }}</span>
                            <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-semibold {{ $chipClass }}">
                                <x-ui.icon :name="$chipIcon" :size="11" />{{ $chipText }}
                            </span>
                        </span>
                        <span class="tabular break-words text-2xl font-bold leading-tight">{{ $stat->value ?? '—' }}</span>
                        @if ($change !== null)
                            <span class="text-2xs text-white/90">
                                {{ $change >= 0 ? '▲' : '▼' }} {{ number_format(abs($change), 1) }}% {{ $stat->previousLabel }}
                            </span>
                        @else
                            <span class="line-clamp-2 text-xs text-white/85">{{ $stat->hint }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif

        {{-- ── ⭐ নজরে রাখুন — মালিকের রঙিন নমুনার তালিকা (৩১ অক্টোবর ২০২৬; পরিকল্পনা §৪.৪) ─────────────────────────
             ⓘ উপরের চার কার্ডের ঠিক নিচে, নমুনার মতো; প্রতিটা সারির সামনে মানের রঙের বিন্দু আর তার হালকা জমিন (লাল এখনই, হলুদ নজর,
               নীল তথ্য), ডানে অঙ্ক, চাপলে সেই তালিকা বা কাগজ। ⓘ কেবল করণীয় দল (`todo`) — ৬ অক্টোবরের যাচাইয়ে একই সংখ্যা তিন জায়গায়
               দেখা গিয়েছিল, তাই উপরের ঘরের সূচক এখানে আসে না; শূন্যও আসে না। --}}
        @php
            // ⓘ নিয়মটা এক জায়গায় — ফোনের উত্তরও এটাই ডাকে ([[WatchList]], ড্যা-৭)
            $waiting = \App\Core\Engines\Dashboard\WatchList::of($dashboard->reminders);
        @endphp
        @php
            // ⭐ নমুনার সারি: বাঁয়ে প্রথম চার্ট (মাথার প্রথম রঙের দাগ), ডানে "নজরে রাখুন" (দ্বিতীয় রঙের দাগ) — পাশাপাশি
            $panels = array_values($dashboard->panels);
            $firstPanel = $panels[0] ?? null;
            $panels = array_slice($panels, 1);
        @endphp
        <div data-dashboard-pair @class(['grid gap-4', 'lg:grid-cols-2' => $firstPanel !== null])>
            @if ($firstPanel !== null)
                <section data-boxed data-panel data-span="1" class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)"
                         style="border-top: 4px solid {{ $head[0] }}">
                    <h2 class="border-b border-(--color-border) px-4 py-3 text-sm font-semibold text-(--color-ink)">{{ $firstPanel->label }}</h2>
                    <x-dashboard.chart :panel="$firstPanel" :wide="false" />
                    @if (! $firstPanel instanceof \App\Core\Engines\Dashboard\Series && $firstPanel->hint)
                        <p class="border-t border-(--color-border) px-4 py-2 text-2xs text-(--color-ink-muted)">{{ $firstPanel->hint }}</p>
                    @endif
                </section>
            @endif
            <section data-dashboard-exceptions data-boxed class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 pb-3 pt-2"
                     style="border-top: 4px solid {{ $head[1] }}">
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
        </div>

        {{-- ── আরও অঙ্ক — বাকি সব সূচক, আগের মতো (পরিকল্পনা §৪.৫: একটাও বাদ নয়) ───────────── --}}
        @if ($moreStats !== [])
            <h2 class="text-sm font-semibold text-(--color-ink-muted)" data-stat-more-title>{{ __('home.more_figures') }}</h2>
            @php
                /*
                 * ⭐ মালিকের নিয়ম (১ অক্টোবর ২০২৬, পুরনো পাতায়): ছয়টা পর্যন্ত এক লাইনে, নয়-দশটা দুই লাইনে (৫ + ৪) — কম লাইন।
                 * ⓘ তাই সারি যত কম সম্ভব, প্রতি সারিতে পাঁচটার বেশি নয় (ছয়টা একাই এক সারি); শেষ সারিতে একা একটা ঘর নয়।
                 */
                $statCount = count($moreStats);
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
                @foreach ($moreStats as $stat)
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
                            {{-- ⭐ কাটা নয়, দুই লাইন পর্যন্ত (Material 3; ২০ অক্টোবর ২০২৬) — বেশি হলে পুরোটা কার্ডের title-এ --}}
                            <span class="line-clamp-2 text-xs text-(--color-ink-muted)">{{ $stat->hint }}</span>
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
        @if ($panels !== [])
            @php
                /*
                 * ⓘ তিন ঘরের সারিতে সাজানো: সময়ের ধারা দুই ঘর, বাকিগুলো এক ঘর। ⭐ সারির শেষে ফাঁকা থাকলে সেই সারির
                 * শেষ বাক্সটা বাকি জায়গা নেয় — একা একটা বাক্স আর পাশে খালি জায়গা নয় (৬ অক্টোবর ২০২৬-এর ১০৮০p যাচাই)।
                 */
                $spans = [];
                $used = 0;
                foreach ($panels as $i => $panel) {
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
                @foreach ($panels as $i => $panel)
                    @php $series = $panel instanceof \App\Core\Engines\Dashboard\Series; @endphp
                    <section data-boxed data-panel data-span="{{ $spans[$i] }}"
                        style="border-top: 4px solid {{ $head[$i % 2] }}" @class([
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
                    <section data-boxed data-listing
                        style="border-top: 4px solid {{ $head[$loop->index % 2] }}" @class([
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

    </div>
</x-layouts.app>
