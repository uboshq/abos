{{--
    ডেলিভারি খরচ — চালান অনুযায়ী ([[DeliveryCostController]], নমুনা v6 খ, মালিক ২১ নভেম্বর ২০২৬)।
    ⓘ হিসাব [[DeliveryCostReport]]-এ; এখানে কেবল দেখানো। মাথা: নাম · ভাগের ট্যাব · ছাঁকনি … নামান (প্রিন্ট · PDF · Excel) — টুলবারের মান।
    ⓘ কার্ড আর চার্ট সার্ভারেই আঁকা; রং টোকেন থেকে (inline style), নতুন CSS নয় — কোনো রূপের পাতা বদলায় না।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Core\Support\Money;

    $exporting = app(\App\Core\Services\ListExport::class)->wanted();
    $money = fn ($v) => Money::format((string) $v, $decimals);
    $up = '<svg viewBox="0 0 12 12" aria-hidden="true" class="size-2.5 flex-none"><path d="M6 1.5 11 10H1z" fill="currentColor"/></svg>';

    // ⭐ খরচ % — সীমার উপরে চিহ্ন আর রং (নমুনার `.hi`); ফাইলে কেবল সংখ্যা
    $pct = function (?string $percent, bool $over) use ($exporting, $up) {
        if ($percent === null) {
            return '—';
        }
        if ($exporting) {
            return $percent;
        }
        if (! $over) {
            return $percent.'%';
        }

        return new \Illuminate\Support\HtmlString('<span data-cost-over title="'.e(__('sales::delivery_cost.over_hint')).'" style="display:inline-flex;align-items:center;gap:4px;padding:0 6px;border-radius:6px;font-weight:700;color:var(--color-badge-warning-ink);background:var(--color-badge-warning-bg)">'
            .$up.e($percent).'%</span>');
    };

    // ⓘ 'total' => 'shown' — সর্বমোটের সারি রিপোর্টের নিজের (গোটা ছাঁকা রিপোর্ট, % মোট থেকে), যেমন সাজানো তেমনই ([[Table]])
    $moneyColumns = [
        ['key' => 'value', 'label' => __('sales::delivery_cost.col_value'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $money($r['value'])],
        ['key' => 'fare', 'label' => __('sales::delivery_cost.col_fare'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $money($r['fare'])],
        ['key' => 'loading', 'label' => __('sales::delivery_cost.col_loading'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $money($r['loading'])],
        ['key' => 'other', 'label' => __('sales::delivery_cost.col_other'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $money($r['other'])],
        ['key' => 'total', 'label' => __('sales::delivery_cost.col_total'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $money($r['total'])],
        ['key' => 'percent', 'label' => __('sales::delivery_cost.col_percent'), 'numeric' => true, 'total' => 'shown', 'render' => fn ($r) => $pct($r['percent'], (bool) $r['over'])],
    ];

    if ($by === 'challan') {
        $columns = [
            ['key' => 'trx_date', 'label' => __('sales::delivery_cost.col_date'), 'width' => '7rem', 'render' => fn ($r) => DateFormat::format($r['trx_date'])],
            ['key' => 'document_no', 'label' => __('sales::delivery_cost.col_challan'), 'width' => '9rem',
                'render' => fn ($r) => $exporting ? $r['document_no']
                    : new \Illuminate\Support\HtmlString('<a class="num" href="'.e(route('sales.challan.show', $r['id'])).'">'.e($r['document_no']).'</a>')],
            ['key' => 'place', 'label' => __('sales::delivery_cost.col_place'), 'wrap' => true, 'render' => fn ($r) => $r['place']],
            ['key' => 'vehicle', 'label' => __('sales::delivery_cost.col_vehicle'), 'width' => '9rem', 'render' => fn ($r) => $r['vehicle'] !== '' ? $r['vehicle'] : '—'],
            ...$moneyColumns,
        ];
    } else {
        $columns = [
            ['key' => 'label', 'label' => __('sales::delivery_cost.by_'.$by), 'wrap' => true, 'render' => fn ($r) => $r['label']],
            ['key' => 'count', 'label' => __('sales::delivery_cost.col_count'), 'numeric' => true, 'width' => '6rem', 'total' => 'shown', 'render' => fn ($r) => (string) $r['count']],
            ...$moneyColumns,
        ];
    }

    // মোটের সারি — % মোট থেকে ([[DeliveryCostReport::totals()]])
    $totalLine = [
        'count' => (string) $totals['count'],
        'value' => $money($totals['value']),
        'fare' => $money($totals['fare']),
        'loading' => $money($totals['loading']),
        'other' => $money($totals['other']),
        'total' => $money($totals['total']),
        'percent' => $pct($totals['percent'], (bool) $totals['over']),
    ];
    $totalLabel = __('sales::delivery_cost.total_row', ['n' => $totals['count']]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    {{-- ⭐ ভাগ — চালান · গাড়ি · পয়েন্ট · মাস (`?by=`); ABOS রূপে মাথার সারিতে নামের পাশে বসে ([[compactPageHead]]) --}}
    <x-ui.list-tabs :tabs="$groups" :label="__('sales::delivery_cost.group_label')" />

    <x-ui.filter-layout :filters="$filters" form="list-form" :report="true" :noun="__('sales::delivery_cost.noun')">
        {{-- ⭐ চার কার্ড — তালিকার রঙিন কার্ডের একই ছাঁচ, তবে লিংক নয় (কেবল অঙ্ক) --}}
        <div class="list-cards" data-list-cards data-cost-cards>
            @foreach ($cards as $card)
                <div class="list-card" data-card="{{ $card['key'] }}" style="--a: {{ $card['gradient'][0] }}; --b: {{ $card['gradient'][1] }}">
                    <span class="list-card-icon" aria-hidden="true">
                        @switch($card['key'])
                            @case('value')<svg viewBox="0 0 24 24" class="size-3.5"><path d="M3 7h13v10H3zM16 10h3l2 3v4h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>@break
                            @case('cost')<svg viewBox="0 0 24 24" class="size-3.5"><path d="M12 3v18M17 7H9.5a3 3 0 0 0 0 6h5a3 3 0 0 1 0 6H6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>@break
                            @case('average')<svg viewBox="0 0 24 24" class="size-3.5"><path d="M5 19 19 5M7 7h.01M17 17h.01" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>@break
                            @default<svg viewBox="0 0 24 24" class="size-3.5"><path d="M12 4 21 20H3z M12 10v4M12 17h.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg>
                        @endswitch
                    </span>
                    <span class="min-w-0">
                        <span class="list-card-label">{{ $card['label'] }}</span>
                        <span class="list-card-value num">{{ $card['value'] }}</span>
                        <span class="list-card-sub">{{ $card['sub'] }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        <div class="grid items-start gap-3 xl:grid-cols-[minmax(0,2.3fr)_minmax(18rem,1fr)]">
            <div data-boxed class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                <form id="list-form" method="GET" class="contents" data-report>
                    <input type="hidden" name="by" value="{{ $by }}">
                    <x-ui.toolbar :title="$title" :columns="$columns" :facets="$filters" :quiet="['by']" :report="true"
                                  :search-placeholder="__('sales::delivery_cost.search')" />
                </form>

                {{--
                    ⭐ সর্বমোট — গোটা ছাঁকা রিপোর্টের একটাই সারি (পাতার নয়), % মোট থেকে। ⓘ ফাইলের শেষেও টেবিল নিজেই দেয়, লুকানো
                      কলাম বাদ দিয়ে ([[Table::grandLine()]]); সারির "দেখুন" কেবল চালান ধরে দেখালে — ভাগের সারির নিজের পাতা নেই।
                --}}
                <x-ui.table
                    :empty="__('sales::delivery_cost.empty')"
                    :rows="$shown"
                    :columns="$columns"
                    :compact="\App\Core\Support\Ui::compact()"
                    :grand="$shown->total() === 0 ? [] : $totalLine"
                    :grand-label="$totalLabel"
                    :view-url="fn ($r) => $by === 'challan' ? route('sales.challan.show', $r['id']) : null" />

                <x-ui.pager :rows="$shown" />
            </div>

            {{-- ⭐ চার্ট — গাড়ি (বা পয়েন্ট / মাস) অনুযায়ী খরচ %, দাগ = সীমা, সীমার উপরে সতর্কতার রং --}}
            @if ($chart['bars'] !== [])
                <section data-cost-chart="{{ $chart['by'] }}" data-boxed
                         class="min-w-0 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                    <h2 class="text-sm font-semibold text-(--color-ink)">{{ $chart['title'] }}</h2>
                    <p class="mb-2 text-xs text-(--color-ink-muted)">{{ __('sales::delivery_cost.chart_legend', ['n' => rtrim(rtrim($threshold, '0'), '.')]) }}</p>

                    <div role="list">
                        @foreach ($chart['bars'] as $bar)
                            <div role="listitem" data-bar @if ($bar['over']) data-over @endif
                                 title="{{ $bar['label'] }}: ৳{{ $money($bar['value']) }} · ৳{{ $money($bar['total']) }}"
                                 style="display:grid;grid-template-columns:minmax(0,7.5rem) minmax(0,1fr) 3.5rem;gap:8px;align-items:center;margin:7px 0;font-size:13px">
                                <span class="truncate num">{{ $bar['label'] }}</span>
                                <span class="h-3.5" style="position:relative;border-radius:4px;background:var(--color-surface-app)">
                                    {{-- ⓘ চওড়া আর দাগ ১০০-এ আটকানো সেবাতেই ([[DeliveryCostReport::chart()]]) — float নয় --}}
                                    <i style="position:absolute;inset:0 auto 0 0;border-radius:4px;width:{{ $bar['width'] }}%;background:{{ $bar['over'] ? 'var(--color-warning)' : 'var(--color-chart-1)' }}"></i>
                                    <span aria-hidden="true" style="position:absolute;top:-3px;bottom:-3px;width:0;left:{{ $chart['line'] }}%;border-left:2px dashed var(--color-ink-muted)"></span>
                                </span>
                                <span class="num text-end" @if ($bar['over']) style="color:var(--color-badge-warning-ink);font-weight:700" @endif>{{ $bar['percent'] }}%</span>
                            </div>
                        @endforeach
                        <div aria-hidden="true" style="display:grid;grid-template-columns:minmax(0,7.5rem) minmax(0,1fr) 3.5rem;gap:8px;font-size:11px;color:var(--color-ink-muted)">
                            <span></span>
                            <span class="num" style="display:flex;justify-content:space-between">
                                @foreach ($chart['ticks'] as $tick)<span>{{ $tick }}%</span>@endforeach
                            </span>
                            <span></span>
                        </div>
                    </div>

                    <p class="mt-2 text-xs text-(--color-ink-muted)">
                        {{ collect($chart['bars'])->map(fn ($b) => __('sales::delivery_cost.chart_line', ['label' => $b['label'], 'value' => $money($b['value']), 'cost' => $money($b['total'])]))->implode(' · ') }}
                    </p>
                </section>
            @endif
        </div>
    </x-ui.filter-layout>
</x-layouts.app>
