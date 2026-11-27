{{--
    ⭐ হোম — মালিকের অনুমোদিত নমুনা, ১৯ নভেম্বর ২০২৬ (claude.ai artifact AAjAfjZ8TnCfnjtt3Kthjz v11; ছবি ২৩: "নিজ দিয়ে দেওয়ার কথা ছিল")।

    ক্রম, গ্রাফ আগে (মালিকের নিয়ম):
      ১ মাথা — সম্ভাষণ, ছাঁকনি (শাখা · গুদাম · এলাকা), আজ / এই মাস / এই অর্থবছর, এক্সিকিউটিভ কমান্ড সেন্টার
      ২ ৮টা রঙিন গ্রাফ — বিক্রির ধারা, বিক্রি বনাম আদায়, বাকির বয়স, ক্যাটাগরি ধরে স্টক, শীর্ষ ১০ পণ্য, শীর্ষ ১০ দোকান,
        মাধ্যম ধরে আদায়, খাটানো মূলধন ([[HomeBoard]], [[x-dashboard.board-chart]])
      ৩ ৮টা ঘর (রঙের দাগ, ছোট রেখা, "গত মাসের একই দিন পর্যন্ত") আর পাশে বড় টাকার বাক্স; তার নিচে মডিউলের বাকি সূচক
      ৪ প্রিন্সিপালকে দেনা · Depot360 কমিশন · পয়েন্ট ধরে বকেয়া
      ৫ আপনার সব প্রতিষ্ঠান
      ৬ আজ যা করতে হবে (৪টা, অর্থের চিপসহ)
      ৭ নজরে রাখুন, সদ্য যা হয়েছে; একদম শেষে মডিউলের চিত্র (আগের ৮টা মডিউল-কার্ড — বাদ নয়, a-redesign-must-not-drop-old-figures)

    ⛔ একই সংখ্যা দুইবার নয়: বোর্ডের ঘরে যা (বিক্রি, আদায়, বাজারে বাকি, মজুদের মূল্য), মডিউলের সূচকে তা আবার নয় — একই উৎস,
       একই ঠিকানা; মডিউলের বাকি সূচক (ক্রয়, অনুমোদন, দেনা …) "আরও সূচক"-এ থাকে।
    ⓘ পাতা রূপ চেনে না ([[EveryScreenObeysTheThemeTest]]) — গড়ন resources/css/home-board.css-এ, সব রূপে এক।
--}}
@php
    $titles = [
        'today' => __('core.dashboard.today'),
        'month' => __('core.dashboard.this_month'),
        'year' => __('home.board.this_fy'),
    ];

    $board = $board ?? ['charts' => [], 'cards' => [], 'lists' => [], 'companies' => null, 'todo' => []];

    $digits = ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9'];
    $pending = fn ($widget) => (int) preg_replace('/\D/', '', strtr($widget->value, $digits)) > 0;

    // ── টাকার বাক্স — ভাগওয়ালা টাকার ঘরটাই (হাতে নগদ · MFS · ব্যাংক · পথে); থাকছে হুবহু, কেবল নমুনার সাজে ──
    $position = null;
    foreach (\App\Core\Dashboard\Widget::PERIODS as $positionGroup) {
        foreach ($groups[$positionGroup] ?? [] as $candidate) {
            if ($candidate->tone === 'money' && $candidate->parts !== []) {
                $position = $candidate;
                break 2;
            }
        }
    }

    // ── মডিউলের মূল সূচক — বোর্ডের ঘরে যা আছে তা বাদে (একই ঠিকানা = একই সংখ্যা) ──
    $boardKeys = array_column($board['cards'], 'key');
    $pathOf = fn (?string $url): string => rtrim((string) parse_url((string) $url, PHP_URL_PATH), '/');
    $boardPaths = array_values(array_filter(array_map(fn (array $t) => $pathOf($t['href'] ?? ''), $board['cards'])));

    /*
     * ⭐ "বাজারে বকেয়া" আর "মজুদের মূল্য" — মডিউলের নিজের ঘরই বোর্ডের তৃতীয় আর ষষ্ঠ ঘরে বসে (একই উৎস [[CustomerMetrics::dues()]] /
     * [[StockFacts::value()]], একই চাবি, খাতার পয়সা পর্যন্ত); বোর্ডের নিজের ঘর কেবল তখন, যখন মডিউলের ঘর নেই। একবারই।
     */
    $owed = null;
    $stockValue = null;
    // ⭐ "নিট বিক্রি" — বোর্ডের বিক্রির ঘরে মডিউলের নিজের ঘর (নাম, পয়সাসহ অঙ্ক, খুললে বিল · ফেরত · VAT সহ মোট); আগে একই ঠিকানা বলে
    //   বাদ পড়ত আর ভাঙা হিসাব হারাত। বকেয়া আর মজুদের একই নিয়ম।
    $netSales = null;
    // ⓘ বাছা সময় ধরে মডিউলের ঘরের নাম বদলায় (আজ · এই মাস · এই বছর) — তিনটাই সেই একই "নিট বিক্রি" ঘর; কেবল "আজ"-এ মেলালে মাস/বছরে
    //   ঘরটা আবার বোর্ডের ঘরে ফিরত আর মডিউলের ভাঙা হিসাব হারাত (Mac run of e88be43a3)
    $netSalesLabels = [__('sales::dashboard.sales_today'), __('sales::dashboard.sales_this_month'), __('sales::dashboard.sales_this_year')];
    $cards = [];
    foreach ($groups['kpi'] ?? [] as $candidate) {
        if (in_array($candidate->label, $netSalesLabels, true)) {
            $netSales = $candidate;
        } elseif ($candidate->label === __('customer::dashboard.kpi_owed')) {
            $owed = $candidate;
        } elseif ($candidate->label === __('inventory::overview.stock_value')) {
            $stockValue = $candidate;
        } elseif (! in_array($pathOf($candidate->href), $boardPaths, true)) {
            $cards[] = $candidate;
        }
    }

    // ঘরের সারি — বোর্ডের ঘর, বকেয়া আর মজুদের জায়গায় মডিউলের ঘর (`widget`), না থাকলে বোর্ডেরটাই
    $tiles = [];
    foreach ($board['cards'] as $tile) {
        if ($tile['key'] === 'dues' && $owed) {
            $tile['widget'] = $owed;
        } elseif ($tile['key'] === 'stock' && $stockValue) {
            $tile['widget'] = $stockValue;
        } elseif ($tile['key'] === 'sales' && $netSales) {
            $tile['widget'] = $netSales;
        }
        $tiles[] = $tile;
    }
    // ⓘ বোর্ডে বিক্রির ঘর না থাকলে (চাবি নেই) মডিউলের ঘর "আরও সূচক"-এ — হারায় না
    if ($netSales && ! in_array('sales', $boardKeys, true)) {
        $cards[] = $netSales;
    }
    foreach ([2 => ['dues', $owed], 5 => ['stock', $stockValue]] as $at => [$key, $widget]) {
        if ($widget && ! in_array($key, $boardKeys, true)) {
            array_splice($tiles, min($at, count($tiles)), 0, [['key' => $key, 'widget' => $widget, 'tone' => $key === 'dues' ? 'var(--color-pal-purple)' : 'var(--color-pal-pink)',
                'href' => $widget->href, 'label' => $widget->label, 'value' => $widget->value, 'delta' => null, 'sub' => null, 'spark' => [], 'note' => null]]);
        }
    }

    // ⛔ পাতায় যা আগেই আছে তার নাম — নজরে রাখুন-এ আবার নয়
    $shown = array_map(fn ($w) => $w->label, $cards);
    if ($position) {
        $shown[] = $position->label;
    }

    $todo = collect($groups['todo'] ?? [])->reject(fn ($w) => in_array($w->label, $shown, true));
    $waiting = $todo->filter($pending)->values();
    // ⓘ বোর্ডের চারটা না থাকলে (চাবি নেই) আগের মতো মডিউলের প্রথম চারটা "আজ যা করতে হবে"-তে, বাকিগুলো নিচে — একই ঘর দুইবার নয়
    $boardTodo = $board['todo'];
    $todayWork = $boardTodo === [] ? $waiting->take(4) : collect();
    $waiting = $boardTodo === [] ? $waiting->slice(4)->values() : $waiting;
    $quiet = $todo->reject($pending);

    // ── মডিউলের চিত্র — আগের ৮টা মডিউল-কার্ড (config-এর ক্রমে), এখন পাতার শেষে ──
    $byModule = collect($overall ?? [])->keyBy('module');
    $pictures = collect(config('abos.home_pictures', []))
        ->map(fn (string $code) => $byModule->get($code))
        ->filter(fn ($row) => $row !== null && ($row['panel'] ?? null) !== null)
        ->take(8)
        ->values();

    $levelOf = fn ($widget): string => \App\Core\Dashboard\WorkLevel::of($widget);
    $tones = ['--color-pal-blue', '--color-pal-orange', '--color-pal-purple', '--color-pal-red', '--color-pal-green', '--color-pal-teal', '--color-pal-pink', '--color-pal-amber'];

    $nothing = $board['charts'] === [] && $tiles === [] && $cards === [] && $pictures->isEmpty() && $todo->isEmpty()
        && $position === null && $happenings === [] && $boardTodo === [];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.menu.dashboard') }}</x-slot:title>

    {{-- ⭐ মাথা এক সারিতে — সম্ভাষণ · "কোম্পানি · শাখা · অর্থবছর · তারিখ" · ডানে ছাঁকনি, সময়, এক্সিকিউটিভ কমান্ড সেন্টার --}}
    <x-slot:header>
    <section data-command-head class="flex flex-col gap-3" style="align-items: stretch">
        <div class="flex min-w-0 flex-col" style="gap: 0.375rem">
            <div data-home-headline class="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-2 xl:flex-nowrap">
            @php
                $hour = (int) now()->format('G');
                $greet = __('auth.greeting.'.($hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening')));
                $headUser = auth()->user();
                $headDate = strtr(now()->locale(app()->getLocale())->translatedFormat('j M Y'), $digits);
                $branchLabel = app(\App\Core\Services\ShellFacts::class)->branchLabel($headUser, $headUser->currentBranch);
                $year = $headUser->currentCompany?->currentFinancialYear();
                $headNote = implode(' · ', array_filter([
                    $headUser->currentCompany?->name(),
                    $branchLabel,
                    $year ? strtr((string) ($year->name ?? ''), $digits) : null,
                    $headDate,
                ]));
            @endphp
            {{-- ⓘ কোম্পানি · শাখা · বছর · তারিখের লাইন শিরোনামের ⓘ-তে (মালিক, ২৪ নভেম্বর ২০২৬: "কোনো পাতায় এই লাইন নয়"; ২৭ নভেম্বর
                 জরিপে ok) — লাইনটা `data-page-subtitle` ধরে লুকায় --}}
            <h1 data-page-title data-home-title class="shrink-0 truncate text-xl font-bold" style="line-height: 1.75rem">{{ $greet }}, {{ $headUser->name }}<x-ui.page-info :text="$headNote" /></h1>
            <p data-home-company data-page-subtitle class="min-w-0 truncate text-sm text-(--color-ink-muted)">{{ $headNote }}</p>

            <div data-home-controls class="ms-auto flex shrink-0 flex-wrap items-center gap-2 xl:flex-nowrap">
                {{-- ⭐ ছাঁকনি — শাখা (উপরের বারে বাছা; এখানে কেবল লেখা), গুদাম, এলাকা (আর SR); বদলায় বিক্রি আর বকেয়া ([[HomeFilter]]),
                     যে ঘরে খাটে না সে নিজেই বলে --}}
                <span class="hb-pill" data-home-branch>{{ __('home.board.filter_branch') }}: <b>{{ $branchLabel ?? __('home.board.filter_all') }}</b></span>
                <details data-home-filter class="relative">
                    <summary class="flex cursor-pointer list-none items-center gap-2">
                        @foreach (['warehouse' => 'warehouses', 'area' => 'areas'] as $key => $list)
                            <span @class(['hb-pill', 'is-on' => $filter->{$key} !== null]) data-home-filter-pill="{{ $key }}">
                                {{ __('home.board.filter_'.$key) }}:
                                <b>{{ $filter->{$key} !== null ? ($filterChoices[$list][$filter->{$key}] ?? '') : __('home.board.filter_all') }}</b> ▾
                            </span>
                        @endforeach
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
                    <summary class="hb-pill cursor-pointer list-none" aria-label="{{ __('home.layout') }}" title="{{ __('home.layout') }}">
                        <x-ui.icon name="settings" :size="14" class="text-(--color-ink-muted)" />
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

                {{-- সময় — আজ · এই মাস · এই অর্থবছর ([[HomePeriod]]) --}}
                <nav data-period-menu aria-label="{{ __('home.period') }}"
                     class="flex items-center gap-1 rounded-(--radius-pill) border border-(--color-border) bg-(--color-surface-card) p-[3px]">
                    @foreach (\App\Core\Dashboard\Widget::PERIODS as $option)
                        <a href="{{ route('dashboard', ['period' => $option] + $filter->query()) }}"
                           @class([
                               'rounded-(--radius-pill) px-3 py-1 text-sm font-semibold',
                               'bg-(--color-brand-600) text-white' => $period === $option,
                               'text-(--color-ink-muted) hover:bg-(--color-surface-hover)' => $period !== $option,
                           ])
                           @if ($period === $option) aria-current="page" @endif>{{ $titles[$option] }}</a>
                    @endforeach
                </nav>
                {{-- ⓘ "এক্সিকিউটিভ কমান্ড সেন্টার"-এর লিংক এখানে আর নয় — মালিক, ১৯ নভেম্বর ২০২৬: "এই লিংকটা দেওয়ার দরকার নাই …
                     আমার দরকার পড়লে আমি যাইতে পারবো"; ওটার জন্যই মাথার সারি দুই লাইনে ভাঙছিল --}}
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

    {{-- ⭐ সাজানো যায় এমন তিন ভাগ — ক্রম CSS `order`-এ, লুকানো অংশ আঁকাই হয় না ([[HomeLayout]]); ডিফল্ট: গ্রাফ ১, ঘর ২, কাজ ৩।
         ⓘ মডিউলের চিত্র সবসময় শেষে (ক্রম ৯) — নতুন গ্রাফের পরে আগের কার্ডগুলো, একই "ছবি" টিকে লুকায়-দেখায়। --}}
    <div data-home-units style="display: flex; flex-direction: column">

        {{-- ── ২ · ৮টা রঙিন গ্রাফ — শুরুর পর্দাতেই ── --}}
        <div data-home-unit="pictures" style="order: {{ $layout->position('pictures') }}">
            @if ($board['charts'] !== [] && $layout->shows('pictures'))
                <section data-home-board-charts class="hb-charts hb-section" aria-label="{{ __('home.pictures_title') }}">
                    @foreach ($board['charts'] as $chart)
                        <x-dashboard.board-chart :chart="$chart" />
                    @endforeach
                </section>
            @endif
        </div>

        {{-- ── ৩–৫ · ৮টা ঘর আর টাকার বাক্স; তারপর তিন তালিকা আর সব প্রতিষ্ঠান ── --}}
        <div data-home-unit="kpis" style="order: {{ $layout->position('kpis') }}">
            @if ($layout->shows('kpis') && ($tiles !== [] || $position || $cards !== []))
                <div class="hb-top hb-section">
                    {{-- ⓘ DOM-এ টাকার বাক্স আগে, ছবিতে ডানে (home-board.css-এর `order`) — ঘরের সারির পরে কোনো টাকার নাম আর আসে না --}}
                    @if ($position)
                        {{-- ⭐ নগদ, ব্যাংক ও MFS-এ আছে — আগের টাকার বাক্সই (একই ঘর, একই চাবি), নমুনার সাজে: বড় মোট, চার ভাগ, আজ এসেছে/গেছে --}}
                        <section data-money-position data-boxed class="hb-card hb-cash">
                            <a href="{{ $position->href }}" data-money-door class="absolute inset-0 rounded-(--radius-card)"
                               aria-label="{{ $position->label }}" @if ($position->definition) title="{{ $position->definition }}" @endif></a>
                            <p class="hb-h"><span><b>{{ $position->label }}</b></span><span>{{ __('home.money_as_booked') }}</span></p>
                            <div class="hb-v num" data-money-total>৳{{ $position->value }}</div>
                            <div data-money-parts class="hb-parts">
                                @foreach ($position->parts as $partLabel => $partValue)
                                    <div title="{{ $partValue }}" data-part="{{ $loop->iteration }}">{{ $partLabel }}<b>৳{{ $partValue }}</b><i></i></div>
                                @endforeach
                            </div>
                            @if ($position->tiles !== [])
                                <div data-money-today class="hb-io">
                                    @foreach ($position->tiles as $heroTile)
                                        <span data-money-tile="{{ $heroTile['tone'] }}">{{ $heroTile['label'] }}
                                            <span data-money-tile-value @class(['hb-in' => $heroTile['tone'] === 'in', 'hb-out' => $heroTile['tone'] === 'out'])>{{ $heroTile['sign'] !== '' ? $heroTile['sign'].' ' : '' }}৳{{ $heroTile['value'] }}</span>
                                            @if (filled($heroTile['hint'] ?? null))
                                                <span data-money-tile-hint class="sr-only">{{ $heroTile['hint'] }}</span>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    @endif
                    @if ($tiles !== [])
                        <div data-kpis class="hb-tiles">
                            @foreach ($tiles as $tile)
                                @php
                                    $spark = array_map('floatval', $tile['spark'] ?? []);
                                    $peak = $spark === [] ? 0.0 : max($spark);
                                    $floor = $spark === [] ? 0.0 : min($spark);
                                    $widget = $tile['widget'] ?? null;
                                @endphp
                                @if ($widget)
                                    {{-- ⓘ মডিউলের নিজের ঘর (বকেয়া · মজুদ) — নাম, অঙ্ক, ব্যাখ্যা হুবহু; বোর্ডের ছোট লেখা নিচে --}}
                                    <a href="{{ $widget->href }}" data-kpi data-boxed
                                       @if ($tile['key'] === 'dues') data-home-owed @elseif ($tile['key'] === 'sales') data-home-sales @else data-home-stock @endif
                                       class="hb-card hb-tile" style="--hb-tone: {{ $tile['tone'] }}"
                                       @if ($widget->definition) title="{{ $widget->definition }}" @endif>
                                        <span class="hb-lb" data-kpi-label>{{ $widget->label }}</span>
                                        <span class="hb-v">৳<span class="num" data-kpi-value>{{ $widget->value }}</span></span>
                                        @if ($widget->hint)
                                            <span class="hb-s"><span data-kpi-hint>{{ $widget->hint }}</span></span>
                                        @endif
                                        {{-- ⓘ বোর্ডের তুলনা (▲/▼ শতাংশ) আর তার লেখা একসাথে — লেখাটা ("গত মাসের একই দিন পর্যন্ত") শতাংশ ছাড়া একা বসলে কিছু বলে না --}}
                                        @if (filled($tile['sub'] ?? null) || ($tile['delta'] ?? null))
                                            <span class="hb-s" data-board-tile-sub>
                                                @if ($tile['delta'] ?? null)
                                                    <span class="{{ $tile['delta']['up'] ? 'hb-up' : 'hb-down' }}" data-board-delta>{{ $tile['delta']['up'] ? '▲' : '▼' }} {{ $tile['delta']['text'] }}</span>
                                                @endif
                                                {{ $tile['sub'] ?? '' }}
                                            </span>
                                        @endif
                                        {{-- ⓘ বোর্ডের ছোট রেখা থাকলে সেটাও (বিক্রির ঘরে) — কিছু হারায় না --}}
                                        @if (count($spark) > 1 && $peak > $floor)
                                            @php
                                                $step = 74 / (count($spark) - 1);
                                                $points = [];
                                                foreach ($spark as $i => $value) {
                                                    $points[] = round(2 + $i * $step, 1).','.round(27 - (($value - $floor) / ($peak - $floor)) * 22, 1);
                                                }
                                            @endphp
                                            <svg viewBox="0 0 78 30" aria-hidden="true"><polyline points="{{ implode(' ', $points) }}" fill="none" stroke="{{ $tile['tone'] }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" /></svg>
                                        @endif
                                    </a>
                                    @continue
                                @endif
                                <a @if ($tile['href'] !== '') href="{{ $tile['href'] }}" @endif data-board-tile="{{ $tile['key'] }}" data-boxed
                                   class="hb-card hb-tile" style="--hb-tone: {{ $tile['tone'] }}">
                                    <span class="hb-lb" data-board-tile-label>{{ $tile['label'] }}</span>
                                    <span class="hb-v num" data-board-tile-value>{{ $tile['value'] }}</span>
                                    <span class="hb-s" data-board-tile-sub>
                                        @if ($tile['delta'])
                                            <span class="{{ $tile['delta']['up'] ? 'hb-up' : 'hb-down' }}" data-board-delta>{{ $tile['delta']['up'] ? '▲' : '▼' }} {{ $tile['delta']['text'] }}</span>
                                        @endif
                                        {{ $tile['sub'] }}
                                    </span>
                                    @if (filled($tile['note'] ?? null))
                                        <span class="hb-s" data-board-note>{{ $tile['note'] }}</span>
                                    @endif
                                    @if (count($spark) > 1 && $peak > $floor)
                                        @php
                                            $step = 74 / (count($spark) - 1);
                                            $points = [];
                                            foreach ($spark as $i => $value) {
                                                $points[] = round(2 + $i * $step, 1).','.round(27 - (($value - $floor) / ($peak - $floor)) * 22, 1);
                                            }
                                        @endphp
                                        <svg viewBox="0 0 78 30" aria-hidden="true"><polyline points="{{ implode(' ', $points) }}" fill="none" stroke="{{ $tile['tone'] }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" /></svg>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endif

                </div>

                {{-- ⓘ মডিউলের বাকি সূচক — বোর্ডে যা নেই (ক্রয়, অনুমোদন, দেনা …); আগের কার্ডই, হারায় না --}}
                @if ($cards !== [])
                    <section class="hb-more-tiles hb-section" data-home-more-kpis>
                        <h2>{{ __('home.board.more_tiles') }}</h2>
                        <div class="hb-tiles">
                            @foreach ($cards as $widget)
                                <a href="{{ $widget->href }}" data-kpi data-boxed class="hb-card hb-tile"
                                   style="--hb-tone: var({{ $tones[$loop->index % count($tones)] }})"
                                   @if ($widget->definition) title="{{ $widget->definition }}" @endif>
                                    <span data-kpi-label class="hb-lb">{{ $widget->label }}</span>
                                    <span data-kpi-value class="hb-v num">{{ $widget->value }}</span>
                                    @if ($widget->delta || $widget->hint)
                                        <span data-kpi-sub class="hb-s">
                                            @if ($widget->delta)
                                                <span class="{{ str_starts_with($widget->delta, '-') ? 'hb-down' : 'hb-up' }}">{{ str_starts_with($widget->delta, '-') ? '▼' : '▲' }} {{ ltrim($widget->delta, '+-') }}</span>
                                            @endif
                                            <span data-kpi-hint>{{ $widget->hint }}</span>
                                        </span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            @endif

            {{-- ── ৪ · তিন তালিকা ── --}}
            @if ($board['lists'] !== [] && $layout->shows('kpis'))
                <div data-home-lists class="hb-three hb-section">
                    @foreach ($board['lists'] as $list)
                        <section class="hb-card hb-list" data-board-list="{{ $list['key'] }}" data-boxed>
                            <div class="hb-head">
                                <h2>{{ $list['title'] }}</h2>
                                @if ($list['href'] !== '')
                                    <a href="{{ $list['href'] }}" class="hb-more">{{ $list['link'] }} →</a>
                                @endif
                            </div>
                            <div class="hb-sub">{{ $list['sub'] }}</div>
                            @forelse ($list['rows'] as $row)
                                @if (filled($row['href'] ?? null))
                                    <a href="{{ $row['href'] }}" class="hb-row" data-tint="{{ $row['tint'] ?? 'info' }}">
                                @else
                                    <div class="hb-row" data-tint="{{ $row['tint'] ?? 'info' }}">
                                @endif
                                        <i style="background: {{ $row['colour'] }}"></i>
                                        <span>{{ $row['name'] }}@if ($row['hint'])<small>{{ $row['hint'] }}</small>@endif</span>
                                        <b>{{ $row['amount'] }}</b>
                                @if (filled($row['href'] ?? null))
                                    </a>
                                @else
                                    </div>
                                @endif
                            @empty
                                <p class="hb-empty" data-board-empty>{{ __('home.board.empty') }}</p>
                            @endforelse
                        </section>
                    @endforeach
                </div>
            @endif

            {{-- ── ৫ · আপনার সব প্রতিষ্ঠান — প্রতিটার এক লাইন, নিচে মোট ── --}}
            @if (($board['companies']['rows'] ?? []) !== [] && $layout->shows('kpis'))
                @php $companies = $board['companies']; @endphp
                <section data-home-companies data-boxed class="hb-card hb-companies hb-section">
                    <h2>{{ __('home.board.companies_title') }}</h2>
                    <p class="hb-sub">{{ __('home.board.companies_sub') }}</p>
                    <table class="ui-grid">
                        <thead>
                            <tr>
                                <th>{{ __('home.board.col_company') }}</th>
                                <th class="num">{{ __('home.board.col_branches') }}</th>
                                @foreach ($companies['keys'] as $key)
                                    <th class="num">{{ __('home.board.col_'.$key) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($companies['rows'] as $row)
                                <tr data-home-company-row="{{ $row['id'] }}">
                                    <td>
                                        @if ($row['href'])
                                            <a href="{{ $row['href'] }}">{{ $row['name'] }}</a>
                                        @else
                                            {{ $row['name'] }}
                                        @endif
                                        @if ($row['here'])
                                            <span class="hb-here">{{ __('home.board.you_are_here') }}</span>
                                        @endif
                                    </td>
                                    <td class="num">{{ $row['branches'] }}</td>
                                    @foreach ($companies['keys'] as $key)
                                        <td class="num">{{ $row['values'][$key] }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            <tr class="hb-total" data-home-company-total>
                                <td>{{ __('home.board.all_companies') }}</td>
                                <td class="num">{{ $companies['total']['branches'] }}</td>
                                @foreach ($companies['keys'] as $key)
                                    <td class="num">{{ $companies['total']['values'][$key] }}</td>
                                @endforeach
                            </tr>
                        </tbody>
                    </table>
                </section>
            @endif
        </div>

        {{-- ── ৬–৭ · আজ যা করতে হবে, তার নিচে পাশাপাশি নজরে রাখুন আর সদ্য যা হয়েছে ── --}}
        <div data-home-unit="work" style="order: {{ $layout->position('work') }}">
            @if (($boardTodo !== [] || $todayWork->isNotEmpty()) && $layout->shows('exceptions'))
                @php $waitingCount = $boardTodo !== [] ? collect($boardTodo)->where('count', '>', 0)->count() : $todayWork->count(); @endphp
                <section data-home-today-work data-boxed class="hb-card hb-todo hb-section">
                    <h2 class="flex items-center justify-between gap-2 text-sm font-semibold text-(--color-ink)">
                        {{ __('home.today_work') }}
                        <x-ui.status-chip :level="$waitingCount > 0 ? 'urgent' : 'ok'">{{ __('home.today_work_count', ['count' => $waitingCount]) }}</x-ui.status-chip>
                    </h2>
                    <div class="hb-todo-items">
                        @foreach ($boardTodo as $item)
                            @php $level = $item['count'] > 0 ? $item['level'] : 'ok'; @endphp
                            <a @if ($item['href'] !== '') href="{{ $item['href'] }}" @endif data-today-work data-level="{{ $level }}" data-board-todo="{{ $item['key'] }}"
                               class="hb-todo-item">
                                <b class="num">{{ $item['count'] }}</b>
                                <span class="min-w-0"><span class="line-clamp-2 text-sm font-semibold leading-snug">{{ $item['label'] }}</span><small>{{ $item['hint'] }}</small></span>
                                <x-ui.status-chip :level="$level" />
                            </a>
                        @endforeach
                        @foreach ($todayWork as $widget)
                            @php $level = $levelOf($widget); @endphp
                            <a href="{{ $widget->href }}" data-today-work data-level="{{ $level }}"
                               class="hb-todo-item">
                                <b class="num">{{ $widget->value }}</b>
                                <span class="min-w-0"><span class="line-clamp-2 text-sm font-semibold leading-snug" title="{{ $widget->label }}">{{ $widget->label }}</span><small>{{ $widget->hint }}</small></span>
                                <x-ui.status-chip :level="$level" />
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <div class="hb-two hb-section">
                @if (($waiting->isNotEmpty() || $quiet->isNotEmpty()) && $layout->shows('exceptions'))
                    <section data-exception-center data-boxed class="hb-card min-w-0 overflow-hidden">
                        <h2 class="px-4 pt-3 pb-1 text-sm font-semibold text-(--color-ink)">{{ __('home.watch_title') }}</h2>
                        @foreach ($waiting as $widget)
                            @php $warn = $widget->tone === 'warn'; @endphp
                            <a href="{{ $widget->href }}" data-exception
                               class="flex items-center gap-3 border-b border-(--color-border) px-4 py-2.5 transition-colors last:border-b-0 hover:bg-(--color-surface-hover)"
                               style="border-inline-start: 4px solid {{ $warn ? 'var(--color-status-week-bar)' : 'var(--color-info)' }}">
                                {{-- ⭐ রঙিন ফোঁটা (নমুনার `.lst .r i`) — হলুদ সতর্কতা, নীল তথ্য --}}
                                <i class="hb-dot" style="background: var({{ $warn ? '--color-status-week-bar' : '--color-info' }})" aria-hidden="true"></i>
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
                    <section data-home-happenings data-boxed class="hb-card min-w-0 overflow-hidden">
                        <h2 class="px-4 pt-3 pb-1 text-sm font-semibold text-(--color-ink)">{{ __('core.dashboard.just_happened') }}</h2>
                        @foreach (array_slice($happenings, 0, 6) as $happening)
                            <x-ui.drill :source="$happening->sourceType" :id="$happening->sourceId"
                                        class="flex items-center gap-3 border-b border-(--color-border) px-4 py-2.5
                                               !text-inherit !no-underline transition-colors last:border-b-0 hover:bg-(--color-surface-hover)">
                                <i class="hb-dot" style="background: var(--color-chart-{{ $loop->odd ? 1 : 3 }})" aria-hidden="true"></i>
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

        {{-- ── মডিউলের চিত্র — আগের ৮টা মডিউল-কার্ড, প্রতিটা মডিউলের প্রথম চার্ট; চাপলে মডিউলের ড্যাশবোর্ড ── --}}
        <div data-home-unit="modules" style="order: 9">
            @if ($pictures->isNotEmpty() && $layout->shows('pictures'))
                <section data-business-pictures class="hb-section">
                    <h2 class="text-sm font-semibold text-(--color-ink-muted)">{{ __('home.board.module_pictures') }}</h2>
                    <div data-pictures-row class="hb-charts" aria-label="{{ __('home.pictures_title') }}">
                        @foreach ($pictures as $row)
                            <a href="{{ route('module.dashboard', ['module' => $row['module']]) }}" data-boxed data-picture="{{ $row['module'] }}"
                               class="hb-card block min-w-0 transition-colors hover:bg-(--color-surface-hover)">
                                <div class="flex items-baseline justify-between gap-3 px-4 pt-3 pb-1">
                                    <span class="min-w-0">
                                        <span class="block break-words text-sm font-semibold text-(--color-ink)">{{ $row['name'] }}</span>
                                        @if ($row['listing'] ?? null)
                                            <span class="line-clamp-2 block text-xs text-(--color-ink-muted)">{{ $row['listing']->label }}</span>
                                        @else
                                            <span class="line-clamp-2 block text-xs text-(--color-ink-muted)"
                                                  title="{{ $row['panel']->range ? $row['panel']->range.' · ' : '' }}{{ $row['panel']->label }}">@if ($row['panel']->range)<span data-chart-range class="font-semibold text-(--color-ink-body)">{{ $row['panel']->range }}</span> · @endif{{ $row['panel']->label }}</span>
                                        @endif
                                    </span>
                                    <span class="shrink-0 text-xs text-(--color-link)">{{ __('home.details') }} →</span>
                                </div>
                                @if ($row['listing'] ?? null)
                                    @php
                                        $homeList = $row['listing'];
                                        $firstCol = $homeList->columns[0];
                                        $lastCol = $homeList->columns[count($homeList->columns) - 1];
                                    @endphp
                                    <div data-home-listing class="px-4 py-2">
                                        @forelse ($homeList->rows->take(4) as $listRow)
                                            @php
                                                $cell = ($firstCol['render'])($listRow);
                                                $cellText = trim((string) preg_replace('/\s+/u', ' ', strip_tags($cell instanceof \Illuminate\Contracts\Support\Htmlable ? $cell->toHtml() : (string) $cell)));
                                            @endphp
                                            <div data-home-listing-row class="flex min-w-0 items-center justify-between gap-2 border-b border-(--color-border) py-1.5 text-sm last:border-b-0">
                                                <span class="line-clamp-2 min-w-0 flex-1 break-words leading-snug text-(--color-ink-body)" title="{{ $cellText }}">{{ $cell }}</span>
                                                <span class="shrink-0">{{ ($lastCol['render'])($listRow) }}</span>
                                            </div>
                                        @empty
                                            <p class="py-3 text-sm text-(--color-ink-muted)">{{ $homeList->empty }}</p>
                                        @endforelse
                                    </div>
                                @else
                                    <x-dashboard.chart :panel="$row['panel']" compact :show-range="false" :interactive="false" />
                                @endif
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>{{-- data-home-units --}}

    @if ($nothing)
        @php
            $why = match (true) {
                $hasWork ?? $menu !== [] => __('core.dashboard.nothing_to_show'),
                ($roleLivesIn ?? []) !== [] => __('core.dashboard.role_lives_elsewhere', ['companies' => implode(', ', $roleLivesIn)]),
                default => __('core.dashboard.no_module_at_all'),
            };
        @endphp
        <x-ui.empty-state :message="$why" />
    @endif
</x-layouts.app>
