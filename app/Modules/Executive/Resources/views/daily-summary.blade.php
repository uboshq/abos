{{--
    ⭐ প্রতিদিনের সারাংশ — মালিক, ৭ নভেম্বর ২০২৬ (নমুনা আর A4 PDF "ok"; [[DailySummary]], [[DailySummaryController]])।
    মাথায় দিন, কোম্পানি, শাখা আর ছয়টা বড় অঙ্ক; নিচে বিক্রি, গাড়ি-ভাড়া, টাকা (কাউন্টার ধরে), কেনা, নজরে রাখুন, আর সবার নিচে
    অর্ডার দিতে হবে এমন পণ্য। ⓘ ছাপা কাগজ ([[print/daily-summary]]) আর রাতের ইমেইল একই অঙ্ক থেকে।
--}}
@php
    $m = fn ($v) => \App\Core\Support\Money::format((string) $v);
    $t = fn (string $k, array $r = []) => __('executive::daily.'.$k, $r);
    $day = $s['date'];
    $branchName = $s['branch_id'] ? \App\Models\Branch::query()->find($s['branch_id'])?->name() : $t('all_branches');
    $k = $s['kpis'];
    $tone = ['paid' => 'success', 'due' => 'danger', 'missing' => 'danger', 'none' => 'neutral'];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $t('title') }}</x-slot:title>

    <x-money.page kind="daily" :title="$t('title')" icon="calendar"
        :count="\App\Core\Support\DateFormat::format($day).' · '.($s['company']?->name() ?? '').' · '.$branchName">
        <x-slot:actions>
            <x-ui.button tone="secondary" :href="$prevUrl">{{ $t('prev_day') }}</x-ui.button>
            @if ($nextUrl)
                <x-ui.button tone="secondary" :href="$nextUrl">{{ $t('next_day') }}</x-ui.button>
            @endif
            <x-ui.button tone="secondary" icon="printer" :href="$pdfUrl" target="_blank" data-daily-pdf>{{ $t('pdf') }}</x-ui.button>
        </x-slot:actions>

    {{-- ── ছয়টা বড় অঙ্ক ── --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-3 xl:grid-cols-6" data-daily-kpis>
        @foreach ([
            ['delivered', '৳ '.$m($k['delivered']['value']), $t('vs_yesterday', ['amount' => '৳ '.$m($k['delivered']['before'])])],
            ['collected', '৳ '.$m($k['collected']['value']), $t('vs_yesterday', ['amount' => '৳ '.$m($k['collected']['before'])])],
            ['fare', '৳ '.$m($k['fare']['value']), $t('fare_due_short', ['amount' => '৳ '.$m($k['fare']['due'])])],
            ['received', '৳ '.$m($k['received']['value']), $t('suppliers', ['count' => $k['received']['suppliers']])],
            ['cash_left', '৳ '.$m($k['cash_left']['value']), $t('tills', ['count' => $k['cash_left']['tills']])],
            ['reorder', $t('products', ['count' => $k['reorder']['value']]), $t('below_level')],
        ] as [$key, $value, $sub])
            <div data-boxed data-kpi="{{ $key }}" class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-3 py-2">
                <p class="text-xs text-(--color-ink-muted)">{{ $t('k_'.$key) }}</p>
                <p class="num text-xl font-bold">{{ $value }}</p>
                <p class="text-2xs text-(--color-ink-muted)">{{ $sub }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- ক. বিক্রি --}}
        <section data-boxed data-daily="sales" class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="flex justify-between gap-2 bg-(--color-kind-receipt) px-4 py-2 font-semibold text-(--color-board-ink)">
                <span>{{ $t('s_sales') }}</span><span class="text-sm">{{ $t('challans', ['count' => $s['sales']['challans']]) }}</span>
            </h2>
            <dl class="grid gap-2 px-4 py-3 text-sm">
                @foreach ([
                    [$t('delivered'), $s['sales']['delivered']],
                    [$t('bills').' · '.$s['sales']['bills'], $s['sales']['billed']],
                    [$t('returned'), $s['sales']['returned']],
                    [$t('net_sales'), $s['sales']['net']],
                ] as $i => [$label, $value])
                    <div class="flex justify-between gap-3 border-b border-dashed border-(--color-border) pb-1 last:border-0">
                        <dt>{{ $label }}</dt>
                        <dd @class(['num font-semibold', 'text-(--color-badge-danger-ink)' => $i === 2])>{{ $i === 2 && bccomp((string) $value, '0', 4) > 0 ? '-' : '' }}{{ $m($value) }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- খ. গাড়ি ও ভাড়া --}}
        <section data-boxed data-daily="fare" class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) lg:col-span-2">
            <h2 class="flex justify-between gap-2 bg-(--color-kind-expense) px-4 py-2 font-semibold text-(--color-board-ink)">
                <span>{{ $t('s_fare') }}</span><span class="text-sm">{{ $t('vehicles', ['count' => $s['fare']['rows']->count()]) }}</span>
            </h2>
            <div class="overflow-x-auto">
                @if ($s['fare']['rows']->isEmpty())
                    <p class="px-4 py-3 text-sm text-(--color-ink-muted)">{{ $t('no_vehicles') }}</p>
                @else
                    <table class="ui-grid">
                        <thead><tr><th>{{ $t('driver') }}</th><th>{{ $t('vehicle') }}</th><th>{{ $t('paper') }}</th><th class="num">{{ $t('fare') }}</th><th>{{ $t('state') }}</th></tr></thead>
                        <tbody>
                            @foreach ($s['fare']['rows'] as $r)
                                <tr data-fare-row="{{ $r['state'] }}">
                                    <td>{{ $r['driver'] ?: '—' }}@if ($r['driver_phone']) <span class="num text-(--color-ink-muted)">· {{ $r['driver_phone'] }}</span>@endif</td>
                                    <td>{{ $r['vehicle'] ?: '—' }}</td>
                                    <td><a class="text-(--color-link) hover:underline" href="{{ $r['owner'] === 'trip' ? route('sales.shipment.show', $r['id']) : route('sales.challan.show', $r['id']) }}">{{ $r['no'] }}</a></td>
                                    <td class="num">{{ $m($r['amount']) }}</td>
                                    <td><x-ui.badge :tone="$tone[$r['state']] ?? 'neutral'">{{ $t('st_'.$r['state']) }}</x-ui.badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr><td colspan="3">{{ $t('fare_total', ['paid' => $m($s['fare']['paid']), 'due' => $m($s['fare']['due'])]) }}</td><td class="num">{{ $m($s['fare']['total']) }}</td><td></td></tr></tfoot>
                    </table>
                @endif
            </div>
            <p class="flex justify-between gap-3 px-4 py-2 text-sm">
                <span>{{ $t('fare_outstanding') }}</span><strong class="num text-(--color-badge-danger-ink)">{{ $m($s['fare']['outstanding']) }}</strong>
            </p>
        </section>

        {{-- গ. টাকা --}}
        <section data-boxed data-daily="money" class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) lg:col-span-3">
            <h2 class="bg-(--color-kind-custody) px-4 py-2 font-semibold text-(--color-board-ink)">{{ $t('s_money') }}</h2>
            <div class="overflow-x-auto">
                <table class="ui-grid">
                    <thead><tr><th>{{ $t('account') }}</th><th>{{ $t('holder') }}</th><th class="num">{{ $t('opening') }}</th><th class="num">{{ $t('in') }}</th><th class="num">{{ $t('out') }}</th><th class="num">{{ $t('closing') }}</th></tr></thead>
                    <tbody>
                        @foreach ($s['money']['rows'] as $r)
                            <tr>
                                <td><a class="text-(--color-link) hover:underline" href="{{ route('accounts.coa.show', $r['id']) }}">{{ $r['name'] }}</a> <span class="text-2xs text-(--color-ink-muted)">{{ $t('kind_'.$r['kind']) }}</span></td>
                                <td>{{ $r['holder'] ?: '—' }}</td>
                                <td class="num">{{ $m($r['opening']) }}</td>
                                <td class="num">{{ $m($r['in']) }}</td>
                                <td class="num">{{ $m($r['out']) }}</td>
                                <td class="num font-bold">{{ $m($r['closing']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td colspan="3"></td><td class="num">{{ $m($s['money']['in_total']) }}</td><td class="num">{{ $m($s['money']['out_total']) }}</td><td class="num">{{ $m($s['money']['closing_total']) }}</td></tr></tfoot>
                </table>
            </div>
            <p class="px-4 py-2 text-sm text-(--color-ink-muted)">{{ $t('collected_line', ['total' => '৳ '.$m($s['money']['collected']), 'cash' => $m($s['money']['in_by_kind']['cash']), 'bank' => $m($s['money']['in_by_kind']['bank']), 'mfs' => $m($s['money']['in_by_kind']['mfs'])]) }}</p>
        </section>

        {{-- ঘ. কেনা --}}
        <section data-boxed data-daily="purchase" class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="bg-(--color-kind-journal) px-4 py-2 font-semibold text-(--color-board-ink)">{{ $t('s_purchase') }}</h2>
            <dl class="grid gap-2 px-4 py-3 text-sm">
                @forelse ($s['purchase']['rows'] as $r)
                    <div class="flex justify-between gap-3 border-b border-dashed border-(--color-border) pb-1"><dt>{{ $r['supplier'] }} <span class="text-2xs text-(--color-ink-muted)">{{ $r['documents'] }}</span></dt><dd class="num font-semibold">{{ $m($r['amount']) }}</dd></div>
                @empty
                    <p class="text-(--color-ink-muted)">{{ $t('no_goods') }}</p>
                @endforelse
                <div class="flex justify-between gap-3 font-semibold"><dt>{{ $t('received_total') }}</dt><dd class="num">{{ $m($s['purchase']['received']) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ $t('supplier_paid') }}</dt><dd class="num">{{ $m($s['purchase']['paid']) }}</dd></div>
            </dl>
        </section>

        {{-- ঙ. নজরে রাখুন --}}
        <section data-boxed data-daily="watch" class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) lg:col-span-2">
            <h2 class="bg-(--color-kind-list-deep) px-4 py-2 font-semibold text-(--color-board-ink)">{{ $t('s_watch') }}</h2>
            <dl class="grid gap-2 px-4 py-3 text-sm">
                <div class="flex justify-between gap-3 border-b border-dashed border-(--color-border) pb-1"><dt><a class="text-(--color-link) hover:underline" href="{{ route('approval.inbox.index') }}">{{ $t('pending', ['count' => $s['watch']['pending_count']]) }}</a></dt><dd class="num font-semibold">{{ $m($s['watch']['pending_amount']) }}</dd></div>
                <div class="flex justify-between gap-3 border-b border-dashed border-(--color-border) pb-1"><dt>{{ $t('crossed', ['count' => $s['watch']['crossed_customers']]) }}</dt><dd class="num font-semibold text-(--color-badge-danger-ink)">{{ $m($s['watch']['crossed_amount']) }}</dd></div>
                <div class="flex justify-between gap-3 border-b border-dashed border-(--color-border) pb-1"><dt>{{ $t('profit') }}</dt><dd class="num font-semibold">{{ $m($s['watch']['profit']) }}</dd></div>
                <div class="flex justify-between gap-3"><dt>{{ $t('expenses') }}</dt><dd class="num font-semibold">{{ $m($s['watch']['expenses']) }}</dd></div>
            </dl>
        </section>

        {{-- চ. অর্ডার দিতে হবে --}}
        <section data-boxed data-daily="reorder" class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) lg:col-span-3">
            <h2 class="flex justify-between gap-2 bg-(--color-badge-danger-ink) px-4 py-2 font-semibold text-(--color-board-ink)">
                <span>{{ $t('s_reorder') }}</span><span class="text-sm">{{ $t('products', ['count' => $s['reorder']->count()]) }}</span>
            </h2>
            @if ($s['reorder']->isEmpty())
                <p class="px-4 py-3 text-sm text-(--color-ink-muted)">{{ $t('no_reorder') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="ui-grid">
                        <thead><tr><th>{{ $t('product') }}</th><th class="num">{{ $t('have') }}</th><th class="num">{{ $t('level') }}</th><th class="num">{{ $t('order') }}</th></tr></thead>
                        <tbody>
                            @foreach ($s['reorder'] as $r)
                                <tr data-reorder-row>
                                    <td>{{ $r['name'] }}</td>
                                    <td class="num">{{ \App\Core\Support\Money::format($r['available']) }} {{ $r['unit'] }}</td>
                                    <td class="num">{{ \App\Core\Support\Money::format($r['level']) }} {{ $r['unit'] }}</td>
                                    <td class="num font-bold">{{ \App\Core\Support\Money::format($r['order']) }} {{ $r['unit'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="px-4 py-2 text-2xs text-(--color-ink-muted)">{{ $t('order_hint') }}</p>
            @endif
        </section>
    </div>
    </x-money.page>
</x-layouts.app>
