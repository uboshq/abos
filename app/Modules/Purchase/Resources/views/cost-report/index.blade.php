{{--
    ⭐ ক্রয়ের খরচ — ক্রয় ইনভয়েস অনুযায়ী (মালিকের অনুমোদিত নমুনা v6, ট্যাব গ; ২১ নভেম্বর ২০২৬; [[PurchaseCostReportController]])।
    ⓘ হিসাব সব [[PurchaseCostReport]]-এ; এখানে কেবল দেখানো। মাথা এক সারিতে: নাম · ভাগ (ইনভয়েস/সরবরাহকারী/মাস/পণ্য) · প্রিন্ট · PDF · Excel;
      নিচে সময়/শাখার চিপ আর ডান পাশ থেকে খোলা ছাঁকনি; চারটা সরু রঙিন কার্ড; টেবিল আর পাশে চার্ট।
    ⓘ নতুন CSS নেই — বানানো শ্রেণি আর চার্টের মাপের জন্য style-অ্যাট্রিবিউট (শুধু এই পাতায়)।
    ⓘ রং টোকেন থেকে, উচ্চতা বানানো শ্রেণিতে; কার্ডের দুই রং [[PurchaseCostReport::CARD_GRADIENTS]]-এ (ডেলিভারি খরচের কার্ডের মতো)।
    ⓘ সারি পাতায় ভাগ ([[PurchaseCostReportController]]); মোট, কার্ড আর চার্ট গোটা ছাঁকা রিপোর্টের — পাতার নয়।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Core\Support\Money;
    use Illuminate\Support\HtmlString;

    $by = $result['by'];
    $totals = $result['totals'];
    $threshold = $result['threshold'];
    $thresholdText = rtrim(rtrim(Money::format($threshold, 2), '0'), '.');

    $money = fn ($v) => Money::format((string) $v, 0);
    $cartons = fn ($v) => Money::quantity((string) $v, 2);
    $perCarton = fn ($v) => $v === null ? '—' : Money::format((string) $v, 2);
    $pctPlain = fn ($p) => $p === null ? '—' : Money::format((string) $p, 2).'%';

    // ⓘ সীমার উপরে — রং আর ▲ চিহ্ন দুইটাই (রং একা কিছু বলে না); কারণটা title-এ, ফাইলে কেবল সংখ্যা
    $pctCell = function (array $r) use ($pctPlain) {
        if (! $r['over']) {
            return $pctPlain($r['pct']);
        }

        return new HtmlString('<span data-over title="'.e(__('purchase::cost_report.over')).'" aria-label="'.e($pctPlain($r['pct']).' — '.__('purchase::cost_report.over')).'"'
            .' class="inline-flex items-center gap-1 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 font-semibold text-(--color-badge-warning-ink)">'
            .'<svg viewBox="0 0 12 12" aria-hidden="true" class="size-3 shrink-0 fill-current"><path d="M6 1.5 11 10H1z"/></svg>'
            .e($pctPlain($r['pct'])).'</span>');
    };

    $metrics = [
        // ⓘ 'total' => 'shown' — সর্বমোটের সারি রিপোর্টের নিজের (গোটা ছাঁকা রিপোর্ট, হার মোট থেকে), যেমন সাজানো তেমনই ([[Table]])
        ['key' => 'cartons', 'label' => __('purchase::cost_report.col.cartons'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $cartons($r['cartons'])],
        ['key' => 'value', 'label' => __('purchase::cost_report.col.value'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $money($r['value'])],
        ['key' => 'labour', 'label' => __('purchase::cost_report.col.labour'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $money($r['labour'])],
        ['key' => 'fare', 'label' => __('purchase::cost_report.col.fare'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $money($r['fare'])],
        ['key' => 'other', 'label' => __('purchase::cost_report.col.other'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $money($r['other'])],
        ['key' => 'total', 'label' => __('purchase::cost_report.col.total'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $money($r['total'])],
        ['key' => 'pct', 'label' => __('purchase::cost_report.col.pct'), 'numeric' => true, 'total' => 'shown', 'render' => $pctCell],
        ['key' => 'per_carton', 'label' => __('purchase::cost_report.col.per_carton'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $perCarton($r['per_carton'])],
    ];

    $docLink = function (array $r) {
        $url = $r['doc'] === 'grn' ? route('purchase.receipt.show', $r['doc_id']) : route('purchase.bill.show', $r['doc_id']);

        return new HtmlString('<a href="'.e($url).'" class="num font-medium text-(--color-brand-600) hover:underline">'.e($r['doc_no']).'</a>');
    };

    $lead = match ($by) {
        'invoice' => [
            ['key' => 'date', 'label' => __('purchase::cost_report.col.date'), 'width' => '6rem',
                'render' => fn ($r) => \Illuminate\Support\Carbon::parse($r['date'])->format('d-m')],
            ['key' => 'doc_no', 'label' => __('purchase::cost_report.col.no'), 'width' => '9rem', 'render' => $docLink],
            ['key' => 'supplier', 'label' => __('purchase::cost_report.col.supplier'),
                'render' => fn ($r) => $r['doc'] === 'bill'
                    ? new HtmlString(e($r['supplier']).' <small class="text-(--color-ink-muted)">'.e(__('purchase::cost_report.direct')).'</small>')
                    : $r['supplier']],
        ],
        'supplier' => [
            ['key' => 'supplier', 'label' => __('purchase::cost_report.col.supplier')],
            ['key' => 'count', 'label' => __('purchase::cost_report.col.purchases'), 'numeric' => true, 'width' => '5rem'],
        ],
        'month' => [
            ['key' => 'month', 'label' => __('purchase::cost_report.col.month')],
            ['key' => 'count', 'label' => __('purchase::cost_report.col.purchases'), 'numeric' => true, 'width' => '5rem'],
        ],
        'product' => [
            ['key' => 'product', 'label' => __('purchase::cost_report.col.product')],
            ['key' => 'count', 'label' => __('purchase::cost_report.col.lines'), 'numeric' => true, 'width' => '5rem'],
        ],
    };

    $columns = array_merge($lead, $metrics);
    $totalLabel = __('purchase::cost_report.total_label', ['n' => $totals['count']]);

    // ⓘ মোটের সারি — প্রথম ঘরে লেখা, নিচে হার মোট থেকে (গড় নয়)
    $footer = ['cartons' => $cartons($totals['cartons']), 'value' => $money($totals['value']), 'labour' => $money($totals['labour']),
        'fare' => $money($totals['fare']), 'other' => $money($totals['other']), 'total' => $money($totals['total']),
        'pct' => $pctPlain($totals['pct']), 'per_carton' => $perCarton($totals['per_carton'])];
    foreach (array_slice($lead, 1) as $c) {
        $footer[$c['key']] = '';
    }

    $period = DateFormat::format($dates['from']).' – '.DateFormat::format($dates['to']);
    $chips = [
        __('purchase::cost_report.chip_date', ['range' => $period]),
        __('purchase::cost_report.chip_branch', ['name' => $branch]),
        __('purchase::cost_report.chip_supplier', ['name' => $supplier ?? __('purchase::cost_report.all')]),
        __('purchase::cost_report.chip_product', ['name' => $product ?? __('purchase::cost_report.all')]),
        __('purchase::cost_report.chip_threshold', ['n' => $thresholdText]),
    ];

    // ⭐ ফাইলে সংখ্যার কলাম খালি সংখ্যা (Excel-এ যোগ হয়) — খরচ % লেখাই থাকে ("%" সহ)
    $export = app(\App\Core\Services\ListExport::class);
    $export->numeric(['count', 'cartons', 'value', 'labour', 'fare', 'other', 'total', 'per_carton']);

    $highest = $result['highest'];
    // ⓘ কার্ডের ছোট আইকন — নমুনার একই রেখা-ছবি (ইমোজি নয়); ডেলিভারি খরচের কার্ডের একই আঁকা
    $svg = fn (string $d, string $width = '2') => new HtmlString('<svg viewBox="0 0 24 24" class="size-3.5"><path d="'.$d.'" fill="none" stroke="currentColor" stroke-width="'.$width.'" stroke-linejoin="round" stroke-linecap="round"/></svg>');
    $tone = \App\Modules\Purchase\Services\PurchaseCostReport::CARD_GRADIENTS;
    $cards = [
        [$svg('M3 7h13v10H3zM16 10h3l2 3v4h-5'), $tone['value'], __('purchase::cost_report.card_value'), '৳'.$money($totals['value']),
            __('purchase::cost_report.card_value_sub', ['n' => $totals['count'], 'cartons' => $cartons($totals['cartons'])])],
        [$svg('M12 3v18M17 7H9.5a3 3 0 0 0 0 6h5a3 3 0 0 1 0 6H6'), $tone['cost'], __('purchase::cost_report.card_cost'), '৳'.$money($totals['total']),
            __('purchase::cost_report.card_cost_sub', ['labour' => $money($totals['labour']), 'fare' => $money($totals['fare']), 'other' => $money($totals['other'])])],
        [$svg('M5 19 19 5M7 7h.01M17 17h.01', '2.4'), $tone['pct'], __('purchase::cost_report.card_pct'), $pctPlain($totals['pct']), __('purchase::cost_report.card_pct_sub')],
        [$svg('M12 4 21 20H3z M12 10v4M12 17h.01'), $tone['per_carton'], __('purchase::cost_report.card_per_carton'), $totals['per_carton'] === null ? '—' : '৳'.$perCarton($totals['per_carton']),
            $highest === null ? '' : __('purchase::cost_report.card_per_carton_sub', ['no' => $highest['doc_no'], 'amount' => $perCarton($highest['per_carton'])])],
    ];

    // ── চার্ট: খরচ % — দাগে সীমা, সীমার উপরে কমলা ──
    $bars = $result['chart'];
    // ⓘ মাপ bc-তে, float-এ নয় ([[MoneyIsNeverAFloatTest]])
    $maxPct = (string) $threshold;
    foreach ($bars as $b) {
        $p = (string) ($b['pct'] ?? '0');
        if (bccomp($p, $maxPct, 4) > 0) {
            $maxPct = $p;
        }
    }
    // ⓘ অক্ষে বড়জোর ছয়-সাতটা দাগ — ধাপ ১, ২, ৫, ১০ …; শেষ দাগ সবচেয়ে বড় দণ্ডের একটু উপরে
    $raw = bcmul($maxPct, '1.05', 4);
    if (bccomp($raw, '1', 4) < 0) {
        $raw = '1';
    }
    $step = 1;
    foreach ([1, 2, 5, 10, 20, 25, 50] as $s) {
        $step = $s;
        if (bccomp(bcdiv($raw, (string) $s, 4), '6', 4) <= 0) {
            break;
        }
    }
    // ⓘ ধাপের পূর্ণ গুণিতকে উপরে তোলা (ceil) — bc-তে
    $steps = bcdiv($raw, (string) $step, 0);
    if (bccomp(bcmul($steps, (string) $step, 4), $raw, 4) < 0) {
        $steps = bcadd($steps, '1', 0);
    }
    $scale = (int) $steps * $step;
    $at = function ($p) use ($scale): string {
        $v = bcdiv(bcmul((string) ($p ?? '0'), '100', 6), (string) $scale, 6);
        if (bccomp($v, '0', 6) < 0) {
            $v = '0';
        }
        if (bccomp($v, '100', 6) > 0) {
            $v = '100';
        }

        return Money::round($v, 2);
    };
    $chartTitle = $result['chart_by'] === 'product' ? __('purchase::cost_report.chart_product') : __('purchase::cost_report.chart_supplier');

    $segment = 'inline-flex items-center px-3 py-1 text-sm transition-colors hover:bg-(--color-surface-hover)';
    $button = 'flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) border border-(--color-border) px-3 text-sm transition-colors hover:bg-(--color-surface-hover)';
    $exportUrl = fn (string $format) => url()->current().'?'.http_build_query(request()->query() + ['export' => $format]);
    $canDownload = auth()->user()?->can('system_admin.list.export') ?? false;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::cost_report.title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$filters" form="list-form" :noun="__('purchase::cost_report.col.purchases')">

        {{-- ⭐ চারটা সরু রঙিন কার্ড — তালিকার কার্ডের একই গড়ন ([[filter-layout]]); এখানে লিংক নয়, কেবল অঙ্ক --}}
        <div class="list-cards" data-list-cards data-cost-cards>
            @foreach ($cards as [$icon, $gradient, $label, $value, $sub])
                <div class="list-card" style="--a: {{ $gradient[0] }}; --b: {{ $gradient[1] }}" data-cost-card>
                    <span class="list-card-icon" aria-hidden="true">{{ $icon }}</span>
                    <span class="min-w-0">
                        <span class="list-card-label">{{ $label }}</span>
                        <span class="list-card-value num">{{ $value }}</span>
                        <span class="list-card-sub">{{ $sub }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-3">
            <div data-boxed class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) xl:col-span-2">
                <form id="list-form" method="GET" class="contents">
                    <input type="hidden" name="by" value="{{ $by }}">
                    <x-ui.toolbar :title="__('purchase::cost_report.title')" :search="false"
                                  :count="implode(' · ', $chips)"
                                  :columns="$columns" :facets="$filters" :quiet="['by']">
                        <x-slot:actions>
                            {{-- ⭐ ভাগ — ইনভয়েস / সরবরাহকারী / মাস / পণ্য (`?by=`) --}}
                            <nav aria-label="{{ __('purchase::cost_report.group_label') }}" data-cost-groups
                                 class="inline-flex overflow-hidden rounded-(--radius-field) border border-(--color-border)">
                                @foreach (\App\Modules\Purchase\Services\PurchaseCostReport::GROUPS as $g)
                                    <a href="{{ request()->fullUrlWithQuery(['by' => $g, 'page' => null]) }}" data-group="{{ $g }}"
                                       @if ($g === $by) aria-current="page" @endif
                                       @class([$segment, 'border-s border-(--color-border)' => ! $loop->first,
                                               'bg-(--color-surface-selected) font-semibold text-(--color-brand-600)' => $g === $by])>{{ __('purchase::cost_report.by.'.$g) }}</a>
                                @endforeach
                            </nav>
                            @if ($canDownload)
                                <a href="{{ $exportUrl('print') }}" target="_blank" rel="noopener" data-no-prefetch data-download="print" class="{{ $button }}">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current"><path d="M7 3h10v4H7V3ZM5 9h14a2 2 0 0 1 2 2v6h-4v4H7v-4H3v-6a2 2 0 0 1 2-2Zm4 8h6v4H9v-4Z"/></svg>
                                    {{ __('purchase::cost_report.print') }}
                                </a>
                                <a href="{{ $exportUrl('pdf') }}" target="_blank" rel="noopener" data-no-prefetch data-download="pdf" class="{{ $button }}">{{ __('purchase::cost_report.pdf') }}</a>
                                <a href="{{ $exportUrl('xlsx') }}" data-no-prefetch data-download="xlsx" class="{{ $button }}">{{ __('purchase::cost_report.excel') }}</a>
                            @endif
                        </x-slot:actions>
                    </x-ui.toolbar>
                </form>

                @php
                    // ⓘ কাগজের মাথা — রিপোর্টের নাম আর সময়কাল, তারপর বাকি চিপ (টুলবারের বসানোর পরে, তাই এটাই থাকে)
                    $export->describe([
                        'title' => __('purchase::cost_report.title'),
                        'title_en' => app()->getLocale() === 'en' ? '' : __('purchase::cost_report.title', [], 'en'),
                        'period' => $period,
                        'facts' => array_slice($chips, 1),
                    ]);
                @endphp

                {{-- ⭐ সর্বমোট — গোটা ছাঁকা রিপোর্টের একটাই সারি (পাতার নয়), হার মোট থেকে; ফাইলের শেষেও টেবিল নিজেই দেয় ([[Table]]) --}}
                <x-ui.table
                    :rows="$result['rows']"
                    :columns="$columns"
                    :empty="__('purchase::cost_report.empty')"
                    :compact="\App\Core\Support\Ui::compact()"
                    :grand="$result['rows']->total() === 0 ? [] : $footer"
                    :grand-label="$totalLabel"
                    :view-url="fn ($r) => $by !== 'invoice' ? null
                        : ($r['doc'] === 'grn' ? route('purchase.receipt.show', $r['doc_id']) : route('purchase.bill.show', $r['doc_id']))" />

                <x-ui.pager :rows="$result['rows']" />
            </div>

            {{-- ⭐ খরচ % — আড়াআড়ি দণ্ড, দাগে সীমা, সীমার উপরে কমলা; প্রতিটা দণ্ডের পাশে সংখ্যা (রং একা কিছু বলে না) --}}
            <section data-boxed data-cost-chart
                     class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="font-semibold">{{ $chartTitle }}</h2>
                <p class="mb-2 text-2xs text-(--color-ink-muted)">{{ __('purchase::cost_report.chart_note', ['n' => $thresholdText]) }}</p>

                @forelse ($bars as $bar)
                    @php
                        $name = $result['chart_by'] === 'product' ? $bar['product'] : $bar['supplier'];
                        $over = $bar['over'];
                    @endphp
                    <div data-bar @if ($over) data-over @endif
                         title="{{ __('purchase::cost_report.chart_bar', ['name' => $name, 'pct' => Money::format((string) ($bar['pct'] ?? '0'), 2), 'value' => $money($bar['value']), 'cost' => $money($bar['total'])]) }}"
                         style="display:grid; grid-template-columns:minmax(0,9rem) minmax(0,1fr) 3.5rem; gap:.5rem; align-items:center; margin:.45rem 0; font-size:13px">
                        <span class="truncate">{{ $name }}</span>
                        <span class="h-3.5" style="position:relative; border-radius:4px; background:var(--color-surface-hover)">
                            <i style="position:absolute; inset:0 auto 0 0; width:{{ $at($bar['pct'] ?? 0) }}%; border-radius:4px; background:{{ $over ? 'var(--color-warning)' : 'var(--color-chart-1)' }}"></i>
                            <span aria-hidden="true" style="position:absolute; top:-3px; bottom:-3px; left:{{ $at($threshold) }}%; border-left:2px dashed var(--color-ink-muted)"></span>
                        </span>
                        <span class="num text-end">{{ $pctPlain($bar['pct']) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-(--color-ink-muted)">{{ __('purchase::cost_report.empty') }}</p>
                @endforelse

                @if ($bars !== [])
                    <div aria-hidden="true" class="num text-2xs text-(--color-ink-muted)"
                         style="display:grid; grid-template-columns:minmax(0,9rem) minmax(0,1fr) 3.5rem; gap:.5rem">
                        <span></span>
                        <span style="display:flex; justify-content:space-between">
                            @foreach (range(0, $scale, $step) as $tick)<span>{{ $tick }}%</span>@endforeach
                        </span>
                        <span></span>
                    </div>
                @endif
            </section>
        </div>
    </x-ui.filter-layout>
</x-layouts.app>
