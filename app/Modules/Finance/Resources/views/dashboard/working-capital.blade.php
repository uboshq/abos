{{--
    ⭐ চলতি মূলধন — অর্থের ড্যাশবোর্ডের সবার নিচে (মালিক, ৯ নভেম্বর ২০২৬): মালিকের নিজের ছবির গড়ন (ছয় ঘর, যোগ-বিয়োগ, waterfall),
    তারিখের ঘর ("কোন তারিখে কত ছিল"), জাঁকালো রূপ, আর "চলতি মূলধন = গড় মাসিক বিক্রির %" (৬ বা ১২ মাসের গড়) শেষ বারের ঠিক উপরে
    বেগুনি ঘরে — নমুনা TWYacgufwfzT9ZqR1eqvaW-এ মালিকের "ok"। অঙ্ক [[WorkingCapital]] থেকে।

    ⓘ তারিখ আর গড় ঠিকানায় (`wc_date`, `wc_span`) — পাতা নতুন করে আঁকে, আলাদা জাভাস্ক্রিপ্ট লাগে না, লিংক কাউকে পাঠানো যায়।
    ⓘ বাংলা পর্দায় অঙ্ক বাংলায় (মালিকের ছবি), ইংরেজিতে ইংরেজি।
--}}
@php
    $bn = app()->getLocale() === 'bn';
    $digits = fn (string $s): string => $bn ? \App\Core\Support\BanglaDigits::of($s) : $s;
    $taka = fn (string $v): string => '৳'.$digits(\App\Core\Support\Money::format($v, 0));
    $lakh = fn (string $v): string => $digits(number_format((float) $v / 100000, 1));
    $w = $wc;
    $date = \Illuminate\Support\Carbon::parse($w['date']);
    $dayText = $digits(\App\Core\Support\DateFormat::format($w['date']));

    $today = \Illuminate\Support\Carbon::today();
    $presets = [
        ['label' => __('finance::working_capital.today'), 'date' => $today->toDateString()],
        ['label' => __('finance::working_capital.last_month_end'), 'date' => $today->copy()->startOfMonth()->subDay()->toDateString()],
        ['label' => __('finance::working_capital.last_year_end'), 'date' => $today->copy()->startOfYear()->subDay()->toDateString()],
    ];
    $url = fn (array $q): string => request()->fullUrlWithQuery($q);

    $ratio = $w['ratio'];
    [$ratioText, $ratioBg, $ratioFg] = match (true) {
        $ratio === null || bccomp($ratio, '1.5', 2) >= 0 => [__('finance::working_capital.ratio_good'), '#22c55e', '#052e16'],
        bccomp($ratio, '1', 2) >= 0 => [__('finance::working_capital.ratio_watch'), '#facc15', '#422006'],
        default => [__('finance::working_capital.ratio_bad'), '#ef4444', '#ffffff'],
    };

    // ── waterfall: ধাপগুলো (লাখে), অক্ষ ২০ লাখের ধাপে, সবচেয়ে উঁচু ধাপ পর্যন্ত
    $L = fn (string $v): float => (float) $v / 100000;
    $r = $L($w['receivable']); $s = $L($w['stock']); $m = $L($w['money']); $p = $L($w['payable']); $o = $L($w['other']); $cap = $L($w['capital']);
    $steps = [[0, $r], [$r, $r + $s], [$r + $s, $r + $s + $m], [$r + $s + $m, $r + $s + $m - $p], [$r + $s + $m - $p, $cap], [0, $cap]];
    $peak = max(1, ...array_map(fn ($st) => max($st[0], $st[1]), $steps));
    // ⓘ অক্ষের ধাপ গোল সংখ্যায় (১, ২, ৫, ১০, ২০, ২৫, ৫০ … লাখ), চারটার বেশি দাগ নয় — মালিকের ছবির মতো ০/২০/৪০/৬০
    $step = collect([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000])->first(fn ($n) => $n * 4 >= $peak) ?? 10000;
    $axisMax = (int) (ceil($peak / $step) * $step);
    $plot = 300;
    $px = fn (float $v): float => round(max(0, $v) / $axisMax * ($plot - 30), 1);
    $fills = ['linear-gradient(180deg,#ff6b81,#e5223a)', 'linear-gradient(180deg,#4ade80,#00a651)', 'linear-gradient(180deg,#60a5fa,#1f6feb)',
        'linear-gradient(180deg,#f472b6,#e91e8c)', 'linear-gradient(180deg,#fdba74,#f47c00)', 'linear-gradient(180deg,#3b5bdb,#0b2fb8)'];
    $names = ['bar_receivable', 'bar_stock', 'bar_money', 'bar_payable', 'bar_other', 'capital'];
    $signs = ['+ ', '+ ', '+ ', '− ', '− ', '= '];
    $amounts = [$w['receivable'], $w['stock'], $w['money'], $w['payable'], $w['other'], $w['capital']];
    $grid = [];
    for ($g = 0; $g <= $axisMax; $g += $step) {
        $grid[] = $g;
    }

    $change = $w['change_pct'];
    $changeText = $change === null ? null : __(bccomp($change, '0', 1) >= 0 ? 'finance::working_capital.up_vs_last_month' : 'finance::working_capital.down_vs_last_month',
        ['pct' => $digits(ltrim($change, '-'))]);

    $boxes = [
        ['#e5223a', 'receivable', 'Receivable', $w['receivable'], null],
        ['#00a651', 'stock', 'Stock', $w['stock'], '+'],
        ['#1f6feb', 'money', 'Cash', $w['money'], '+'],
        ['#e91e8c', 'payable', 'Payable', $w['payable'], '−'],
        ['#f47c00', 'other', null, $w['other'], '−'],
    ];
