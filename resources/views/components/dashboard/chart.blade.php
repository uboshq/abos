{{-- ⭐ একটা চার্ট, ধরন অনুযায়ী আঁকা — মালিক, ৪ অক্টোবর ২০২৬: *"sob ekoi rokom graph diyecho keno vino rokomer int standred
     world femus graph daw"* আর *"sob chart ei velue dio"*।

     ⓘ সময়ের ধারা ([[Series]]): রেখা (line), ভরা রেখা (area), পাশাপাশি স্তম্ভ (bars)।
     ⓘ ভাগ ([[Breakdown]]): ডোনাট, আড়াআড়ি দণ্ড (hbars), খাড়া স্তম্ভ (columns), ফানেল (funnel)।
     ⓘ প্রতিটা দাগে মান লেখা — রং একা কিছু বলে না; পুরো অঙ্ক title-এ। জাভাস্ক্রিপ্ট নেই, SVG সার্ভারেই আঁকা।
     ⓘ রং চার্টের নিজের টোকেন `--color-chart-১…৬` (tokens.css §১৪.৮) — ক্রম স্থির, ঘোরে না; রং-দৃষ্টির ঘাটতিতেও পাশাপাশি
       আলাদা (ΔE ≥ ৮.৬, পাঁচ যাচাই পাশ)। ⚠️ মডিউলের রং নয়: কোম্পানির রূপ (look) সেগুলো এক রঙে মিলিয়ে দেয়, তখন সব টুকরো
       এক হয়ে যেত (৪ অক্টোবর ২০২৬ ছবিতে ধরা)। বান্ডেল টোকেনটা না জানলেও চলে — পাশে একই রঙের বিকল্প।
     ⓘ শূন্যের মাথায় "0" লেখা হয় না — শূন্য বিন্দু মাটিতেই বসে; ডোনাটের পাশের তালিকায় শূন্যও থাকে।
     ⓘ হোম আর মডিউলের নতুন পাতা এই একটাই ছক ডাকে — দুই জায়গায় দুই রকম আঁকা হয় না। --}}
@props(['panel', 'compact' => false, 'showRange' => true])

@php
    $palette = ['var(--color-chart-1, #2563eb)', 'var(--color-chart-2, #d97706)', 'var(--color-chart-3, #7c3aed)',
        'var(--color-chart-4, #059669)', 'var(--color-chart-5, #dc2626)', 'var(--color-chart-6, #0891b2)'];
    $zero = fn ($v) => (float) str_replace(',', '', (string) $v) == 0.0;
    $short = fn ($v) => \App\Core\Engines\Dashboard\Series::short($v);
    $isSeries = $panel instanceof \App\Core\Engines\Dashboard\Series;
    $kind = $isSeries ? $panel->chart : $panel->kind();
@endphp

<div data-chart="{{ $kind }}" class="min-w-0">
@if ($isSeries && $kind === 'bars')
    @php $peak = $panel->peak(); @endphp
    <div class="flex items-end gap-2 px-4 pt-4" style="height: {{ $compact ? '7.5rem' : 'var(--spacing-chart)' }}">
        @foreach ($panel->points as $point)
            <div class="flex h-full flex-1 items-end justify-center gap-0.5">
                @foreach ([['first', $palette[0], $panel->firstLabel], ['second', $palette[1], $panel->secondLabel]] as [$side, $fill, $name])
                    {{-- ⭐ মান দণ্ডের ভেতরে, খাড়া করে (মালিক, ৫ অক্টোবর ২০২৬: *"amount gulo color pipe er vitore dile full buzazabe"*);
                         দণ্ড বেঁটে হলে (৩৫%-এর কম) ভেতরে ধরে না, তখন মাথার উপরে --}}
                    @php
                        $barH = max(2, (int) round((float) $point[$side] / $peak * 85));
                        $inside = $barH >= 35;
                    @endphp
                    <div class="flex h-full w-1/2 flex-col items-center justify-end" style="position: relative">
                        <span data-bar-value @class(['whitespace-nowrap leading-none tabular-nums', 'mb-0.5 text-(--color-ink-muted)' => ! $inside])
                              @if ($inside) style="position: absolute; bottom: 4px; left: 50%; transform: translateX(-50%) rotate(180deg); writing-mode: vertical-rl; color: var(--color-ink-inverse); font-weight: 700; font-size: 0.62rem; z-index: 1; text-shadow: 0 0 2px rgb(0 0 0 / 0.35)"
                              @else style="font-size: 0.6rem" @endif>{{ $zero($point[$side]) ? '' : ($point[$side.'Note'] ?? $short($point[$side])) }}</span>
                        <div class="w-full rounded-t"
                             style="height:{{ $barH }}%; background: {{ $fill }}"
                             title="{{ $point['label'] }} · {{ $name }}: {{ $point[$side.'Title'] ?? $point[$side] }}"></div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
    <div class="flex gap-2 px-4 pt-1">
        @foreach ($panel->points as $point)
            <div class="min-w-0 flex-1 truncate text-center text-2xs text-(--color-ink-muted)">{{ $point['label'] }}</div>
        @endforeach
    </div>
