{{--
    ⭐ Dynamic রূপের হোম — "রোল সেন্টার" (মালিক, ১৯ নভেম্বর ২০২৬: নমুনা "খ", "ok final")।

    উপরে বাঁয়ে বড় হালকা অক্ষরে একটা খবর (পালা করে কয়েকটা), ডানে "কাজ"-এর লিংক; তার নিচে আজকের জরুরি কাজের ফিতা;
    তারপর "কার্যক্রম" — বড় হালকা সংখ্যা আর নিচে রঙের দাগ; শেষে টিয়া টালি — অপেক্ষার কাজগুলোর গোনা।

    ⛔ একটাও নতুন সংখ্যা নয়, একটাও বাদ নয় (মালিকের নিয়ম: নতুন নকশা পুরনো সংখ্যা ফেলে না)। সবই হোমের নিজের হিসাব —
    এই ফাইলটা [[workspace.home]]-এর ভিতর থেকে `@include` হয়, তাই একই `$cards` (মূল সূচক + বাজারে বকেয়া + মজুদের মূল্য,
    একই চাবিতে), একই `$position` (হাতে ও ব্যাংকে মোট, তার ভাগ আর আজকের আসা-যাওয়া), একই `$todayWork` / `$waiting` /
    `$quiet` (করণীয়)। ঐ পাতার যে অংশগুলো এখানে নতুন করে আঁকা হলো, সেগুলো এই রূপে CSS-এ লুকায় ([[dynamic-look.css]]);
    চার্ট আর "সদ্য যা হয়েছে" নিচে থেকেই যায়।
--}}
@php
    $dyn = \App\Core\Support\DynamicLook::class;
    $period = $period ?? 'today';

    // ── খবর — টাকার মোট আর প্রথম চারটা সূচক, পালা করে (BC-র "Insight from last month") ──
    $slides = [];
    if ($position) {
        $slides[] = ['label' => $position->label, 'value' => $position->value, 'href' => $position->href];
    }
    foreach (array_slice($cards, 0, 5 - count($slides)) as $w) {
        $slides[] = ['label' => $w->label, 'value' => $w->value, 'href' => $w->href];
    }

    // ── কাজ — শুরু করার (＋) আর যাওয়ার (›) পাতা, মেনুর সারি থেকে: মেনুতে যা নেই তা এখানেও নেই ──
    $starts = $dyn::pick($menu, $dyn::START);
    $goes = $dyn::pick($menu, $dyn::GO);

    // ── রঙের দাগ — ভালো সবুজ, সতর্ক হলুদ, অপেক্ষায় থাকা সতর্ক লাল, টাকা টিয়া, বাকি ধূসর ──
    $barOf = function ($w) use ($pending): string {
        return match (true) {
            $w->tone === 'warn' && $pending($w) => 'dyn-bar-bad',
            $w->tone === 'warn' => 'dyn-bar-warn',
            $w->tone === 'good' => 'dyn-bar-good',
            $w->tone === 'money' => 'dyn-bar-money',
            default => 'dyn-bar-none',
        };
    };
    $sizeOf = fn (string $value): string => match (true) {
        mb_strlen($value) > 16 => 'dyn-v-s',
        mb_strlen($value) > 12 => 'dyn-v-m',
        default => '',
    };

    $tilesOn = $layout->shows('exceptions');
    $busy = $tilesOn ? $todayWork->concat($waiting)->values() : collect();
    $calm = $tilesOn ? $quiet->values() : collect();
    $banner = $tilesOn ? $todayWork->first() : null;
@endphp

