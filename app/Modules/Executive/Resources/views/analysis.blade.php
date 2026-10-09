{{--
    মালিকের কেন্দ্র — বিশ্লেষণ।

    ⓘ আটটা তালিকা, সব কোম্পানি মিলিয়ে: সেরা দশ ক্রেতা, পণ্য, এলাকা (রুট), বিক্রয়কর্মী; লোকসানি ক্রেতা;
    কম মার্জিনের পণ্য; অচল মাল; আর উপরে বাকির বয়স। প্রতিটা তালিকা একটা কেন্দ্রীয় রিপোর্টের নিজের সারি।
    ⓘ সারিতে চাপলে: ক্রেতা → তাঁর খাতা; পণ্য → পণ্যের পাতা; বাকিগুলো → নিজের রিপোর্ট — ঐ কোম্পানিতে বসে।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Executive\Http\Controllers\AnalysisController;
    use App\Modules\Executive\Support\Go;

    $customer = fn (array $r) => ['accounts.report.show', ['slug' => 'customer-ledger', 'customer_id' => $r['customer_id'] ?? null, 'from' => $range['from'], 'to' => $range['to']]];
    $product = fn (array $r) => ['inventory.product.show', ['product' => $r['product_id'] ?? null]];

    $cards = [
        ['customers', 'customer_name', 'total', 'money', $customer],
        ['products', 'product_name', 'revenue', 'money', $product],
        ['areas', 'route_name', 'sales', 'money', fn (array $r) => ['sales.report.show', ['slug' => 'by-route', 'from' => $range['from'], 'to' => $range['to']]]],
        ['salespeople', 'salesperson', 'net_sales', 'money', fn (array $r) => ['sales.report.show', ['slug' => 'by-salesperson', 'from' => $range['from'], 'to' => $range['to']]]],
        ['losing', 'customer_name', 'gross_profit', 'money', $customer],
        ['thin', 'product_name', 'margin_percent', 'percent', $product],
        ['dead', 'product_name', 'value', 'money', fn (array $r) => ['inventory.report.show', ['slug' => 'slow-dead', 'status' => 'dead']]],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('executive::analysis.title') }}</x-slot:title>

    @include('executive::partials.open-form')

    <div data-executive-analysis class="flex flex-col gap-3">
        <div class="flex items-center justify-between gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4"
             style="height: 56px">
            <h1 class="truncate text-lg font-bold text-(--color-ink)">{{ __('executive::analysis.title') }}</h1>

            <span class="text-2xs text-(--color-ink-muted)">{{ \App\Core\Engines\Dashboard\DateRange::label($range['from'], $range['to']) }}</span>

            <form method="GET" action="{{ route('executive.analysis') }}" class="flex items-center gap-2">
                <label class="sr-only" for="an-period">{{ __('executive::today.period') }}</label>
                <select id="an-period" name="period" class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    @foreach (AnalysisController::PERIODS as $p)
                        <option value="{{ $p }}" @selected($period === $p)>{{ __('executive::analysis.period_'.$p) }}</option>
                    @endforeach
                </select>
                <x-ui.button type="submit">{{ __('executive::today.show') }}</x-ui.button>
            </form>
        </div>

        {{-- ── বাকির বয়স — সব কোম্পানির যোগ, রিপোর্টের নিজের যোগফল ─────────────── --}}
        <section data-ageing class="grid grid-cols-5 gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-2">
            @foreach (['bucket_current', 'bucket_30', 'bucket_60', 'bucket_90', 'outstanding'] as $bucket)
                <div class="min-w-0">
                    <span class="block truncate text-2xs text-(--color-ink-muted)">{{ __('executive::analysis.'.$bucket) }}</span>
                    <span class="tabular text-lg font-bold text-(--color-ink)">{{ Money::format($ageing['totals'][$bucket], 0) }}</span>
                </div>
            @endforeach
            @if ($ageing['refused'] !== [])
                <p class="text-2xs text-(--color-ink-muted)">{{ __('executive::today.refused', ['companies' => implode(', ', $ageing['refused'])]) }}</p>
            @endif
        </section>

        <div class="grid gap-3 xl:grid-cols-4">
            @foreach ($cards as [$name, $labelKey, $measure, $kind, $target])
                @php $list = $lists[$name]; @endphp
                <section data-list="{{ $name }}" class="flex min-w-0 flex-col overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)"
                         style="height: 330px">
                    <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('executive::analysis.list_'.$name) }}</h2>
                    <ol class="min-h-0 flex-1 overflow-auto text-sm">
                        @forelse ($list['rows'] as $row)
                            @php [$route, $params] = $target($row); @endphp
                            <li class="border-t border-(--color-border) px-4 py-1">
                                <button type="submit" form="executive-open" name="go"
                                        value="{{ Go::to($row['company_id'], null, $route, $params) }}"
                                        class="flex w-full items-center justify-between gap-2 text-left hover:underline">
                                    <span class="min-w-0 truncate">{{ $row[$labelKey] ?? '—' }}
                                        @if ($many)
                                            <span class="text-2xs text-(--color-ink-muted)">· {{ $row['company_name'] }}</span>
                                        @endif
                                    </span>
                                    <span @class(['tabular shrink-0', 'text-(--color-badge-danger-ink)' => bccomp((string) ($row[$measure] ?? '0'), '0', 4) < 0])>
                                        @if (! array_key_exists($measure, $row))
                                            {{ \App\Modules\Executive\Services\Figures::HIDDEN }}
                                        @elseif ($kind === 'percent')
                                            {{ $row[$measure] === null ? '—' : $row[$measure].'%' }}
                                        @else
                                            {{ Money::format($row[$measure], 0) }}
                                        @endif
                                    </span>
                                </button>
                            </li>
                        @empty
                            <li class="px-4 py-2 text-2xs text-(--color-ink-muted)">{{ __('executive::analysis.empty') }}</li>
                        @endforelse
                    </ol>
                    @if ($list['refused'] !== [])
                        <p class="px-4 pb-1 text-2xs text-(--color-ink-muted)">{{ __('executive::today.refused', ['companies' => implode(', ', $list['refused'])]) }}</p>
                    @endif
                </section>
            @endforeach

            <section class="flex min-w-0 flex-col justify-center gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4"
                     style="height: 330px">
                <a href="{{ route('executive.report.show', ['slug' => 'profit-by-customer', 'from' => $range['from'], 'to' => $range['to']]) }}"
                   class="text-sm font-semibold text-(--color-brand-700) hover:underline">{{ __('executive::analysis.profit_by_customer') }} →</a>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('executive::analysis.profit_by_customer_hint') }}</p>
                @unless ($canSeeCost)
                    <p class="text-2xs text-(--color-badge-warning-ink)">{{ __('executive::analysis.no_cost_key') }}</p>
                @endunless
            </section>
        </div>
    </div>
</x-layouts.app>