@endphp

<section data-working-capital
         style="border-radius: 22px; padding: 22px 24px; display: flex; flex-direction: column; gap: 18px; color: #ffffff; background: #0b1437; background-image: radial-gradient(1200px 400px at 10% -10%, rgba(124,58,237,0.55), transparent 60%), radial-gradient(900px 380px at 100% 110%, rgba(14,165,233,0.40), transparent 60%), linear-gradient(135deg, #0b1437 0%, #1e1b4b 55%, #2e1065 100%); box-shadow: 0 18px 40px rgba(30,27,75,0.35); border: 1px solid rgba(245,199,107,0.35)">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 14px; align-items: flex-start">
        <div style="display: flex; flex-direction: column; gap: 4px">
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center">
                <h2 style="margin: 0; font-size: 22px; font-weight: 700; color: #f5c76b">{{ __('finance::working_capital.title') }}</h2>
                <form method="GET" action="{{ url()->current() }}" data-wc-date
                      style="display: flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.10); border: 1px solid rgba(245,199,107,0.55); border-radius: 12px; padding: 4px 6px 4px 12px">
                    @foreach (request()->except(['wc_date']) as $k => $v)
                        @if (is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                    @endforeach
                    <label for="wc-date" style="font-size: 13px; font-weight: 600; color: #fde68a">{{ __('finance::working_capital.date') }}</label>
                    <input id="wc-date" type="date" name="wc_date" value="{{ $w['date'] }}" max="{{ $today->toDateString() }}"
                           style="font: inherit; font-size: 14px; font-weight: 600; color: #0b1437; background: #fef3c7; border: 0; border-radius: 8px; padding: 6px 8px; min-height: 36px">
                    <button type="submit" style="font: inherit; font-size: 13px; font-weight: 700; min-height: 36px; padding: 0 12px; border-radius: 8px; border: 0; background: #f5c76b; color: #1e1b4b; cursor: pointer">{{ __('finance::working_capital.show') }}</button>
                </form>
                @foreach ($presets as $preset)
                    @php $on = $preset['date'] === $w['date']; @endphp
                    <a href="{{ $url(['wc_date' => $preset['date']]) }}" data-wc-preset @if ($on) aria-current="true" @endif
                       style="font-size: 13px; font-weight: 600; min-height: 36px; display: inline-flex; align-items: center; padding: 0 12px; border-radius: 999px; text-decoration: none; border: 1px solid {{ $on ? '#f5c76b' : 'rgba(255,255,255,0.25)' }}; background: {{ $on ? '#f5c76b' : 'transparent' }}; color: {{ $on ? '#1e1b4b' : '#e0e7ff' }}">{{ $preset['label'] }}</a>
                @endforeach
            </div>
            <p style="margin: 0; font-size: 13px; color: #c7d2fe">{{ __('finance::working_capital.subtitle', ['date' => $dayText]) }}</p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; background: rgba(255,255,255,0.08); border-radius: 14px; padding: 8px 14px">
            <span style="font-size: 13px; color: #c7d2fe">{{ __('finance::working_capital.ratio') }}</span>
            <span style="font-size: 26px; font-weight: 700" data-wc-ratio>{{ $ratio === null ? '—' : $digits($ratio) }}</span>
            <span style="font-size: 12px; font-weight: 700; background: {{ $ratioBg }}; color: {{ $ratioFg }}; border-radius: 999px; padding: 3px 10px">{{ $ratioText }}</span>
        </div>
    </div>

    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: stretch">
        @foreach ($boxes as [$edge, $key, $english, $value, $op])
            @if ($op)<span style="align-self: center; font-size: 22px; font-weight: 700; color: #f5c76b">{{ $op }}</span>@endif
            <div data-wc-part="{{ $key }}" style="flex: 1 1 150px; min-width: 0; border-radius: 14px; padding: 10px 12px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14); border-top: 3px solid {{ $edge }}; box-shadow: 0 6px 18px rgba(0,0,0,0.25)">
                <p style="margin: 0; font-size: 13px; color: #e0e7ff">{{ __('finance::working_capital.'.$key) }}</p>
                <p style="margin: 2px 0 0; font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; color: #ffffff">{{ $taka($value) }}</p>
                <p style="margin: 2px 0 0; font-size: 12px; color: #c7d2fe">{{ $english ?? __('finance::working_capital.other_sub') }}</p>
            </div>
        @endforeach
        <span style="align-self: center; font-size: 22px; font-weight: 700; color: #f5c76b">=</span>
        <div data-wc-part="capital" style="flex: 1 1 180px; min-width: 0; border-radius: 14px; padding: 10px 12px; background: linear-gradient(135deg, #b8860b, #f5c76b); color: #1e1b4b; box-shadow: 0 8px 22px rgba(245,199,107,0.35)">
            <p style="margin: 0; font-size: 13px; font-weight: 700; color: #1e1b4b">{{ __('finance::working_capital.capital') }}</p>
            <p style="margin: 2px 0 0; font-size: 21px; font-weight: 700; font-variant-numeric: tabular-nums; color: #0b1437">{{ $taka($w['capital']) }}</p>
            @if ($changeText)<p style="margin: 2px 0 0; font-size: 12px; font-weight: 700; color: #1e1b4b">{{ $changeText }}</p>@endif
        </div>
    </div>

    <div role="img" aria-label="{{ __('finance::working_capital.aria', ['date' => $dayText]) }}"
         style="display: flex; gap: 10px; background: #ffffff; border-radius: 16px; padding: 30px 18px 14px 10px; box-shadow: inset 0 0 0 1px rgba(245,199,107,0.45)">
        <div style="position: relative; width: 64px; height: {{ $plot }}px; flex: none">
            @foreach ($grid as $g)
                <span style="position: absolute; right: 0; bottom: {{ $px($g) - 8 }}px; font-size: 14px; color: #64748b; white-space: nowrap">{{ __('finance::working_capital.axis_lakh', ['n' => $digits((string) $g)]) }}</span>
            @endforeach
        </div>
        <div style="flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 8px">
            <div style="position: relative; height: {{ $plot }}px">
                @foreach ($grid as $g)
                    <div style="position: absolute; left: 0; right: 0; bottom: {{ $px($g) }}px; border-top: 1px solid #e2e8f0"></div>
                @endforeach

                {{-- ⭐ গড় মাসিক বিক্রির % — শেষ বারের (চলতি মূলধন) ঠিক উপরে, বেগুনি ঘরে (মালিকের দাগ দেওয়া ছবি, ৯ নভেম্বর) --}}
                <div style="position: absolute; right: 0; top: 0; width: 16.66%; display: flex; justify-content: center; z-index: 2" data-wc-sales>
                    <div style="width: 88%; box-sizing: border-box; background: linear-gradient(135deg, #7c3aed 0%, #4c1d95 100%); border: 2px solid #c4b5fd; border-radius: 14px; padding: 8px 10px; display: flex; flex-direction: column; gap: 4px; align-items: center; text-align: center; box-shadow: 0 8px 20px rgba(76,29,149,0.40)">
                        <span style="font-size: 12px; font-weight: 600; color: #ede9fe">{{ __('finance::working_capital.sales_share') }}</span>
                        <span style="font-size: 32px; line-height: 1; font-weight: 700; color: #fde047" data-wc-sales-pct>{{ $w['sales_pct'] === null ? '—' : $digits($w['sales_pct']).'%' }}</span>
                        <span style="display: flex; gap: 4px; flex-wrap: wrap; justify-content: center">
                            @foreach (\App\Modules\Finance\Services\WorkingCapital::SPANS as $n)
                                @php $on = $w['span'] === $n; @endphp
                                <a href="{{ $url(['wc_span' => $n, 'wc_date' => $w['date']]) }}" data-wc-span="{{ $n }}" @if ($on) aria-current="true" @endif
                                   style="font-size: 11px; font-weight: 700; min-height: 26px; display: inline-flex; align-items: center; padding: 0 8px; border-radius: 999px; text-decoration: none; border: 1px solid #fde047; background: {{ $on ? '#fde047' : 'transparent' }}; color: {{ $on ? '#3b0764' : '#fef9c3' }}">{{ __('finance::working_capital.avg_months', ['n' => $digits((string) $n)]) }}</a>
                            @endforeach
                        </span>
                        <span style="font-size: 11px; color: #ddd6fe">{{ bccomp($w['avg_sales'], '0', 4) > 0 ? __('finance::working_capital.avg_sales', ['amount' => $lakh($w['avg_sales'])]) : __('finance::working_capital.no_sales') }}</span>
                    </div>
                </div>

                <div style="position: absolute; inset: 0; display: grid; grid-template-columns: repeat(6, minmax(0, 1fr))">
                    @foreach ($steps as $i => [$from, $to])
                        @php
                            $lo = $px(min($from, $to)); $hi = $px(max($from, $to)); $end = $px($to);
                        @endphp
                        <div style="position: relative; height: {{ $plot }}px">
                            @if ($i < 5)<div style="position: absolute; left: 86%; right: -14%; bottom: {{ $end }}px; border-top: 2px dashed #94a3b8"></div>@endif
                            @if ($i < 5)
                                <div style="position: absolute; left: 0; right: 0; bottom: {{ $hi + 6 }}px; text-align: center; font-size: 16px; font-weight: 700; color: #0f172a; white-space: nowrap">{{ $signs[$i] }}৳{{ $lakh($amounts[$i]) }} {{ __('finance::working_capital.lakh') }}</div>
                            @endif
                            <div data-wc-bar="{{ $names[$i] }}" style="position: absolute; left: 14%; right: 14%; bottom: {{ $lo }}px; height: {{ max($hi - $lo, 2) }}px; border-radius: 8px; background: {{ $fills[$i] }}; box-shadow: 0 4px 10px rgba(15,23,42,0.18)"></div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(6, minmax(0, 1fr))">
                @foreach ($names as $i => $name)
                    <span style="text-align: center; font-size: 15px; color: {{ $i === 5 ? '#0f172a' : '#334155' }}; font-weight: {{ $i === 5 ? 700 : 400 }}">
                        {{ __('finance::working_capital.'.$name) }}@if ($i === 5)<br><b style="font-size: 16px">= ৳{{ $lakh($w['capital']) }} {{ __('finance::working_capital.lakh') }}</b>@endif
                    </span>
                @endforeach
            </div>
            <p style="margin: 2px 0 0; font-size: 13px; color: #475569; text-align: center">{{ __('finance::working_capital.read_me') }}</p>
        </div>
    </div>
</section>
