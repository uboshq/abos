{{--
    ⭐ হিসাবের ড্যাশবোর্ড, সারি 7 — অনুমোদনে সময় বনাম টাকা (বাবল), খাতার স্বাস্থ্য (রাডার), সবচেয়ে বেশি নড়াচড়া, সাম্প্রতিক কাজ
    (মালিকের চূড়ান্ত নমুনা TErMgXrtgVBkuyuoCZq8NS)। ⓘ বাবল: x = টাকা (সবচেয়ে বড় অঙ্কের মাপে), y = ঘণ্টা (8 ঘণ্টা y 88-তে), বৃত্ত টাকার মাপে।
--}}
@php
    $card = 'background: var(--color-dash-ffffff); border-radius: 1.125rem; padding: 1.125rem 1.25rem; box-shadow: 0 1px 3px var(--color-dash-17-24-39-a06); display: flex; flex-direction: column; color: var(--color-dash-111827)';
    $short = fn (string $v) => \App\Modules\Accounts\Dashboard\AccountsBoard::short($v);

    $maxAmount = max(1.0, ...array_map(fn ($b) => (float) $b['amount'], $bubbles ?: [['amount' => '1']]));
    $maxHours = max(16.0, ...array_map(fn ($b) => $b['hours'], $bubbles ?: [['hours' => 0]]));
    $dots = array_map(fn ($b) => [
        'x' => round(48 + (float) $b['amount'] / $maxAmount * 290, 1),
        'y' => round(200 - min($b['hours'], $maxHours) / $maxHours * 190, 1),
        'r' => round(4 + 10 * sqrt((float) $b['amount'] / $maxAmount), 1),
        'c' => $b['hours'] > 8 ? 'var(--color-dash-ef4444)' : ($b['approved'] ? 'var(--color-dash-d946ef)' : 'var(--color-dash-94a3b8)'),
        'h' => $b['hours'], 'a' => $b['amount'],
    ], $bubbles);
    $limitY = round(200 - 8 / $maxHours * 190, 1);
    $avg = $bubbles === [] ? 0 : round(array_sum(array_column($bubbles, 'hours')) / count($bubbles), 1);
    $late = count(array_filter($bubbles, fn ($b) => $b['hours'] > 8));

    $pt = function (int $i, float $f): array { $a = -M_PI / 2 + $i * 2 * M_PI / 6; return [round(160 + cos($a) * 66 * $f, 1), round(120 + sin($a) * 66 * $f, 1)]; };
    $poly = fn (array $fs) => implode(' ', array_map(fn ($f, $i) => implode(',', $pt($i, $f)), $fs, array_keys($fs)));
    $toF = fn (array $vals) => array_map(fn ($v) => max(0.04, ($v ?? 0) / 100), $vals);
    $labels = [];
    foreach (__('accounts::board.r7.axes') as $i => $t) {
        [$x, $y] = $pt($i, 1.16);
        $labels[] = ['t' => $t.($health['now'][$i] === null ? '' : ' '.$health['now'][$i]), 'x' => $x, 'y' => $y + ($i === 0 ? -2 : ($i === 3 ? 10 : 4)), 'a' => abs($x - 160) < 5 ? 'middle' : ($x > 160 ? 'start' : 'end')];
    }

    $topPaint = ['var(--color-dash-0ea5e9)', 'var(--color-dash-6366f1)', 'var(--color-dash-10b981)', 'var(--color-dash-f59e0b)', 'var(--color-dash-ec4899)', 'var(--color-dash-8b5cf6)', 'var(--color-dash-64748b)'];
    $topMax = max(1.0, ...array_map(fn ($a) => (float) $a['value'], $top ?: [['value' => '1']]));
    $workPaint = ['created' => ['var(--color-dash-10b981)', 'var(--color-dash-d1fae5)'], 'updated' => ['var(--color-dash-6366f1)', 'var(--color-dash-e0e7ff)'], 'deleted' => ['var(--color-dash-ef4444)', 'var(--color-dash-fee2e2)']];
