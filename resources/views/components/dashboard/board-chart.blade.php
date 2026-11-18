@props(['chart'])

{{--
    ⭐ হোমের গোটা-ব্যবসার বোর্ডের একটা গ্রাফ — মালিকের অনুমোদিত নমুনা, ১৯ নভেম্বর ২০২৬ (claude.ai artifact AAjAfjZ8TnCfnjtt3Kthjz v11)।

    চার রকম: `line` (১২ মাসের ধারা), `pair` (দুই সারির পাশাপাশি বার), `donut` (ভাগ, পাশে টাকা আর %), `bars` (শীর্ষ ১০, আড়াআড়ি)।
    ⓘ রং আসে বোর্ড থেকে ([[HomeBoard]]) — প্রতিটা গ্রাফ নিজের রঙে; পাতায় কোনো রং লেখা নেই।
    ⓘ খালি হলে "এই সময়ে কিছু নেই।" — ফাঁকা অক্ষ আঁকা হয় না। ছাঁকনি না খাটলে নিচে ছোট করে বলা (`note`)।
    ⓘ মাপ resources/css/home-board.css-এ (সব রূপে একই — পাতা রূপ চেনে না)।
--}}
@php
    $key = (string) $chart['key'];
    $type = (string) $chart['type'];
    $href = (string) ($chart['href'] ?? '');
@endphp

