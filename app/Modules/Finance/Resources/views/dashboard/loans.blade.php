{{--
    ⭐ ঋণের ঘর — অর্থের ড্যাশবোর্ডের সবার নিচে (মালিক, ৯ নভেম্বর ২০২৬: "loan box sobar nice daw"; অনুমোদিত নমুনা)।
    মোট আর ব্যাংক ঋণের মাসিক বদল; পাঁচ মাপ; ঋণদাতা ধরে ভাগের দাগ; আর সারি — ঋণদাতা, ধরন, বাকি, হার, পরের কিস্তি। অঙ্ক [[LoanBox]] থেকে।
--}}
@php
    $bn = app()->getLocale() === 'bn';
    $digits = fn (string $s): string => $bn ? \App\Core\Support\BanglaDigits::of($s) : $s;
    $taka = fn (string $v): string => '৳'.$digits(\App\Core\Support\Money::format($v, 0));
    $lakh = fn (string $v): string => '৳'.$digits(bcdiv($v, '100000', 1)).' '.__('finance::fund.lakh');
    $months = __('finance::fund.months');
    $day = function (string $iso) use ($digits, $months): string {
        $d = \Illuminate\Support\Carbon::parse($iso);

        return $digits((string) $d->day).' '.($months[$d->month] ?? '');
    };
    $l = $loans;
    $colours = ['var(--color-fin-2563eb)', 'var(--color-fin-7c3aed)', 'var(--color-fin-0891b2)', 'var(--color-fin-ea580c)', 'var(--color-fin-db2777)', 'var(--color-fin-65a30d)'];
    $change = $l['bank_change'];
    $down = bccomp($change, '0', 4) < 0;
    $flat = bccomp($change, '0', 4) === 0;
    $all = \Illuminate\Support\Facades\Route::has('finance.bank_facility.index') ? route('finance.bank_facility.index') : null;
    $kpi = 'flex: 1 1 150px; min-width: 0; border-radius: 12px; padding: 10px 12px';
    $label = 'margin: 0; font-size: 12px; font-weight: 600; color: var(--color-fin-475569)';
    $hint = 'margin: 0; font-size: 11px; color: var(--color-fin-475569)';
    $big = fn (string $colour): string => "margin: 0; font-size: 20px; font-weight: 700; color: {$colour}";
    $n = $l['next'];
    $nextHint = $n === null ? __('finance::loan_box.next_none')
        : __($n['days'] > 0 ? 'finance::loan_box.next_hint' : ($n['days'] === 0 ? 'finance::loan_box.next_today' : 'finance::loan_box.next_late'),
            ['name' => $n['name'], 'kind' => $n['kind'], 'days' => $digits((string) abs($n['days']))]);
    $grid = 'display: grid; grid-template-columns: 1.3fr 1.3fr 1fr 0.6fr 1.5fr; gap: 10px';
@endphp

