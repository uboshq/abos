{{--
    ⭐ দিন শেষের হিসাব — পর্দা (মালিকের অনুমোদিত নমুনা v7, ১৬ নভেম্বর ২০২৬; [[DayClosingController]])।
    ⓘ কাগজের একই ১১টা অংশ, একই অঙ্ক ([[DayClosingService::build()]]); কাগজ "ছাপুন" থেকে।
--}}
@php
    $m = fn ($v) => $v === null ? '' : \App\Core\Support\Money::format((string) $v);
    $z = fn ($v) => ($v === null || bccomp((string) $v, '0', 4) === 0) ? '' : \App\Core\Support\Money::format((string) $v);
    $neg = fn ($v) => $v === null ? '' : (bccomp((string) $v, '0', 4) < 0 ? '('.\App\Core\Support\Money::format(ltrim((string) $v, '-')).')' : \App\Core\Support\Money::format((string) $v));
    $day = $r['date'];
    $fmtNews = function (array $n) use ($m) {
        $p = $n['params'];
        foreach (['amount', 'delivered', 'total', 'cash', 'bank', 'mfs', 'gap', 'per_day'] as $k) {
            if (isset($p[$k]) && is_numeric($p[$k])) { $p[$k] = $m($p[$k]); }
        }
        return __('accounts::dayclose.news.'.$n['key'], $p);
    };
    $title = fn (int $n, string $key) => $n.' · '.__('accounts::dayclose.sec.'.$key)[0];
    $box = 'mb-4 overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::dayclose.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::dayclose.title')" :subtitle="__('accounts::dayclose.subtitle')">
            <x-slot:actions>
                @can('system_admin.list.export')
                    <x-ui.button tone="secondary" icon="print" target="_blank" rel="noopener" data-paper="print"
                                 :href="route('executive.dayclose', ['date' => $day->toDateString(), 'export' => 'print'])">
                        {{ __('core.action.print') }}
                    </x-ui.button>
                    <x-ui.button tone="secondary" data-paper="pdf" target="_blank" rel="noopener" :href="route('executive.dayclose', ['date' => $day->toDateString(), 'export' => 'pdf'])">
                        {{ __('core.toolbar.export_pdf') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3 print-hide" data-report>
        <x-ui.field name="date" type="date" :label="__('accounts::dayclose.date')" :value="$day->toDateString()" />
        <x-ui.button type="submit" tone="secondary">{{ __('accounts::dayclose.show') }}</x-ui.button>
    </form>

    <p class="mb-3 flex flex-wrap gap-x-6 gap-y-1 text-sm" data-info-line>
        <span><span class="text-(--color-ink-muted)">{{ __('accounts::dayclose.date') }}:</span> <b>{{ $day->format('d/m/Y') }} ({{ $day->copy()->locale(app()->getLocale())->translatedFormat('l') }})</b></span>
        <span><span class="text-(--color-ink-muted)">{{ __('accounts::dayclose.month') }}:</span>
            <b>{{ __('accounts::dayclose.month_value', ['month' => $day->copy()->locale(app()->getLocale())->translatedFormat('F Y'), 'day' => $r['calendar']['day_of_month'], 'done' => $r['calendar']['done']]) }}</b></span>
        <span><span class="text-(--color-ink-muted)">{{ __('accounts::dayclose.branch') }}:</span> <b>{{ $branch }}</b></span>
        <span><span class="text-(--color-ink-muted)">{{ __('accounts::dayclose.closed_by') }}:</span> <b>{{ $r['closed']?->closer?->name ?? __('accounts::dayclose.not_closed') }}</b></span>
    </p>

    {{-- আজকের খবর --}}
    <section class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-muted) px-4 py-3" data-news>
        <h2 class="mb-1 font-semibold">{{ __('accounts::dayclose.news_title') }}</h2>
        <ul class="grid list-disc gap-x-8 gap-y-0.5 ps-5 text-sm md:grid-cols-2">
            @foreach ($r['news'] as $n)<li>{{ $fmtNews($n) }}</li>@endforeach
        </ul>
    </section>

    {{-- ⭐ কর্মীর হাতে টাকা — হলুদ বাক্সে, খবরের ঠিক নিচে (মালিক, ১৮ নভেম্বর ২০২৬) --}}
    @if ($r['staff_cash'] !== [])
        <section class="mb-4 rounded-(--radius-card) border border-(--color-warning) bg-(--color-badge-warning-bg) px-4 py-3 text-(--color-badge-warning-ink)" data-staff-cash>
            <h2 class="mb-1 font-semibold">{{ __('accounts::dayclose.staff_cash.title') }}</h2>
            <ul class="list-disc ps-5 text-sm">
                @foreach ($r['staff_cash'] as $s)
                    <li><b>{{ __('accounts::dayclose.staff_cash.row', ['who' => $s['who'] ?? __('accounts::dayclose.staff_cash.nobody'), 'amount' => $m($s['amount']), 'account' => $s['account']]) }}</b></li>
                @endforeach
            </ul>
            <p class="mt-1 text-xs">{{ __('accounts::dayclose.staff_cash.note') }}</p>
        </section>
    @endif

    {{-- ১ · আজ টাকা এল --}}
    <h2 class="mb-1 font-semibold">{{ $title(1, 'inflow') }}</h2>
    <div class="{{ $box }}">
        <table class="ui-grid w-full text-sm" data-sec="inflow">
            <thead><tr><th>{{ __('accounts::dayclose.col.party') }}</th><th>{{ __('accounts::dayclose.col.account') }}</th><th>{{ __('accounts::dayclose.col.how') }}</th>
                <th class="num">{{ __('accounts::dayclose.col.amount') }}</th><th class="num">{{ __('accounts::dayclose.col.charge') }}</th><th>{{ __('accounts::dayclose.col.note') }}</th></tr></thead>
            <tbody>
                @foreach ($r['inflow']['groups'] as $g)
                    <tr class="bg-(--color-surface-muted) font-semibold"><td colspan="6">{{ __('accounts::dayclose.kind.'.$g['kind']) }}</td></tr>
                    @foreach ($g['rows'] as $row)
                        <tr><td>{{ $row['party'] ?? '—' }}</td><td>{{ $row['account'] }}</td><td class="text-(--color-ink-muted)">{{ $row['how'] }}</td>
                            <td class="num">{{ $m($row['amount']) }}</td><td class="num">{{ $z($row['charge']) }}</td><td class="text-(--color-ink-muted)">{{ $row['note'] }}</td></tr>
                    @endforeach
                    <tr class="font-semibold"><td colspan="3" class="text-end">{{ __('accounts::dayclose.subtotal', ['kind' => __('accounts::dayclose.kind.'.$g['kind'])]) }}</td><td class="num">{{ $m($g['total']) }}</td><td class="num">{{ $z($g['charge']) }}</td><td></td></tr>
                @endforeach
                <tr class="font-bold" data-inflow-total><td colspan="3" class="text-end">{{ __('accounts::dayclose.inflow_total') }}</td><td class="num">{{ $m($r['inflow']['total']) }}</td><td class="num">{{ $z($r['inflow']['charge']) }}</td><td></td></tr>
            </tbody>
        </table>
    </div>

    {{-- ২ · আজকের ডেলিভারি --}}
    <h2 class="mb-1 font-semibold">{{ $title(2, 'delivery') }}</h2>
    <div class="{{ $box }}">
        <table class="ui-grid w-full text-sm" data-sec="delivery">
            <thead><tr><th>{{ __('accounts::dayclose.col.challan') }}</th><th>{{ __('accounts::dayclose.col.party') }}</th><th class="num">{{ __('accounts::dayclose.col.value') }}</th>
                <th>{{ __('accounts::dayclose.col.vehicle') }}</th><th class="num">{{ __('accounts::dayclose.col.fare') }}</th><th class="num">{{ __('accounts::dayclose.col.pct') }}</th>
                {{-- ⭐ লোডিং (হাম্মালি) — মালিক, ১৮ নভেম্বর ২০২৬ --}}
                <th class="num">{{ __('supplier::labour.day_column') }}</th></tr></thead>
            <tbody>
                @foreach ($r['delivery']['rows'] as $row)
                    <tr><td class="font-mono text-xs">{{ $row['no'] }}</td><td>{{ $row['party'] }}</td><td class="num">{{ $m($row['value']) }}</td><td class="text-(--color-ink-muted)">{{ $row['vehicle'] }}</td>
                        <td class="num">{{ $z($row['fare']) }}</td><td class="num">{{ $row['pct'] !== null ? $row['pct'].'%' : '' }}</td><td class="num">{{ $z($row['loading'] ?? '0') }}</td></tr>
                @endforeach
                <tr class="font-bold"><td colspan="2" class="text-end">{{ __('accounts::dayclose.delivery_total', ['count' => count($r['delivery']['rows'])]) }}</td><td class="num">{{ $m($r['delivery']['value']) }}</td><td></td>
                    <td class="num">{{ $m($r['delivery']['fare']) }}</td><td class="num">{{ $r['delivery']['pct'] !== null ? $r['delivery']['pct'].'%' : '' }}</td><td class="num">{{ $m($r['delivery']['loading'] ?? '0') }}</td></tr>
            </tbody>
        </table>
    </div>

    {{-- ৩ · পার্টি ধরে বিক্রয় ও লাভ --}}
    <h2 class="mb-1 font-semibold">{{ $title(3, 'sales') }}</h2>
    <div class="{{ $box }}">
        <table class="ui-grid w-full text-sm" data-sec="sales">
            <thead><tr><th>{{ __('accounts::dayclose.col.bill') }}</th><th>{{ __('accounts::dayclose.col.party') }}</th><th class="num">{{ __('accounts::dayclose.col.net') }}</th><th class="num">{{ __('accounts::dayclose.col.vat') }}</th>
                <th class="num">{{ __('accounts::dayclose.col.cogs') }}</th><th class="num">{{ __('accounts::dayclose.col.margin') }}</th><th class="num">{{ __('accounts::dayclose.col.rate') }}</th>
                <th class="num">{{ __('accounts::dayclose.col.delivery') }}</th><th class="num">{{ __('accounts::dayclose.col.after') }}</th><th>{{ __('accounts::dayclose.col.terms') }}</th></tr></thead>
            <tbody>
                @foreach ($r['sales']['rows'] as $row)
                    <tr><td class="font-mono text-xs">{{ $row['no'] }}</td><td>{{ $row['party'] }}</td><td class="num">{{ $m($row['net']) }}</td><td class="num">{{ $m($row['vat']) }}</td><td class="num">{{ $m($row['cogs']) }}</td>
                        <td class="num">{{ $neg($row['margin']) }}</td><td class="num">{{ $row['rate'] !== null ? $row['rate'].'%' : '' }}</td><td class="num">{{ $z($row['delivery']) }}</td><td class="num">{{ $neg($row['after']) }}</td>
                        <td>{{ __('accounts::dayclose.terms.'.$row['terms']) }}</td></tr>
                @endforeach
                @php($t = $r['sales']['totals'])
                <tr class="font-bold" data-sales-total><td colspan="2" class="text-end">{{ __('accounts::dayclose.sales_total', ['count' => count($r['sales']['rows'])]) }}</td><td class="num">{{ $m($t['net']) }}</td><td class="num">{{ $m($t['vat']) }}</td>
                    <td class="num">{{ $m($t['cogs']) }}</td><td class="num">{{ $neg($t['margin']) }}</td><td class="num">{{ $t['rate'] !== null ? $t['rate'].'%' : '' }}</td><td class="num">{{ $z($t['delivery']) }}</td><td class="num">{{ $neg($t['after']) }}</td><td></td></tr>
            </tbody>
        </table>
    </div>

    <div class="grid gap-4 lg:grid-cols-5">
        {{-- ৪ · আজকের খরচ --}}
        <div class="min-w-0 lg:col-span-3">
            <h2 class="mb-1 font-semibold">{{ $title(4, 'expense') }}</h2>
            <div class="{{ $box }}">
                <table class="ui-grid w-full text-sm" data-sec="expense">
                    <thead><tr><th>{{ __('accounts::dayclose.col.code') }}</th><th>{{ __('accounts::dayclose.col.head') }}</th><th>{{ __('accounts::dayclose.col.from') }}</th><th class="num">{{ __('accounts::dayclose.col.amount') }}</th></tr></thead>
                    <tbody>
                        @foreach ($r['expenses']['groups'] as $g)
                            <tr class="bg-(--color-surface-muted) font-semibold"><td colspan="4">{{ __('accounts::dayclose.expense_group.'.$g['key']) }}</td></tr>
                            @foreach ($g['rows'] as $row)
                                <tr><td class="font-mono text-xs">{{ $row['code'] }}</td><td>{{ $row['head'] }}@if ($row['note']) <span class="text-(--color-ink-muted)">— {{ $row['note'] }}</span>@endif</td>
                                    <td class="text-(--color-ink-muted)">{{ $row['from'] }}</td><td class="num">{{ $neg($row['amount']) }}</td></tr>
                            @endforeach
                        @endforeach
                        <tr class="font-bold"><td colspan="3" class="text-end">{{ __('accounts::dayclose.expense_total') }}</td><td class="num">{{ $neg($r['expenses']['total']) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ৫ · নগদ গণনা ও মিল --}}
        <div class="min-w-0 lg:col-span-2">
            <h2 class="mb-1 font-semibold">{{ $title(5, 'count') }}</h2>
            <div class="{{ $box }}">
                @if ($r['count'] === null)
                    <p class="px-4 py-3 text-sm" data-not-counted>{{ __('accounts::dayclose.not_counted') }}</p>
                @else
                    <table class="ui-grid w-full text-sm" data-sec="count">
                        <thead><tr><th class="num">{{ __('accounts::dayclose.col.note_col') }}</th><th class="num">{{ __('accounts::dayclose.col.qty') }}</th><th class="num">{{ __('accounts::dayclose.col.amount') }}</th></tr></thead>
                        <tbody>
                            @foreach ($r['count']['notes'] as $n)
                                <tr><td class="num">{{ number_format($n['note']) }}</td><td class="num">{{ $n['qty'] }}</td><td class="num">{{ $m($n['amount']) }}</td></tr>
                            @endforeach
                            <tr class="font-semibold"><td colspan="2">{{ __('accounts::dayclose.counted') }}</td><td class="num">{{ $m($r['count']['counted']) }}</td></tr>
                            <tr><td colspan="2">{{ __('accounts::dayclose.book_cash') }}</td><td class="num">{{ $m($r['count']['book']) }}</td></tr>
                            <tr class="font-bold"><td colspan="2">{{ __('accounts::dayclose.gap') }}</td><td class="num" data-cash-gap>{{ $neg($r['count']['gap']) }}</td></tr>
                            @if ($r['count']['reason'])<tr><td colspan="3" class="text-(--color-ink-muted)">{{ __('accounts::dayclose.reason') }}: {{ $r['count']['reason'] }}</td></tr>@endif
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    {{-- ⭐ ৪খ · হাম্মালি (মাল নামানো ও তোলা) — মালিক, ১৮ নভেম্বর ২০২৬ ("ওকে"); ৪-এর উপ-অংশ, তাই মালিকের ১১ অংশের ক্রম ভাঙে না --}}
    @php($lab = $r['labour'])
    <h2 class="mb-1 font-semibold">{{ __('accounts::dayclose.labour.no') }} · {{ __('supplier::labour.day_title') }}</h2>
    <div class="{{ $box }}">
        <table class="ui-grid w-full text-sm" data-sec="labour">
            <thead><tr><th>{{ __('supplier::labour.day_paper') }}</th><th>{{ __('supplier::labour.day_kind') }}</th><th>{{ __('supplier::labour.day_party') }}</th>
                <th>{{ __('supplier::labour.day_how') }}</th><th class="num">{{ __('accounts::dayclose.col.amount') }}</th></tr></thead>
            <tbody>
                @forelse ($lab['rows'] as $row)
                    <tr><td class="font-mono text-xs">{{ $row['no'] }}</td><td>{{ __('supplier::labour.day_'.$row['kind']) }}</td><td>{{ $row['party'] }}</td>
                        <td class="text-(--color-ink-muted)">{{ $row['how'] }}</td><td class="num">{{ $m($row['amount']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-(--color-ink-muted)" data-labour-none>{{ __('supplier::labour.day_none') }}</td></tr>
                @endforelse
                @if ($lab['rows'] !== [])
                    <tr><td colspan="4" class="text-end">{{ __('supplier::labour.day_unloading') }}</td><td class="num">{{ $m($lab['unloading']) }}</td></tr>
                    <tr><td colspan="4" class="text-end">{{ __('supplier::labour.day_loading') }}</td><td class="num">{{ $m($lab['loading']) }}</td></tr>
                @endif
                <tr class="font-bold" data-labour-total><td colspan="4" class="text-end">{{ __('supplier::labour.day_total') }}</td><td class="num">{{ $m($lab['total']) }}</td></tr>
                <tr class="font-semibold" data-labour-payable><td colspan="4" class="text-end">
                    @if (\Illuminate\Support\Facades\Route::has('finance.carrier_labour.index') && auth()->user()?->can('finance.expense.view'))
                        <a href="{{ route('finance.carrier_labour.index', ['tab' => 'labour']) }}" class="text-(--color-brand-600) underline-offset-2 hover:underline" data-labour-payable-link>{{ __('supplier::labour.day_payable') }}</a>
                    @else
                        {{ __('supplier::labour.day_payable') }}
                    @endif
                    </td><td class="num">{{ $neg($lab['payable']) }}</td></tr>
            </tbody>
        </table>
    </div>

    {{-- ৬ · আজ টাকা গেছে, কিন্তু খরচ নয় --}}
    <h2 class="mb-1 font-semibold">{{ $title(6, 'paid_out') }}</h2>
    <div class="{{ $box }}">
        <table class="ui-grid w-full text-sm" data-sec="paid_out">
            <thead><tr><th>{{ __('accounts::dayclose.col.voucher') }}</th><th>{{ __('accounts::dayclose.col.description') }}</th><th>{{ __('accounts::dayclose.col.from') }}</th><th class="num">{{ __('accounts::dayclose.col.amount') }}</th></tr></thead>
            <tbody>
                @foreach ($r['paid_out']['rows'] as $row)
                    <tr><td class="font-mono text-xs">{{ $row['no'] }}</td><td>{{ $row['note'] }}</td><td class="text-(--color-ink-muted)">{{ $row['from'] }}</td><td class="num">{{ $m($row['amount']) }}</td></tr>
                @endforeach
                <tr class="font-bold"><td colspan="3" class="text-end">{{ __('accounts::dayclose.paid_out_total') }}</td><td class="num">{{ $m($r['paid_out']['total']) }}</td></tr>
            </tbody>
        </table>
    </div>

    {{-- ৭ · হিসাবের জের --}}
    <h2 class="mb-1 font-semibold">{{ $title(7, 'balances') }}</h2>
    <div class="{{ $box }}">
        <table class="ui-grid w-full text-sm" data-sec="balances">
            <thead><tr><th>{{ __('accounts::dayclose.col.acct') }}</th><th class="num">{{ __('accounts::dayclose.col.opening') }}</th><th class="num">{{ __('accounts::dayclose.col.in') }}</th>
                <th class="num">{{ __('accounts::dayclose.col.out') }}</th><th class="num">{{ __('accounts::dayclose.col.closing') }}</th></tr></thead>
            <tbody>
                @foreach ($r['balances']['rows'] as $row)
                    <tr><td>{{ $row['name'] }}@if ($row['holder']) <span class="text-(--color-ink-muted)">· {{ $row['holder'] }}</span>@endif</td><td class="num">{{ $neg($row['opening']) }}</td>
                        <td class="num">{{ $z($row['in']) }}</td><td class="num">{{ $z($row['out']) }}</td><td class="num">{{ $neg($row['closing']) }}</td></tr>
                @endforeach
                <tr class="font-bold"><td>{{ __('accounts::dayclose.balances_total') }}</td><td></td><td class="num">{{ $m($r['balances']['in_total']) }}</td><td class="num">{{ $m($r['balances']['out_total']) }}</td><td class="num">{{ $neg($r['balances']['closing_total']) }}</td></tr>
            </tbody>
        </table>
    </div>

    {{-- ৮ · লাভ-ক্ষতির সারাংশ --}}
    <h2 class="mb-1 font-semibold">{{ $title(8, 'pl') }}</h2>
    @php($pl = $r['pl'])
    <div class="{{ $box }}">
        <table class="ui-grid w-full text-sm" data-sec="pl">
            <thead><tr><th>{{ __('accounts::dayclose.col.particular') }}</th><th class="num">{{ __('accounts::dayclose.col.today') }}</th><th class="num">{{ __('accounts::dayclose.col.mtd') }}</th><th class="num">{{ __('accounts::dayclose.col.avg') }}</th></tr></thead>
            <tbody>
                @foreach (['gross', 'vat', 'returned', 'net', 'cogs', 'margin', 'delivery', 'after', 'office', 'fixed'] as $key)
                    @continue($key === 'returned' && bccomp((string) $pl['month']['returned'], '0', 4) === 0)
                    <tr @class(['font-semibold' => in_array($key, ['net', 'margin', 'after'], true)])>
                        <td><span @class(['ms-6 inline-block' => in_array($key, ['vat', 'returned', 'cogs', 'delivery', 'office', 'fixed'], true)])>{{ __('accounts::dayclose.pl.'.$key, ['rate' => $pl['month']['margin_rate'] ?? '0', 'monthly' => $m($r['fixed']['monthly']), 'days' => $r['fixed']['workdays']]) }}</span></td>
                        @foreach (['today', 'month', 'avg'] as $col)<td class="num">{{ $neg($pl[$col][$key] ?? '0') }}</td>@endforeach
                    </tr>
                @endforeach
                <tr class="font-bold" data-net-profit><td>{{ __('accounts::dayclose.pl.profit', ['rate' => $pl['month']['net_rate'] ?? '0']) }}</td>
                    @foreach (['today', 'month', 'avg'] as $col)<td class="num">{{ $neg($pl[$col]['profit']) }}</td>@endforeach</tr>
            </tbody>
        </table>
    </div>

    {{-- ৯ · মজুদ ও বকেয়া · ১০ স্থির খরচ · ১১ মূসক ও লক্ষ্য --}}
    @php($v = $r['vat'])
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="min-w-0">
            <h2 class="mb-1 font-semibold">{{ $title(9, 'stock') }}</h2>
            <div class="{{ $box }}">
                <table class="ui-grid w-full text-sm" data-sec="stock">
                    <thead><tr><th></th><th class="num">{{ __('accounts::dayclose.col.stock') }}</th><th class="num">{{ __('accounts::dayclose.col.dues') }}</th></tr></thead>
                    <tbody>
                        @foreach (['opening', 'in', 'out', 'closing'] as $k)
                            <tr @class(['font-bold' => $k === 'closing'])><td>{{ __('accounts::dayclose.roll.'.$k) }}</td><td class="num">{{ $neg($r['stock']['stock'][$k]) }}</td><td class="num">{{ $neg($r['stock']['dues'][$k]) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
                <table class="ui-grid w-full text-sm" data-sec="oldest">
                    <thead><tr><th>{{ __('accounts::dayclose.col.oldest') }}</th><th class="num">{{ __('accounts::dayclose.col.amount') }}</th><th class="num">{{ __('accounts::dayclose.col.age') }}</th></tr></thead>
                    <tbody>
                        @foreach ($r['stock']['oldest'] as $o)
                            <tr><td>{{ $o['party'] }}</td><td class="num">{{ $m($o['amount']) }}</td><td class="num">{{ __('accounts::dayclose.days', ['count' => $o['days']]) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="min-w-0">
            <h2 class="mb-1 font-semibold">{{ $title(10, 'fixed') }}</h2>
            <div class="{{ $box }}">
                <table class="ui-grid w-full text-sm" data-sec="fixed">
                    <thead><tr><th>{{ __('accounts::dayclose.col.fixed_head') }}</th><th class="num">{{ __('accounts::dayclose.col.monthly') }}</th><th class="num">{{ __('accounts::dayclose.col.daily') }}</th></tr></thead>
                    <tbody>
                        @forelse ($r['fixed']['rows'] as $row)
                            <tr><td>{{ __('accounts::dayclose.fixed_heads.'.$row['key']) }}</td><td class="num">{{ $m($row['monthly']) }}</td><td class="num">{{ $m($row['daily']) }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="text-(--color-ink-muted)">{{ __('accounts::dayclose.fixed_none') }}</td></tr>
                        @endforelse
                        <tr class="font-bold"><td>{{ __('accounts::dayclose.fixed_total') }}</td><td class="num">{{ $m($r['fixed']['monthly']) }}</td><td class="num">{{ $m($r['fixed']['daily']) }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="min-w-0">
            <h2 class="mb-1 font-semibold">{{ $title(11, 'vat') }}</h2>
            <div class="{{ $box }}">
                <table class="ui-grid w-full text-sm" data-sec="vat">
                    <tbody>
                        <tr><td>{{ __('accounts::dayclose.vat_63') }}</td><td class="num">{{ $v['challans_63'] }} · {{ $m($v['vat_today']) }}</td></tr>
                        <tr><td>{{ __('accounts::dayclose.vat_67') }}</td><td class="num">{{ $v['notes_67'] }}</td></tr>
                        <tr class="font-semibold"><td>{{ __('accounts::dayclose.vat_month', ['month' => $day->copy()->locale(app()->getLocale())->translatedFormat('F')]) }}</td><td class="num">{{ $m($v['vat_month']) }}</td></tr>
                        <tr><td colspan="2" class="text-(--color-ink-muted)">{{ __('accounts::dayclose.vat_due', ['date' => \Illuminate\Support\Carbon::parse($v['vat_due_on'])->format('d/m/Y')]) }}</td></tr>
                        @if ($v['target'] === null)
                            <tr><td colspan="2" class="text-(--color-ink-muted)">{{ __('accounts::dayclose.target_none') }}</td></tr>
                        @else
                            <tr><td>{{ __('accounts::dayclose.target') }}</td><td class="num">{{ $m($v['target']) }}</td></tr>
                            <tr><td>{{ __('accounts::dayclose.achieved') }}</td><td class="num">{{ $m($v['achieved']) }}</td></tr>
                            <tr class="font-semibold"><td>{{ __('accounts::dayclose.elapsed', ['pct' => $v['elapsed_pct']]) }}</td><td class="num">{{ $v['achieved_pct'] }}%</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ⭐ দিন বন্ধের পরে যোগ হয়েছে — কেউ চুপচাপ পুরনো দিনের অঙ্ক বদলাতে পারবে না (মালিক, ১৮ নভেম্বর ২০২৬) --}}
    @if ($r['late'] !== [])
        <h2 class="mb-1 mt-2 font-semibold text-(--color-danger)">{{ __('accounts::dayclose.late.title') }}</h2>
        <p class="mb-1 text-sm text-(--color-ink-muted)">{{ __('accounts::dayclose.late.note') }}</p>
        <div class="{{ $box }}">
            <table class="ui-grid w-full text-sm" data-sec="late">
                <thead><tr><th>{{ __('accounts::dayclose.late.paper') }}</th><th>{{ __('accounts::dayclose.late.kind') }}</th><th class="num">{{ __('accounts::dayclose.col.amount') }}</th>
                    <th>{{ __('accounts::dayclose.late.who') }}</th><th>{{ __('accounts::dayclose.late.when') }}</th></tr></thead>
                <tbody>
                    @foreach ($r['late'] as $row)
                        <tr><td class="font-mono text-xs">{{ $row['no'] }}</td><td>{{ __('core.source.'.$row['type']) }}</td><td class="num">{{ $m($row['amount']) }}</td>
                            <td>{{ $row['who'] }}</td><td>{{ $row['when']->format('d/m/Y h:i A') }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- দিন বন্ধ --}}
    <section class="mt-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-muted) px-4 py-3 text-sm" data-close-line>
        @if ($r['closed'])
            @php($c = $r['closed'])
            <b>{{ __('accounts::dayclose.close_line', [
                'count' => $c->cash_counted === null ? __('accounts::dayclose.close_line_uncounted')
                    : (bccomp((string) $c->cash_gap, '0', 2) === 0 ? __('accounts::dayclose.close_line_count_ok') : __('accounts::dayclose.close_line_count_gap', ['gap' => $m(ltrim((string) $c->cash_gap, '-'))])),
                'date' => $day->format('d/m/Y'),
                'who' => $c->closer?->name ?? '—',
                'time' => $c->closed_at?->format('h:i A') ?? '',
            ]) }}</b>
            {{-- ⭐ দিন আবার খোলা — কারণ লিখে, কেবল যাঁর চাবি আছে (মালিক, ১৮ নভেম্বর ২০২৬) --}}
            @if ($canReopen)
                <form method="POST" action="{{ route('executive.dayclose.reopen') }}" class="mt-3 flex flex-wrap items-end gap-3" data-day-reopen-form>
                    @csrf
                    <input type="hidden" name="date" value="{{ $day->toDateString() }}">
                    <x-ui.field name="reopen_reason" :label="__('accounts::dayclose.reopen.reason')" required />
                    <x-ui.button type="submit" tone="secondary">{{ __('accounts::dayclose.reopen.button') }}</x-ui.button>
                </form>
            @endif
        @else
            @if ($r['record']?->reopened_at)
                <p class="mb-2 font-semibold" data-reopened-line>{{ __('accounts::dayclose.reopen.line', [
                    'who' => $r['record']->reopener?->name ?? '—',
                    'time' => $r['record']->reopened_at->format('d/m/Y h:i A'),
                    'reason' => $r['record']->reopen_reason,
                ]) }}</p>
            @endif
            <p class="mb-2">{{ __('accounts::dayclose.open_line') }}</p>
            {{-- ⭐ বন্ধের আগে চেকলিস্ট (মালিক, ১৮ নভেম্বর ২০২৬) — কেবল সতর্কতা, বোতাম সবসময় চলে --}}
            <div class="mb-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-muted) px-4 py-3 print-hide" data-day-check>
                <h3 class="mb-1 font-semibold">{{ __('accounts::dayclose.check.title') }}</h3>
                @include('accounts::partials.day-checklist', ['items' => $checklist ?? []])
                @if (($checklist ?? []) !== [])<p class="mt-1 text-xs text-(--color-ink-muted)">{{ __('accounts::dayclose.check.note') }}</p>@endif
            </div>
            @if ($canClose && ! $day->isFuture())
                <form method="POST" action="{{ route('executive.dayclose.close') }}" class="flex flex-wrap items-end gap-3" data-day-close-form>
                    @csrf
                    <input type="hidden" name="date" value="{{ $day->toDateString() }}">
                    @if ($r['count'] !== null && bccomp($r['count']['gap'], '0', 2) !== 0)
                        <x-ui.field name="gap_reason" :label="__('accounts::dayclose.close.reason')" :value="$r['count']['reason']" />
                    @endif
                    <x-ui.button type="submit" tone="primary">{{ __('accounts::dayclose.close.button') }}</x-ui.button>
                    <span class="text-xs text-(--color-ink-muted)">{{ __('accounts::dayclose.close.confirm') }}</span>
                </form>
            @endif
        @endif
    </section>
</x-layouts.app>
