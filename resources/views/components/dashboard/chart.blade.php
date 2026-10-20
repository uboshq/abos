{{-- ⭐ একটা চার্ট, ধরন অনুযায়ী আঁকা — মালিক, ৪ অক্টোবর ২০২৬: *"sob ekoi rokom graph diyecho keno vino rokomer int standred
     world femus graph daw"* আর *"sob chart ei velue dio"*।

     ⓘ সময়ের ধারা ([[Series]]): রেখা (line), ভরা রেখা (area), পাশাপাশি স্তম্ভ (bars)।
     ⓘ ভাগ ([[Breakdown]]): ডোনাট, আড়াআড়ি দণ্ড (hbars), খাড়া স্তম্ভ (columns), ফানেল (funnel)।
     ⓘ প্রতিটা দাগে মান — রং একা কিছু বলে না; পুরো অঙ্ক title-এ। জাভাস্ক্রিপ্ট নেই, সার্ভারেই আঁকা।
     ⭐ চার্টের প্রতিটা লেখা HTML-এ, আসল পিক্সেলে, কখনো [[Series::TEXT_PX]] (12px)-এর নিচে নয় — অক্ষ, নাম, মান, ডোনাটের মাঝখান
       (মালিকের মান: Fiori + Material 3 + WCAG 2.2 AA, ২০ অক্টোবর ২০২৬)। ⛔ আগে লেখা SVG-র ভিতরে ছিল আর SVG ছোট হলে
       লেখাও ছোট হত (হোমে প্রায় ৮px); দণ্ডের মান খাড়া করে লেখা হত। এখন সব লেখা আড়াআড়ি।
     ⭐ সব মান পাশাপাশি না ধরলে কেবল সবচেয়ে বড়টার মান চোখে; বাকিগুলো পর্দা-পাঠকের জন্য থাকে (sr-only), আর পুরো অঙ্ক
       মাউস রাখলে (title) আর কিবোর্ডে এলে (নিচের ছোট বাক্স) — "14,52,300.00 টাকা" ([[Series::full()]])।
     ⓘ কিবোর্ড: `interactive` হলে প্রতিটা দণ্ড/বিন্দু Tab-এ আসে। হোমে পুরো কার্ডটাই একটা লিংক — লিংকের ভেতরে আরেকটা
       ফোকাসের জায়গা HTML-এ বৈধ নয়, তাই সেখানে `:interactive="false"`; হোমে পুরো অঙ্ক মডিউলের পাতায়।
     ⓘ রং চার্টের নিজের টোকেন `--color-chart-১…৬` (tokens.css §১৪.৮) — ক্রম স্থির, ঘোরে না; রং-দৃষ্টির ঘাটতিতেও পাশাপাশি
       আলাদা (ΔE ≥ ৮.৬, পাঁচ যাচাই পাশ)। ⚠️ মডিউলের রং নয়: কোম্পানির রূপ (look) সেগুলো এক রঙে মিলিয়ে দেয়, তখন সব টুকরো
       এক হয়ে যেত (৪ অক্টোবর ২০২৬ ছবিতে ধরা)। বান্ডেল টোকেনটা না জানলেও চলে — পাশে একই রঙের বিকল্প।
     ⓘ শূন্যের মাথায় "0" লেখা হয় না — শূন্য বিন্দু মাটিতেই বসে; ডোনাটের পাশের তালিকায় শূন্যও থাকে।
     ⓘ হোম আর মডিউলের নতুন পাতা এই একটাই ছক ডাকে — দুই জায়গায় দুই রকম আঁকা হয় না। --}}
@props(['panel', 'compact' => false, 'showRange' => true, 'wide' => false, 'interactive' => true])

