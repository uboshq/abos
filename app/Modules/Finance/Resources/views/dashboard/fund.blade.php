{{--
    ⭐ মোট তহবিলের ঘর — অর্থের ড্যাশবোর্ডের মাথায় (মালিকের অনুমোদিত নমুনা TWYacgufwfzT9ZqR1eqvaW, ৯ নভেম্বর ২০২৬: "ok kaj koro ebar")।
    মোট আর গত মাসের তুলনা; ডানে উপরে দুই ঘর ("খরচ চলবে", "৩০ দিনে আসবে − যাবে"); নগদ/ব্যাংক/MFS-এর দাগ আর তিন ভাগ; নিচে এক
    লাইনে চার ঘর, প্রতিটা আলাদা রঙে। অঙ্ক [[FundPosition]] থেকে; লাখের অঙ্ক bcmath-এ, float নয়।
--}}
@php
    $bn = app()->getLocale() === 'bn';
    $digits = fn (string $s): string => $bn ? \App\Core\Support\BanglaDigits::of($s) : $s;
    $taka = fn (string $v): string => '৳'.$digits(\App\Core\Support\Money::format($v, 0));
    $lakh = fn (string $v): string => $digits(bcdiv($v, '100000', 1));
    $signed = fn (string $v): string => (bccomp($v, '0', 4) < 0 ? '−' : '+').'৳'.$lakh(ltrim($v, '-')).' '.__('finance::fund.lakh');
    $f = $fund;
    $months = __('finance::fund.months');
    $day = function (string $iso) use ($digits, $months): string {
        $d = \Illuminate\Support\Carbon::parse($iso);

        return $digits((string) $d->day).' '.($months[$d->month] ?? '');
    };

    $up = $f['change_pct'] === null ? null : bccomp($f['change'], '0', 4) >= 0;
    $changeText = $up === null ? __('finance::fund.no_compare')
        : __($up ? 'finance::fund.up' : 'finance::fund.down', ['pct' => $digits(ltrim($f['change_pct'], '-')), 'amount' => $taka(ltrim($f['change'], '-'))]);

    $partColour = ['cash' => '#fbbf24', 'bank' => '#38bdf8', 'mfs' => '#34d399'];
    $share = collect($f['parts'])->mapWithKeys(fn ($p) => [$p['key'] => $p['share']]);

    $cover = $f['cover7'];
    $coverTone = $cover === null || bccomp($cover, '2', 1) >= 0 ? '#bbf7d0' : (bccomp($cover, '1', 1) >= 0 ? '#fde68a' : '#fecaca');

    // ⓘ চার ঘর — নমুনার চার রং (আকাশি, অ্যাম্বার, গোলাপি, বেগুনি)
    $tiles = [
        ['key' => 'free', 'rgb' => '56,189,248', 'top' => '#7dd3fc', 'label' => __('finance::fund.free'), 'value' => '৳'.$lakh($f['free']).' '.__('finance::fund.lakh'),
            'hint' => __('finance::fund.free_hint', ['amount' => '৳'.$lakh($f['held'])]), 'tone' => '#e0e7ff'],
        ['key' => 'lowest', 'rgb' => '245,158,11', 'top' => '#fcd34d', 'label' => __('finance::fund.lowest'),
            'value' => bccomp($f['lowest']['closing'], $f['total'], 4) < 0 ? $day($f['lowest']['date']).' · ৳'.$lakh($f['lowest']['closing']).' '.__('finance::fund.lakh') : '—',
            'hint' => bccomp($f['lowest']['closing'], $f['total'], 4) < 0
                ? ($f['lowest']['who'] !== '' ? __('finance::fund.lowest_hint', ['who' => $f['lowest']['who']]) : '')
                : __('finance::fund.lowest_none'),
            'tone' => bccomp($f['lowest']['closing'], '0', 4) < 0 ? '#fecaca' : '#fde68a'],
        ['key' => 'cover', 'rgb' => '236,72,153', 'top' => '#f9a8d4', 'label' => __('finance::fund.cover'),
            'value' => $cover === null ? '—' : __('finance::fund.cover_times', ['n' => $digits($cover)]),
            'hint' => $cover === null ? __('finance::fund.cover_none') : __('finance::fund.cover_hint'), 'tone' => $coverTone],
        ['key' => 'od', 'rgb' => '167,139,250', 'top' => '#c4b5fd', 'label' => __('finance::fund.od'),
            'value' => bccomp($f['od_limit'], '0', 4) > 0 ? __('finance::fund.od_left', ['amount' => '৳'.$lakh($f['od_left'])]) : '—',
            'hint' => bccomp($f['od_limit'], '0', 4) > 0 ? __('finance::fund.od_hint', ['limit' => '৳'.$lakh($f['od_limit']), 'used' => '৳'.$lakh($f['od_used'])]) : __('finance::fund.od_none'),
            'tone' => '#e0e7ff'],
    ];
    $box = fn (string $rgb, string $top): string => "border-radius: 12px; padding: 9px 12px; display: flex; flex-direction: column; gap: 1px; background: rgba({$rgb},0.28); border: 1px solid rgba({$rgb},0.65); border-top: 4px solid {$top}; box-shadow: 0 4px 12px rgba(0,0,0,0.18); min-width: 0";
