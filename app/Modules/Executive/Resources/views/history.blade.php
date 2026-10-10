{{--
    মালিকের কেন্দ্র — আগের দিনের হিসাব।

    ⓘ উপরে: বাছা দিনের রাতে লেখা আটটা সংখ্যা, প্রতিটা কোম্পানি ও শাখার। নিচে: একটা সংখ্যার গত ৯০ দিন,
    রাতের হিসাব থেকে। ⚠️ কোনো সংখ্যা আজকের খাতা থেকে নতুন করে গোনা নয় ([[Snapshots]])।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Executive\Services\Figures;

    $show = fn (?string $v, string $key) => match (true) {
        $v === null => '—',
        Figures::definition($key)['money'] => Money::format($v, 0),
        default => (string) (int) $v,
    };

    $points = [];
    foreach ($series as $day => $value) {
        $points[] = ['label' => \Illuminate\Support\Carbon::parse($day)->translatedFormat('j M'), 'first' => $value,
            'second' => $monthBefore[\Illuminate\Support\Carbon::parse($day)->subMonthNoOverflow()->toDateString()] ?? '0'];
    }
    $chart = $points === [] ? null : new \App\Core\Engines\Dashboard\Series(
        label: __('executive::history.trend', ['figure' => __('executive::figure.'.$figure)]),
        points: $points,
        firstLabel: __('executive::figure.'.$figure),
        secondLabel: __('executive::history.month_before'),
        chart: 'area',
        range: \App\Core\Engines\Dashboard\DateRange::label(array_key_first($series), array_key_last($series)),
    );
@endphp
<x-layouts.app :menu="$menu">
    @include('executive::partials.fit')
    <x-slot:title>{{ __('executive::history.title') }}</x-slot:title>

    <div data-executive-history class="flex flex-col gap-3">
        <div data-fit class="flex flex-wrap items-center justify-between gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4"
             style="height: var(--exec-bar)">
            <h1 class="truncate text-lg font-bold text-(--color-ink)">{{ __('executive::history.title') }}</h1>
            <form method="GET" action="{{ route('executive.history') }}" class="flex flex-wrap items-center gap-2">
                <label for="hist-date" class="text-sm text-(--color-ink-muted)">{{ __('executive::history.date') }}</label>
                <input id="hist-date" type="date" name="date" value="{{ $date }}" max="{{ now()->toDateString() }}"
                       class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                <label class="sr-only" for="hist-figure">{{ __('executive::compare.figure') }}</label>
                <select id="hist-figure" name="figure" class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    @foreach (Figures::KEYS as $key)
                        <option value="{{ $key }}" @selected($figure === $key)>{{ __('executive::figure.'.$key) }}</option>
                    @endforeach
                </select>
                <x-ui.button type="submit">{{ __('executive::today.show') }}</x-ui.button>
            </form>
        </div>

        <section data-history-grid class="flex min-h-0 flex-col overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)"
                 style="max-height: 420px">
            <p class="border-b border-(--color-border) px-4 py-2 text-2xs text-(--color-ink-muted)">
                {{ __('executive::history.as_kept', ['date' => \App\Core\Engines\Dashboard\DateRange::label($date, $date)]) }}
            </p>
            <div class="min-h-0 flex-1 overflow-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-(--color-surface-card)">
                        <tr class="text-left text-2xs text-(--color-ink-muted)">
                            <th class="px-3 py-2 font-semibold">{{ __('executive::today.col_place') }}</th>
                            @foreach (Figures::KEYS as $key)
                                <th class="px-3 py-2 text-right font-semibold">{{ __('executive::figure.'.$key) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr data-history-row="{{ $row['company_id'] }}-{{ $row['branch_id'] ?? 0 }}" @class(['border-t border-(--color-border)', 'bg-(--color-surface-hover) font-semibold' => $row['branch_id'] === null])>
                                <td class="px-3 py-1.5">{{ $row['name'] }}
                                    @if ($row['branch_id'] !== null)
                                        <span class="text-2xs text-(--color-ink-muted)">· {{ $row['company_name'] }}</span>
                                    @endif
                                </td>
                                @foreach (Figures::KEYS as $key)
                                    <td class="tabular px-3 py-1.5 text-right" data-cell="{{ $key }}">{{ $show($row['values'][$key], $key) }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count(Figures::KEYS) + 1 }}" class="px-4 py-3 text-(--color-ink-muted)">{{ __('executive::history.none_that_day') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-history-trend class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) pb-2">
            @if ($chart !== null)
                <h2 class="px-4 pt-2 text-sm font-semibold text-(--color-ink)">{{ $chart->label }}</h2>
                <x-dashboard.chart :panel="$chart" wide />
            @else
                <p class="px-4 py-3 text-2xs text-(--color-ink-muted)">{{ __('executive::history.none_that_day') }}</p>
            @endif
        </section>
    </div>
</x-layouts.app>