@php
    $palette = ['var(--color-chart-1)', 'var(--color-chart-2)', 'var(--color-chart-3)',
        'var(--color-chart-4)', 'var(--color-chart-5)', 'var(--color-chart-6)'];
    $zero = fn ($v) => (float) str_replace(',', '', (string) $v) == 0.0;
    $short = fn ($v) => \App\Core\Engines\Dashboard\Series::short($v);
    $isSeries = $panel instanceof \App\Core\Engines\Dashboard\Series;
    $kind = $isSeries ? $panel->chart : $panel->kind();

    // ⭐ লেখার মাপ একটাই ধ্রুবক থেকে; আঁকার জায়গার আন্দাজি চওড়া ([[Series::PLOT_PX]])
    $px = \App\Core\Engines\Dashboard\Series::TEXT_PX;
    $plot = \App\Core\Engines\Dashboard\Series::PLOT_PX[$compact ? 'compact' : ($wide ? 'wide' : 'normal')];
    $fits = fn (array $texts, float $slot) => \App\Core\Engines\Dashboard\Series::fits($texts, $slot);
    $every = fn (array $texts, float $slot) => \App\Core\Engines\Dashboard\Series::every($texts, $slot);

    // ⭐ পুরো অঙ্ক — মাউস/কিবোর্ডের বাক্সে; টাকার ধারায় শেষে "টাকা"
    $taka = $isSeries && $panel->money ? ' '.__('core.dashboard.taka') : '';
    $tip = fn (array $point, string $side, string $name) => $point['label'].' · '.$name.': '
        .($point[$side.'Title'] ?? \App\Core\Engines\Dashboard\Series::full($point[$side])).$taka;

    // ⓘ কিবোর্ডের বাক্সের নিয়ম পাতায় একবারই (CSP: nonce-ওয়ালা style; বান্ডেলে group-focus শ্রেণি নেই)
    $tipCss = $interactive && ! request()->attributes->get('chart-tip-css');
    if ($tipCss) {
        request()->attributes->set('chart-tip-css', true);
    }
@endphp

@if ($tipCss)
    <style @nonce>
        [data-chart-tip] { outline: none; }
        [data-chart-tip]:focus-visible { outline: 2px solid var(--color-brand-600); outline-offset: 2px; }
        [data-chart-tip] > .chart-tip { display: none; position: absolute; left: 50%; bottom: calc(100% + 6px); transform: translateX(-50%);
            z-index: 20; white-space: nowrap; padding: .25rem .5rem; border-radius: .375rem; font-size: {{ $px }}px; line-height: 1.25rem;
            font-weight: 600; color: var(--color-surface-card); background: var(--color-ink); box-shadow: 0 2px 6px rgb(0 0 0 / .25);
            pointer-events: none; }
        [data-chart-tip]:focus-visible > .chart-tip { display: block; }
    </style>
@endif

<div data-chart="{{ $kind }}" class="min-w-0">
@if ($isSeries && $kind === 'bars')
    @php
        $peak = $panel->peak();
        $n = count($panel->points);
        $names = ['first' => $panel->firstLabel, 'second' => $panel->secondLabel];
        $texts = [];
        $top = [null, -1.0];
        foreach ($panel->points as $i => $point) {
            foreach (['first', 'second'] as $side) {
                if (! $zero($point[$side])) {
                    $texts[] = $point[$side.'Note'] ?? $short($point[$side]);
                }
                if ((float) $point[$side] > $top[1]) {
                    $top = [$i.$side, (float) $point[$side]];
                }
            }
        }
        // ⓘ প্রতি দণ্ডের ঘর ≈ চওড়া ÷ (বিন্দু × ২); সব মান ধরলে সব, নইলে কেবল সবচেয়ে উঁচুটা
        $allFit = $fits($texts, $plot / max(1, $n * 2) - 2);
        $step = $every(array_column($panel->points, 'label'), $plot / max(1, $n) - 8);
    @endphp
    <div class="flex items-end gap-2 px-4 pt-4" style="height: {{ $compact ? '7.5rem' : 'var(--spacing-chart)' }}">
        @foreach ($panel->points as $i => $point)
            <div class="flex h-full flex-1 items-end justify-center gap-0.5">
                @foreach ([['first', $palette[0], $panel->firstLabel], ['second', $palette[1], $panel->secondLabel]] as [$side, $fill, $name])
                    {{-- ⭐ মান দণ্ডের মাথায়, আড়াআড়ি, 12px (২০ অক্টোবর ২০২৬; আগে দণ্ডের ভেতরে খাড়া লেখা ছিল) --}}
                    @php
                        $barH = max(2, (int) round((float) $point[$side] / $peak * 85));
                        $shown = $allFit || $top[0] === $i.$side;
                        $label = $tip($point, $side, $name);
                    @endphp
                    <div class="flex h-full w-1/2 flex-col items-center justify-end">
                        <span data-bar-value @class(['mb-0.5 whitespace-nowrap leading-none tabular-nums font-semibold text-(--color-ink-body)' => $shown, 'sr-only' => ! $shown])
                              @if ($shown) style="font-size: {{ $px }}px" @endif>{{ $zero($point[$side]) ? '' : ($point[$side.'Note'] ?? $short($point[$side])) }}</span>
                        <div class="w-full rounded-t"
                             style="position: relative; height:{{ $barH }}%; background: {{ $fill }}"
                             title="{{ $label }}"
                             @if ($interactive) data-chart-tip tabindex="0" aria-label="{{ $label }}" @endif>@if ($interactive)<span class="chart-tip" aria-hidden="true">{{ $label }}</span>@endif</div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
    <div class="flex gap-2 px-4 pt-1">
        @foreach ($panel->points as $i => $point)
            {{-- ⓘ সব নাম না ধরলে প্রতি ২য়/৩য়টা, শেষেরটা সবসময় ([[Series::every()]]) — কাটা বা ঘেঁষাঘেঁষি নয় --}}
            <div class="min-w-0 flex-1 whitespace-nowrap text-center text-xs text-(--color-ink-muted)">{{ ($n - 1 - $i) % $step === 0 ? $point['label'] : '' }}</div>
        @endforeach
    </div>
