{{--
    ⭐ গ্রাফের বোর্ড — মডিউলের ড্যাশবোর্ডের খোলার পর্দা (মালিক, ২০ নভেম্বর ২০২৬; নমুনা 5gynumii আর RoPEayT7 "ok")।
    এক মাপের কার্ড, সারিতে তিনটা (আটটা হলে চারটা); প্রতিটায় শিরোনাম, ডানে বড় অঙ্ক, নিচে এক লাইনের কথা, গ্রাফ, দরকার হলে রঙের নাম।
    ⓘ গ্রাফ সার্ভারেই SVG ([[App\Core\Dashboard\Viz\Svg]]); আঁকার কিছু না থাকলে "এই সময়ে কিছু নেই" — ফাঁকা অক্ষ নয়।
    ⓘ মাপ আর রং resources/css/viz-board.css-এ; সব রূপে একই (পাতা রূপ চেনে না)।
--}}
@php
    $vizCards = array_values($vizCards ?? []);
    $cols = count($vizCards) % 4 === 0 && count($vizCards) % 3 !== 0 ? 4 : 3;
@endphp

@if ($vizCards !== [])
    <div class="viz-board" data-viz-board style="--viz-cols: {{ $cols }}">
        @foreach ($vizCards as $card)
            @php($svg = \App\Core\Dashboard\Viz\Svg::render($card['kind'], $card['data']))
            <section class="viz-card" data-viz-card="{{ $card['kind'] }}" style="--viz-edge: {{ $card['colour'] }}">
                <header class="viz-head">
                    <h2>{{ $card['title'] }}</h2>
                    @if ($card['value'] !== '')
                        <b class="num">{{ $card['value'] }}</b>
                    @endif
                </header>
                @if ($card['sub'] !== '')
                    <p class="viz-sub">{{ $card['sub'] }}</p>
                @endif

                @if ($svg === null)
                    <div class="viz-empty" data-viz-empty>{{ __('core.viz.empty') }}</div>
                @else
                    <div class="viz-plot">{!! $svg !!}</div>
                @endif

                <footer class="viz-foot">
                    @if ($card['legend'] !== [] && $svg !== null)
                        <span class="viz-legend">
                            @foreach ($card['legend'] as [$name, $colour])
                                <span><i style="background: {{ $colour }}"></i>{{ $name }}</span>
                            @endforeach
                        </span>
                    @endif
                    @if ($card['href'] !== '')
                        <a href="{{ $card['href'] }}" class="viz-more">{{ __('core.viz.details') }} →</a>
                    @endif
                </footer>
            </section>
        @endforeach
    </div>
@endif
