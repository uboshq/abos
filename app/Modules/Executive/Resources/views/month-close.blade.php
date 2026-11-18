{{--
    ⭐ মাস শেষের হিসাব — পর্দা (মালিক, ১৭ নভেম্বর ২০২৬: নমুনা "Ok", "Sob ok"; [[MonthClosePack]], [[MonthCloseController]])।
    ছাপা কাগজের ([[print/month-close]]) একই এগারো অংশ, একই অঙ্ক — মাস বাছাই, PDF, আর মাস খোলা থাকলে মাস বন্ধের পাতার পথ।
    ⓘ মাস বন্ধ যেমন ছিল তেমনই — এখানে কেবল দেখা।
--}}
@php
    $t = fn (string $k, array $r = []) => __('executive::month_close.'.$k, $r);
    $mf = fn ($v) => $v === null ? '—' : \App\Core\Support\Money::format((string) $v);
    $less = fn ($v) => $v === null ? '—' : (bccomp((string) $v, '0', 4) === 0 ? \App\Core\Support\Money::format('0') : '('.\App\Core\Support\Money::format(ltrim((string) $v, '-')).')');
    $signed = fn ($v) => $v === null ? '—' : (str_starts_with((string) $v, '-') ? '('.\App\Core\Support\Money::format(ltrim((string) $v, '-')).')' : \App\Core\Support\Money::format((string) $v));
    $p = $m['period'];
    $plCols = \App\Modules\Executive\Services\MonthClose\MonthClosePack::COLUMNS;
    $ar = $m['receivables'];
    $ap = $m['payables'];
    $st = $m['stock'];
    $v = $m['vat'];
    $pay = $m['payroll'];
    $fx = $m['fixed'];
    $k = $m['sales']['kinds'];
    $lock = $m['lock'];
    $pending = collect($m['checks'])->where('state', 'pending')->reject(fn ($c) => $c['key'] === 'locked')->count();
    $card = 'min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)';
    $head = 'flex flex-wrap items-baseline justify-between gap-2 border-b border-(--color-border) px-4 py-2';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $t('title') }}</x-slot:title>

    <x-money.page kind="journal" :title="$t('title')" icon="calendar"
        :count="$p['label'].' ('.$p['range'].') · '.$p['branch']">
        <x-slot:actions>
            <form method="GET" action="{{ route('executive.month_close') }}" class="flex flex-wrap items-center gap-2" data-month-pick>
                <x-ui.month name="month" :value="$p['key']" :max="now()->format('Y-m')"
                            class="min-h-(--spacing-touch) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-(--color-ink)" />
                <x-ui.button type="submit" tone="secondary">{{ $t('show') }}</x-ui.button>
            </form>
            <x-ui.button tone="secondary" icon="printer" :href="$pdfUrl" target="_blank" data-month-pdf>{{ $t('pdf') }}</x-ui.button>
        </x-slot:actions>

    {{-- এক লাইনের তথ্য --}}
    <p class="mb-3 flex flex-wrap gap-x-5 gap-y-1 text-sm text-(--color-ink-muted)" data-info-line>
        <span>{{ $t('info.month') }}: <b class="text-(--color-ink)">{{ $p['label'] }} ({{ $p['range'] }})</b></span>
        <span>{{ $t('info.working_days') }}: <b class="num text-(--color-ink)">{{ $p['working_days'] }}</b> ({{ $t('info.fridays_off') }})</span>
        <span>{{ $t('info.branch') }}: <b class="text-(--color-ink)">{{ $p['branch'] }}</b></span>
        @if ($p['closed_by'])<span>{{ $t('info.closed_by') }}: <b class="text-(--color-ink)">{{ $p['closed_by'] }}</b></span>@endif
    </p>

    {{-- মাসের খবর --}}
    <section class="{{ $card }} mb-4" data-news>
        <h2 class="{{ $head }} font-semibold">{{ $t('news_title') }}</h2>
        <div class="grid gap-x-6 gap-y-1 px-4 py-3 text-sm md:grid-cols-2">
            <ul class="list-disc space-y-1 ps-5">@foreach ($m['news']['left'] as $line)<li>{!! $line !!}</li>@endforeach</ul>
            <ul class="list-disc space-y-1 ps-5">@foreach ($m['news']['right'] as $line)<li>{!! $line !!}</li>@endforeach</ul>
        </div>
    </section>

    {{-- ১ লাভ-ক্ষতি --}}
    <section class="{{ $card }} mb-4" data-section="pl">
        <h2 class="{{ $head }}"><span class="font-semibold">1 · {{ $t('s.pl') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.pl') }}</span></h2>
        <div class="overflow-x-auto">
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.particulars') }}</th>@foreach ($plCols as $c)<th class="num">{{ $p['columns'][$c] }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($m['pl']['rows'] as $row)
                    <tr data-pl="{{ $row['key'] }}" @class(['font-semibold bg-(--color-surface-sunken)' => $row['style'] === 'sub'])>
                        {{-- ⓘ বাদ/যোগের লাইন ভেতরে সরানো — ঘরের প্যাডিং নয় (থিমের ঘনত্ব), ভেতরের লেখায় --}}
                        <td><span @class(['inline-block ps-6' => in_array($row['style'], ['less', 'add'], true)])>{{ $t('pl.'.$row['key']) }}</span></td>
                        @foreach ($plCols as $c)<td class="num">{{ $row['style'] === 'less' ? $less($row['values'][$c]) : $signed($row['values'][$c]) }}</td>@endforeach
                    </tr>
                @endforeach
                    <tr data-pl="net_profit" class="font-bold bg-(--color-surface-sunken)">
                        <td>{{ $t('pl.net_profit') }}</td>
                        @foreach ($plCols as $c)<td class="num">{{ $signed($m['pl']['figures'][$c]['net_profit'] ?? null) }}</td>@endforeach
                    </tr>
                    <tr><td><span class="inline-block ps-6">{{ $t('pl.margin') }}</span></td>@foreach ($plCols as $c)<td class="num">{{ $m['pl']['margin'][$c] ?? '—' }}</td>@endforeach</tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- ২ বিক্রি --}}
    <div class="mb-4 grid gap-4 xl:grid-cols-2">
        <section class="{{ $card }}" data-section="branches">
            <h2 class="{{ $head }}"><span class="font-semibold">2 · {{ $t('s.branches') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.branches') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.branch') }}</th><th class="num">{{ $t('h.bill') }}</th><th class="num">{{ $t('h.margin') }}</th><th class="num">{{ $t('h.vs_prior') }}</th></tr></thead>
                <tbody>
                @forelse ($m['sales']['branches'] as $r)
                    <tr><td>{{ $r['name'] }}</td><td class="num">{{ $mf($r['bill']) }}</td><td class="num">{{ $r['margin'] ?? '—' }}</td><td class="num">{{ $r['change'] ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-(--color-ink-muted)">{{ $t('empty.sales') }}</td></tr>
                @endforelse
                    <tr class="font-bold"><td>{{ $t('h.total') }}</td><td class="num">{{ $mf($m['sales']['parts']['gross']) }}</td><td class="num">{{ $m['sales']['margin'] ?? '—' }}</td><td class="num">{{ $m['sales']['change'] ?? '—' }}</td></tr>
                </tbody>
            </table>
        </section>
        <section class="{{ $card }}" data-section="groups">
            <h2 class="{{ $head }}"><span class="font-semibold">{{ $t('s.groups') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.groups') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.group') }}</th><th class="num">{{ $t('h.qty') }}</th><th class="num">{{ $t('h.net_sales') }}</th><th class="num">{{ $t('h.rate') }}</th></tr></thead>
                <tbody>
                @forelse ($m['sales']['groups'] as $r)
                    <tr><td>{{ $r['name'] }}</td><td class="num">{{ $r['qty'] ?? '—' }}</td><td class="num">{{ $mf($r['amount']) }}</td><td class="num">{{ $r['margin'] ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-(--color-ink-muted)">{{ $t('empty.sales') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <section class="{{ $card }} mb-4" data-section="sellers">
        <h2 class="{{ $head }}"><span class="font-semibold">{{ $t('s.sellers') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.sellers') }}</span></h2>
        <div class="overflow-x-auto">
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.seller') }}</th><th>{{ $t('h.area') }}</th><th class="num">{{ $t('h.target') }}</th><th class="num">{{ $t('h.net_sales') }}</th><th class="num">{{ $t('h.achieved') }}</th><th class="num">{{ $t('h.collected') }}</th></tr></thead>
                <tbody>
                @forelse ($m['sales']['sellers']['rows'] as $r)
                    <tr><td>{{ $r['name'] }}</td><td>{{ $r['area'] ?: '—' }}</td><td class="num">{{ $mf($r['target']) }}</td><td class="num">{{ $mf($r['net']) }}</td><td class="num">{{ $r['achieved'] ?? '—' }}</td><td class="num">{{ $mf($r['collected']) }}</td></tr>
                @empty
                    <tr><td colspan="6" class="text-(--color-ink-muted)">{{ $t('empty.sales') }}</td></tr>
                @endforelse
                    <tr class="font-bold"><td colspan="2">{{ $t('h.total') }}</td><td class="num">{{ $mf($m['sales']['sellers']['target']) }}</td><td class="num">{{ $mf($m['sales']['sellers']['net']) }}</td><td class="num">{{ $m['sales']['sellers']['achieved'] ?? '—' }}</td><td class="num">{{ $mf($m['sales']['sellers']['collected']) }}</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <div class="mb-4 grid gap-4 xl:grid-cols-2">
        <section class="{{ $card }}" data-section="top">
            <h2 class="{{ $head }}"><span class="font-semibold">{{ $t('s.top') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.top') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th class="num">{{ $t('h.sl') }}</th><th>{{ $t('h.party') }}</th><th class="num">{{ $t('h.bill') }}</th><th class="num">{{ $t('h.share') }}</th></tr></thead>
                <tbody>
                @forelse ($m['sales']['top'] as $i => $r)
                    <tr><td class="num">{{ $i + 1 }}</td><td>{{ $r['name'] }}@if ($r['point']) <span class="text-(--color-ink-muted)">· {{ $r['point'] }}</span>@endif</td><td class="num">{{ $mf($r['bill']) }}</td><td class="num">{{ $r['share'] ?? '—' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-(--color-ink-muted)">{{ $t('empty.sales') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
        <section class="{{ $card }}" data-section="kinds">
            <h2 class="{{ $head }}"><span class="font-semibold">{{ $t('s.kinds') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.kinds') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.kind') }}</th><th class="num">{{ $t('h.amount_count') }}</th></tr></thead>
                <tbody>
                    <tr class="font-semibold"><td>{{ $t('k.billed') }}</td><td class="num">{{ $mf($k['billed']) }}</td></tr>
                    <tr><td>{{ $t('k.returned', ['n' => $k['return_count']]) }}@if ($k['return_nos'] !== []) ({{ implode(', ', $k['return_nos']) }})@endif</td><td class="num">{{ $less($k['returned']) }}</td></tr>
                    <tr class="font-semibold"><td>{{ $t('k.after_returns') }}</td><td class="num">{{ $mf($k['after_returns']) }}</td></tr>
                    <tr><td>{{ $t('k.collected') }}</td><td class="num">{{ $mf($k['collected']) }}</td></tr>
                    <tr><td>{{ $t(bccomp($k['due_change'], '0', 4) >= 0 ? 'k.due_up' : 'k.due_down') }}</td><td class="num">{{ $mf(ltrim($k['due_change'], '-')) }}</td></tr>
                    <tr><td>{{ $t('k.bills') }}</td><td class="num">{{ $t('count', ['n' => $k['bills']]) }}</td></tr>
                    <tr><td>{{ $t('k.buyers') }}</td><td class="num">{{ $t('people', ['n' => $k['buyers']]) }}</td></tr>
                </tbody>
            </table>
        </section>
    </div>

    {{-- ৩ টাকা, মিল | ৪ স্থির খরচ --}}
    <section class="{{ $card }} mb-4" data-section="money">
        <h2 class="{{ $head }}"><span class="font-semibold">3 · {{ $t('s.money') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.money') }}</span></h2>
        <div class="overflow-x-auto">
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.account') }}</th><th class="num">{{ $t('h.opening') }}</th><th class="num">{{ $t('h.in') }}</th><th class="num">{{ $t('h.out') }}</th><th class="num">{{ $t('h.closing') }}</th><th class="num">{{ $t('h.statement') }}</th><th>{{ $t('h.match') }}</th></tr></thead>
                <tbody>
                @foreach ($m['money']['rows'] as $r)
                    <tr data-recon="{{ $r['state'] }}">
                        <td><a class="text-(--color-link) hover:underline" href="{{ route('accounts.coa.show', $r['id']) }}">{{ $r['name'] }}</a></td>
                        <td class="num">{{ $signed($r['opening']) }}</td><td class="num">{{ $mf($r['in']) }}</td><td class="num">{{ $mf($r['out']) }}</td><td class="num font-semibold">{{ $signed($r['closing']) }}</td>
                        <td class="num">{{ $signed($r['counted']) }}</td>
                        <td><x-ui.badge :tone="$r['state'] === 'ok' ? 'success' : ($r['state'] === 'gap' ? 'pending' : 'danger')">{{ $r['note'] }}</x-ui.badge></td>
                    </tr>
                @endforeach
                    <tr class="font-bold"><td>{{ $t('h.total') }}</td><td class="num">{{ $signed($m['money']['total']['opening']) }}</td><td class="num">{{ $mf($m['money']['total']['in']) }}</td><td class="num">{{ $mf($m['money']['total']['out']) }}</td><td class="num">{{ $signed($m['money']['total']['closing']) }}</td><td></td><td></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <div class="mb-4 grid gap-4 xl:grid-cols-2">
        <section class="{{ $card }}" data-section="fixed">
            <h2 class="{{ $head }}"><span class="font-semibold">4 · {{ $t('s.fixed') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.fixed') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.fixed_head') }}</th><th class="num">{{ $t('h.actual') }}</th><th class="num">{{ $t('h.budget') }}</th><th class="num">{{ $t('h.gap') }}</th></tr></thead>
                <tbody>
                @forelse ($fx['rows'] as $r)
                    <tr><td>{{ $t('fixed.'.$r['key']) }}</td><td class="num">{{ $mf($r['actual']) }}</td><td class="num">{{ $mf($r['budget']) }}</td>
                        <td class="num">{{ $signed($r['gap']) }} @if ($r['over'] !== null && $r['over'] !== 0)<span class="text-2xs">{{ $t($r['over'] > 0 ? 'over' : 'under') }}</span>@endif</td></tr>
                @empty
                    <tr><td colspan="4" class="text-(--color-ink-muted)">{{ $t('empty.fixed') }}</td></tr>
                @endforelse
                    <tr class="font-bold"><td>{{ $t('h.total') }}</td><td class="num">{{ $mf($fx['total']['actual']) }}</td><td class="num">{{ $fx['has_budget'] ? $mf($fx['total']['budget']) : '—' }}</td><td class="num">{{ $signed($fx['total']['gap']) }}</td></tr>
                </tbody>
            </table>
            @unless ($fx['has_budget'])<p class="px-4 py-2 text-2xs text-(--color-ink-muted)">{{ $p['one_branch'] ? $t('budget_one_branch') : $t('budget_none') }}</p>@endunless
        </section>

        {{-- ৫ পাওনা --}}
        <section class="{{ $card }}" data-section="receivables">
            <h2 class="{{ $head }}"><span class="font-semibold">5 · {{ $t('s.receivables') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.receivables') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.customer_due') }}</th><th class="num">{{ $t('h.amount') }}</th></tr></thead>
                <tbody>
                    <tr data-roll="opening"><td>{{ $t('roll.opening', ['date' => \Illuminate\Support\Carbon::parse($p['from'])->format('d-m')]) }}</td><td class="num">{{ $signed($ar['opening']) }}</td></tr>
                    <tr data-roll="sales"><td>{{ $t('roll.credit_sales') }}</td><td class="num">{{ $signed($ar['sales']) }}</td></tr>
                    <tr data-roll="collections"><td>{{ $t('roll.collections') }}</td><td class="num">{{ $less($ar['collections']) }}</td></tr>
                    <tr data-roll="returns"><td>{{ $t('roll.returns') }}</td><td class="num">{{ $less($ar['returns']) }}</td></tr>
                    @if (bccomp($ar['other'], '0', 4) !== 0)<tr data-roll="other"><td>{{ $t('roll.other') }}</td><td class="num">{{ $signed($ar['other']) }}</td></tr>@endif
                    <tr class="font-bold" data-roll="closing"><td>{{ $t('roll.closing', ['date' => \Illuminate\Support\Carbon::parse($p['to'])->format('d-m')]) }}</td><td class="num">{{ $signed($ar['closing']) }}</td></tr>
                    <tr><td>{{ $t('roll.rate') }}</td><td class="num">{{ $ar['rate'] ?? '—' }}</td></tr>
                </tbody>
            </table>
        </section>
    </div>

    <div class="mb-4 grid gap-4 xl:grid-cols-2">
        <section class="{{ $card }}" data-section="ageing">
            <h2 class="{{ $head }}"><span class="font-semibold">{{ $t('s.ageing') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.ageing') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.age') }}</th><th class="num">{{ $t('h.due') }}</th><th class="num">{{ $t('h.share') }}</th><th class="num">{{ $t('h.rate') }}</th><th class="num">{{ $t('h.ecl') }}</th></tr></thead>
                <tbody>
                @foreach ($ar['ageing']['buckets'] as $b)
                    <tr data-bucket="{{ $b['key'] }}"><td>{{ $t('age.'.$b['key']) }}</td><td class="num">{{ $mf($b['due']) }}</td><td class="num">{{ $b['share'] ?? '—' }}</td><td class="num">{{ \App\Core\Support\Money::quantity($b['rate']) }}%</td><td class="num">{{ $mf($b['loss']) }}</td></tr>
                @endforeach
                    <tr class="font-bold"><td>{{ $t('h.total') }}</td><td class="num">{{ $mf($ar['ageing']['total']) }}</td><td></td><td></td><td class="num" data-ecl>{{ $mf($ar['ageing']['ecl']) }}</td></tr>
                    <tr><td colspan="4">{{ $t('ecl_before', ['amount' => $mf($ar['ecl_before'])]) }}</td><td class="num">{{ $signed($ar['ecl_change']) }}</td></tr>
                </tbody>
            </table>
        </section>
        <section class="{{ $card }}" data-section="oldest">
            <h2 class="{{ $head }}"><span class="font-semibold">{{ $t('s.oldest') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.oldest') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.party') }}</th><th>{{ $t('h.seller') }}</th><th class="num">{{ $t('h.amount') }}</th><th class="num">{{ $t('h.age') }}</th></tr></thead>
                <tbody>
                @forelse ($ar['oldest'] as $r)
                    <tr><td>{{ $r['name'] }}@if ($r['point']) <span class="text-(--color-ink-muted)">· {{ $r['point'] }}</span>@endif</td><td>{{ $r['seller'] ?: '—' }}</td><td class="num">{{ $mf($r['amount']) }}</td><td class="num">{{ $r['days'] === null ? '—' : $t('days', ['n' => $r['days']]) }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-(--color-ink-muted)">{{ $t('empty.dues') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>

    {{-- ৬ দেনা | ৭ মজুদ --}}
    <div class="mb-4 grid gap-4 xl:grid-cols-2">
        <section class="{{ $card }}" data-section="payables">
            <h2 class="{{ $head }}"><span class="font-semibold">6 · {{ $t('s.payables') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.payables') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.supplier') }}</th><th class="num">{{ $t('h.owed') }}</th><th class="num">{{ $t('h.due_next', ['month' => $ap['next_month']]) }}</th><th class="num">{{ $t('h.due_on') }}</th></tr></thead>
                <tbody>
                @forelse ($ap['rows'] as $r)
                    <tr><td>{{ $r['name'] }}</td><td class="num">{{ $mf($r['amount']) }}</td><td class="num">{{ $mf($r['due_next']) }}</td><td class="num">{{ $r['due_on'] ? \App\Core\Support\DateFormat::format($r['due_on']) : '—' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="text-(--color-ink-muted)">{{ $t('empty.payables') }}</td></tr>
                @endforelse
                    <tr class="font-bold"><td>{{ $t('h.total') }}</td><td class="num">{{ $signed($ap['closing']) }}</td><td class="num">{{ $mf($ap['due_total']) }}</td><td></td></tr>
                </tbody>
            </table>
            <p class="px-4 py-2 text-2xs text-(--color-ink-muted)">{{ $t('ap_line', ['opening' => $signed($ap['opening']), 'purchases' => $mf($ap['purchases']), 'paid' => $mf($ap['paid']), 'closing' => $signed($ap['closing'])]) }}</p>
        </section>
        <section class="{{ $card }}" data-section="stock">
            <h2 class="{{ $head }}"><span class="font-semibold">7 · {{ $t('s.stock') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.stock') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.stock_cost') }}</th><th class="num">{{ $t('h.amount') }}</th></tr></thead>
                <tbody>
                    <tr><td>{{ $t('roll.opening', ['date' => \Illuminate\Support\Carbon::parse($p['from'])->format('d-m')]) }}</td><td class="num">{{ $signed($st['opening']) }}</td></tr>
                    <tr><td>{{ $t('roll.purchases') }}</td><td class="num">{{ $signed($st['in']) }}</td></tr>
                    <tr><td>{{ $t('roll.cogs') }}</td><td class="num">{{ $less($st['cogs']) }}</td></tr>
                    <tr><td>{{ $t('roll.shortage') }}</td><td class="num">{{ $less($st['shortage']) }}</td></tr>
                    <tr class="font-bold"><td>{{ $t('roll.closing', ['date' => \Illuminate\Support\Carbon::parse($p['to'])->format('d-m')]) }}</td><td class="num">{{ $signed($st['closing']) }}</td></tr>
                </tbody>
            </table>
            <h3 class="border-t border-(--color-border) px-4 py-2 text-sm font-semibold">{{ $t('s.slow') }}</h3>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.item') }}</th><th class="num">{{ $t('h.qty') }}</th><th class="num">{{ $t('h.amount') }}</th></tr></thead>
                <tbody>
                @forelse ($st['slow'] as $r)
                    <tr><td>{{ $r['name'] }}</td><td class="num">{{ $r['qty'] }}</td><td class="num">{{ $mf($r['value']) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="text-(--color-ink-muted)">{{ $t('empty.slow') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    </div>

    {{-- ৮ মূসক | ৯ বেতন --}}
    <div class="mb-4 grid gap-4 xl:grid-cols-2">
        <section class="{{ $card }}" data-section="vat">
            <h2 class="{{ $head }}"><span class="font-semibold">8 · {{ $t('s.vat') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.vat') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.vat_tax') }}</th><th class="num">{{ $t('h.amount') }}</th></tr></thead>
                <tbody>
                    <tr><td>{{ $t('vat.output') }}</td><td class="num">{{ $signed($v['output']) }}</td></tr>
                    <tr><td>{{ $t('vat.input') }}</td><td class="num">{{ $less($v['input']) }}</td></tr>
                    <tr class="font-bold" data-vat-due><td>{{ $t(bccomp($v['due'], '0', 4) >= 0 ? 'vat.due' : 'vat.credit', ['date' => $v['due_on']]) }}</td><td class="num">{{ $mf(ltrim($v['due'], '-')) }}</td></tr>
                    <tr><td>{{ $t('vat.vds_customers') }}</td><td class="num">{{ $mf($v['vds_by_customers']) }}</td></tr>
                    @if (bccomp($v['vds_on_suppliers'], '0', 4) !== 0)<tr><td>{{ $t('vat.vds_suppliers', ['date' => $v['due_on']]) }}</td><td class="num">{{ $mf($v['vds_on_suppliers']) }}</td></tr>@endif
                    <tr><td>{{ $t('vat.tds', ['date' => $v['due_on']]) }}</td><td class="num">{{ $mf($v['tds_on_suppliers']) }}</td></tr>
                </tbody>
            </table>
        </section>
        <section class="{{ $card }}" data-section="payroll">
            <h2 class="{{ $head }}"><span class="font-semibold">9 · {{ $t('s.payroll') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.payroll') }}</span></h2>
            <table class="ui-grid">
                <thead><tr><th>{{ $t('h.payroll', ['month' => $t('months.'.\Illuminate\Support\Carbon::parse($p['from'])->month)]) }}</th><th class="num">{{ $t('h.amount') }}</th></tr></thead>
                <tbody>
                @if ($pay === null)
                    <tr><td colspan="2" class="text-(--color-ink-muted)">{{ $t('empty.payroll') }}</td></tr>
                @else
                    <tr><td>{{ $t('pay.staff') }}</td><td class="num">{{ $t('people', ['n' => $pay['staff']]) }}</td></tr>
                    <tr><td>{{ $t('pay.gross') }}</td><td class="num">{{ $mf($pay['gross']) }}</td></tr>
                    @foreach ($pay['deductions'] as $d)
                        <tr><td>{{ $t('pay.less', ['name' => $d['name']]) }}</td><td class="num">{{ $less($d['amount']) }}</td></tr>
                    @endforeach
                    <tr class="font-bold"><td>{{ $t('pay.net', ['date' => $pay['pay_by']]) }}</td><td class="num">{{ $mf($pay['net']) }}</td></tr>
                @endif
                </tbody>
            </table>
        </section>
    </div>

    {{-- ১০ চেকলিস্ট --}}
    <section class="{{ $card }} mb-4" data-section="checks">
        <h2 class="{{ $head }}"><span class="font-semibold">10 · {{ $t('s.checks') }}</span><span class="text-2xs text-(--color-ink-muted)">{{ $t('cap.checks') }}</span></h2>
        <table class="ui-grid">
            <thead><tr><th class="num">{{ $t('h.sl') }}</th><th>{{ $t('h.task') }}</th><th>{{ $t('h.result') }}</th><th>{{ $t('h.fix') }}</th></tr></thead>
            <tbody>
            @foreach ($m['checks'] as $i => $c)
                <tr data-check="{{ $c['key'] }}" data-state="{{ $c['state'] }}">
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $c['label'] }}</td>
                    <td><x-ui.badge :tone="$c['state'] === 'ok' ? 'success' : ($c['state'] === 'na' ? 'draft' : 'danger')">{{ $c['result'] }}</x-ui.badge></td>
                    <td>@if ($c['state'] === 'pending' && Route::has($c['route']))<a class="text-(--color-link) hover:underline" href="{{ route($c['route'], $c['params']) }}">{{ __('accounts::control.go_fix') }}</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    {{-- ১১ মাস তালা --}}
    <section class="{{ $card }} mb-4 px-4 py-3 text-sm" data-lock="{{ $lock['locked'] ? 'locked' : 'open' }}">
        @if ($lock['locked'])
            <b>{{ $t('lock.closed_title') }}</b> {{ $t('lock.closed', ['month' => $p['label'], 'by' => $lock['by'] ?? '—', 'at' => $lock['at'] ?? '—']) }}
        @else
            <b>{{ $t('lock.open_title') }}</b> {{ $pending > 0 ? $t('lock.open_pending', ['month' => $p['label'], 'n' => $pending]) : $t('lock.open_ready', ['month' => $p['label']]) }}
            @can('accounts.period.close')
                <a class="ms-2 text-(--color-link) hover:underline" href="{{ route('accounts.period.index') }}" data-close-link>{{ $t('lock.go_close') }}</a>
            @endcan
        @endif
    </section>

    </x-money.page>
</x-layouts.app>