<section class="hb-card hb-chart" data-board-chart="{{ $key }}" data-boxed>
    <div class="hb-head">
        <h2>{{ $chart['title'] }}</h2>
        @if ($href !== '')
            <a href="{{ $href }}" class="hb-more">{{ __('home.details') }} →</a>
        @endif
    </div>
    <div class="hb-sub">
        {{ $chart['sub'] ?? '' }}
        @if ($type === 'pair')
            @foreach ($chart['series'] as $series)
                <span class="hb-key"><i style="background: {{ $series['colour'] }}"></i>{{ $series['name'] }}</span>
            @endforeach
        @endif
    </div>

    @if ($chart['empty'] ?? false)
        <div class="hb-empty" data-board-empty>{{ __('home.board.empty') }}</div>
    @elseif ($type === 'line')
        @php
            $values = $chart['series'][0]['values'];
            $peak = max(max($values), 1.0);
            $step = count($values) > 1 ? 275 / (count($values) - 1) : 0;
            $points = [];
            foreach ($values as $i => $v) {
                $points[] = [round(20 + $i * $step, 1), round(120 - max(0.0, $v) / $peak * 95, 1)];
            }
            $line = implode(' ', array_map(fn ($p) => $p[0].','.$p[1], $points));
            [$lastX, $lastY] = end($points);
            $colour = $chart['series'][0]['colour'];
        @endphp
        <svg viewBox="0 0 310 150" role="img" aria-label="{{ $chart['title'] }}">
            <defs>
                <linearGradient id="hb-grad-{{ $key }}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="{{ $colour }}" stop-opacity=".2" />
                    <stop offset="1" stop-color="{{ $colour }}" stop-opacity="0" />
                </linearGradient>
            </defs>
            @foreach ([30, 60, 90, 120] as $grid)
                <line x1="14" x2="305" y1="{{ $grid }}" y2="{{ $grid }}" class="hb-grid" />
            @endforeach
            <polygon points="20,120 {{ $line }} {{ $lastX }},120" fill="url(#hb-grad-{{ $key }})" />
            <polyline points="{{ $line }}" fill="none" stroke="{{ $colour }}" stroke-width="2.4" />
            @foreach ($points as $i => $p)
                <circle cx="{{ $p[0] }}" cy="{{ $p[1] }}" r="2.6" fill="{{ $colour }}"><title>{{ $chart['labels'][$i] ?? '' }}</title></circle>
            @endforeach
            <text x="{{ max(30, $lastX - 26) }}" y="{{ max(12, $lastY - 8) }}" class="hb-val">{{ $chart['last'] ?? '' }}</text>
            @foreach ($chart['labels'] as $i => $label)
                @if ($i % 2 === 1)
                    <text x="{{ $points[$i][0] }}" y="140" text-anchor="middle" class="hb-axis">{{ $label }}</text>
                @endif
            @endforeach
        </svg>
    @elseif ($type === 'pair')
        @php
            $all = array_merge(...array_map(fn ($s) => $s['values'], $chart['series']));
            $peak = max(max($all), 1.0);
            $groups = count($chart['labels']);
            $width = $groups > 0 ? 291 / $groups : 0;
        @endphp
        <svg viewBox="0 0 310 150" role="img" aria-label="{{ $chart['title'] }}">
            @foreach ([30, 60, 90, 120] as $grid)
                <line x1="14" x2="305" y1="{{ $grid }}" y2="{{ $grid }}" class="hb-grid" />
            @endforeach
            @for ($i = 0; $i < $groups; $i++)
                @foreach ($chart['series'] as $s => $series)
                    @php $h = round(max(0.0, $series['values'][$i] ?? 0) / $peak * 95, 1); @endphp
                    <rect x="{{ round(14 + $i * $width + 3 + $s * 10, 1) }}" y="{{ 120 - $h }}" width="9" height="{{ $h }}" rx="2"
                          fill="{{ $series['colour'] }}"><title>{{ $chart['labels'][$i] }} · {{ $series['name'] }}</title></rect>
                @endforeach
                @if ($i % 2 === 1)
                    <text x="{{ round(14 + $i * $width + $width / 2, 1) }}" y="140" text-anchor="middle" class="hb-axis">{{ $chart['labels'][$i] }}</text>
                @endif
            @endfor
        </svg>
    @elseif ($type === 'donut')
        @php
            $ring = 2 * M_PI * 50;
            $sum = array_sum(array_map(fn ($p) => $p['value'], $chart['parts'])) ?: 1.0;
            $drawn = 0.0;
        @endphp
        <div class="hb-donut">
            <svg viewBox="0 0 140 140" role="img" aria-label="{{ $chart['title'] }}: {{ $chart['centre'] }}">
                <circle cx="70" cy="70" r="50" fill="none" stroke-width="18" class="hb-ring" />
                @foreach ($chart['parts'] as $part)
                    @php $dash = $part['value'] / $sum * $ring; @endphp
                    @if ($dash > 0)
                        <circle cx="70" cy="70" r="50" fill="none" stroke="{{ $part['colour'] }}" stroke-width="18"
                                stroke-dasharray="{{ round($dash, 2) }} {{ round($ring - $dash, 2) }}" stroke-dashoffset="{{ round(-$drawn, 2) }}"
                                transform="rotate(-90 70 70)"><title>{{ $part['label'] }} · {{ $part['text'] }}</title></circle>
                    @endif
                    @php $drawn += $dash; @endphp
                @endforeach
                <text x="70" y="68" text-anchor="middle" class="hb-centre">{{ $chart['centre'] }}</text>
                <text x="70" y="85" text-anchor="middle" class="hb-axis">{{ $chart['centre_label'] }}</text>
            </svg>
            <div class="hb-legend">
                @foreach ($chart['parts'] as $part)
                    <div><i style="background: {{ $part['colour'] }}"></i><span>{{ $part['label'] }}</span><b class="num">{{ $part['text'] }}</b><small class="num">{{ $part['share'] }}</small></div>
                @endforeach
            </div>
        </div>
    @elseif ($type === 'bars')
        @php $peak = max(array_map(fn ($r) => $r['value'], $chart['rows']) ?: [1.0]) ?: 1.0; @endphp
        <ol class="hb-bars">
            @foreach ($chart['rows'] as $row)
                <li>
                    @if (filled($row['href'] ?? null))
                        <a href="{{ $row['href'] }}" class="hb-bar-name" title="{{ $row['label'] }}">{{ $row['label'] }}</a>
                    @else
                        <span class="hb-bar-name" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                    @endif
                    <span class="hb-bar"><i style="width: {{ round(max(0.0, $row['value']) / $peak * 100, 1) }}%; background: {{ $chart['colour'] }}"></i></span>
                    <b class="num">{{ $row['text'] }}</b>
                </li>
            @endforeach
        </ol>
    @endif

    @if (filled($chart['note'] ?? null))
        <p class="hb-note" data-board-note>{{ $chart['note'] }}</p>
    @endif
</section>
