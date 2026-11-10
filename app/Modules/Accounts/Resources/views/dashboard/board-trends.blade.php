{{--
    ⭐ হিসাবের ড্যাশবোর্ড, সারি 5 — ভাউচারের প্রবাহ · শেষ 30 দিন (স্তূপ বার, দুই ভাগ চওড়া), খরচ: এ মাস বনাম গত মাস (বুলেট), আয় বনাম খরচ · 12 মাস
    (মালিকের চূড়ান্ত নমুনা TErMgXrtgVBkuyuoCZq8NS)। ⓘ বুলেটে গত মাস = 75%-এর কালো দাগ, এ মাস সেই মাপে; রং: 10%-এর বেশি বাড়লে লাল, বাড়লে সোনালি, কম বা সমান নীল।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Accounts\Dashboard\AccountsBoard::short($v);
    $monthShort = __('accounts::board.months_short');
    $typePaint = ['receipt' => 'var(--color-dash-10b981)', 'payment' => 'var(--color-dash-f97316)', 'expense' => 'var(--color-dash-ef4444)', 'journal' => 'var(--color-dash-6366f1)', 'contra' => 'var(--color-dash-06b6d4)'];
    $dayTotal = fn (array $d) => array_reduce($d['by'], fn (float $c, string $v) => $c + (float) $v, 0.0);
    $peak = max(1.0, ...array_map($dayTotal, $flow['days']));
    $ticks = array_values(array_filter($flow['days'], fn ($d, $i) => $i % 7 === 0 || $i === count($flow['days']) - 1, ARRAY_FILTER_USE_BOTH));

    $pts = $ie;
    $hi = max(1.0, ...array_map(fn ($m) => max((float) $m['income'], (float) $m['expense']), $pts));
    $mx = fn (int $i) => round(20 + $i * 29.1, 1);
    $my = fn (float $v) => round(196 - max(0, $v) / $hi * 180, 1);
    $incomeLine = implode(' ', array_map(fn ($m, $i) => $mx($i).','.$my((float) $m['income']), $pts, array_keys($pts)));
    $expenseLine = implode(' ', array_map(fn ($m, $i) => $mx($i).','.$my((float) $m['expense']), $pts, array_keys($pts)));
    $incomeArea = 'M'.$mx(0).',196 '.implode(' ', array_map(fn ($m, $i) => 'L'.$mx($i).','.$my((float) $m['income']), $pts, array_keys($pts))).' L'.$mx(count($pts) - 1).',196 Z';
@endphp
<div data-acc-board-trends style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-acc-flow style="grid-column: span 2; {{ $card }} gap: 0.625rem; border-top: 0.25rem solid var(--color-dash-6366f1)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r5.flow') }}</h2>
            <div style="display: flex; gap: 0.75rem; font-size: 0.75rem; color: var(--color-dash-4b5563); flex-wrap: wrap">
                @foreach ($typePaint as $t => $c)<span style="display: flex; align-items: center; gap: 0.3125rem"><span style="width: 0.625rem; height: 0.625rem; border-radius: 3px; background: {{ $c }}"></span>{{ __('accounts::board.r5.types.'.$t) }}</span>@endforeach
            </div>
        </div>
        <div role="img" aria-label="{{ __('accounts::board.r5.flow_label') }}" style="display: flex; align-items: flex-end; gap: 0.3125rem; height: 15.625rem; border-bottom: 1px solid var(--color-dash-e5e7f0); padding: 0 2px">
            @foreach ($flow['days'] as $d)
                <div data-flow-day title="{{ \Illuminate\Support\Carbon::parse($d['date'])->day }} {{ $monthShort[\Illuminate\Support\Carbon::parse($d['date'])->month] }} · {{ $short((string) $dayTotal($d)) }}" style="flex: 1 1 0; display: flex; flex-direction: column-reverse; gap: 1px">
                    @foreach ($typePaint as $t => $c)
                        @php $h = round((float) $d['by'][$t] / $peak * 240, 1); @endphp
                        @if ($h > 0)<span style="height: {{ $h / 16 }}rem; background: {{ $c }}"></span>@endif
                    @endforeach
                </div>
            @endforeach
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 0.6875rem; color: var(--color-dash-6b7280)">@foreach ($ticks as $d)<span>{{ \Illuminate\Support\Carbon::parse($d['date'])->day }} {{ $monthShort[\Illuminate\Support\Carbon::parse($d['date'])->month] }}</span>@endforeach</div>
    </section>

    <section data-acc-bullets style="{{ $card }} gap: 0.625rem; border-top: 0.25rem solid var(--color-dash-ef4444)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r5.bullets') }}</h2>
        @forelse ($bullets as $b)
            @php
                $c = $b['pct'] === null ? 'var(--color-dash-f59e0b)' : ($b['pct'] > 10 ? 'var(--color-dash-ef4444)' : ($b['pct'] > 0 ? 'var(--color-dash-f59e0b)' : 'var(--color-dash-6366f1)'));
                $w = bccomp($b['last'], '0', 4) > 0 ? min(100, round((float) $b['now'] / (float) $b['last'] * 75)) : 100;
            @endphp
            <div data-bullet style="display: flex; flex-direction: column; gap: 3px">
                <span style="display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.75rem"><span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $b['name'] }}</span><b style="flex: none; color: {{ $c }}">{{ $b['pct'] === null ? __('accounts::board.r5.new') : ($b['pct'] > 0 ? '▲ ' : ($b['pct'] < 0 ? '▼ ' : '— ')).abs($b['pct']).'%' }}</b></span>
                <span title="{{ $short($b['now']) }} · {{ $short($b['last']) }}" style="position: relative; height: 0.875rem; border-radius: 0.375rem; background: linear-gradient(90deg, var(--color-dash-eef0f6) 0 60%, var(--color-dash-e2e5ef) 60% 90%, var(--color-dash-d6dae6) 90% 100%)">
                    <span style="position: absolute; left: 0; top: 3px; height: 0.5rem; width: {{ $w }}%; background: {{ $c }}; border-radius: 0.25rem"></span>
                    @if (bccomp($b['last'], '0', 4) > 0)<span style="position: absolute; left: 75%; top: -2px; width: 3px; height: 1.125rem; background: var(--color-dash-111827); border-radius: 2px"></span>@endif
                </span>
            </div>
        @empty
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.r5.bullets_none') }}</span>
        @endforelse
        <span style="font-size: 0.6875rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.r5.bullets_note', ['d' => \Illuminate\Support\Carbon::today()->day]) }}</span>
    </section>

    <section data-acc-income-expense style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-0ea5e9)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r5.ie') }}</h2>
        <svg role="img" aria-label="{{ __('accounts::board.r5.ie_label') }}" viewBox="0 0 360 220" style="width: 100%; height: 14.375rem">
            <defs><linearGradient id="accIncomeFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--color-dash-0ea5e9)" stop-opacity="0.35"></stop><stop offset="1" stop-color="var(--color-dash-0ea5e9)" stop-opacity="0"></stop></linearGradient></defs>
            <path d="{{ $incomeArea }}" fill="url(#accIncomeFill)"></path>
            <polyline points="{{ $incomeLine }}" fill="none" stroke="var(--color-dash-0ea5e9)" stroke-width="3"></polyline>
            <polyline points="{{ $expenseLine }}" fill="none" stroke="var(--color-dash-f43f5e)" stroke-width="3" stroke-dasharray="6 4"></polyline>
            @foreach ($pts as $i => $m)<text x="{{ $mx($i) }}" y="214" text-anchor="middle" font-size="10" fill="var(--color-dash-6b7280)">{{ $monthShort[$m['month']] }}</text>@endforeach
        </svg>
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-0ea5e9)">—</b> {{ __('accounts::board.r5.income') }} &#160; <b style="color: var(--color-dash-f43f5e)">- -</b> {{ __('accounts::board.r5.expense') }} · {{ __('accounts::board.r5.ie_note') }}</span>
    </section>
</div>
