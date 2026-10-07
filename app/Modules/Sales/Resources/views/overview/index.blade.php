{{--
    বিক্রয়ের পর্দা — কার্ড, ধারা আর ভাগ (NEXUS §৪)।

    ── কেন কোনো চার্ট-লাইব্রেরি নেই ────────────────────────────────────
    মডিউলের ড্যাশবোর্ড ([[dashboard/module.blade.php]]) বার আঁকে কেবল CSS-এ,
    উচ্চতা শতাংশে — এখানেও তাই। ⓘ কোনো Alpine নেই, তাই CSP-র কোনো
    সীমায় পড়ার ঝুঁকিও নেই; আর ফোনের ছোট পর্দায় টেবিলগুলো কার্ড হয়ে যায়।

    ── কেন কার্ড থাকে বা থাকে না, ঢাকা নয় ──────────────────────────────
    কোন কার্ড আসবে তা [[SalesOverview]] ঠিক করে, চাবি ধরে। এই ব্লেড কেবল
    যা পায় তাই আঁকে — এখানে কোনো `can` নেই, যাতে নিয়মটা দুই জায়গায় না থাকে।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Sales\Metrics\SalesPeriod;

    $peakOf = function (array $values): string {
        $peak = '0';
        foreach ($values as $v) {
            if (bccomp((string) $v, $peak, 4) > 0) {
                $peak = (string) $v;
            }
        }

        return $peak;
    };

    $barHeight = fn (string $value, string $peak): int => bccomp($peak, '0', 4) <= 0 || bccomp($value, '0', 4) <= 0
        ? 2
        : max(2, (int) bcdiv(bcmul($value, '100', 4), $peak, 0));

    $shareCell = fn (?string $share): string => $share === null ? '—' : $share.'%';

    $rankColumns = [
        ['key' => 'name', 'label' => __('sales::overview.col.name'), 'render' => fn ($r) => $r['name']],
        ['key' => 'count', 'label' => __('sales::overview.col.count'), 'numeric' => true, 'width' => '6rem',
            'render' => fn ($r) => $r['count']],
        ['key' => 'amount', 'label' => __('sales::overview.col.amount'), 'numeric' => true, 'width' => '10rem',
            'render' => fn ($r) => Money::format($r['amount'])],
        ['key' => 'share', 'label' => __('sales::overview.col.share'), 'numeric' => true, 'width' => '6rem',
            'render' => fn ($r) => $shareCell($r['share'])],
    ];

    $productColumns = [
        ['key' => 'name', 'label' => __('sales::overview.col.product'), 'render' => fn ($r) => $r['name']],
        ['key' => 'qty', 'label' => __('sales::overview.col.qty'), 'numeric' => true, 'width' => '7rem',
            'render' => fn ($r) => Money::format($r['qty'])],
        ['key' => 'amount', 'label' => __('sales::overview.col.revenue'), 'numeric' => true, 'width' => '10rem',
            'render' => fn ($r) => Money::format($r['amount'])],
    ];

    $monthlyColumns = [
        ['key' => 'label', 'label' => __('sales::overview.col.month'), 'render' => fn ($r) => $r['label']],
        ['key' => 'gross', 'label' => __('sales::overview.col.gross'), 'numeric' => true,
            'render' => fn ($r) => Money::format($r['gross'])],
        ['key' => 'discount', 'label' => __('sales::overview.col.discount'), 'numeric' => true,
            'render' => fn ($r) => Money::format($r['discount'])],
        ['key' => 'discount_percent', 'label' => __('sales::overview.col.discount_percent'), 'numeric' => true,
            'width' => '6rem', 'render' => fn ($r) => $shareCell($r['discount_percent'])],
        ['key' => 'net', 'label' => __('sales::overview.col.net'), 'numeric' => true,
            'render' => fn ($r) => Money::format($r['net'])],
    ];

    if (($panels['monthly']['collected'] ?? null) !== null) {
        $collectedByMonth = $panels['monthly']['collected'];
        $monthlyColumns[] = ['key' => 'collected', 'label' => __('sales::overview.col.collected'), 'numeric' => true,
            'render' => fn ($r) => Money::format($collectedByMonth[$r['ym']] ?? '0')];
    }

    $returnColumns = [
        ['key' => 'label', 'label' => __('sales::overview.col.month'), 'render' => fn ($r) => $r['label']],
        ['key' => 'count', 'label' => __('sales::overview.col.count'), 'numeric' => true, 'width' => '6rem',
            'render' => fn ($r) => $r['count']],
        ['key' => 'amount', 'label' => __('sales::overview.col.amount'), 'numeric' => true, 'width' => '10rem',
            'render' => fn ($r) => Money::format($r['amount'])],
    ];

    $rankings = [
        'top_customers' => $rankColumns,
        'top_products' => $productColumns,
        'slow_products' => $productColumns,
        'by_seller' => $rankColumns,
        'by_branch' => $rankColumns,
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::overview.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::overview.title')" :subtitle="__('sales::overview.subtitle')">
            <x-slot:actions>
                <form method="GET" class="flex flex-wrap items-end gap-2" data-period-form>
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('sales::overview.period') }}</span>
                        <select name="period"
                                class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                       bg-(--color-surface-card) px-2">
                            @foreach (SalesPeriod::KINDS as $kind)
                                <option value="{{ $kind }}" @selected($period->kind === $kind)>{{ __('sales::overview.kind.'.$kind) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('core.table.from_date') }}</span>
                        <input type="date" name="from" value="{{ $period->from }}"
                               class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2">
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('core.table.to_date') }}</span>
                        <input type="date" name="to" value="{{ $period->to }}"
                               class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2">
                    </label>
                    <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <p class="mb-3 text-xs text-(--color-ink-muted)" data-period="{{ $period->kind }}">
        {{ __('sales::overview.showing', ['from' => $period->from, 'to' => $period->to]) }}
    </p>

    {{-- ⛔ উল্টো বা অতিরিক্ত লম্বা পরিসর চুপ করে ঠিক হয় না — পর্দা বলে দেয় ([[SalesPeriod]]) --}}
    @if ($period->refused)
        <div role="alert" data-period-refused
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm
                    text-(--color-badge-warning-ink)">
            {{ __('sales::overview.range_refused', ['days' => SalesPeriod::MAX_DAYS]) }}
        </div>
    @endif

    {{-- ── কার্ড ─────────────────────────────────────────────────── --}}
    @if ($cards === [] && $panels === [])
        <x-ui.empty-state :message="__('sales::overview.nothing_for_you')" />
    @endif

    @if ($cards !== [])
        <div class="mb-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($cards as $card)
                <{{ $card['href'] ? 'a' : 'div' }}
                    @if ($card['href']) href="{{ $card['href'] }}" @endif
                    data-boxed data-card="{{ $card['key'] }}"
                    class="block rounded-(--radius-card) border border-(--color-border)
                           bg-(--color-surface-card) px-4 py-3">
                    <div class="text-xs text-(--color-ink-muted)">{{ $card['label'] }}</div>
                    <div @class([
                        'mt-1 text-2xl font-semibold tabular-nums',
                        'text-(--color-badge-success-ink)' => $card['tone'] === 'good',
                        'text-(--color-badge-warning-ink)' => $card['tone'] === 'warn',
                        'text-(--color-badge-danger-ink)' => $card['tone'] === 'bad',
                    ])>{{ $card['value'] }}</div>
                    <div class="mt-1 text-2xs text-(--color-ink-muted)">{{ $card['hint'] }}</div>
                </{{ $card['href'] ? 'a' : 'div' }}>
            @endforeach
        </div>
    @endif

    {{-- ── ধারা: দিনে দিনে ──────────────────────────────────────────── --}}
    @isset($panels['daily'])
        @php
            $daily = $panels['daily'];
            $dailyPeak = $peakOf(array_column($daily, 'value'));
        @endphp
        <div data-boxed data-panel="daily" class="mb-3 rounded-(--radius-card) border border-(--color-border)
                                                   bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('sales::overview.panel.daily', ['days' => count($daily)]) }}
            </h2>
            <div class="flex items-end gap-1 px-4 pt-6 pb-2" style="height: var(--spacing-chart)">
                @foreach ($daily as $point)
                    <div class="flex flex-1 items-end justify-center" style="height:100%">
                        <div class="w-full rounded-t bg-(--color-brand-500)"
                             style="height:{{ $barHeight($point['value'], $dailyPeak) }}%"
                             title="{{ $point['date'] }}: {{ Money::format($point['value']) }}"></div>
                    </div>
                @endforeach
            </div>
            <div class="flex gap-1 px-4 pb-2">
                @foreach ($daily as $point)
                    <div class="min-w-0 flex-1 truncate text-center text-2xs text-(--color-ink-muted)">{{ $point['label'] }}</div>
                @endforeach
            </div>
        </div>
    @endisset

    {{-- ── ধারা: মাসে মাসে, আর ছাড়ের বিশ্লেষণ ───────────────────────── --}}
    @isset($panels['monthly'])
        @php
            $monthly = $panels['monthly']['rows'];
            $monthlyPeak = $peakOf(array_column($monthly, 'net'));
        @endphp
        <div class="mb-3 grid gap-3 lg:grid-cols-3">
            <div data-boxed data-panel="monthly" class="rounded-(--radius-card) border border-(--color-border)
                                                         bg-(--color-surface-card) lg:col-span-2">
                <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                    {{ __('sales::overview.panel.monthly') }}
                </h2>
                <div class="flex items-end gap-1 px-4 pt-6 pb-2" style="height: var(--spacing-chart)">
                    @foreach ($monthly as $point)
                        <div class="flex flex-1 items-end justify-center" style="height:100%">
                            <div class="w-full rounded-t bg-(--color-brand-500)"
                                 style="height:{{ $barHeight($point['net'], $monthlyPeak) }}%"
                                 title="{{ $point['label'] }}: {{ Money::format($point['net']) }}"></div>
                        </div>
                    @endforeach
                </div>
                <div class="flex gap-1 px-4 pb-2">
                    @foreach ($monthly as $point)
                        <div class="min-w-0 flex-1 truncate text-center text-2xs text-(--color-ink-muted)">{{ $point['label'] }}</div>
                    @endforeach
                </div>
            </div>

            @isset($panels['return_trend'])
                @php
                    $returnsByMonth = $panels['return_trend'];
                    $returnPeak = $peakOf(array_column($returnsByMonth, 'amount'));
                @endphp
                <div data-boxed data-panel="return_trend" class="rounded-(--radius-card) border border-(--color-border)
                                                                  bg-(--color-surface-card)">
                    <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                        {{ __('sales::overview.panel.return_trend') }}
                    </h2>
                    <div class="space-y-3 p-4">
                        @foreach ($returnsByMonth as $point)
                            <div>
                                <div class="mb-1 flex items-baseline justify-between text-xs">
                                    <span class="text-(--color-ink-muted)">{{ $point['label'] }}</span>
                                    <span class="font-semibold tabular-nums">{{ Money::format($point['amount']) }}</span>
                                </div>
                                <div class="h-2 overflow-hidden rounded-full bg-(--color-surface-hover)">
                                    <div class="h-full bg-(--color-brand-500)"
                                         style="width:{{ $barHeight($point['amount'], $returnPeak) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endisset
        </div>

        <div data-boxed data-panel="discount" class="mb-3 overflow-hidden rounded-(--radius-card) border
                                                      border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('sales::overview.panel.discount') }}
            </h2>
            <x-ui.table :rows="$monthly" :columns="$monthlyColumns" :empty="__('sales::overview.empty')" />
        </div>
    @elseif (isset($panels['return_trend']))
        <div data-boxed data-panel="return_trend" class="mb-3 overflow-hidden rounded-(--radius-card) border
                                                          border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                {{ __('sales::overview.panel.return_trend') }}
            </h2>
            <x-ui.table :rows="$panels['return_trend']" :columns="$returnColumns" :empty="__('sales::overview.empty')" />
        </div>
    @endisset

    {{-- ── ভাগ: কে, কী, কোথায় ───────────────────────────────────────── --}}
    @php
        $shown = array_filter($rankings, fn ($columns, $key) => isset($panels[$key]), ARRAY_FILTER_USE_BOTH);
    @endphp
    @if ($shown !== [] || isset($panels['by_territory']))
        <div class="grid gap-3 lg:grid-cols-2">
            @foreach ($shown as $key => $columns)
                <div data-boxed data-panel="{{ $key }}" class="overflow-hidden rounded-(--radius-card) border
                                                                border-(--color-border) bg-(--color-surface-card)">
                    <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                        {{ __('sales::overview.panel.'.$key) }}
                    </h2>
                    <x-ui.table :rows="$panels[$key]" :columns="$columns" :empty="__('sales::overview.empty')" />
                </div>
            @endforeach

            @isset($panels['by_territory'])
                <div data-boxed data-panel="by_territory" class="overflow-hidden rounded-(--radius-card) border
                                                                  border-(--color-border) bg-(--color-surface-card)">
                    <h2 class="border-b border-(--color-border) px-4 py-3 text-xs font-semibold text-(--color-ink-muted)">
                        {{ __('sales::overview.panel.by_territory', ['level' => __('master_data::level.'.$panels['by_territory']['level'])]) }}
                    </h2>
                    <x-ui.table :rows="$panels['by_territory']['rows']" :columns="$rankColumns" :empty="__('sales::overview.empty')" />
                </div>
            @endisset
        </div>
    @endif
</x-layouts.app>
