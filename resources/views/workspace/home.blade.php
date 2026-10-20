{{--
    ⭐ হোম — নতুন রূপ (মালিকের পরিকল্পনা ২, ৫ অক্টোবর ২০২৬) — সব সাইটে, সুইচ ছাড়াই।

    মালিকের লাল দাগ: টাকার বাক্স আর "ব্যবসার চিত্র"-এর চার্ট — দুইটা থাকবে, বাকি সব নতুন।
    ক্রম (মালিকের কথায়): টাকার বাক্স → ৮টা চার্ট, ৪টা করে দুই সারি, জরুরিগুলো প্রথমে → ৮টা মূল সূচক →
    পাশাপাশি ব্যতিক্রম কেন্দ্র আর সদ্য যা হয়েছে।

    ⛔ একই সংখ্যা দুইবার নয়: টাকার বাক্সে যা, মূল সূচকে তা নয়; মূল সূচকে যা, ব্যতিক্রম কেন্দ্রে তা নয়
    (নাম মিলিয়ে বাদ, নিচে `$shown`)। পুরনো "গোটা ব্যবসা" সারি আর সব-চার্ট অংশ এখানে নেই।
    ⓘ সুইচ abos.dashboards_v2 কেবল মডিউলের ড্যাশবোর্ড বাঁধে; হোম দুই অবস্থাতেই এটাই।
--}}
@php
    $titles = [
        'today' => __('core.dashboard.today'),
        'month' => __('core.dashboard.this_month'),
        'year' => __('core.dashboard.this_year'),
    ];

    $digits = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];
    $pending = fn ($widget) => (int) preg_replace('/\D/', '', strtr($widget->value, $digits)) > 0;

    // ── টাকার বাক্স — ভাগওয়ালা টাকার ঘরটাই (হাতে নগদ · MFS · ব্যাংক · পথে); থাকছে হুবহু ──
    $position = null;
    foreach (\App\Core\Dashboard\Widget::PERIODS as $positionGroup) {
        foreach ($groups[$positionGroup] ?? [] as $candidate) {
            if ($candidate->tone === 'money' && $candidate->parts !== []) {
                $position = $candidate;
                break 2;
            }
        }
    }

    // ── মূল সূচক — মডিউলের নিজের `kpi` ঘর, ক্রম মডিউলই বলে (sort) ──
    $kpis = $groups['kpi'] ?? [];

    /*
     * ⭐ "বাজারে বকেয়া" টাকার বাক্সের নিচে (মালিকের অনুমোদিত নকশা, ২০ অক্টোবর ২০২৬) — মূল সূচকের সেই ঘরটাই সরে আসে, নতুন করে
     * গোনা নয়: একই উৎস ([[CustomerMetrics::dues()]], হোমের ছাঁকনিসহ), একই চাবি (customer.report), চাবি না থাকলে ঘরটাই নেই।
     * ⛔ একই সংখ্যা দুইবার নয় — বাক্সে উঠলে মূল সূচকের সারি থেকে বাদ। টাকার বাক্স না থাকলে আগের মতো সূচকের সারিতেই।
     */
    $owed = null;
    if ($position) {
        foreach ($kpis as $k => $candidate) {
            if ($candidate->label === __('customer::dashboard.kpi_owed')) {
                $owed = $candidate;
                unset($kpis[$k]);
                $kpis = array_values($kpis);
                break;
            }
        }
    }

    // ⛔ পাতায় যা আগেই আছে তার নাম — ব্যতিক্রম কেন্দ্রে আবার নয়
    $shown = array_map(fn ($w) => $w->label, $kpis);
    if ($position) {
        $shown[] = $position->label;
    }
    if ($owed) {
        $shown[] = $owed->label;
    }

    $todo = collect($groups['todo'] ?? [])->reject(fn ($w) => in_array($w->label, $shown, true));
    $waiting = $todo->filter($pending)->values();
    $quiet = $todo->reject($pending);

    // ── ৮টা চার্ট — কোন মডিউল আর কোন ক্রম config-এ; প্রতিটা মডিউলের প্রথম চার্ট, যা নিজের পাতাতেও প্রথম ──
    $byModule = collect($overall ?? [])->keyBy('module');
    $pictures = collect(config('abos.home_pictures', []))
        ->map(fn (string $code) => $byModule->get($code))
        ->filter(fn ($row) => $row !== null && ($row['panel'] ?? null) !== null)
        ->take(8)
        ->values();

    $nothing = $kpis === [] && $pictures->isEmpty() && $todo->isEmpty() && $position === null && $happenings === [];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.menu.dashboard') }}</x-slot:title>

    {{-- ⭐ শিরোনাম একা; উপশিরোনামের জায়গায় কোম্পানি-শাখা বাছাই, আর ফিল্টার · লেআউট · সময় তার নিচে
         (মালিক, ৫ অক্টোবর ২০২৬: *"company branch selector dewar kotha"* · *"ei gulo ড্যাশবোর্ড er niche niye aso"*) --}}
    <x-slot:header>
    {{-- ── মাথা (মালিক, ৬ অক্টোবর ২০২৬): টাকার বাক্স সোজা উপরে, শিরোনামের সমান উচ্চতায় ডানে; বাঁয়ে শিরোনাম, তার ঠিক নিচে
         কোম্পানি · তারিখ, তার কাছেই ফিল্টার · লেআউট · সময় — মাঝে ফাঁকা নয়, যাতে চার্ট উপরে ওঠে ── --}}
    {{-- ⭐ মাথা এক সারিতে — মালিকের অনুমোদিত নমুনা (২০ অক্টোবর ২০২৬; fe-র ছবি-যাচাই: ১৩৬৬-এ সব নিচে নিচে নেমে বড় জায়গা নষ্ট হত):
         শিরোনাম · "তারিখ · কোম্পানি · শাখা" · ডানে ফিল্টার, লেআউট, সময়; টাকার বাক্স তার নিচে পুরো চওড়ায়।
         ⓘ কোম্পানি-শাখার বাছাই এখানে আর নেই — পুরো চওড়া বারেই আছে ([[shell.company-switcher]]), একই কাজ দুই জায়গায় নয়;
         এখানে কেবল লেখা, বারের একই উত্তরে ([[ShellFacts::branchLabel()]])। ⛔ ৫–৬ অক্টোবরের পাশাপাশি সাজ নমুনায় বদলেছে। --}}
    <section data-command-head class="flex flex-col gap-3" style="align-items: stretch">
        <div class="flex min-w-0 flex-col" style="gap: 0.375rem">
            <div data-home-headline class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2">
            <h1 data-home-title class="truncate text-xl font-semibold" style="line-height: 1.75rem">{{ __('core.menu.dashboard') }}</h1>
            @php
                $headUser = auth()->user();
                $headDate = strtr(now()->locale(app()->getLocale())->translatedFormat('l, j F Y'),
                    ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
            @endphp
            <p data-home-company class="min-w-0 text-sm text-(--color-ink-muted)">{{ implode(' · ', array_filter([
                $headDate,
                $headUser->currentCompany?->name(),
                app(\App\Core\Services\ShellFacts::class)->branchLabel($headUser, $headUser->currentBranch),
            ])) }}</p>

            {{-- ⭐ ফিল্টার · লেআউট সাজান · সময় — একই সারির ডান কোণে (নমুনার চিপ) --}}
            <div data-home-controls class="ms-auto flex flex-wrap items-center gap-2">
                {{-- ⭐ হোমের ফিল্টার — গুদাম, এলাকা, SR; বদলায় কেবল বিক্রি আর বকেয়া ([[HomeFilter]]) --}}
                <details data-home-filter class="relative">
                    <summary @class([
                        'flex h-10 cursor-pointer list-none items-center gap-2 rounded-(--radius-field) border px-3 text-sm',
                        'border-(--color-brand-600) bg-(--color-brand-50) text-(--color-brand-700)' => $filter->active(),
                        'border-(--color-border) bg-(--color-surface-card) text-(--color-ink)' => ! $filter->active(),
                    ])>
                        <x-ui.icon name="filter" :size="14" />
                        <span class="font-semibold">{{ __('home.filter') }}</span>
                    </summary>
                    <form method="GET" action="{{ route('dashboard') }}"
                          class="pops-onto-page absolute end-0 top-full z-50 mt-1 w-72 space-y-2 rounded-(--radius-field)
                                 border border-(--color-border) bg-(--color-surface-card) p-3 shadow-lg">
                        <input type="hidden" name="period" value="{{ $period }}">
                        @foreach (['warehouse' => 'warehouses', 'area' => 'areas', 'seller' => 'sellers'] as $key => $list)
                            <label class="block text-xs text-(--color-ink-muted)">
                                {{ __('home.filter_'.$key) }}
                                <select name="{{ $key }}" data-filter-{{ $key }}
                                        class="mt-1 h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm text-(--color-ink)">
                                    <option value="">{{ __('home.filter_all') }}</option>
                                    @foreach ($filterChoices[$list] as $id => $name)
                                        <option value="{{ $id }}" @selected($filter->{$key} === (int) $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                        <div class="flex justify-between gap-2 pt-1">
                            <a href="{{ route('dashboard', ['period' => $period]) }}"
                               class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-xs">{{ __('home.filter_clear') }}</a>
                            <button type="submit"
                                    class="rounded-(--radius-field) bg-(--color-brand-600) px-4 py-1.5 text-xs font-semibold text-white">{{ __('home.filter_apply') }}</button>
                        </div>
                    </form>
                </details>

                {{-- ⭐ "লেআউট সাজান" — কোন অংশ দেখা যাবে আর কোনটা আগে; কেবল নিজের জন্য ([[HomeLayout]]) --}}
                <details data-layout-menu class="relative">
                    <summary class="flex h-(--spacing-field-compact) cursor-pointer list-none items-center gap-2 rounded-(--radius-field)
                                    border border-(--color-border) bg-(--color-surface-card) px-3 text-sm text-(--color-ink)">
                        <x-ui.icon name="settings" :size="14" class="text-(--color-ink-muted)" />
                        <span class="font-semibold">{{ __('home.layout') }}</span>
                    </summary>
                    <form method="POST" action="{{ route('home.layout') }}"
                          class="pops-onto-page absolute end-0 top-full z-50 mt-1 w-72 space-y-2 rounded-(--radius-field)
                                 border border-(--color-border) bg-(--color-surface-card) p-3 shadow-lg">
                        @csrf
                        <input type="hidden" name="period" value="{{ $period }}">
                        <p class="text-2xs text-(--color-ink-muted)">{{ __('home.layout_hint') }}</p>
                        @foreach (\App\Core\Dashboard\HomeLayout::PARTS as $part)
                            @php $unit = in_array($part, ['exceptions', 'happenings'], true) ? 'work' : $part; @endphp
                            <div class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="show[{{ $part }}]" value="1" @checked($layout->shows($part)) class="size-4">
                                <span class="min-w-0 flex-1 truncate">{{ __('home.layout_part_'.$part) }}</span>
                                @if ($part !== 'happenings')
                                    <input type="number" name="position[{{ $unit }}]" min="1" max="{{ count(\App\Core\Dashboard\HomeLayout::UNITS) }}" value="{{ $layout->position($unit) }}"
                                           aria-label="{{ __('home.layout_position') }}"
                                           class="num h-(--spacing-field-dense) w-14 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-end text-sm">
                                @else
                                    <span class="w-14 text-center text-2xs text-(--color-ink-muted)">↑</span>
                                @endif
                            </div>
                        @endforeach
                        <div class="flex justify-between gap-2 pt-1">
                            <button type="submit" name="reset" value="1"
                                    class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-xs">{{ __('home.layout_reset') }}</button>
                            <button type="submit"
                                    class="rounded-(--radius-field) bg-(--color-brand-600) px-4 py-1.5 text-xs font-semibold text-white">{{ __('home.layout_save') }}</button>
                        </div>
                    </form>
                </details>

                {{-- সময় — আজ · এ মাস · এ বছর; বদলায় কেবল প্রবাহের সূচক ([[HomePeriod]]) --}}
                <details data-period-menu class="relative">
                    <summary class="flex h-(--spacing-field-compact) cursor-pointer list-none items-center gap-2 rounded-(--radius-field)
                                    border border-(--color-border) bg-(--color-surface-card) px-3 text-sm text-(--color-ink)">
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('home.period') }}</span>
                        <span class="font-semibold">{{ $titles[$period] }}</span>
                        <x-ui.icon name="chevron_down" :size="14" class="text-(--color-ink-muted)" />
                    </summary>
                    <div class="pops-onto-page absolute end-0 top-full z-50 mt-1 w-48 rounded-(--radius-field)
                                border border-(--color-border) bg-(--color-surface-card) p-1 shadow-lg">
                        @foreach (\App\Core\Dashboard\Widget::PERIODS as $option)
                            <a href="{{ route('dashboard', ['period' => $option] + $filter->query()) }}"
                               @class([
                                   'block rounded-(--radius-field) px-3 py-2 text-sm',
                                   'bg-(--color-brand-50) font-semibold text-(--color-brand-700)' => $period === $option,
                                   'text-(--color-ink-body) hover:bg-(--color-surface-hover)' => $period !== $option,
                               ])
                               @if ($period === $option) aria-current="page" @endif>{{ $titles[$option] }}</a>
                        @endforeach
                    </div>
                </details>
            </div>
            </div>
            @if ($filter->active())
                <div data-home-filter-on class="mt-2 flex flex-wrap items-center gap-2 rounded-(--radius-field) border border-(--color-brand-600)
                            bg-(--color-brand-50) px-3 py-2 text-sm text-(--color-brand-700)">
                    <span class="font-semibold">{{ __('home.filter_on') }}:</span>
                    @foreach (['warehouse' => 'warehouses', 'area' => 'areas', 'seller' => 'sellers'] as $key => $list)
                        @if ($filter->{$key} !== null)
                            <span>{{ __('home.filter_'.$key) }} — {{ $filterChoices[$list][$filter->{$key}] ?? '' }}</span>
                        @endif
                    @endforeach
                    <span class="text-xs text-(--color-ink-muted)">· {{ __('home.filter_scope') }}</span>
                    <a href="{{ route('dashboard', ['period' => $period]) }}" class="ms-auto text-xs font-semibold underline">{{ __('home.filter_clear') }}</a>
                </div>
            @endif
        </div>

        @if ($position)
            <a href="{{ $position->href }}" data-money-position
               class="block w-full shrink-0 rounded-(--radius-card) px-5 py-3 text-(--color-ink-inverse) shadow-lg
                      transition-shadow hover:shadow-xl"
               style="background: linear-gradient(135deg, var(--color-brand-700), var(--color-brand-900))"
               @if ($position->definition) title="{{ $position->definition }}" @endif>
                <p class="flex items-center gap-1.5 text-sm text-white/70">
                    @if ($position->icon)
                        <x-ui.icon :name="$position->icon" :size="15" />
                    @endif
                    {{ $position->label }}
                </p>
                <p class="tabular text-2xl font-semibold">{{ $position->value }}</p>
                <div class="mt-1.5 grid grid-cols-2 gap-x-8 gap-y-2 border-t border-white/15 pt-2 sm:grid-cols-4">
                    @foreach ($position->parts as $partLabel => $partValue)
                        <div class="min-w-0">
                            <p class="truncate text-2xs text-white/60">{{ $partLabel }}</p>
                            <p class="tabular mt-0.5 font-semibold">{{ $partValue }}</p>
                        </div>
                    @endforeach
                </div>
                {{-- ⭐ আজ এসেছে · আজ গেছে · বাজারে বকেয়া (মালিকের অনুমোদিত নকশা, ২০ অক্টোবর ২০২৬) — ফোনের আজকের পাতার একই উৎস:
                     [[AccountsWidgets::todayTiles()]] (accounts.view) আর মূল সূচকের "বাজারে বকেয়া" ঘর (customer.report)।
                     ⓘ চিহ্ন লেখায় ("+ " / "− "), রং একা কিছু বলে না; চাবি না থাকা ঘর আঁকাই হয় না, শূন্য নয়। --}}
                @php
                    $heroTiles = $position->tiles;
                    if ($owed) {
                        // ⓘ ঘরের পুরো কথা সাথে — অগ্রিমের ইঙ্গিতও ("… অগ্রিম"), সূচকের সারিতে যেমন ছিল; সরে আসায় হারায় না
                        $heroTiles[] = ['label' => $owed->label, 'value' => $owed->value, 'sign' => '', 'tone' => 'plain', 'hint' => $owed->hint, 'mark' => 'owed'];
                    }
                @endphp
                @if ($heroTiles !== [])
                    <div data-money-today class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                        @foreach ($heroTiles as $heroTile)
                            <div data-money-tile="{{ $heroTile['tone'] }}" @if (($heroTile['mark'] ?? null) === 'owed') data-home-owed @endif
                                 class="min-w-0 rounded-(--radius-field) px-2.5 py-1.5" style="background: var(--color-on-brand-surface)">
                                <p class="text-xs text-white/70">{{ $heroTile['label'] }}</p>
                                {{-- ⓘ রং টোকেন থেকে (tokens.css: --color-on-brand-in/out), হাতে লেখা নয় — নকশার নিয়ম --}}
                                <p data-money-tile-value class="tabular text-base font-semibold"
                                   @if ($heroTile['tone'] === 'in') style="color: var(--color-on-brand-in)" @elseif ($heroTile['tone'] === 'out') style="color: var(--color-on-brand-out)" @endif>{{ $heroTile['sign'] !== '' ? $heroTile['sign'].' ' : '' }}{{ $heroTile['value'] }}</p>
                                @if (filled($heroTile['hint'] ?? null))
                                    <p data-money-tile-hint class="text-xs text-white/70">{{ $heroTile['hint'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </a>
        @endif
    </section>
    </x-slot:header>

    <style @nonce>
        .hm-pictures { display: grid; gap: .75rem; grid-template-columns: minmax(0, 1fr); }
        .hm-kpis { display: grid; gap: .6rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .hm-work { display: grid; gap: .75rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 768px) {
            .hm-pictures { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .hm-kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (min-width: 1280px) {
            /* ⭐ ৪টা করে দুই সারি (মালিক, ৫ অক্টোবর ২০২৬) — চার্ট বড়, মান পরিষ্কার */
            .hm-pictures { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .hm-kpis { grid-template-columns: repeat(8, minmax(0, 1fr)); }
            .hm-work { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        /* ⓘ মান কখনো কাটা নয় (truncate নয়) — লম্বা হলে ভাঙে ([[TheHomeShowsOnlyYourOwnWorkTest]]) */
        .hm-kpi-value { line-height: 1.3; }
    </style>


    {{-- ⭐ সাজানো যায় এমন তিন ভাগ — ক্রম CSS `order`-এ, লুকানো অংশ আঁকাই হয় না ([[HomeLayout]]) --}}
    <div data-home-units style="display: flex; flex-direction: column">

        {{-- ── ব্যবসার চিত্র — ৮টা চার্ট, ৪টা করে দুই সারি (থাকছে; বড় হলো) ── --}}
        <div data-home-unit="pictures" style="order: {{ $layout->position('pictures') }}">
            @if ($pictures->isNotEmpty() && $layout->shows('pictures'))
                <section data-business-pictures class="mb-3">
                    {{-- ⓘ আলাদা শিরোনাম নেই — প্রতিটা কার্ড নিজের নাম বলে; জায়গাটা এক পর্দায় সব ধরাতে লাগে --}}
                    <div data-pictures-row class="hm-pictures" aria-label="{{ __('home.pictures_title') }}">
                        @foreach ($pictures as $row)
                            <a href="{{ route('module.dashboard', ['module' => $row['module']]) }}" data-boxed data-picture="{{ $row['module'] }}"
                               class="block min-w-0 rounded-(--radius-card) border border-(--color-border)
                                      bg-(--color-surface-card) shadow-(--shadow-card) transition-colors hover:bg-(--color-surface-hover)">
                                <div class="flex items-baseline justify-between gap-3 border-b border-(--color-border) px-4 py-2">
                                    <span class="min-w-0">
                                        <span class="block break-words text-sm font-semibold text-(--color-ink)">{{ $row['name'] }}</span>
                                        {{-- ⓘ হোমে তারিখ শিরোনামের আগে — নিচে আলাদা লাইন হলে এক পর্দায় ধরত না
                                             ⭐ উপশিরোনাম কাটা নয়, দুই লাইন পর্যন্ত ভাঙে (Material 3: মূল লেখা কখনো কাটা নয়; ২০ অক্টোবর ২০২৬ —
                                             আগে "ছয় মাসের বি…" দেখাত); তার বেশি হলে পুরোটা title-এ --}}
                                        @if ($row['listing'] ?? null)
                                            {{-- ⓘ তালিকা দেখালে উপশিরোনামও তালিকার — চার্টের ("কত দিন ধরে") কথা তালিকার মাথায় বেমানান --}}
                                            <span class="line-clamp-2 block text-xs text-(--color-ink-muted)">{{ $row['listing']->label }}</span>
                                        @else
                                        <span class="line-clamp-2 block text-xs text-(--color-ink-muted)"
                                              title="{{ $row['panel']->range ? $row['panel']->range.' · ' : '' }}{{ $row['panel']->label }}">@if ($row['panel']->range)<span data-chart-range class="font-semibold text-(--color-ink-body)">{{ $row['panel']->range }}</span> · @endif{{ $row['panel']->label }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-xs text-(--color-link)">{{ __('home.details') }} →</span>
                                </div>
                                {{-- ⭐ মডিউল হোমের জন্য একটা তালিকা চিহ্নিত করলে ([[Listing::$onHome]]) চার্টের বদলে সেটা — প্রথম চারটা সারি,
                                     প্রথম কলাম (কাগজ · পক্ষ) আর শেষ কলাম (অবস্থার চিপ); মালিকের অনুমোদিত নমুনা, ২০ অক্টোবর ২০২৬ --}}
                                @if ($row['listing'] ?? null)
                                    @php
                                        $homeList = $row['listing'];
                                        $firstCol = $homeList->columns[0];
                                        $lastCol = $homeList->columns[count($homeList->columns) - 1];
                                    @endphp
                                    <div data-home-listing class="px-4 py-2">
                                        @forelse ($homeList->rows->take(4) as $listRow)
                                            <div class="flex min-w-0 items-center justify-between gap-2 border-b border-(--color-border) py-1.5 text-sm last:border-b-0">
                                                {{-- ⓘ কাটা নয়, দুই লাইন পর্যন্ত — "নম্বর · নাম · পয়েন্ট · শাখা" পুরোটা পড়া যায় (মালিকের স্থায়ী নিয়ম; fe-র ছবি-যাচাই) --}}
                                                <span class="line-clamp-2 min-w-0 break-words leading-snug text-(--color-ink-body)" title="{{ ($firstCol['render'])($listRow) }}">{{ ($firstCol['render'])($listRow) }}</span>
                                                <span class="shrink-0">{{ ($lastCol['render'])($listRow) }}</span>
                                            </div>
                                        @empty
                                            <p class="py-3 text-sm text-(--color-ink-muted)">{{ $homeList->empty }}</p>
                                        @endforelse
                                    </div>
                                @else
                                {{-- ⓘ কার্ডটাই লিংক — ভেতরে আলাদা ফোকাসের জায়গা নয় ([[x-dashboard.chart]]-এর `interactive`) --}}
                                <x-dashboard.chart :panel="$row['panel']" compact :show-range="false" :interactive="false" />
                                @endif
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        {{-- ── মূল সূচক — এক সারিতে ৮টা, প্রতিটা একবার ── --}}
        <div data-home-unit="kpis" style="order: {{ $layout->position('kpis') }}">
            @if ($kpis !== [] && $layout->shows('kpis'))
                <section data-kpis class="mb-4">
                    <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">{{ __('home.kpis_title') }} · {{ $titles[$period] }}</h2>
                    <div class="hm-kpis">
                        @foreach ($kpis as $widget)
                            @php
                                $spark = array_map('floatval', $widget->spark);
                                $peak = $spark === [] ? 0.0 : max($spark);
                                $floor = $spark === [] ? 0.0 : min($spark);
                            @endphp
                            <a href="{{ $widget->href }}" data-kpi data-boxed
                               class="block min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)
                                      px-3 py-2 shadow-(--shadow-card) transition-colors hover:bg-(--color-surface-hover)"
                               @if ($widget->definition) title="{{ $widget->definition }}" @endif>
                                <span class="flex items-center gap-1.5 truncate text-2xs font-semibold text-(--color-ink-muted)">
                                    @if ($widget->icon)
                                        <x-ui.icon :name="$widget->icon" :size="13" />
                                    @endif
                                    <span class="truncate">{{ $widget->label }}</span>
                                </span>
                                {{-- ⓘ সংখ্যা ভাঙে না — লম্বা হলে অক্ষর ছোট (৬ অক্টোবর ২০২৬-এর যাচাই: "1,13,98,450.0" এক লাইনে, "0" পরের লাইনে) --}}
                                <span @class([
                                    'hm-kpi-value tabular mt-1 block whitespace-nowrap font-semibold',
                                    'text-lg' => mb_strlen($widget->value) <= 12,
                                    'text-base' => mb_strlen($widget->value) > 12 && mb_strlen($widget->value) <= 14,
                                    'text-sm' => mb_strlen($widget->value) > 14,
                                    'text-(--color-badge-warning-ink)' => $widget->tone === 'warn' && $pending($widget),
                                    'text-(--color-ink)' => ! ($widget->tone === 'warn' && $pending($widget)),
                                ])>{{ $widget->value }}</span>
                                @if ($widget->delta || $widget->hint)
                                    <span class="mt-0.5 block truncate text-2xs text-(--color-ink-muted)">
                                        @if ($widget->delta)
                                            <span @class([
                                                'font-semibold',
                                                'text-(--color-success)' => ! str_starts_with($widget->delta, '-'),
                                                'text-(--color-danger)' => str_starts_with($widget->delta, '-'),
                                            ])>{{ str_starts_with($widget->delta, '-') ? '▼' : '▲' }} {{ ltrim($widget->delta, '+-') }}</span>
                                        @endif
                                        {{ $widget->hint }}
                                    </span>
                                @endif
                                @if (count($spark) > 1 && $peak > $floor)
                                    @php
                                        $step = 100 / (count($spark) - 1);
                                        $points = [];
                                        foreach ($spark as $i => $value) {
                                            $points[] = round($i * $step, 2).','.round(14 - (($value - $floor) / ($peak - $floor)) * 12, 2);
                                        }
                                    @endphp
                                    <svg viewBox="0 0 100 16" preserveAspectRatio="none" aria-hidden="true" class="mt-1 block h-4 w-full">
                                        <polyline points="{{ implode(' ', $points) }}" fill="none" stroke-width="1.5"
                                                  vector-effect="non-scaling-stroke" style="stroke: var(--color-chart-1)" />
                                    </svg>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        {{-- ── পাশাপাশি: ব্যতিক্রম কেন্দ্র আর সদ্য যা হয়েছে ── --}}
        <div data-home-unit="work" style="order: {{ $layout->position('work') }}">
            <div class="hm-work mb-4">
                @if ($todo->isNotEmpty() && $layout->shows('exceptions'))
                    <section data-exception-center class="min-w-0">
                        <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">{{ __('home.exceptions_title') }}</h2>
                        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) shadow-(--shadow-card)">
                            @foreach ($waiting as $widget)
                                @php $warn = $widget->tone === 'warn'; @endphp
                                <a href="{{ $widget->href }}" data-exception
                                   class="flex items-center gap-3 border-b border-(--color-border) px-4 py-2.5 transition-colors last:border-b-0 hover:bg-(--color-surface-hover)"
                                   style="border-inline-start: 4px solid {{ $warn ? 'var(--color-warning)' : 'var(--color-info)' }}">
                                    <x-ui.icon :name="$warn ? 'bell' : 'clock'" :size="15" class="shrink-0 text-(--color-ink-muted)" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-(--color-ink)">{{ $widget->label }}</span>
                                        @if ($widget->hint)
                                            <span class="block truncate text-2xs text-(--color-ink-muted)">{{ $widget->hint }}</span>
                                        @endif
                                    </span>
                                    <span class="tabular shrink-0 font-semibold text-(--color-ink)">{{ $widget->value }}</span>
                                    <x-ui.icon name="chevron_right" :size="15" class="shrink-0 text-(--color-ink-disabled) rtl:rotate-180" />
                                </a>
                            @endforeach
                            @if ($quiet->isNotEmpty())
                                <p class="flex items-center gap-2 px-4 py-2.5 text-sm text-(--color-ink-muted)">
                                    <x-ui.icon name="check_circle" :size="16" class="text-(--color-success)" />
                                    {{ trans_choice('core.dashboard.nothing_pending', $quiet->count(), ['count' => $quiet->count()]) }}
                                </p>
                            @endif
                        </div>
                    </section>
                @endif

                @if ($happenings !== [] && $layout->shows('happenings'))
                    <section class="min-w-0">
                        <h2 class="mb-2 text-sm font-semibold text-(--color-ink-muted)">{{ __('core.dashboard.just_happened') }}</h2>
                        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) shadow-(--shadow-card)">
                            @foreach (array_slice($happenings, 0, 6) as $happening)
                                <x-ui.drill :source="$happening->sourceType" :id="$happening->sourceId"
                                            class="flex items-center gap-3 border-b border-(--color-border) px-4 py-2.5
                                                   !text-inherit !no-underline transition-colors last:border-b-0 hover:bg-(--color-surface-hover)">
                                    <span @class([
                                        'grid size-8 shrink-0 place-items-center rounded-(--radius-field)',
                                        'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $happening->tone === 'good',
                                        'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => $happening->tone === 'warn',
                                        'bg-(--color-surface-app) text-(--color-brand-600)' => ! in_array($happening->tone, ['good', 'warn'], true),
                                    ])>
                                        <x-ui.icon :name="$happening->icon" :size="16" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium">{{ $happening->title }}</span>
                                        <span class="block truncate text-2xs text-(--color-ink-muted)">
                                            {{ $happening->subtitle }}
                                            @if ($happening->subtitle !== '') · @endif
                                            <span class="num">{{ $happening->when->format('H:i') }}</span>
                                        </span>
                                    </span>
                                    @if ($happening->isDrillable())
                                        <x-ui.icon name="chevron_right" :size="16" class="text-(--color-ink-disabled) rtl:rotate-180" />
                                    @endif
                                </x-ui.drill>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </div>{{-- data-home-units --}}

    @if ($nothing)
        @php
            $why = match (true) {
                $menu !== [] => __('core.dashboard.nothing_to_show'),
                ($roleLivesIn ?? []) !== [] => __('core.dashboard.role_lives_elsewhere', ['companies' => implode(', ', $roleLivesIn)]),
                default => __('core.dashboard.no_module_at_all'),
            };
        @endphp
        <x-ui.empty-state :message="$why" />
    @endif
</x-layouts.app>