<section data-loan-box style="background: var(--color-fin-ffffff); color: var(--color-fin-0f172a); border-radius: 18px; padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; box-shadow: 0 1px 2px var(--color-fin-15-23-42-a8); border-top: 5px solid var(--color-fin-dc2626); margin-top: 16px">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px; align-items: baseline">
        <div style="display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap">
            <h2 style="margin: 0; font-size: 18px; font-weight: 700">{{ __('finance::loan_box.title') }}</h2>
            <span data-loan-total style="font-size: 26px; font-weight: 700; color: var(--color-fin-b91c1c); font-variant-numeric: tabular-nums">{{ $taka($l['total']) }}</span>
            <span style="font-size: 13px; font-weight: 700; border-radius: 999px; padding: 2px 10px; color: {{ $down || $flat ? 'var(--color-fin-047857)' : 'var(--color-fin-b91c1c)' }}; background: {{ $down || $flat ? 'var(--color-fin-d1fae5)' : 'var(--color-fin-fee2e2)' }}">
                {{ $flat ? __('finance::loan_box.same') : __($down ? 'finance::loan_box.down' : 'finance::loan_box.up', ['amount' => $lakh(ltrim($change, '-'))]) }}
            </span>
        </div>
        @if ($all)<a href="{{ $all }}" style="font-size: 14px; font-weight: 600; color: var(--color-fin-1d4ed8)">{{ __('finance::loan_box.all') }}</a>@endif
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 10px">
        <div data-loan-kpi="next" style="{{ $kpi }}; background: var(--color-fin-fef2f2)"><p style="{{ $label }}">{{ __('finance::loan_box.next') }}</p>
            <p style="{{ $big('var(--color-fin-b91c1c)') }}">{{ $n === null ? '—' : $day($n['date']).' · '.$lakh($n['amount']) }}</p><p style="{{ $hint }}">{{ $nextHint }}</p></div>
        <div data-loan-kpi="interest" style="{{ $kpi }}; background: var(--color-fin-fff7ed)"><p style="{{ $label }}">{{ __('finance::loan_box.interest') }}</p>
            <p style="{{ $big('var(--color-fin-c2410c)') }}">{{ $taka($l['interest_month']) }}</p><p style="{{ $hint }}">{{ $l['avg_rate'] === null ? '' : __('finance::loan_box.avg_rate', ['rate' => $digits($l['avg_rate'])]) }}</p></div>
        <div data-loan-kpi="od" style="{{ $kpi }}; background: var(--color-fin-eef2ff)"><p style="{{ $label }}">{{ __('finance::loan_box.od') }}</p>
            <p style="{{ $big('var(--color-fin-4338ca)') }}">{{ $l['od_pct'] === null ? '—' : $digits($l['od_pct']).'%' }}</p>
            <p style="{{ $hint }}">{{ $l['od_pct'] === null ? __('finance::loan_box.od_none') : __('finance::loan_box.od_hint', ['used' => $lakh($l['od_used']), 'limit' => $lakh($l['od_limit'])]) }}</p></div>
        <div data-loan-kpi="de" style="{{ $kpi }}; background: var(--color-fin-ecfdf5)"><p style="{{ $label }}">{{ __('finance::loan_box.de') }}</p>
            <p style="{{ $big($l['debt_equity'] !== null && bccomp($l['debt_equity'], '1', 2) >= 0 ? 'var(--color-fin-b91c1c)' : 'var(--color-fin-047857)') }}">{{ $l['debt_equity'] === null ? '—' : $digits($l['debt_equity']) }}</p><p style="{{ $hint }}">{{ __('finance::loan_box.de_hint') }}</p></div>
        <div data-loan-kpi="dscr" style="{{ $kpi }}; background: var(--color-fin-f0f9ff)"><p style="{{ $label }}">{{ __('finance::loan_box.dscr') }}</p>
            <p style="{{ $big($l['dscr'] !== null && bccomp($l['dscr'], '1.25', 2) < 0 ? 'var(--color-fin-b91c1c)' : 'var(--color-fin-0369a1)') }}">{{ $l['dscr'] === null ? '—' : __('finance::loan_box.dscr_value', ['n' => $digits($l['dscr'])]) }}</p>
            <p style="{{ $hint }}">{{ $l['dscr'] === null ? __('finance::loan_box.dscr_none') : __('finance::loan_box.dscr_hint') }}</p></div>
    </div>

    @if ($l['rows'] === [])
        <p style="margin: 0; font-size: 14px; color: var(--color-fin-475569)">{{ __('finance::loan_box.none') }}</p>
    @else
        @if (bccomp($l['total'], '0', 4) > 0)
            <div role="img" aria-label="{{ __('finance::loan_box.share') }}" style="display: flex; height: 0.75rem; border-radius: 999px; overflow: hidden; gap: 2px">
                @foreach ($l['rows'] as $i => $r)
                    @if (bccomp($r['left'], '0', 4) > 0)
                        <div title="{{ $r['name'] }} · {{ $taka($r['left']) }}" style="flex: {{ bcdiv(bcmul($r['left'], '100', 4), $l['total'], 1) }}; background: {{ $colours[$i % 6] }}"></div>
                    @endif
                @endforeach
            </div>
        @endif
        <div style="overflow-x: auto">
            <div style="min-width: 640px">
                <div style="{{ $grid }}; padding: 0 4px 4px; font-size: 12px; font-weight: 700; color: var(--color-fin-64748b)">
                    <span>{{ __('finance::loan_box.lender') }}</span><span>{{ __('finance::loan_box.kind') }}</span><span style="text-align: right">{{ __('finance::loan_box.left') }}</span>
                    <span style="text-align: right">{{ __('finance::loan_box.rate') }}</span><span style="text-align: right">{{ __('finance::loan_box.next_col') }}</span>
                </div>
                @foreach ($l['rows'] as $i => $r)
                    <div data-loan-row style="{{ $grid }}; align-items: center; padding: 9px 4px; border-top: 1px solid var(--color-fin-eef2f7); font-size: 14px">
                        <span style="font-weight: 700"><span style="display: inline-block; width: 10px; height: 0.625rem; border-radius: 3px; background: {{ $colours[$i % 6] }}; margin-right: 8px"></span>{{ $r['name'] }}</span>
                        <span style="color: var(--color-fin-475569)">{{ $r['kind'] }}</span>
                        <span style="font-weight: 700; text-align: right; font-variant-numeric: tabular-nums">{{ $taka($r['left']) }}</span>
                        <span style="text-align: right; color: var(--color-fin-475569)">{{ $r['rate'] === null ? '—' : $digits($r['rate']).'%' }}</span>
                        <span style="text-align: right; color: {{ $r['note'] !== null && $r['next'] === null && $r['kind'] === __('finance::loan_box.hand') ? 'var(--color-fin-b91c1c)' : 'var(--color-fin-475569)' }}">{{ $r['next'] !== null ? $day($r['next']['date']).' · '.$taka($r['next']['amount']) : ($r['note'] ?? '—') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>
