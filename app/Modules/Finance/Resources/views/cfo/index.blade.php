{{--
    CFO ড্যাশবোর্ড — মানচিত্র §১। এক পাতা, কোনো ট্যাব নয়: টাকা কোথায়,
    কে কত দেবে, কাকে কত দিতে হবে, তারল্য, আর সামনের ৩০ দিন।

    ⓘ প্রতিটা সংখ্যা খতিয়ান থেকে ([[CfoFigures]]), আর যে কার্ডের বিস্তারিত
    পাতা আছে সেটা সেখানে নিয়ে যায় — সংখ্যা দেখে "কেন" জানতে এক ক্লিক।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $tone = [
        'good' => 'text-(--color-badge-success-ink)',
        'warn' => 'text-(--color-badge-warning-ink)',
        'bad' => 'text-(--color-badge-danger-ink)',
    ];

    $cards = [
        ['label' => __('finance::forecast.cash'), 'value' => $money($f['cash'])],
        ['label' => __('finance::forecast.bank'), 'value' => $money($f['bank'])],
        ['label' => __('finance::forecast.mfs'), 'value' => $money($f['mfs'])],
        ['label' => __('finance::forecast.receivable'), 'value' => $money($f['receivable'])],
        ['label' => __('finance::forecast.payable'), 'value' => $money($f['payable'])],
        ['label' => __('finance::forecast.loans'), 'value' => $money($f['loans'])],
        [
            'label' => __('finance::forecast.current_ratio'),
            'value' => $f['current_ratio'] ?? __('finance::forecast.no_liabilities'),
            'hint' => __('finance::forecast.current_ratio_hint', [
                'assets' => $money($f['current_assets']),
                'liabilities' => $money($f['current_liabilities']),
            ]),
            'tone' => $tone[$f['liquidity']],
        ],
        [
            'label' => __('finance::forecast.cash_ratio'),
            'value' => $f['cash_ratio'] ?? __('finance::forecast.no_liabilities'),
        ],
        [
            'label' => __('finance::forecast.liquidity'),
            'value' => __('finance::forecast.liquidity_'.$f['liquidity']),
            'hint' => __('finance::forecast.liquidity_rule'),
            'tone' => $tone[$f['liquidity']],
        ],
        [
            'label' => __('finance::forecast.in30'),
            'value' => $money($in30),
            'href' => route('finance.forecast.cash'),
            'tone' => bccomp((string) $in30, '0', 4) < 0 ? $tone['bad'] : '',
        ],
    ];

    if ($budget !== null) {
        $over = $budget['used_pct'] !== null && bccomp($budget['used_pct'], '100', 1) > 0;

        $cards[] = [
            'label' => __('finance::budget.status'),
            'value' => ($budget['used_pct'] ?? '—').'%',
            'hint' => __('finance::budget.status_hint', [
                'budget' => $money($budget['budget']),
                'actual' => $money($budget['actual']),
            ]),
            'href' => route('finance.budget.actual'),
            'tone' => $over ? $tone['bad'] : '',
        ];
    }

    $box = 'block rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::forecast.cfo') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::forecast.cfo')" :subtitle="__('finance::forecast.cfo_note')" />
    </x-slot:header>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($cards as $card)
            @if (isset($card['href']))
                <a href="{{ $card['href'] }}" data-boxed class="{{ $box }} transition-colors hover:bg-(--color-surface-hover)">
                    @include('finance::cfo.partials.card', ['card' => $card])
                </a>
            @else
                <div data-boxed class="{{ $box }}">
                    @include('finance::cfo.partials.card', ['card' => $card])
                </div>
            @endif
        @endforeach
    </div>
</x-layouts.app>
