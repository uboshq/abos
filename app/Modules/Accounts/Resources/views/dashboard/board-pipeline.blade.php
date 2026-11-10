{{--
    ⭐ হিসাবের ড্যাশবোর্ড, সারি 6 — কাগজের যাত্রা (ফানেল), ধরন ধরে টাকা (ডোনাট), পোস্টিংয়ের ক্যালেন্ডার, নগদ বাক্স ও চেক
    (মালিকের চূড়ান্ত নমুনা TErMgXrtgVBkuyuoCZq8NS)। অঙ্ক [[AccountsBoard::pipeline()]] থেকে।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Accounts\Dashboard\AccountsBoard::short($v);
    $f = $pipe['funnel'];
    $funnel = [
        ['t' => __('accounts::board.r6.written'), 'n' => $f['written'], 'w' => '100%', 'c' => 'linear-gradient(90deg, var(--color-dash-a78bfa), var(--color-dash-8b5cf6))'],
        ['t' => __('accounts::board.r6.sent'), 'n' => $f['sent'], 'w' => '88%', 'c' => 'linear-gradient(90deg, var(--color-dash-818cf8), var(--color-dash-6366f1))'],
        ['t' => __('accounts::board.r6.approved'), 'n' => $f['approved'], 'w' => '76%', 'c' => 'linear-gradient(90deg, var(--color-dash-60a5fa), var(--color-dash-3b82f6))'],
        ['t' => __('accounts::board.r6.posted'), 'n' => $f['posted'], 'w' => '64%', 'c' => 'linear-gradient(90deg, var(--color-dash-34d399), var(--color-dash-10b981))'],
    ];
    $typePaint = ['receipt' => 'var(--color-dash-10b981)', 'payment' => 'var(--color-dash-f97316)', 'expense' => 'var(--color-dash-ef4444)', 'journal' => 'var(--color-dash-6366f1)', 'contra' => 'var(--color-dash-06b6d4)'];
    $total = array_reduce($pipe['types'], fn (string $c, string $v) => bcadd($c, $v, 4), '0');
    $C = 2 * M_PI * 64; $acc = 0.0; $ring = [];
    foreach ($pipe['types'] as $t => $v) {
        if (bccomp($v, '0', 4) <= 0) { continue; }
        $len = (float) $v / (float) $total * $C;
        $ring[] = ['t' => $t, 'c' => $typePaint[$t], 'p' => (int) round((float) $v / (float) $total * 100), 'dash' => round(max($len - 2, 0), 1).' '.round($C - $len + 2, 1), 'off' => round(-$acc, 1)];
        $acc += $len;
    }

    $today = \Illuminate\Support\Carbon::today();
    $start = $today->copy()->startOfMonth()->startOfWeek(\Illuminate\Support\Carbon::SATURDAY);
    $busiest = max(1, ...array_values($pipe['calendar'] ?: [0]));
    $cal = [];
    for ($i = 0; $i < 35; $i++) {
        $d = $start->copy()->addDays($i);
        $inMonth = $d->month === $today->month;
        $n = $pipe['calendar'][$d->toDateString()] ?? 0;
        $share = $n / $busiest;
        $cal[] = ['t' => $inMonth ? $d->day : '', 'n' => $n,
            'bg' => ! $inMonth ? 'transparent' : ($n === 0 ? 'var(--color-dash-f1f5f9)' : ($share < 0.34 ? 'var(--color-dash-99f6e4)' : ($share < 0.67 ? 'var(--color-dash-2dd4bf)' : 'var(--color-dash-0d9488)'))),
            'ink' => $inMonth && $share >= 0.34 ? 'var(--color-dash-ffffff)' : 'var(--color-dash-475569)'];
    }

    $tillPaint = ['var(--color-dash-f97316)', 'var(--color-dash-f59e0b)', 'var(--color-dash-10b981)', 'var(--color-dash-0ea5e9)', 'var(--color-dash-8b5cf6)', 'var(--color-dash-64748b)'];
    $tills = array_slice($pipe['tills'], 0, 6);
    $tillTotal = array_reduce($tills, fn (string $c, array $t) => bccomp($t['value'], '0', 4) > 0 ? bcadd($c, $t['value'], 4) : $c, '0');
    $TC = 2 * M_PI * 50; $tacc = 0.0; $tring = [];
    foreach ($tills as $i => $t) {
        if (bccomp($t['value'], '0', 4) <= 0 || bccomp($tillTotal, '0', 4) <= 0) { continue; }
        $len = (float) $t['value'] / (float) $tillTotal * $TC;
        $tring[] = $t + ['c' => $tillPaint[$i % count($tillPaint)], 'dash' => round(max($len - 2, 0), 1).' '.round($TC - $len + 2, 1), 'off' => round(-$tacc, 1)];
        $tacc += $len;
    }
@endphp
<div data-acc-board-pipeline style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-acc-funnel style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-8b5cf6)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r6.funnel') }}</h2>
        @foreach ($funnel as $u)
            <div style="display: flex; justify-content: center"><div style="width: {{ $u['w'] }}; background: {{ $u['c'] }}; color: var(--color-dash-ffffff); border-radius: 0.625rem; padding: 0.5625rem 0.75rem; display: flex; justify-content: space-between; font-size: 0.8125rem; font-weight: 600"><span>{{ $u['t'] }}</span><span style="font-weight: 800">{{ $u['n'] }}</span></div></div>
        @endforeach
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563); text-align: center">{{ __('accounts::board.r6.funnel_note', ['n' => $f['written'], 'pct' => $f['written'] === 0 ? 0 : (int) round($f['posted'] / $f['written'] * 100)]) }}</span>
    </section>

    <section data-acc-types style="{{ $card }} gap: 0.375rem; border-top: 0.25rem solid var(--color-dash-ec4899)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r6.donut') }}</h2>
        @if ($ring === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.r6.none') }}</span>
        @else
            <div style="display: flex; align-items: center; gap: 0.875rem">
                <svg role="img" aria-label="{{ __('accounts::board.r6.donut_label') }}" viewBox="0 0 180 180" style="width: 10.625rem; height: 10.625rem; flex-shrink: 0">
                    <g transform="rotate(-90 90 90)">@foreach ($ring as $d)<circle cx="90" cy="90" r="64" fill="none" stroke="{{ $d['c'] }}" stroke-width="26" stroke-dasharray="{{ $d['dash'] }}" stroke-dashoffset="{{ $d['off'] }}"></circle>@endforeach</g>
                    <text x="90" y="86" text-anchor="middle" font-size="20" font-weight="800" fill="var(--color-dash-111827)">{{ $short($total) }}</text>
                    <text x="90" y="106" text-anchor="middle" font-size="11" fill="var(--color-dash-6b7280)">{{ __('accounts::board.r6.donut_total') }}</text>
                </svg>
                <div style="display: flex; flex-direction: column; gap: 0.375rem; font-size: 0.75rem">
                    @foreach ($ring as $d)<span style="display: flex; align-items: center; gap: 0.375rem"><span style="width: 0.625rem; height: 0.625rem; border-radius: 3px; background: {{ $d['c'] }}"></span>{{ __('accounts::board.r5.types.'.$d['t']) }} <b>{{ $d['p'] }}%</b></span>@endforeach
                </div>
            </div>
        @endif
    </section>

    <section data-acc-calendar style="{{ $card }} gap: 0.625rem; border-top: 0.25rem solid var(--color-dash-14b8a6)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r6.calendar', ['month' => __('accounts::board.months')[$today->month]]) }}</h2>
            <span style="font-size: 0.75rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.r6.calendar_note') }}</span>
        </div>
        <div style="display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.375rem; font-size: 0.6875rem; color: var(--color-dash-6b7280); text-align: center">
            @foreach (__('accounts::board.r6.weekdays') as $w)<span>{{ $w }}</span>@endforeach
            @foreach ($cal as $d)
                <span @if ($d['n'] > 0) title="{{ $d['n'] }}" @endif style="height: 2.25rem; border-radius: 0.5rem; background: {{ $d['bg'] }}; color: {{ $d['ink'] }}; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 600">{{ $d['t'] }}</span>
            @endforeach
        </div>
    </section>

    <section data-acc-tills style="{{ $card }} gap: 0.5rem; border-top: 0.25rem solid var(--color-dash-f97316)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r6.tills') }}</h2>
            @if (\Illuminate\Support\Facades\Route::has('accounts.custody'))<a href="{{ route('accounts.custody') }}" style="font-size: 0.8125rem; font-weight: 600">{{ __('accounts::board.r6.tills_go') }}</a>@endif
        </div>
        @if ($tring === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.r6.no_tills') }}</span>
        @else
            <div style="display: flex; align-items: center; gap: 0.875rem">
                <svg role="img" aria-label="{{ __('accounts::board.r6.tills_label') }}" viewBox="0 0 140 140" style="width: 8.125rem; height: 8.125rem; flex-shrink: 0">
                    <g transform="rotate(-90 70 70)">@foreach ($tring as $d)<circle cx="70" cy="70" r="50" fill="none" stroke="{{ $d['c'] }}" stroke-width="20" stroke-dasharray="{{ $d['dash'] }}" stroke-dashoffset="{{ $d['off'] }}"></circle>@endforeach</g>
                    <text x="70" y="74" text-anchor="middle" font-size="15" font-weight="800" fill="var(--color-dash-111827)">{{ $short($tillTotal) }}</text>
                </svg>
                <div style="display: flex; flex-direction: column; gap: 0.375rem; font-size: 0.75rem; flex-grow: 1; min-width: 0">
                    @foreach ($tring as $d)<span style="display: flex; justify-content: space-between; gap: 0.375rem"><span style="display: flex; align-items: center; gap: 0.375rem; min-width: 0"><span style="flex: none; width: 0.625rem; height: 0.625rem; border-radius: 3px; background: {{ $d['c'] }}"></span><span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{ $d['name'] }}</span></span><b>{{ $short($d['value']) }}</b></span>@endforeach
                </div>
            </div>
        @endif
        <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem">
            <a href="{{ \Illuminate\Support\Facades\Route::has('accounts.cheque.index') ? route('accounts.cheque.index') : '#' }}" style="background: linear-gradient(135deg, var(--color-dash-fff7ed), var(--color-dash-ffedd5)); border-radius: 0.75rem; padding: 0.5rem 0.625rem; color: var(--color-dash-111827); text-decoration: none"><span style="display: block; font-size: 0.75rem; color: var(--color-dash-c2410c)">{{ __('accounts::board.r6.due_today') }}</span><b style="font-size: 1rem">{{ __('accounts::board.r6.n_value', ['n' => $pipe['cheques']['today'][0], 'value' => $short($pipe['cheques']['today'][1])]) }}</b></a>
            <a href="{{ \Illuminate\Support\Facades\Route::has('accounts.cheque.index') ? route('accounts.cheque.index') : '#' }}" style="background: linear-gradient(135deg, var(--color-dash-fef2f2), var(--color-dash-fee2e2)); border-radius: 0.75rem; padding: 0.5rem 0.625rem; color: var(--color-dash-111827); text-decoration: none"><span style="display: block; font-size: 0.75rem; color: var(--color-dash-b91c1c)">{{ __('accounts::board.r6.overdue') }}</span><b style="font-size: 1rem">{{ __('accounts::board.r6.n_value', ['n' => $pipe['cheques']['overdue'][0], 'value' => $short($pipe['cheques']['overdue'][1])]) }}</b></a>
        </div>
    </section>
</div>