@elseif ($isSeries)
    {{-- রেখা বা ভরা রেখা — দুই ধারা, প্রতিটা বিন্দুতে মান (প্রথমটা উপরে, দ্বিতীয়টা নিচে, যাতে না ঢাকে) --}}
    @php
        // ⓘ ভিতরের চওড়া কার্ডের চওড়ার কাছাকাছি — নইলে SVG চওড়ায় টেনে লম্বাও হয় (চওড়া কার্ডে ৬০০px পর্যন্ত, ৫ অক্টোবর ২০২৬ ছবিতে ধরা)
        $w = $compact ? 380 : 760; $h = $compact ? 130 : 200; $left = 16; $right = 16; $top = 18; $bottom = 24;
        $peak = $panel->peak();
        $n = count($panel->points);
        $x = fn (int $i) => $n === 1 ? $w / 2 : $left + $i * ($w - $left - $right) / ($n - 1);
        $y = fn ($v) => $top + ($h - $top - $bottom) * (1 - (float) $v / $peak);
        $line = fn (string $side) => implode(' ', array_map(fn ($i) => round($x($i), 1).','.round($y($panel->points[$i][$side]), 1), range(0, $n - 1)));
    @endphp
    <svg viewBox="0 0 {{ $w }} {{ $h }}" class="block w-full px-2 pt-2" role="img" aria-label="{{ $panel->label }}">
        <line x1="{{ $left }}" x2="{{ $w - $right }}" y1="{{ $h - $bottom }}" y2="{{ $h - $bottom }}" style="stroke: var(--color-border)" stroke-width="1" />
        @foreach ([['second', $palette[1], $panel->secondLabel, 13], ['first', $palette[0], $panel->firstLabel, -7]] as [$side, $stroke, $name, $dy])
            @if ($kind === 'area')
                <polygon points="{{ round($x(0), 1) }},{{ $h - $bottom }} {{ $line($side) }} {{ round($x($n - 1), 1) }},{{ $h - $bottom }}"
                         style="fill: {{ $stroke }}; fill-opacity: 0.14" />
            @endif
            <polyline points="{{ $line($side) }}" fill="none" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" style="stroke: {{ $stroke }}" />
            @foreach ($panel->points as $i => $point)
                <circle cx="{{ round($x($i), 1) }}" cy="{{ round($y($point[$side]), 1) }}" r="3" stroke-width="1.5"
                        style="fill: {{ $stroke }}; stroke: var(--color-surface-card)">
                    <title>{{ $point['label'] }} · {{ $name }}: {{ $point[$side.'Title'] ?? $point[$side] }}</title>
                </circle>
                <text data-bar-value x="{{ round($x($i), 1) }}" y="{{ round(min($h - $bottom - 2, max(10, $y($point[$side]) + $dy)), 1) }}"
                      text-anchor="middle" font-size="9.5" font-weight="600" class="tabular-nums" style="fill: {{ $stroke }}">{{ $zero($point[$side]) ? '' : ($point[$side.'Note'] ?? $short($point[$side])) }}</text>
            @endforeach
        @endforeach
        @foreach ($panel->points as $i => $point)
            <text x="{{ round($x($i), 1) }}" y="{{ $h - 8 }}" text-anchor="middle" font-size="9.5" style="fill: var(--color-ink-muted)">{{ $point['label'] }}</text>
        @endforeach
    </svg>
