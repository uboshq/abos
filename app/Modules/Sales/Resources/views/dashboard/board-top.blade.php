{{--
    ⭐ বিক্রয়ের ড্যাশবোর্ড, সারি 1 — সমস্যা কেন্দ্র (10 ঘর) আর ডানে এক নজরে (আজ · এ মাস · এ অর্থবছর)
    (মালিকের চূড়ান্ত নমুনা H8JXFPzh8m7zpsQSedmPDT, 10 নভেম্বর 2026 রাত)। অঙ্ক [[SalesBoard]] থেকে; রং মজুদ-হিসাবের সমস্যা কেন্দ্রের একই নিয়মে।
--}}
@php
    $paint = [
        'urgent' => ['bg' => 'linear-gradient(145deg, var(--color-dash-fb7185) 0%, var(--color-dash-e11d48) 45%, var(--color-dash-9f1239) 100%)', 'glow' => 'var(--color-dash-225-29-72-a35)', 'ink' => 'var(--color-dash-dc2626)'],
        'today' => ['bg' => 'linear-gradient(145deg, var(--color-dash-fdba74) 0%, var(--color-dash-f97316) 45%, var(--color-dash-c2410c) 100%)', 'glow' => 'var(--color-dash-249-115-22-a33)', 'ink' => 'var(--color-dash-ea580c)'],
        'week' => ['bg' => 'linear-gradient(145deg, var(--color-dash-fcd34d) 0%, var(--color-dash-f59e0b) 45%, var(--color-dash-b45309) 100%)', 'glow' => 'var(--color-dash-245-158-11-a32)', 'ink' => 'var(--color-dash-ca8a04)'],
        'ok' => ['bg' => 'linear-gradient(145deg, var(--color-dash-6ee7b7) 0%, var(--color-dash-10b981) 45%, var(--color-dash-047857) 100%)', 'glow' => 'var(--color-dash-16-185-129-a32)', 'ink' => 'var(--color-dash-16a34a)'],
    ];
    $short = fn (string $v) => \App\Modules\Sales\Dashboard\SalesBoard::short($v);
    $g = $glance;
    $rowInk = ['sales' => 'var(--color-dash-6ee7b7)', 'collected' => 'var(--color-dash-7dd3fc)', 'rate' => 'var(--color-dash-fde68a)', 'count' => 'var(--color-dash-f9a8d4)'];
    $cell = fn (string $row, array $col) => match ($row) {
        'sales' => $short($col['sales']),
        'collected' => $short($col['collected']),
        'rate' => $col['rate'] === null ? '—' : $col['rate'].'%',
        default => number_format($col['count']),
    };