<section data-dyn-role class="dyn-rc" aria-label="{{ __('core.menu.dashboard') }}">
    @if ($slides !== [] || $starts !== [] || $goes !== [])
        <div class="dyn-rc-top">
            <div class="dyn-rc-insight" data-dyn-insight>
                @if ($slides !== [])
                    <small>{{ __('dynamic.insight', ['period' => $titles[$period] ?? $period]) }}</small>
                    @foreach ($slides as $i => $slide)
                        <input type="radio" name="dyn-insight" id="dyn-insight-{{ $i }}" class="dyn-rc-radio"
                               @if ($i === 0) checked @endif aria-label="{{ __('dynamic.slide', ['number' => $i + 1]) }}">
                    @endforeach
                    <div class="dyn-rc-slides">
                        @foreach ($slides as $i => $slide)
                            <a href="{{ $slide['href'] }}" class="dyn-headline" data-slide="{{ $i }}">
                                {{ $slide['label'] }} <b class="tabular">{{ $slide['value'] }}</b>
                            </a>
                        @endforeach
                    </div>
                    @if (count($slides) > 1)
                        <div class="dyn-rc-dots">
                            @foreach ($slides as $i => $slide)
                                <label for="dyn-insight-{{ $i }}" data-dot="{{ $i }}"><span class="sr-only">{{ __('dynamic.slide', ['number' => $i + 1]) }}</span></label>
                            @endforeach
                        </div>
                    @endif
                    <style @nonce>
                        @foreach ($slides as $i => $slide)
                        :root[data-ui='dynamic'] .dyn-rc-insight:has(#dyn-insight-{{ $i }}:checked) [data-slide="{{ $i }}"] { display: block; }
                        :root[data-ui='dynamic'] .dyn-rc-insight:has(#dyn-insight-{{ $i }}:checked) [data-dot="{{ $i }}"] { background: currentColor; }
                        @endforeach
                    </style>
                @endif
            </div>

            <div class="dyn-rc-actions" data-dyn-actions>
                <h2>{{ __('dynamic.actions') }}</h2>
                <div class="dyn-rc-actions-grid">
                    @foreach ($starts as $item)
                        <a href="{{ $item['url'] }}"><span aria-hidden="true" class="dyn-plus">+</span>{{ $item['label'] }}</a>
                    @endforeach
                    <button type="button" x-data @click="$dispatch('open-command-center')"><span aria-hidden="true" class="dyn-go">›</span>{{ __('dynamic.find') }}</button>
                    @foreach ($goes as $item)
                        <a href="{{ $item['url'] }}"><span aria-hidden="true" class="dyn-go">›</span>{{ $item['label'] }}</a>
                    @endforeach
                    @if (\Illuminate\Support\Facades\Route::has('reports.center'))
                        <a href="{{ route('reports.center') }}"><span aria-hidden="true" class="dyn-go">›</span>{{ __('dynamic.reports') }}</a>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if ($banner)
        <div class="dyn-rc-banner" data-dyn-banner>
            <p><b>{{ __('dynamic.today') }}</b> {{ $banner->label }} — <span class="tabular">{{ $banner->value }}</span>
                @if ($banner->hint) <span class="dyn-rc-banner-hint">· {{ $banner->hint }}</span> @endif</p>
            <a href="{{ $banner->href }}">{{ __('dynamic.open') }}</a>
        </div>
    @endif

    @if ($cards !== [])
        <h2 class="dyn-rc-sec">{{ __('dynamic.activities') }}
            <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 6l4.5 4.5L12.5 6" fill="none" stroke="currentColor" stroke-width="1.2"/></svg></h2>
        <div class="dyn-cues" data-dyn-cues>
            @foreach ($cards as $widget)
                @php
                    $spark = array_map('floatval', $widget->spark);
                    $peak = $spark === [] ? 0.0 : max($spark);
                    $floor = $spark === [] ? 0.0 : min($spark);
                @endphp
                <div class="dyn-cue" data-kpi @if ($widget->definition) title="{{ $widget->definition }}" @endif>
                    <small>{{ $widget->label }}</small>
                    <a href="{{ $widget->href }}" class="dyn-cue-v tabular {{ $sizeOf($widget->value) }}">{{ $widget->value }}</a>
                    <div class="dyn-cue-bar">
                        <i class="{{ $barOf($widget) }}"></i>
                        @if (count($spark) > 1 && $peak > $floor)
                            @php
                                $step = 100 / (count($spark) - 1);
                                $points = [];
                                foreach ($spark as $si => $sv) {
                                    $points[] = round($si * $step, 2).','.round(18 - (($sv - $floor) / ($peak - $floor)) * 16, 2);
                                }
                            @endphp
                            <svg viewBox="0 0 100 20" preserveAspectRatio="none" aria-hidden="true" class="dyn-cue-spark">
                                <polyline points="{{ implode(' ', $points) }}" fill="none" stroke-width="1.4" vector-effect="non-scaling-stroke" />
                            </svg>
                        @endif
                    </div>
                    @if ($widget->delta || $widget->hint)
                        <span class="dyn-cue-sub">
                            @if ($widget->delta)
                                <span @class(['dyn-up' => ! str_starts_with($widget->delta, '-'), 'dyn-down' => str_starts_with($widget->delta, '-')])>{{ str_starts_with($widget->delta, '-') ? '▼' : '▲' }} {{ ltrim($widget->delta, '+-') }}</span>
                            @endif
                            {{ $widget->hint }}
                        </span>
                    @endif
                    <a href="{{ $widget->href }}" class="dyn-more"><span aria-hidden="true">›</span>{{ __('dynamic.see_more') }}</a>
                </div>
            @endforeach
        </div>
    @endif

    @if ($position)
        <h2 class="dyn-rc-sec">{{ $position->label }}
            <span class="dyn-rc-sec-note">{{ __('home.money_as_booked') }}</span></h2>
        <div class="dyn-cues" data-dyn-money>
            <div class="dyn-cue" @if ($position->definition) title="{{ $position->definition }}" @endif>
                <small>{{ $position->label }}</small>
                <a href="{{ $position->href }}" class="dyn-cue-v tabular {{ $sizeOf((string) $position->value) }}">{{ $position->value }}</a>
                <div class="dyn-cue-bar"><i class="dyn-bar-money"></i></div>
                <a href="{{ $position->href }}" class="dyn-more"><span aria-hidden="true">›</span>{{ __('dynamic.see_more') }}</a>
            </div>
            @foreach ($position->parts as $partLabel => $partValue)
                <div class="dyn-cue">
                    <small>{{ $partLabel }}</small>
                    <span class="dyn-cue-v tabular {{ $sizeOf((string) $partValue) }}">{{ $partValue }}</span>
                    <div class="dyn-cue-bar"><i style="background: var(--color-chart-{{ $loop->index % 8 + 1 }})"></i></div>
                </div>
            @endforeach
            @foreach ($position->tiles as $heroTile)
                <div class="dyn-cue" data-money-tile="{{ $heroTile['tone'] }}">
                    <small>{{ $heroTile['label'] }}</small>
                    <span class="dyn-cue-v tabular {{ $sizeOf((string) $heroTile['value']) }}">{{ $heroTile['sign'] !== '' ? $heroTile['sign'].' ' : '' }}{{ $heroTile['value'] }}</span>
                    <div class="dyn-cue-bar"><i class="{{ $heroTile['tone'] === 'in' ? 'dyn-bar-good' : ($heroTile['tone'] === 'out' ? 'dyn-bar-bad' : 'dyn-bar-none') }}"></i></div>
                    @if (filled($heroTile['hint'] ?? null))
                        <span class="dyn-cue-sub">{{ $heroTile['hint'] }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($busy->isNotEmpty() || $calm->isNotEmpty())
        <div class="dyn-tiles" data-dyn-tiles>
            @if ($busy->isNotEmpty())
                <div class="dyn-tile-group">
                    <h3>{{ __('dynamic.waiting') }}</h3>
                    <div class="dyn-tile-row">
                        @foreach ($busy as $widget)
                            <a href="{{ $widget->href }}" class="dyn-tile" data-level="{{ $levelOf($widget) }}"
                               @if ($widget->hint) title="{{ $widget->hint }}" @endif>
                                <span class="dyn-tile-l">{{ $widget->label }}</span>
                                <span class="dyn-tile-n tabular">{{ $widget->value }}</span>
                                <span class="dyn-tile-a" aria-hidden="true">›</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($calm->isNotEmpty())
                <div class="dyn-tile-group">
                    <h3>{{ __('dynamic.all_clear') }}</h3>
                    <div class="dyn-tile-row">
                        @foreach ($calm as $widget)
                            <a href="{{ $widget->href }}" class="dyn-tile dyn-tile-grey">
                                <span class="dyn-tile-l">{{ $widget->label }}</span>
                                <span class="dyn-tile-n tabular">{{ $widget->value }}</span>
                                <span class="dyn-tile-a" aria-hidden="true">›</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif
</section>