@endif

@if ($isSeries)
    <div @class(['flex flex-wrap items-center gap-4 px-4 text-2xs text-(--color-ink-muted)', 'py-1' => $compact, 'py-2' => ! $compact])>
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
        {{-- ডোনাট — টুকরোর মাঝে সরু ফাঁক; মাঝখানে মোট; পাশে নাম, মান আর ভাগ --}}
        @php $r = 42; $circ = 2 * M_PI * $r; $at = 0.0; @endphp
        <div class="flex items-center gap-3 px-4 py-3">
            <svg viewBox="0 0 120 120" class="shrink-0" style="width: {{ $compact ? '5.5rem' : '8rem' }}" role="img" aria-label="{{ $panel->label }}">
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
                <text x="60" y="58" text-anchor="middle" font-size="15" font-weight="700" class="tabular-nums" style="fill: var(--color-ink)">{{ $short($sum) }}</text>
                <text x="60" y="73" text-anchor="middle" font-size="9" style="fill: var(--color-ink-muted)">{{ __('core.dashboard.chart_total') }}</text>
            </svg>
            <div class="min-w-0 flex-1 space-y-1.5">
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
                            <span data-bar-value class="font-semibold tabular-nums" style="text-shadow: 0 0 2px rgb(0 0 0 / 0.45)">{{ $part['value'] }}</span>
                        </div>
                    </div>
                    <span class="w-10 shrink-0 text-end tabular-nums text-(--color-ink-muted)">{{ $prev !== null && $prev > 0 ? (int) round($v / $prev * 100).'%' : '' }}</span>
                </div>
            @endforeach
        </div>
    @elseif ($kind === 'columns')
        {{-- খাড়া স্তম্ভ — এক ধারা, এক রং; মাথায় মান, নিচে নাম --}}
        <div class="flex items-end gap-2 px-4 pt-4" style="height: {{ $compact ? '7.5rem' : 'var(--spacing-chart)' }}">
            @foreach ($parts as $part)
                <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end">
                    <span data-bar-value class="mb-0.5 whitespace-nowrap leading-none tabular-nums text-(--color-ink-muted)" style="font-size: 0.65rem">{{ $zero($part['value']) ? '' : $part['value'] }}</span>
                    <div class="rounded-t" title="{{ $part['label'] }}: {{ $part['value'] }}"
                         style="width: 70%; height: {{ max(2, (int) round(max(0.0, $num($part['value'])) / $max * 85)) }}%; background: {{ $palette[0] }}"></div>
                </div>
            @endforeach
        </div>
        <div class="flex gap-2 px-4 pt-1 pb-2">
            @foreach ($parts as $part)
                <div class="min-w-0 flex-1 truncate text-center text-2xs text-(--color-ink-muted)">{{ $part['label'] }}</div>
            @endforeach
        </div>
    @else
        {{-- আড়াআড়ি দণ্ড — নাম আর মান এক লাইনে, নিচে দণ্ড --}}
        <div class="space-y-3 px-4 py-3">
            @foreach ($compact ? array_slice($parts, 0, 5) : $parts as $part)
                <div>
                    <div class="mb-1 flex items-baseline justify-between gap-3 text-xs">
                        <span class="min-w-0 truncate text-(--color-ink-body)">{{ $part['label'] }}</span>
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

{{-- ⭐ কোন তারিখ থেকে কোন তারিখ (মালিক, ৫ অক্টোবর ২০২৬: *"kobe theke kobe porjonto eta likhbe"*) --}}
@if ($panel->range && $showRange)
    <p data-chart-range class="flex items-center gap-1 px-4 pb-2 text-2xs text-(--color-ink-muted)">
        <x-ui.icon name="calendar" :size="12" />{{ $panel->range }}
    </p>
@endif
</div>
