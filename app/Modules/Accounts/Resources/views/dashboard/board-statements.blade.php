{{--
    ⭐ হিসাবের ড্যাশবোর্ড, সারি 2 — লাভ-ক্ষতির সেতু · এ মাস (দুই ভাগ চওড়া), স্থিতিপত্র · আজ, রেওয়ামিল ও উপ-খাতার মিল
    (মালিকের চূড়ান্ত নমুনা TErMgXrtgVBkuyuoCZq8NS)। ⓘ সেতুর বার 210 পর্যন্ত, সবচেয়ে বড় ধাপের মাপে; স্থিতিপত্রের দুই স্তম্ভ মোটের মাপে 240।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Accounts\Dashboard\AccountsBoard::short($v);
    $sign = fn (string $v, bool $plus = false) => (bccomp($v, '0', 4) > 0 && $plus ? '+' : '').$short($v);

    // ── সেতু: শুরু থেকে ধাপে ধাপে
    $p = $pnl;
    $run0 = (float) $p['sales'];
    $run1 = $run0 - (float) $p['cogs'];
    $run2 = $run1 - (float) $p['people'];
    $run3 = $run2 - (float) $p['other_cost'];
    $run4 = $run3 + (float) $p['other_income'];
    $top = max(1.0, $run0, $run1, $run4, abs($run4));
    $px = fn (float $v) => max(0, round($v / $top * 210));
    $step = fn (float $from, float $to) => ['h' => max(2, abs($px($to) - $px($from))), 'mb' => min($px($from), $px($to))];
    $bars = [
        ['t' => __('accounts::board.st.sales'), 'v' => $short($p['sales']), 'h' => $px($run0), 'mb' => 0, 'bg' => ['var(--color-dash-34d399)', 'var(--color-dash-10b981)'], 'c' => 'var(--color-dash-059669)'],
        ['t' => __('accounts::board.st.cogs'), 'v' => '−'.$short($p['cogs'])] + $step($run0, $run1) + ['bg' => ['var(--color-dash-fb7185)', 'var(--color-dash-e11d48)'], 'c' => 'var(--color-dash-e11d48)'],
        ['t' => __('accounts::board.st.gross'), 'v' => $short($p['gross']), 'h' => $px(max(0, $run1)), 'mb' => 0, 'bg' => ['var(--color-dash-60a5fa)', 'var(--color-dash-2563eb)'], 'c' => 'var(--color-dash-2563eb)'],
        ['t' => __('accounts::board.st.people'), 'v' => '−'.$short($p['people'])] + $step($run1, $run2) + ['bg' => ['var(--color-dash-fb923c)', 'var(--color-dash-ea580c)'], 'c' => 'var(--color-dash-ea580c)'],
        ['t' => __('accounts::board.st.other_cost'), 'v' => '−'.$short($p['other_cost'])] + $step($run2, $run3) + ['bg' => ['var(--color-dash-fbbf24)', 'var(--color-dash-d97706)'], 'c' => 'var(--color-dash-d97706)'],
        ['t' => __('accounts::board.st.other_income'), 'v' => $sign($p['other_income'], true)] + $step($run3, $run4) + ['bg' => ['var(--color-dash-2dd4bf)', 'var(--color-dash-0d9488)'], 'c' => 'var(--color-dash-0d9488)'],
        ['t' => __('accounts::board.st.net'), 'v' => $short($p['net']), 'h' => max(2, $px(abs($run4))), 'mb' => 0, 'bg' => ['var(--color-dash-a78bfa)', 'var(--color-dash-7c3aed)'], 'c' => bccomp($p['net'], '0', 4) < 0 ? 'var(--color-dash-dc2626)' : 'var(--color-dash-7c3aed)'],
    ];
    $netPct = bccomp($p['sales'], '0', 4) > 0 ? bcdiv(bcmul($p['net'], '100', 4), $p['sales'], 1) : null;

    // ── স্থিতিপত্র
    $bs = $balance;
    $total = max(1.0, abs((float) $bs['total']));
    $unit = $total >= 10000000 ? 10000000 : 100000;
    $fig = fn (string $v) => number_format((float) $v / $unit, 2, '.', '');
    $aPaint = ['fixed' => 'var(--color-dash-64748b)', 'stock' => 'var(--color-dash-f59e0b)', 'receivable' => 'var(--color-dash-10b981)', 'advances' => 'var(--color-dash-a855f7)', 'money' => 'var(--color-dash-0ea5e9)'];
    $cPaint = ['capital' => 'var(--color-dash-6366f1)', 'retained' => 'var(--color-dash-818cf8)', 'long_term' => 'var(--color-dash-be123c)', 'payable' => 'var(--color-dash-f43f5e)', 'other_current' => 'var(--color-dash-fb7185)'];
    $col = function (array $parts, array $paint) use ($total, $fig) {
        $out = [];
        foreach ($paint as $k => $c) {
            $v = (float) ($parts[$k] ?? '0');
            if ($v <= 0) { continue; }
            $h = round($v / $total * 240);
            $out[] = ['k' => $k, 'h' => max(2, $h), 'c' => $c, 's' => $h >= 26 ? __('accounts::board.st.short.'.$k).' '.$fig((string) $v) : ''];
        }
        return $out;
    };
    $assetsCol = $col($bs['assets'], $aPaint);
    $claimsCol = $col($bs['claims'], $cPaint);
    $claimsTotal = array_reduce($bs['claims'], fn (string $c, string $v) => bcadd($c, $v, 4), '0');
    $tb = $checks;
    $balanced = bccomp($tb['debit'], $tb['credit'], 2) === 0;
@endphp
<div data-acc-board-statements style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-acc-pnl style="grid-column: span 2; {{ $card }} gap: 0.625rem; border-top: 0.25rem solid var(--color-dash-10b981)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.st.pnl') }}</h2>
            <span style="font-size: 0.8125rem; font-weight: 700; color: {{ bccomp($p['net'], '0', 4) < 0 ? 'var(--color-dash-dc2626)' : 'var(--color-dash-059669)' }}">
                @if (bccomp($p['net'], '0', 4) < 0){{ __('accounts::board.st.loss_head', ['value' => $short(ltrim($p['net'], '-'))]) }}@else{{ __('accounts::board.st.net_head', ['value' => $short($p['net']), 'pct' => $netPct ?? '0']) }}@endif
            </span>
        </div>
        <div style="display: flex; align-items: flex-end; gap: 0.875rem; height: 15.625rem; border-bottom: 1px solid var(--color-dash-e5e7f0); padding: 0 0.375rem">
            @foreach ($bars as $b)
                <div data-pnl-step style="flex: 1 1 0; height: 15.625rem; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 0.25rem">
                    <span style="font-size: 0.75rem; font-weight: 800; color: {{ $b['c'] }}; white-space: nowrap">{{ $b['v'] }}</span>
                    <span style="width: 100%; height: {{ $b['h'] / 16 }}rem; margin-bottom: {{ $b['mb'] / 16 }}rem; background: linear-gradient(180deg, {{ $b['bg'][0] }}, {{ $b['bg'][1] }}); border-radius: 0.5rem"></span>
                </div>
            @endforeach
        </div>
        <div style="display: flex; gap: 0.875rem; padding: 0 0.375rem; font-size: 0.75rem; color: var(--color-dash-4b5563)">@foreach ($bars as $b)<span style="flex: 1 1 0; text-align: center">{{ $b['t'] }}</span>@endforeach</div>
    </section>

    <section data-acc-balance-sheet style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-0ea5e9)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.st.bs') }}</h2>
            <span style="font-size: 0.75rem; font-weight: 800; color: {{ $balanced ? 'var(--color-dash-059669)' : 'var(--color-dash-dc2626)' }}">{{ __($balanced ? 'accounts::board.st.bs_ok' : 'accounts::board.st.bs_off') }}</span>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.875rem; align-items: end">
            @foreach ([[$assetsCol, __('accounts::board.st.assets_total', ['value' => $short($bs['total'])])], [$claimsCol, __('accounts::board.st.claims_total', ['value' => $short($claimsTotal)])]] as [$parts, $label])
                <div style="display: flex; flex-direction: column; align-items: stretch; gap: 0.25rem">
                    <div style="display: flex; flex-direction: column-reverse; height: 15rem; border-radius: 0.625rem; overflow: hidden; gap: 2px">
                        @foreach ($parts as $b)<span style="height: {{ $b['h'] / 16 }}rem; flex: none; background: {{ $b['c'] }}; color: var(--color-dash-ffffff); font-size: 0.6875rem; font-weight: 700; display: flex; align-items: center; justify-content: center; white-space: nowrap; overflow: hidden">{{ $b['s'] }}</span>@endforeach
                    </div>
                    <b style="text-align: center; font-size: 0.8125rem">{{ $label }}</b>
                </div>
            @endforeach
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 3px 0.75rem; font-size: 0.6875rem">
            @foreach (array_keys($aPaint) as $i => $k)
                @php $ck = array_keys($cPaint)[$i] ?? null; @endphp
                <span style="display: flex; justify-content: space-between; gap: 0.25rem"><span style="display: flex; align-items: center; gap: 0.3125rem"><span style="width: 0.5625rem; height: 0.5625rem; border-radius: 3px; background: {{ $aPaint[$k] }}"></span>{{ __('accounts::board.st.a.'.$k) }}</span><b>{{ $fig($bs['assets'][$k]) }}</b></span>
                @if ($ck !== null)
                    <span style="display: flex; justify-content: space-between; gap: 0.25rem"><span style="display: flex; align-items: center; gap: 0.3125rem"><span style="width: 0.5625rem; height: 0.5625rem; border-radius: 3px; background: {{ $cPaint[$ck] }}"></span>{{ __('accounts::board.st.c.'.$ck) }}</span><b>{{ $fig($bs['claims'][$ck]) }}</b></span>
                @else
                    <span style="color: var(--color-dash-6b7280)">{{ __($unit === 10000000 ? 'accounts::board.st.unit' : 'accounts::board.st.unit_lakh') }}</span>
                @endif
            @endforeach
        </div>
    </section>

    <section data-acc-trial style="{{ $card }} gap: 0.5rem; border-top: 0.3125rem solid var(--color-dash-16a34a)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.st.tb') }}</h2>
        <div style="border-radius: 0.875rem; padding: 0.75rem 0.875rem; background: linear-gradient(135deg, {{ $balanced ? 'var(--color-dash-ecfdf5), var(--color-dash-d1fae5)' : 'var(--color-dash-fef2f2), var(--color-dash-fee2e2)' }}); display: flex; flex-direction: column; gap: 2px">
            <span style="font-size: 0.75rem; color: {{ $balanced ? 'var(--color-dash-065f46)' : 'var(--color-dash-b91c1c)' }}">{{ __($balanced ? 'accounts::board.st.tb_equal' : 'accounts::board.st.tb_off') }}</span>
            <b data-tb-totals style="font-size: 1.375rem; color: {{ $balanced ? 'var(--color-dash-065f46)' : 'var(--color-dash-b91c1c)' }}">{{ $short($tb['debit']) }} {{ $balanced ? '=' : '≠' }} {{ $short($tb['credit']) }}{{ $balanced ? ' ✓' : '' }}</b>
        </div>
        @foreach ($tb['checks'] as $r)
            @php
                $ok = $r['ok'];
                $c = $ok ? 'var(--color-dash-16a34a)' : 'var(--color-dash-d97706)';
                $text = match (true) {
                    $r['key'] === 'bank' => $ok ? __('accounts::board.st.matched') : __('accounts::board.st.gap', ['value' => $r['value']]),
                    $r['key'] === 'closed_months' => $ok ? __('accounts::board.st.none') : $r['value'],
                    default => $ok ? __('accounts::board.st.matched') : __('accounts::board.st.found', ['n' => $r['value']]),
                };
            @endphp
            <div data-tb-check="{{ $r['key'] }}" style="display: grid; grid-template-columns: 1.125rem minmax(0, 1fr) auto; gap: 0.5rem; align-items: center; font-size: 0.8125rem; padding: 0.3125rem 0; border-top: 1px solid var(--color-dash-f1f2f7)">
                <span style="width: 1rem; height: 1rem; border-radius: 999px; background: {{ $c }}; color: var(--color-dash-ffffff); font-size: 0.625rem; font-weight: 800; display: flex; align-items: center; justify-content: center">{{ $ok ? '✓' : '!' }}</span>
                <span>{{ __('accounts::board.st.checks.'.$r['key']) }}</span><b style="color: {{ $c }}">{{ $text }}</b>
            </div>
        @endforeach
        <span style="font-size: 0.6875rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.st.last_run', ['time' => $tb['at']]) }}</span>
    </section>
</div>