@endphp
<div data-acc-board-review style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem; width: 100%">
    <section data-acc-bubbles style="{{ $card }} gap: 0.375rem; border-top: 0.25rem solid var(--color-dash-d946ef)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r7.bubbles') }}</h2>
        @if ($dots === [])
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.r7.bubbles_none') }}</span>
        @else
            <svg role="img" aria-label="{{ __('accounts::board.r7.bubbles_label') }}" viewBox="0 0 360 230" style="width: 100%; height: 15rem">
                <line x1="40" y1="200" x2="350" y2="200" stroke="var(--color-dash-e5e7f0)"></line><line x1="40" y1="10" x2="40" y2="200" stroke="var(--color-dash-e5e7f0)"></line>
                <line x1="40" y1="{{ $limitY }}" x2="350" y2="{{ $limitY }}" stroke="var(--color-dash-fca5a5)" stroke-dasharray="4 4"></line>
                <text x="346" y="{{ $limitY - 6 }}" text-anchor="end" font-size="10" fill="var(--color-dash-dc2626)">{{ __('accounts::board.r7.limit') }}</text>
                @foreach ($dots as $d)<circle cx="{{ $d['x'] }}" cy="{{ $d['y'] }}" r="{{ $d['r'] }}" fill="{{ $d['c'] }}" fill-opacity="0.7" stroke="var(--color-dash-ffffff)" stroke-width="1.5"><title>{{ $d['h'] }}h · {{ $short($d['a']) }}</title></circle>@endforeach
                <text x="195" y="222" text-anchor="middle" font-size="11" fill="var(--color-dash-6b7280)">{{ __('accounts::board.r7.money_axis') }}</text>
                <text x="14" y="105" font-size="11" fill="var(--color-dash-6b7280)" transform="rotate(-90 14 105)">{{ __('accounts::board.r7.hours_axis') }}</text>
            </svg>
            <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)">{{ __('accounts::board.r7.bubbles_note', ['avg' => $avg, 'n' => $late]) }}</span>
        @endif
    </section>

    <section data-acc-health style="{{ $card }} gap: 0.375rem; border-top: 0.25rem solid var(--color-dash-22c55e)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r7.health') }}</h2>
            @if ($health['score'] !== null)<span style="font-size: 0.8125rem; font-weight: 800; color: var(--color-dash-16a34a)">{{ $health['score'] }} / 100</span>@endif
        </div>
        <svg role="img" aria-label="{{ __('accounts::board.r7.health_label') }}" viewBox="0 0 320 250" style="width: 100%; height: 15.625rem">
            @foreach ([1 / 3, 2 / 3, 1] as $fr)<polygon points="{{ $poly(array_fill(0, 6, $fr)) }}" fill="none" stroke="var(--color-dash-e5e7f0)"></polygon>@endforeach
            <polygon points="{{ $poly($toF($health['last'])) }}" fill="var(--color-dash-148-163-184-a18)" stroke="var(--color-dash-94a3b8)" stroke-width="2" stroke-dasharray="4 3"></polygon>
            <polygon points="{{ $poly($toF($health['now'])) }}" fill="var(--color-dash-34-197-94-a25)" stroke="var(--color-dash-16a34a)" stroke-width="3"></polygon>
            @foreach ($labels as $l)<text x="{{ $l['x'] }}" y="{{ $l['y'] }}" text-anchor="{{ $l['a'] }}" font-size="10" fill="var(--color-dash-374151)">{{ $l['t'] }}</text>@endforeach
        </svg>
        <span style="font-size: 0.75rem; color: var(--color-dash-4b5563)"><b style="color: var(--color-dash-16a34a)">{{ __('accounts::board.r7.green') }}</b> {{ __('accounts::board.r7.this_month') }} · <b style="color: var(--color-dash-94a3b8)">{{ __('accounts::board.r7.grey') }}</b> {{ __('accounts::board.r7.last_month') }}</span>
    </section>

    <section data-acc-top style="{{ $card }} gap: 0.5625rem; border-top: 0.25rem solid var(--color-dash-0ea5e9)">
        <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r7.top') }}</h2>
        @forelse ($top as $i => $a)
            <div data-top-account style="display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) auto; gap: 0.625rem; align-items: center; font-size: 0.8125rem">
                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap" title="{{ $a['name'] }}">{{ $a['name'] }}</span>
                <span style="display: block; height: 0.875rem; border-radius: 999px; background: var(--color-dash-f1f2f7)"><span style="display: block; height: 0.875rem; width: {{ round((float) $a['value'] / $topMax * 100) }}%; background: {{ $topPaint[$i % count($topPaint)] }}; border-radius: 999px"></span></span>
                <b style="text-align: right">{{ $short($a['value']) }}</b>
            </div>
        @empty
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.r7.top_none') }}</span>
        @endforelse
    </section>

    <section data-acc-recent style="{{ $card }} gap: 0.25rem; border-top: 0.25rem solid var(--color-dash-64748b)">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem">
            <h2 style="margin: 0; font-size: 1rem; font-weight: 700">{{ __('accounts::board.r7.recent') }}</h2>
        </div>
        @forelse ($recent as $w)
            @php [$c, $soft] = $workPaint[$w['action']] ?? ['var(--color-dash-64748b)', 'var(--color-dash-f1f5f9)']; @endphp
            <div style="display: flex; gap: 0.75rem; font-size: 0.8125rem; padding: 0.4375rem 0">
                <span style="width: 0.75rem; height: 0.75rem; border-radius: 999px; background: {{ $c }}; margin-top: 0.25rem; flex-shrink: 0; box-shadow: 0 0 0 4px {{ $soft }}"></span>
                <span style="color: var(--color-dash-6b7280); width: 2.625rem; flex-shrink: 0">{{ $w['at'] }}</span>
                <span style="min-width: 0"><b>{{ $w['who'] }}</b> {{ __('accounts::board.r7.actions')[$w['action']] ?? $w['action'] }} {{ $w['doc'] }}</span>
            </div>
        @empty
            <span style="font-size: 0.8125rem; color: var(--color-dash-6b7280)">{{ __('accounts::board.r7.recent_none') }}</span>
        @endforelse
    </section>
</div>
