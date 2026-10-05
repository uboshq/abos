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

    // ⛔ পাতায় যা আগেই আছে তার নাম — ব্যতিক্রম কেন্দ্রে আবার নয়
    $shown = array_map(fn ($w) => $w->label, $kpis);
    if ($position) {
        $shown[] = $position->label;
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

    <x-slot:header>
        <x-ui.page-header
            :title="__('core.menu.dashboard')"
            :subtitle="auth()->user()?->currentCompany?->name()
                . (auth()->user()?->currentBranch ? ' · ' . auth()->user()->currentBranch->name() : '')">
            <x-slot:actions>
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
                                        class="mt-1 h-9 w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm text-(--color-ink)">
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
                    <summary class="flex h-10 cursor-pointer list-none items-center gap-2 rounded-(--radius-field)
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
                                           class="num h-8 w-14 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-end text-sm">
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
                    <summary class="flex h-10 cursor-pointer list-none items-center gap-2 rounded-(--radius-field)
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
            </x-slot:actions>
        </x-ui.page-header>
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

    {{-- ── মাথা: বাঁয়ে চালু ফিল্টারের লাইন, ডানে টাকার বাক্স (থাকছে হুবহু) ── --}}
    <section data-command-head class="mb-3 flex flex-col gap-3 lg:flex-row" style="justify-content: space-between; align-items: flex-end">
        <div class="min-w-0 flex-1">
            {{-- ⓘ আজকের তারিখ আর দেখার শাখা — টাকার বাক্সের পাশের জায়গাটা খালি না থাকে --}}
            <p class="text-sm text-(--color-ink-muted)">{{ now()->locale(app()->getLocale())->translatedFormat('l, j F Y') }}</p>
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
               style="background: linear-gradient(135deg, var(--color-brand-700), var(--color-brand-900)); max-width: 40rem"
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
            </a>
        @endif
    </section>

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
                                        <span class="block truncate text-sm font-semibold text-(--color-ink)">{{ $row['name'] }}</span>
                                        <span class="block truncate text-2xs text-(--color-ink-muted)">{{ $row['panel']->label }}</span>
                                    </span>
                                    <span class="shrink-0 text-xs text-(--color-link)">{{ __('home.details') }} →</span>
                                </div>
                                <x-dashboard.chart :panel="$row['panel']" compact />
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
                                <span @class([
                                    'hm-kpi-value tabular mt-1 break-words text-lg block font-semibold',
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
                                                  vector-effect="non-scaling-stroke" style="stroke: var(--color-chart-1, #2563eb)" />
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