@elseif ($isSeries)
    {{-- রেখা বা ভরা রেখা — দুই ধারা; দাগ SVG-তে, লেখা উপরে HTML-এ (শতাংশে বসানো, তাই SVG যত চওড়াই হোক লেখা 12px) --}}
    @php
        // ⓘ ভিতরের চওড়া কার্ডের চওড়ার কাছাকাছি — নইলে SVG চওড়ায় টেনে লম্বাও হয় (চওড়া কার্ডে ৬০০px পর্যন্ত, ৫ অক্টোবর ২০২৬ ছবিতে ধরা)
        // ⓘ পুরো সারি জুড়লে (`wide`) আঁকার জায়গাও চওড়া — লেখা আর দাগ দুই ঘরের চার্টের মাপেই থাকে (৬ অক্টোবর ২০২৬-এর যাচাই)
        $w = $compact ? 380 : ($wide ? 1140 : 760); $h = $compact ? 140 : 210; $left = 16; $right = 16; $top = 22; $bottom = 30;
        $peak = $panel->peak();
        $n = count($panel->points);
        $x = fn (int $i) => $n === 1 ? $w / 2 : $left + $i * ($w - $left - $right) / ($n - 1);
        $y = fn ($v) => $top + ($h - $top - $bottom) * (1 - (float) $v / $peak);
        $pct = fn (float $v, float $of) => round($v / $of * 100, 2).'%';
        $line = fn (string $side, array $run) => implode(' ', array_map(fn ($i) => round($x($i), 1).','.round($y($panel->points[$i][$side]), 1), $run));
        /*
         * ⛔ `null` মান = ঐ দিনের হিসাব নেই — শূন্য নয়, তাই দাগ ওখানে ভাঙে, মাটিতে নামে না (ec, Executive লাইন-নিরীক্ষা,
         * ১৪ অক্টোবর ২০২৬: মালিকের ইতিহাসে রাতের হিসাব না থাকা দিন ৳০ আঁকা হতো)। ⓘ বাকি সব চার্টে মান সবসময় লেখা, তাই একটাই দৌড়।
         */
        $runs = function (string $side) use ($panel): array {
            $out = [];
            $run = [];
            foreach ($panel->points as $i => $point) {
                if ($point[$side] !== null) {
                    $run[] = $i;

                    continue;
                }

                if ($run !== []) {
                    $out[] = $run;
                }

                $run = [];
            }

            if ($run !== []) {
                $out[] = $run;
            }

            return $out;
        };

        // ⓘ মান সব ধরলে সব; নইলে প্রতিটা ধারার সবচেয়ে বড়টা
        $texts = [];
        $tops = ['first' => [null, -1.0], 'second' => [null, -1.0]];
        foreach ($panel->points as $i => $point) {
            foreach (['first', 'second'] as $side) {
                if ($point[$side] !== null && ! $zero($point[$side])) {
                    $texts[] = $point[$side.'Note'] ?? $short($point[$side]);
                }
                if ($point[$side] !== null && (float) $point[$side] > $tops[$side][1]) {
                    $tops[$side] = [$i, (float) $point[$side]];
                }
            }
        }
        $allFit = $fits($texts, $plot / max(1, $n));
        $step = $every(array_column($panel->points, 'label'), $plot / max(1, $n) - 8);
    @endphp
    <div class="px-2 pt-2">
        <div style="position: relative">
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="block w-full" role="img" aria-label="{{ $panel->label }}">
                <line x1="{{ $left }}" x2="{{ $w - $right }}" y1="{{ $h - $bottom }}" y2="{{ $h - $bottom }}" style="stroke: var(--color-border)" stroke-width="1" />
                @foreach ([['second', $palette[1], $panel->secondLabel], ['first', $palette[0], $panel->firstLabel]] as [$side, $stroke, $name])
                    @foreach ($runs($side) as $run)
                        @if ($kind === 'area')
                            <polygon points="{{ round($x($run[0]), 1) }},{{ $h - $bottom }} {{ $line($side, $run) }} {{ round($x($run[count($run) - 1]), 1) }},{{ $h - $bottom }}"
                                     style="fill: {{ $stroke }}; fill-opacity: 0.14" />
                        @endif
                        <polyline points="{{ $line($side, $run) }}" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" style="stroke: {{ $stroke }}" />
                    @endforeach
                    @foreach ($panel->points as $i => $point)
                        @continue($point[$side] === null)
                        <circle cx="{{ round($x($i), 1) }}" cy="{{ round($y($point[$side]), 1) }}" r="3" stroke-width="1.5"
                                style="fill: {{ $stroke }}; stroke: var(--color-surface-card)">
                            <title>{{ $tip($point, $side, $name) }}</title>
                        </circle>
                    @endforeach
                @endforeach
            </svg>

            @foreach ([['second', $palette[1], $panel->secondLabel, 14], ['first', $palette[0], $panel->firstLabel, -11]] as [$side, $stroke, $name, $dy])
                @foreach ($panel->points as $i => $point)
                    @continue($point[$side] === null)
                    @php
                        $shown = $allFit || $tops[$side][0] === $i;
                        $label = $tip($point, $side, $name);
                    @endphp
                    <span data-bar-value @class(['whitespace-nowrap leading-none tabular-nums font-semibold' => $shown, 'sr-only' => ! $shown])
                          @if ($shown) style="position: absolute; left: {{ $pct($x($i), $w) }}; top: {{ $pct(min($h - $bottom - 8, max(9, $y($point[$side]) + $dy)), $h) }}; transform: translate(-50%, -50%); font-size: {{ $px }}px; color: {{ $stroke }}" @endif>{{ $zero($point[$side]) ? '' : ($point[$side.'Note'] ?? $short($point[$side])) }}</span>
                    @if ($interactive)
                        <span data-chart-tip tabindex="0" aria-label="{{ $label }}" title="{{ $label }}"
                              style="position: absolute; left: {{ $pct($x($i), $w) }}; top: {{ $pct($y($point[$side]), $h) }}; width: 1.25rem; height: 1.25rem; transform: translate(-50%, -50%); border-radius: 9999px"><span class="chart-tip" aria-hidden="true">{{ $label }}</span></span>
                    @endif
                @endforeach
            @endforeach

            @foreach ($panel->points as $i => $point)
                @if (($n - 1 - $i) % $step === 0)
                    <span class="whitespace-nowrap leading-none text-(--color-ink-muted)"
                          style="position: absolute; left: {{ $pct($x($i), $w) }}; top: {{ $pct($h - 12, $h) }}; transform: translate(-50%, -50%); font-size: {{ $px }}px">{{ $point['label'] }}</span>
                @endif
            @endforeach
        </div>
    </div>