@endphp

<section data-fund
         style="flex: 999 1 640px; min-width: 0; border-radius: 18px; padding: 20px 22px; color: #ffffff; background: #312e81; background-image: linear-gradient(120deg, #312e81 0%, #4f46e5 55%, #7c3aed 100%); display: flex; flex-direction: column; gap: 14px">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; align-items: flex-end">
        <div>
            <p style="margin: 0; font-size: 14px; font-weight: 600; color: #c7d2fe">{{ __('finance::fund.title') }}</p>
            <p data-fund-total style="margin: 0; font-size: 44px; line-height: 1.1; font-weight: 700; font-variant-numeric: tabular-nums">{{ $taka($f['total']) }}</p>
            <p style="margin: 4px 0 0; font-size: 14px; font-weight: 600">
                <span data-fund-change style="border-radius: 999px; padding: 2px 10px; background: {{ $up === false ? 'rgba(239,68,68,0.25)' : 'rgba(34,197,94,0.25)' }}; color: {{ $up === false ? '#fecaca' : '#bbf7d0' }}">{{ $changeText }}</span>
            </p>
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 10px">
            <div data-fund-runway style="{{ $box('20,184,166', '#5eead4') }}; padding: 10px 14px">
                <p style="margin: 0; font-size: 12px; color: #c7d2fe">{{ __('finance::fund.runway') }}</p>
                <p style="margin: 0; font-size: 22px; font-weight: 700">{{ $f['runway_days'] === null ? '—' : __('finance::fund.runway_days', ['n' => $digits((string) $f['runway_days'])]) }}</p>
                <p style="margin: 0; font-size: 11px; color: #bbf7d0">{{ $f['runway_days'] === null ? __('finance::fund.runway_none') : __('finance::fund.runway_hint', ['amount' => $taka($f['daily_out'])]) }}</p>
            </div>
            <div data-fund-flow30 style="{{ $box('34,197,94', '#86efac') }}; padding: 10px 14px">
                <p style="margin: 0; font-size: 12px; color: #c7d2fe">{{ __('finance::fund.flow30') }}</p>
                <p style="margin: 0; font-size: 22px; font-weight: 700">{{ $signed($f['net30']) }}</p>
                <p style="margin: 0; font-size: 11px; color: #bbf7d0">{{ __('finance::fund.flow30_hint', ['in' => '৳'.$lakh($f['in30']), 'out' => '৳'.$lakh($f['out30'])]) }}</p>
            </div>
        </div>
    </div>

    <div role="img" aria-label="{{ __('finance::fund.share_bar', ['cash' => $digits($share['cash']), 'bank' => $digits($share['bank']), 'mfs' => $digits($share['mfs'])]) }}"
         style="display: flex; height: 14px; border-radius: 999px; overflow: hidden; gap: 2px; background: rgba(255,255,255,0.15)">
        @foreach ($f['parts'] as $part)
            @if (bccomp($part['share'], '0', 1) > 0)
                <div style="flex: {{ $part['share'] }}; background: {{ $partColour[$part['key']] }}"></div>
            @endif
        @endforeach
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px">
        @foreach ($f['parts'] as $part)
            <div data-fund-part="{{ $part['key'] }}">
                <p style="margin: 0; font-size: 13px; color: #c7d2fe"><span style="display: inline-block; width: 10px; height: 10px; border-radius: 3px; background: {{ $partColour[$part['key']] }}; margin-right: 6px"></span>{{ __('finance::fund.'.$part['key']) }} · {{ $digits(bcdiv($part['share'], '1', 0)) }}%</p>
                <p style="margin: 0; font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums">{{ $taka($part['amount']) }}</p>
                <p style="margin: 0; font-size: 12px; color: {{ $part['change_pct'] !== null && bccomp($part['change_pct'], '0', 1) < 0 ? '#fecaca' : '#bbf7d0' }}">
                    @if ($part['change_pct'] !== null){{ bccomp($part['change_pct'], '0', 1) < 0 ? '▼' : '▲' }} {{ $digits(ltrim($part['change_pct'], '-')) }}%@endif
                    @if ($part['key'] === 'bank'){{ $part['change_pct'] !== null ? ' · ' : '' }}{{ __('finance::fund.accounts', ['n' => $digits((string) $f['bank_accounts'])]) }}@endif
                </p>
            </div>
        @endforeach
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px">
        @foreach ($tiles as $t)
            <div data-fund-tile="{{ $t['key'] }}" style="{{ $box($t['rgb'], $t['top']) }}">
                <p style="margin: 0; font-size: 12px; color: #c7d2fe">{{ $t['label'] }}</p>
                <p style="margin: 0; font-size: 19px; font-weight: 700; color: #ffffff">{{ $t['value'] }}</p>
                <p style="margin: 0; font-size: 11px; font-weight: 600; color: {{ $t['tone'] }}">{{ $t['hint'] }}</p>
            </div>
        @endforeach
    </div>
</section>
