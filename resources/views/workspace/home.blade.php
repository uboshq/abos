{{--
    ⭐ হোম — নতুন রূপ (মালিকের পরিকল্পনা ২, ৫ অক্টোবর ২০২৬) — সব সাইটে, সুইচ ছাড়াই।

    মালিকের লাল দাগ: টাকার বাক্স আর "ব্যবসার চিত্র"-এর চার্ট — দুইটা থাকবে, বাকি সব নতুন।
    ক্রম (মালিকের কথায়): টাকার বাক্স → ৮টা চার্ট, ৪টা করে দুই সারি, জরুরিগুলো প্রথমে → ৮টা মূল সূচক →
    পাশাপাশি ব্যতিক্রম কেন্দ্র আর সদ্য যা হয়েছে।

    ⛔ একই সংখ্যা দুইবার নয়: টাকার বাক্সে যা, মূল সূচকে তা নয়; মূল সূচকে যা, ব্যতিক্রম কেন্দ্রে তা নয়
    (নাম মিলিয়ে বাদ, নিচে `$shown`)। পুরনো "গোটা ব্যবসা" সারি আর সব-চার্ট অংশ এখানে নেই।
    ⓘ সুইচ abos.dashboards_v2 কেবল মডিউলের ড্যাশবোর্ড বাঁধে; হোম দুই অবস্থাতেই এটাই।

    ⭐ ১৮ নভেম্বর ২০২৬ থেকে ক্রম (মালিক, নীল প্যানেলের নমুনা): মাথা → ৮টা চার্ট (শুরুর পর্দাতেই) →
    ৮টা সূচক আর "হাতে ও ব্যাংকে মোট" → আজ যা করতে হবে → পাশাপাশি নজরে রাখুন আর সদ্য যা হয়েছে। ⓘ কিছু বাদ যায়নি, কেবল জায়গা বদল।
    ⓘ Copilot-এর এক লাইন পাতায় নেই — মালিকের পরের সিদ্ধান্ত (১৮ নভেম্বর ২০২৬): নোটিশের ফিতা আর এই লাইন সারাদিন জায়গা খায়;
    কথাটা ([[CopilotLine]]) সফটওয়্যার খোলার পপআপে যাবে।
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
    /*
     * ⭐ "মজুদের মূল্য" "বাজারে বকেয়া"-র পাশে, টাকার বাক্সে (মালিক, fe-র মারফত, ২১ অক্টোবর ২০২৬: মালিক আর হিসাবরক্ষক এক নজরে
     * চলতি পুঁজি দেখবেন — IAS 1-এর চলতি সম্পদ)। ⓘ বকেয়ার হুবহু পথ: মূল সূচকের সেই ঘরটাই সরে আসে ([[InventoryWidgets]] →
     * [[StockFacts::value()]], মজুদের খাত ১১২০-এর জের, কেনা দরে, হেডারে বাছা শাখা মেনে), একই চাবি (inventory.cost.view) —
     * চাবি না থাকলে ঘরটাই নেই, বিক্রয়কর্মীর হোমেও নেই। ⛔ বাক্সে উঠলে সূচকের সারি থেকে বাদ — একই সংখ্যা দুই জায়গায় নয়।
     */
    $owed = null;
    $stockValue = null;
    if ($position) {
        foreach ($kpis as $k => $candidate) {
            if ($candidate->label === __('customer::dashboard.kpi_owed')) {
                $owed = $candidate;
                unset($kpis[$k]);
            } elseif ($candidate->label === __('inventory::overview.stock_value')) {
                $stockValue = $candidate;
                unset($kpis[$k]);
            }
        }
        $kpis = array_values($kpis);
    }

    // ⛔ পাতায় যা আগেই আছে তার নাম — ব্যতিক্রম কেন্দ্রে আবার নয়
    $shown = array_map(fn ($w) => $w->label, $kpis);
    if ($position) {
        $shown[] = $position->label;
    }
    if ($owed) {
        $shown[] = $owed->label;
    }
    if ($stockValue) {
        $shown[] = $stockValue->label;
    }

    $todo = collect($groups['todo'] ?? [])->reject(fn ($w) => in_array($w->label, $shown, true));
    $waiting = $todo->filter($pending)->values();
    // ⓘ প্রথম চারটা মাথার "আজ যা করতে হবে"-তে, বাকিগুলো নিচের তালিকায় — একই ঘর দুইবার নয়
    $todayWork = $waiting->take(4);
    $waiting = $waiting->slice(4)->values();
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
            @php
                $hour = (int) now()->format('G');
                $greet = __('auth.greeting.'.($hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening')));
            @endphp
            {{-- ⭐ নাম ধরে সম্ভাষণ (মালিকের অনুমোদিত নমুনা, ১ নভেম্বর ২০২৬); পাতার নাম "ড্যাশবোর্ড" ট্যাবে আর পথে থাকে --}}
            <h1 data-home-title class="truncate text-xl font-bold" style="line-height: 1.75rem">{{ $greet }}, {{ auth()->user()->name }}</h1>
            @php
                $headUser = auth()->user();
                $headDate = strtr(now()->locale(app()->getLocale())->translatedFormat('l, j F Y · g:i A'),
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

                {{-- সময় — আজ · এ মাস · এ বছর, পাশাপাশি তিনটা ট্যাব (মালিকের অনুমোদিত নমুনা, ১ নভেম্বর ২০২৬); বদলায় কেবল প্রবাহের সূচক ([[HomePeriod]]) --}}
                <nav data-period-menu aria-label="{{ __('home.period') }}"
                     class="flex items-center gap-1 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) p-[3px]">
                    @foreach (\App\Core\Dashboard\Widget::PERIODS as $option)
                        <a href="{{ route('dashboard', ['period' => $option] + $filter->query()) }}"
                           @class([
                               'rounded-[7px] px-3 py-1 text-sm font-semibold',
                               'bg-(--color-brand-600) text-white' => $period === $option,
                               'text-(--color-ink-muted) hover:bg-(--color-surface-hover)' => $period !== $option,
                           ])
                           @if ($period === $option) aria-current="page" @endif>{{ $titles[$option] }}</a>
                    @endforeach
                </nav>
                @if (\Illuminate\Support\Facades\Route::has('executive.today') && auth()->user()->can('executive.view'))
                    <a href="{{ route('executive.today') }}" data-home-executive class="whitespace-nowrap text-sm font-semibold text-(--color-link)">Executive Command Center →</a>
                @endif
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
    </section>
    </x-slot:header>

    @php
        /*
         * ⭐ মূল সূচক আর টাকার বাক্স — এখন চার্টের নিচে (মালিক, ১৮ নভেম্বর ২০২৬: "সব গ্রাফ একদম উপরে, শুরুর পর্দাতেই"; নীল
         * প্যানেলের নমুনা)। ⓘ ঘর, উৎস, চাবি, ক্রম সব আগের মতো — কেবল জায়গা বদল: মাথা থেকে সাজানো যায় এমন `kpis` ভাগে।
         * ⓘ "বাজারে বকেয়া" আর "মজুদের মূল্য" সূচক-কার্ডে, নমুনার তৃতীয় আর ষষ্ঠ ঘরে — একই উৎস, একই চাবি, একবারই।
         */
        $cards = $layout->shows('kpis') ? $kpis : [];
        if ($owed) {
            array_splice($cards, min(2, count($cards)), 0, [$owed]);
        }
        if ($stockValue) {
            array_splice($cards, min(5, count($cards)), 0, [$stockValue]);
        }
        $cardTones = ['blue', 'orange', 'purple', 'red', 'green', 'teal', 'pink', 'amber'];

        // ⓘ লেখা অঙ্ক থেকে সংখ্যা — টাকার বাক্সের ডোনাটের ভাগ আর মাঝের মোট (নমুনা: "22.30 লাখ টাকা"); পুরো অঙ্ক পাশে আর `title`-এ
        $amountOf = fn (string $text): float => (float) preg_replace('/[^0-9.\-]/', '', strtr($text, $digits));

        // ⭐ আজ যা করতে হবে — রং অর্থ ধরে, ক্রম ধরে নয়: জরুরি · আজই · এ সপ্তাহে ([[WorkLevel]]; মালিক, ১৮ নভেম্বর ২০২৬)
        $levelOf = fn ($widget): string => \App\Core\Dashboard\WorkLevel::of($widget);
    @endphp

    <style @nonce>
        .hm-pictures { display: grid; gap: .75rem; grid-template-columns: minmax(0, 1fr); }
        .hm-work { display: grid; gap: .75rem; grid-template-columns: minmax(0, 1fr); }
        .hm-top { display: grid; gap: .6rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
        .hm-cards { display: grid; gap: .5rem; grid-template-columns: repeat(2, minmax(0, 1fr)); order: 1; }
        .hm-money { order: 2; }
        .hm-today-items { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
        /* ⓘ রূপের রং কার্ডের নিজের ঘরে (`--hm-tone`) — রূপ চাইলে দাগটা বদলায় বা তোলে ([[blue-look.css]]) */
        .hm-picture { border-top: 4px solid var(--hm-tone); }
        .hm-kpi { border-inline-start: 4px solid var(--hm-tone); }
        .hm-kpi-spark-fill { fill: var(--hm-tone); opacity: .12; }
        .hm-kpi-spark-line { stroke: var(--hm-tone); }
        .hm-donut { width: 6rem; height: 6rem; flex: none; }
        .hm-avatar { display: none; }
        @media (min-width: 768px) {
            .hm-pictures { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .hm-cards { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (min-width: 1100px) { .hm-top { grid-template-columns: minmax(0, 1fr) 400px; } }
        @media (min-width: 1280px) {
            /* ⭐ ৪টা করে দুই সারি (মালিক, ৫ অক্টোবর ২০২৬) — চার্ট বড়, মান পরিষ্কার */
            .hm-pictures { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .hm-work { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        /* ⓘ মান কখনো কাটা নয় (truncate নয়) — লম্বা হলে ভাঙে ([[TheHomeShowsOnlyYourOwnWorkTest]]) */
        .hm-kpi-value { line-height: 1.3; }
    </style>


    {{-- ⭐ সাজানো যায় এমন তিন ভাগ — ক্রম CSS `order`-এ, লুকানো অংশ আঁকাই হয় না ([[HomeLayout]])।
         ⭐ ডিফল্ট ক্রম মালিকের (১৮ নভেম্বর ২০২৬): ৮টা চার্ট → ৮টা সূচক আর টাকার বাক্স → আজ যা করতে হবে → নজরে রাখুন ও সদ্য যা
         হয়েছে। ⓘ সব রূপেই — পাতা রূপ চেনে না ([[EveryScreenObeysTheThemeTest]]); যিনি নিজে ক্রম সাজিয়েছেন তাঁর ক্রমই থাকে। --}}
    <div data-home-units style="display: flex; flex-direction: column">

        {{-- ── ব্যবসার চিত্র — ৮টা চার্ট, ৪টা করে দুই সারি, শুরুর পর্দাতেই ── --}}
        <div data-home-unit="pictures" style="order: {{ $layout->position('pictures') }}">
            @if ($pictures->isNotEmpty() && $layout->shows('pictures'))
                <section data-business-pictures class="mb-3">
                    {{-- ⓘ আলাদা শিরোনাম নেই — প্রতিটা কার্ড নিজের নাম বলে; জায়গাটা এক পর্দায় সব ধরাতে লাগে --}}
                    <div data-pictures-row class="hm-pictures" aria-label="{{ __('home.pictures_title') }}">
                        @foreach ($pictures as $row)
                            {{-- ⭐ প্রতিটা চার্ট-কার্ডের মাথায় নিজের রঙের দাগ — রঙিন ড্যাশবোর্ড (মালিক, ১ নভেম্বর ২০২৬); নীল প্যানেলে দাগ নেই --}}
                            <a href="{{ route('module.dashboard', ['module' => $row['module']]) }}" data-boxed data-picture="{{ $row['module'] }}"
                               style="--hm-tone: var(--color-pal-{{ ['blue', 'orange', 'red', 'teal', 'indigo', 'green', 'purple', 'sky'][$loop->index % 8] }})"
                               class="hm-picture block min-w-0 rounded-(--radius-card) border border-(--color-border)
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
                                            {{-- ⓘ ঘরটা লেখা বা HTML (অনুমোদনের তালিকা ইনবক্সের `what` অংশ দেয় — নাম, নম্বর · পক্ষ, কারণ): লেখায় যেমন আছে,
                                                 আর `title`-এ কেবল সাদা লেখা — ⛔ নইলে ট্যাগসুদ্ধ HTML গুণের ভেতরে পড়ে মার্কআপ ভাঙত (a5-এর তালিকা মেশানোয়, ২১ অক্টোবর ২০২৬) --}}
                                            @php
                                                $cell = ($firstCol['render'])($listRow);
                                                $cellText = trim((string) preg_replace('/\s+/u', ' ', strip_tags($cell instanceof \Illuminate\Contracts\Support\Htmlable ? $cell->toHtml() : (string) $cell)));
                                            @endphp
                                            <div data-home-listing-row class="flex min-w-0 items-center justify-between gap-2 border-b border-(--color-border) py-1.5 text-sm last:border-b-0">
                                                {{-- ⓘ আদ্যক্ষরের গোল ছাপ — কেবল যে রূপ চায় দেখায় (নীল প্যানেলের নমুনা, ১৮ নভেম্বর ২০২৬; [[blue-look.css]]); লেখার প্রথম দুই অক্ষর --}}
                                                <span class="hm-avatar" aria-hidden="true" style="background: var(--color-chart-{{ [1, 3, 6, 4][$loop->index % 4] }})">{{ mb_substr($cellText, 0, 2) }}</span>
                                                {{-- ⓘ কাটা নয়, দুই লাইন পর্যন্ত — "নম্বর · নাম · পয়েন্ট · শাখা" পুরোটা পড়া যায় (মালিকের স্থায়ী নিয়ম; fe-র ছবি-যাচাই) --}}
                                                <span class="line-clamp-2 min-w-0 flex-1 break-words leading-snug text-(--color-ink-body)" title="{{ $cellText }}">{{ $cell }}</span>
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

        {{-- ── মূল সূচক আর টাকার বাক্স — চার্টের নিচে (মালিক, ১৮ নভেম্বর ২০২৬); বাঁয়ে ৮টা সূচক (চারটা করে দুই সারি), ডানে টাকার বাক্স ──
             ⓘ DOM-এ টাকার বাক্স আগে, ছবিতে ডানে — CSS `order`। --}}
        <div data-home-unit="kpis" style="order: {{ $layout->position('kpis') }}">
            @if ($position || $cards !== [])
            <div data-home-top class="hm-top mb-3">
                @if ($position)
                    @php
                        $partSum = array_sum(array_map(fn ($v) => max(0.0, $amountOf((string) $v)), $position->parts)) ?: 1.0;
                        $total = abs($amountOf((string) $position->value));
                        [$centre, $centreUnit] = match (true) {
                            $total >= 10000000 => [number_format($total / 10000000, 2), __('home.crore').' '.__('core.dashboard.taka')],
                            $total >= 100000 => [number_format($total / 100000, 2), __('home.lakh').' '.__('core.dashboard.taka')],
                            default => [(string) $position->value, __('core.dashboard.taka')],
                        };
                        $ring = 2 * M_PI * 46;
                        $drawn = 0.0;
                    @endphp
                    {{-- ⭐ হাতে ও ব্যাংকে মোট — ডোনাট আর পাশে চার ভাগের পুরো অঙ্ক (নীল প্যানেলের নমুনা, ১৮ নভেম্বর ২০২৬); মাঝে মোট,
                         পুরো অঙ্ক `title`-এ আর পর্দা-পাঠকে। ⓘ ভাগের রং চার্টের রং ([[x-dashboard.chart]]-এর একই টোকেন) — নীল প্যানেলে নীলের পরিবার। --}}
                    <div data-money-position class="hm-money relative min-w-0 rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card) px-4 py-3 text-(--color-ink) shadow-(--shadow-card)">
                        <a href="{{ $position->href }}" data-money-door class="absolute inset-0 rounded-(--radius-card)"
                           aria-label="{{ $position->label }}" @if ($position->definition) title="{{ $position->definition }}" @endif></a>
                        <p class="flex items-center justify-between gap-2 text-xs text-(--color-ink-muted)">
                            <span class="flex items-center gap-1.5 text-sm font-semibold text-(--color-ink)">
                                @if ($position->icon)
                                    <x-ui.icon :name="$position->icon" :size="14" />
                                @endif
                                {{ $position->label }}
                            </span>
                            <span>{{ __('home.money_as_booked') }}</span>
                        </p>
                        <div class="mt-2 flex items-center gap-3">
                            <svg viewBox="0 0 120 120" class="hm-donut" role="img" aria-label="{{ $position->label }}: {{ $position->value }}">
                                <title>{{ $position->label }}: {{ $position->value }}</title>
                                <circle cx="60" cy="60" r="46" fill="none" stroke-width="14" style="stroke: var(--color-border)" />
                                @foreach ($position->parts as $partValue)
                                    @php
                                        $share = max(0.0, $amountOf((string) $partValue)) / $partSum * $ring;
                                    @endphp
                                    @if ($share > 0)
                                        <circle cx="60" cy="60" r="46" fill="none" stroke-width="14" transform="rotate(-90 60 60)"
                                                stroke-dasharray="{{ round($share, 2) }} {{ round($ring - $share, 2) }}" stroke-dashoffset="{{ round(-$drawn, 2) }}"
                                                style="stroke: var(--color-chart-{{ $loop->index % 8 + 1 }})" />
                                    @endif
                                    @php $drawn += $share; @endphp
                                @endforeach
                                <text x="60" y="58" text-anchor="middle" class="num" style="fill: var(--color-ink); font-weight: 600; font-size: 15px">{{ $centre }}</text>
                                <text x="60" y="74" text-anchor="middle" style="fill: var(--color-ink-muted); font-size: 11px">{{ $centreUnit }}</text>
                            </svg>
                            <div data-money-parts class="grid min-w-0 flex-1 gap-1.5 text-sm">
                                @foreach ($position->parts as $partLabel => $partValue)
                                    <div class="flex min-w-0 items-center gap-2" title="{{ $partValue }}">
                                        <i class="size-2.5 shrink-0 rounded-full" style="background: var(--color-chart-{{ $loop->index % 8 + 1 }})"></i>
                                        <span class="min-w-0 truncate">{{ $partLabel }}</span>
                                        <b class="num ms-auto whitespace-nowrap font-semibold">{{ $partValue }}</b>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        @if ($position->tiles !== [])
                            <div data-money-today class="mt-2 grid grid-cols-1 gap-1.5 rounded-[14px] bg-(--color-surface-muted) px-3 py-1.5 sm:grid-cols-2">
                                @foreach ($position->tiles as $heroTile)
                                    <div data-money-tile="{{ $heroTile['tone'] }}" class="flex min-w-0 items-center justify-between gap-2 text-xs">
                                        <span class="truncate">{{ $heroTile['label'] }}</span>
                                        <span data-money-tile-value class="tabular whitespace-nowrap text-sm font-bold"
                                              @if ($heroTile['tone'] === 'in') style="color: var(--color-success)" @elseif ($heroTile['tone'] === 'out') style="color: var(--color-danger)" @endif>{{ $heroTile['sign'] !== '' ? $heroTile['sign'].' ' : '' }}{{ $heroTile['value'] }}</span>
                                        @if (filled($heroTile['hint'] ?? null))
                                            <span data-money-tile-hint class="sr-only">{{ $heroTile['hint'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @if ($cards !== [])
                    <div data-kpis class="hm-cards">
                        @foreach ($cards as $widget)
                            @php
                                $tone = $cardTones[$loop->index % count($cardTones)];
                                $spark = array_map('floatval', $widget->spark);
                                $peak = $spark === [] ? 0.0 : max($spark);
                                $floor = $spark === [] ? 0.0 : min($spark);
                            @endphp
                            <a href="{{ $widget->href }}" data-kpi data-boxed
                               @if ($widget === $owed) data-home-owed @endif @if ($widget === $stockValue) data-home-stock @endif
                               class="hm-kpi relative block min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-2.5 py-1.5
                                      shadow-(--shadow-card) transition-colors hover:bg-(--color-surface-hover)"
                               style="--hm-tone: var(--color-pal-{{ $tone }})"
                               @if ($widget->definition) title="{{ $widget->definition }}" @endif>
                                <span data-kpi-label class="block truncate text-xs font-semibold text-(--color-ink-muted)" title="{{ $widget->label }}">{{ $widget->label }}</span>
                                <span data-kpi-value @class([
                                    'hm-kpi-value tabular block whitespace-nowrap font-bold',
                                    'text-lg' => mb_strlen($widget->value) <= 12,
                                    'text-base' => mb_strlen($widget->value) > 12 && mb_strlen($widget->value) <= 14,
                                    'text-sm' => mb_strlen($widget->value) > 14,
                                    'text-(--color-badge-warning-ink)' => $widget->tone === 'warn' && $pending($widget),
                                    'text-(--color-ink)' => ! ($widget->tone === 'warn' && $pending($widget)),
                                ])>{{ $widget->value }}</span>
                                @if ($widget->delta || $widget->hint)
                                    <span data-kpi-sub class="block truncate text-2xs text-(--color-ink-muted)">
                                        @if ($widget->delta)
                                            <span @class([
                                                'font-semibold',
                                                'text-(--color-success)' => ! str_starts_with($widget->delta, '-'),
                                                'text-(--color-danger)' => str_starts_with($widget->delta, '-'),
                                            ])>{{ str_starts_with($widget->delta, '-') ? '▼' : '▲' }} {{ ltrim($widget->delta, '+-') }}</span>
                                        @endif
                                        <span data-kpi-hint>{{ $widget->hint }}</span>
                                    </span>
                                @endif
                                @if (count($spark) > 1 && $peak > $floor)
                                    @php
                                        $step = 100 / (count($spark) - 1);
                                        $points = [];
                                        foreach ($spark as $i => $value) {
                                            $points[] = round($i * $step, 2).','.round(26 - (($value - $floor) / ($peak - $floor)) * 22, 2);
                                        }
                                    @endphp
                                    <svg viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true" class="hm-kpi-spark mt-0.5 block h-5 w-full">
                                        <polygon points="0,28 {{ implode(' ', $points) }} 100,28" class="hm-kpi-spark-fill" />
                                        <polyline points="{{ implode(' ', $points) }}" fill="none" stroke-width="1.6"
                                                  vector-effect="non-scaling-stroke" class="hm-kpi-spark-line" />
                                    </svg>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
            @endif
        </div>

        {{-- ── কাজ: আজ যা করতে হবে, তার নিচে পাশাপাশি নজরে রাখুন আর সদ্য যা হয়েছে ── --}}
        <div data-home-unit="work" style="order: {{ $layout->position('work') }}">
            {{-- ⭐ আজ যা করতে হবে — অপেক্ষার প্রথম চারটা, বড় অঙ্কে, প্রতিটার পাশে অবস্থার শব্দ; বাকিগুলো নিচে "নজরে রাখুন"-এ —
                 ⛔ একই ঘর দুই জায়গায় নয় --}}
            @if ($todayWork->isNotEmpty() && $layout->shows('exceptions'))
                <section data-home-today-work class="mb-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 pb-3 pt-2.5 shadow-(--shadow-card)">
                    <h2 class="mb-2 flex items-center justify-between gap-2 text-sm font-semibold text-(--color-ink)">
                        {{ __('home.today_work') }}
                        <x-ui.status-chip level="info">{{ __('home.today_work_count', ['count' => $todayWork->count()]) }}</x-ui.status-chip>
                    </h2>
                    <div class="hm-today-items">
                        @foreach ($todayWork as $widget)
                            @php $level = $levelOf($widget); @endphp
                            <a href="{{ $widget->href }}" data-today-work data-level="{{ $level }}"
                               class="grid min-w-0 grid-cols-[auto_1fr_auto] items-center gap-x-2.5 rounded-[18px] px-3 py-2"
                               style="background: var(--color-status-{{ $level }}-soft)">
                                <b class="tabular row-span-2 whitespace-nowrap text-xl font-bold" style="color: var(--color-status-{{ $level }})">{{ $widget->value }}</b>
                                <span class="line-clamp-2 text-sm font-semibold leading-snug text-(--color-ink)" title="{{ $widget->label }}">{{ $widget->label }}</span>
                                <x-ui.status-chip :level="$level" class="row-span-2" />
                                <small class="truncate text-2xs text-(--color-ink-muted)">{{ $widget->hint }}</small>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <div class="hm-work mb-4">
                @if (($waiting->isNotEmpty() || $quiet->isNotEmpty()) && $layout->shows('exceptions'))
                    <section data-exception-center data-boxed class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) shadow-(--shadow-card)">
                        <h2 class="px-4 pt-3 pb-1 text-sm font-semibold text-(--color-ink)">{{ __('home.watch_title') }}</h2>
                        @foreach ($waiting as $widget)
                            @php $warn = $widget->tone === 'warn'; @endphp
                            <a href="{{ $widget->href }}" data-exception
                               class="flex items-center gap-3 border-b border-(--color-border) px-4 py-2.5 transition-colors last:border-b-0 hover:bg-(--color-surface-hover)"
                               style="border-inline-start: 4px solid {{ $warn ? 'var(--color-status-week-bar)' : 'var(--color-info)' }}">
                                <x-ui.icon :name="$warn ? 'bell' : 'clock'" :size="15" class="shrink-0 text-(--color-ink-muted)" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-(--color-ink)">{{ $widget->label }}</span>
                                    @if ($widget->hint)
                                        <span class="block truncate text-2xs text-(--color-ink-muted)">{{ $widget->hint }}</span>
                                    @endif
                                </span>
                                <span class="tabular shrink-0 font-semibold text-(--color-ink)">{{ $widget->value }}</span>
                                @if ($warn)
                                    <x-ui.status-chip level="week" class="shrink-0" />
                                @endif
                                <x-ui.icon name="chevron_right" :size="15" class="shrink-0 text-(--color-ink-disabled) rtl:rotate-180" />
                            </a>
                        @endforeach
                        @if ($quiet->isNotEmpty())
                            <p data-home-quiet class="flex items-center gap-2 px-4 py-2.5 text-sm text-(--color-status-ok)">
                                <x-ui.icon name="check_circle" :size="16" />
                                {{ trans_choice('core.dashboard.nothing_pending', $quiet->count(), ['count' => $quiet->count()]) }}
                                <x-ui.status-chip level="ok" />
                            </p>
                        @endif
                    </section>
                @endif

                @if ($happenings !== [] && $layout->shows('happenings'))
                    <section data-home-happenings data-boxed class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) shadow-(--shadow-card)">
                        <h2 class="px-4 pt-3 pb-1 text-sm font-semibold text-(--color-ink)">{{ __('core.dashboard.just_happened') }}</h2>
                        @foreach (array_slice($happenings, 0, 6) as $happening)
                            <x-ui.drill :source="$happening->sourceType" :id="$happening->sourceId"
                                        class="flex items-center gap-3 border-b border-(--color-border) px-4 py-2.5
                                               !text-inherit !no-underline transition-colors last:border-b-0 hover:bg-(--color-surface-hover)">
                                <span @class([
                                    'grid size-8 shrink-0 place-items-center rounded-full',
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