@endif

@if ($isSeries)
    <div @class(['flex flex-wrap items-center gap-4 px-4 text-xs text-(--color-ink-muted)', 'py-1' => $compact, 'py-2' => ! $compact])>
        <span class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm" style="background: {{ $palette[0] }}"></span>{{ $panel->firstLabel }}</span>
        <span class="flex items-center gap-1.5"><span class="inline-block size-2.5 rounded-sm" style="background: {{ $palette[1] }}"></span>{{ $panel->secondLabel }}</span>
    </div>
@else
    @php
        $num = fn ($v) => \App\Core\Engines\Dashboard\Breakdown::number($v);
        $parts = $panel->parts;
        $sum = array_sum(array_map(fn ($p) => max(0.0, $num($p['value'])), $parts));
        $total = max(1.0, $sum);
        $max = max(1.0, ...array_map(fn ($p) => abs($num($p['value'])), $parts));
    @endphp

    @if ($kind === 'donut')
        {{-- ডোনাট — টুকরোর মাঝে সরু ফাঁক; মাঝখানে মোট (HTML-এ, 13px আর 12px — SVG-র ভিতরে ছোট হয়ে যেত); পাশে নাম, মান আর ভাগ --}}
        @php $r = 42; $circ = 2 * M_PI * $r; $at = 0.0; $sumFull = \App\Core\Engines\Dashboard\Series::full($sum); @endphp
        <div class="flex items-center gap-3 px-4 py-3">
            <div class="shrink-0" style="position: relative; width: {{ $compact ? '8rem' : '9rem' }}">
                <svg viewBox="0 0 120 120" class="block w-full" role="img" aria-label="{{ $panel->label }}">
                    <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke-width="18" style="stroke: var(--color-surface-hover)" />
                    @foreach ($parts as $i => $part)
                        @php $len = $sum > 0 ? max(0.0, $num($part['value'])) / $total * $circ : 0.0; $gap = $len > 4 ? 1.5 : 0; @endphp
                        @if ($len > 0)
                            <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke-width="18" transform="rotate(-90 60 60)"
                                    stroke-dasharray="{{ round($len - $gap, 2) }} {{ round($circ - $len + $gap, 2) }}" stroke-dashoffset="{{ round(-$at, 2) }}"
                                    style="stroke: {{ $palette[$i % 6] }}"><title>{{ $part['label'] }}: {{ $part['value'] }}</title></circle>
                        @endif
                        @php $at += $len; @endphp
                    @endforeach
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center text-center leading-tight" title="{{ __('core.dashboard.chart_total') }}: {{ $sumFull }}">
                    <span data-donut-total class="tabular-nums font-bold text-(--color-ink)" style="font-size: {{ $px + 1 }}px">{{ $short($sum) }}</span>
                    <span class="text-(--color-ink-muted)" style="font-size: {{ $px }}px">{{ __('core.dashboard.chart_total') }}</span>
                </div>
            </div>
            {{-- ⓘ চওড়া বাক্সেও নাম আর মান কাছাকাছি — পুরো সারি জুড়লে মানটা ডান কিনারায় চলে যেত (৬ অক্টোবর ২০২৬-এর যাচাই) --}}
            <div class="min-w-0 flex-1 space-y-1.5" style="max-width: 28rem">
                @foreach ($parts as $i => $part)
                    <div class="flex items-center gap-1.5 text-xs" title="{{ $part['label'] }}: {{ $part['value'] }}">
                        <span class="inline-block size-2.5 shrink-0 rounded-full" style="background: {{ $palette[$i % 6] }}"></span>
                        <span class="min-w-0 flex-1 truncate text-(--color-ink-body)">{{ $part['label'] }}</span>
                        <span data-bar-value class="shrink-0 font-semibold tabular-nums">{{ $part['value'] }}</span>
                        @unless ($compact)
                            <span class="w-10 shrink-0 text-end tabular-nums text-(--color-ink-muted)">{{ $sum > 0 ? (int) round(max(0.0, $num($part['value'])) / $total * 100) : 0 }}%</span>
                        @endunless
                    </div>
                @endforeach
            </div>
        </div>
    @elseif ($kind === 'funnel')
        {{-- ফানেল — ধাপগুলো মাঝ বরাবর সরু হয়; পাশে আগের ধাপের কত ভাগ এল --}}
        <div class="space-y-1.5 px-4 py-3">
            @foreach ($parts as $i => $part)
                @php
                    $v = $num($part['value']);
                    $prev = $i > 0 ? $num($parts[$i - 1]['value']) : null;
                @endphp
                <div class="flex items-center gap-2 text-xs">
                    <span class="w-20 shrink-0 truncate text-(--color-ink-body)">{{ $part['label'] }}</span>
                    <div class="flex min-w-0 flex-1 justify-center">
                        <div class="flex h-6 items-center justify-center rounded text-white"
                             style="width: {{ max(8, (int) round(abs($v) / $max * 100)) }}%; min-width: 2.5rem; background: {{ $palette[$i % 6] }}"
                             title="{{ $part['label'] }}: {{ $part['value'] }}">
                            <span data-bar-value class="font-semibold tabular-nums" style="text-shadow: 0 0 2px color-mix(in srgb, black 45%, transparent)">{{ $part['value'] }}</span>
                        </div>
                    </div>
                    <span class="w-10 shrink-0 text-end tabular-nums text-(--color-ink-muted)">{{ $prev !== null && $prev > 0 ? (int) round($v / $prev * 100).'%' : '' }}</span>
                </div>
            @endforeach
        </div>
    @elseif ($kind === 'columns')
        {{-- খাড়া স্তম্ভ — এক ধারা, এক রং; মাথায় মান (12px, আড়াআড়ি), নিচে নাম --}}
        @php
            $n = count($parts);
            $texts = array_values(array_filter(array_map(fn ($p) => $zero($p['value']) ? null : (string) $p['value'], $parts)));
            $allFit = $fits($texts, $plot / max(1, $n) - 8);
            $topAt = array_search(max(array_map(fn ($p) => $num($p['value']), $parts)), array_map(fn ($p) => $num($p['value']), $parts), true);
            $step = $every(array_column($parts, 'label'), $plot / max(1, $n) - 8);
        @endphp
        <div class="flex items-end gap-2 px-4 pt-4" style="height: {{ $compact ? '7.5rem' : 'var(--spacing-chart)' }}">
            @foreach ($parts as $i => $part)
                @php $shown = $allFit || $i === $topAt; $label = $part['label'].': '.$part['value']; @endphp
                <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end">
                    <span data-bar-value @class(['mb-0.5 whitespace-nowrap leading-none tabular-nums font-semibold text-(--color-ink-body)' => $shown, 'sr-only' => ! $shown])
                          @if ($shown) style="font-size: {{ $px }}px" @endif>{{ $zero($part['value']) ? '' : $part['value'] }}</span>
                    <div class="rounded-t" title="{{ $label }}"
                         style="position: relative; width: 70%; height: {{ max(2, (int) round(max(0.0, $num($part['value'])) / $max * 85)) }}%; background: {{ $palette[0] }}"
                         @if ($interactive) data-chart-tip tabindex="0" aria-label="{{ $label }}" @endif>@if ($interactive)<span class="chart-tip" aria-hidden="true">{{ $label }}</span>@endif</div>
                </div>
            @endforeach
        </div>
        <div class="flex gap-2 px-4 pt-1 pb-2">
            @foreach ($parts as $i => $part)
                @php $axis = ($n - 1 - $i) % $step === 0; @endphp
                {{-- ⓘ ধাপে চাপলে তার তালিকা — যে ভাগ নিজের ঠিকানা দেয় (`href`; বাকি নিয়ন্ত্রণ, ১১ অক্টোবর ২০২৬) --}}
                @if (! empty($part['href']))
                    <a href="{{ $part['href'] }}" class="min-w-0 flex-1 whitespace-nowrap text-center text-xs text-(--color-brand-500) hover:underline"
                       @unless ($axis) aria-label="{{ $part['label'] }}" @endunless>{{ $axis ? $part['label'] : '' }}</a>
                @else
                    <div class="min-w-0 flex-1 whitespace-nowrap text-center text-xs text-(--color-ink-muted)">{{ $axis ? $part['label'] : '' }}</div>
                @endif
            @endforeach
        </div>
    @else
        {{-- আড়াআড়ি দণ্ড — নাম আর মান এক লাইনে, নিচে দণ্ড --}}
        <div class="space-y-3 px-4 py-3">
            @foreach ($compact ? array_slice($parts, 0, 5) : $parts as $part)
                <div>
                    <div class="mb-1 flex items-baseline justify-between gap-3 text-xs">
                        @if (! empty($part['href']))
                            <a href="{{ $part['href'] }}" class="min-w-0 truncate text-(--color-brand-500) hover:underline">{{ $part['label'] }}</a>
                        @else
                            <span class="min-w-0 truncate text-(--color-ink-body)">{{ $part['label'] }}</span>
                        @endif
                        <span data-bar-value class="shrink-0 font-semibold tabular-nums">{{ $part['value'] }}</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-(--color-surface-hover)">
                        <div class="h-full rounded-full" title="{{ $part['label'] }}: {{ $part['value'] }}"
                             style="width: {{ min(100, max(0, (int) round(max(0.0, $num($part['value'])) / $max * 100))) }}%; background: {{ $palette[0] }}"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endif

{{-- ⭐ কোন তারিখ থেকে কোন তারিখ (মালিক, ৫ অক্টোবর ২০২৬: *"kobe theke kobe porjonto eta likhbe"*) — ইংরেজি অঙ্কে ([[DateRange]]) --}}
@if ($panel->range && $showRange)
    <p data-chart-range class="flex items-center gap-1 px-4 pb-2 text-xs text-(--color-ink-muted)">
        <x-ui.icon name="calendar" :size="12" />{{ $panel->range }}
    </p>
@endif
</div>