@endphp
<div data-sales-board-top style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-sales-problems style="grid-column: span 3; background: var(--color-dash-ffffff); border-radius: 1.375rem; padding: 1.125rem 1.25rem; box-shadow: 0 0.875rem 2.125rem var(--color-dash-239-68-68-a12); display: flex; flex-direction: column; gap: 0.75rem; border-top: 0.375rem solid var(--color-dash-ef4444); color: var(--color-dash-111827)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1.1875rem; font-weight: 800">{{ __('sales::board.p.title') }}</h2>
            <span style="font-size: 0.8125rem; color: var(--color-dash-4b5563)">
                <b style="color: var(--color-dash-dc2626)">●</b> {{ __('sales::board.sev.urgent') }} &#160;
                <b style="color: var(--color-dash-ea580c)">●</b> {{ __('sales::board.sev.today_long') }} &#160;
                <b style="color: var(--color-dash-ca8a04)">●</b> {{ __('sales::board.sev.week') }} &#160;
                <b style="color: var(--color-dash-16a34a)">●</b> {{ __('sales::board.sev.ok') }}
            </span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 0.75rem">
            @foreach ($problems as $x)
                @php $p = $paint[$x['sev']] ?? $paint['week']; @endphp
                <a href="{{ $x['href'] }}" data-sales-problem="{{ $x['key'] }}" data-sev="{{ $x['sev'] }}"
                   style="display: flex; flex-direction: column; gap: 0.25rem; border-radius: 1rem; padding: 0.75rem 0.875rem; background: {{ $p['bg'] }}; color: var(--color-dash-ffffff); box-shadow: 0 0.625rem 1.375rem {{ $p['glow'] }}; text-decoration: none; min-width: 0">
                    <span style="display: flex; justify-content: space-between; align-items: center; gap: 0.375rem">
                        <span style="font-size: 0.75rem; font-weight: 700">{{ __('sales::board.p.'.$x['key']) }}</span>
                        <span style="flex-shrink: 0; font-size: 0.625rem; font-weight: 800; background: var(--color-dash-255-255-255-a24); border-radius: 999px; padding: 0.0625rem 0.5rem">{{ __('sales::board.sev.'.$x['sev']) }}</span>
                    </span>
                    <span style="font-size: 1.875rem; font-weight: 800; line-height: 1.1">{{ $x['n'] }}</span>
                    <span style="font-size: 0.75rem; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $x['detail'] }}</span>
                    <span style="font-size: 0.6875rem; opacity: 0.9; line-height: 1.4">{{ $x['note'] !== '' ? $x['note'] : __('sales::board.p.'.$x['key'].'_note') }}</span>
                    <span style="font-size: 0.6875rem; font-weight: 800; background: var(--color-dash-ffffff); color: {{ $p['ink'] }}; border-radius: 0.5rem; padding: 0.1875rem 0.5625rem; width: fit-content; margin-top: 0.125rem">{{ __('sales::board.p.'.$x['key'].'_go') }} →</span>
                </a>
            @endforeach
        </div>
    </section>

    <section data-sales-glance style="border-radius: 1.375rem; padding: 1.125rem 1.25rem; background: linear-gradient(160deg, var(--color-dash-0c4a6e) 0%, var(--color-dash-0e7490) 45%, var(--color-dash-047857) 100%); color: var(--color-dash-ffffff); display: flex; flex-direction: column; gap: 0.625rem; box-shadow: 0 0.875rem 2.125rem var(--color-dash-14-116-144-a32)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700; color: var(--color-dash-ffffff)">{{ __('sales::board.g.title') }}</h2>
        <div style="display: grid; grid-template-columns: 4.75rem repeat(3, minmax(0, 1fr)); font-size: 0.8125rem; text-align: right">
            <span></span>
            @foreach (['today', 'month', 'year'] as $c)<span style="font-size: 0.6875rem; color: var(--color-dash-bae6fd); padding-bottom: 0.375rem">{{ __('sales::board.g.'.$c) }}</span>@endforeach
            @foreach (['sales', 'collected', 'rate', 'count'] as $row)
                <span style="padding: 0.4375rem 0; border-top: 1px solid var(--color-dash-255-255-255-a16); text-align: left; font-weight: 600">{{ __('sales::board.g.'.$row) }}</span>
                @foreach (['today', 'month', 'year'] as $c)
                    <span data-glance-cell="{{ $row }}-{{ $c }}" style="padding: 0.4375rem 0; border-top: 1px solid var(--color-dash-255-255-255-a16); font-weight: 800; color: {{ $rowInk[$row] }}; font-size: 0.9375rem; white-space: nowrap">{{ $cell($row, $g['cols'][$c]) }}</span>
                @endforeach
            @endforeach
        </div>
        @if ($g['yoy'] !== null)
            <span style="font-size: 0.75rem; font-weight: 800; color: {{ $g['yoy'] >= 0 ? 'var(--color-dash-86efac)' : 'var(--color-dash-fca5a5)' }}; background: var(--color-dash-255-255-255-a12); border-radius: 999px; padding: 3px 0.625rem; width: fit-content">{{ __($g['yoy'] >= 0 ? 'sales::board.g.yoy_up' : 'sales::board.g.yoy_down', ['n' => abs($g['yoy'])]) }}</span>
        @endif
        <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.375rem; font-size: 0.6875rem">
            <a href="{{ \Illuminate\Support\Facades\Route::has('sales.order.index') ? route('sales.order.index') : '#' }}" style="background: linear-gradient(135deg, var(--color-dash-f97316), var(--color-dash-ea580c)); border-radius: 0.75rem; padding: 0.4375rem 0.5625rem; color: var(--color-dash-ffffff); text-decoration: none">{{ __('sales::board.g.undelivered') }}<b style="display: block; font-size: 0.875rem">{{ __('sales::board.g.n_value', ['n' => $g['undelivered'][0], 'value' => $short($g['undelivered'][1])]) }}</b></a>
            <a href="{{ \Illuminate\Support\Facades\Route::has('customer.index') ? route('customer.index') : '#' }}" style="background: linear-gradient(135deg, var(--color-dash-ec4899), var(--color-dash-be185d)); border-radius: 0.75rem; padding: 0.4375rem 0.5625rem; color: var(--color-dash-ffffff); text-decoration: none">{{ __('sales::board.g.shops') }}<b style="display: block; font-size: 0.875rem">{{ number_format($g['shops']) }}</b></a>
            <span style="background: linear-gradient(135deg, var(--color-dash-6366f1), var(--color-dash-4338ca)); border-radius: 0.75rem; padding: 0.4375rem 0.5625rem; color: var(--color-dash-ffffff)">{{ __('sales::board.g.avg') }}<b style="display: block; font-size: 0.875rem">{{ $g['avg'] === null ? '—' : '৳'.number_format((float) $g['avg']) }}</b></span>
            <a href="{{ \Illuminate\Support\Facades\Route::has('sales.quotation.index') ? route('sales.quotation.index') : '#' }}" style="background: linear-gradient(135deg, var(--color-dash-a855f7), var(--color-dash-7e22ce)); border-radius: 0.75rem; padding: 0.4375rem 0.5625rem; color: var(--color-dash-ffffff); text-decoration: none">{{ __('sales::board.g.quote_rate') }}<b style="display: block; font-size: 0.875rem">{{ $g['quote_rate'] === null ? '—' : $g['quote_rate'].'%' }}</b></a>
        </div>
    </section>
</div>
