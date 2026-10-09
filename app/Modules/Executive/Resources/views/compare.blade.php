{{--
    মালিকের কেন্দ্র — তুলনা।

    ⓘ উপরে ছক: সারিতে কোম্পানি (বা একটা কোম্পানির শাখা), কলামে ছয়টা সংখ্যা — প্রতিটায় এখন, তখন, বদল।
    ⓘ নিচে ধারা: একটা সংখ্যা, দিন/সপ্তাহ/মাস/ত্রৈমাসিক/বছর ধরে ([[Trend]])।
    ⓘ ঘরে চাপলে: কোম্পানির সারি → ঐ কোম্পানির "শাখা পাশাপাশি"; শাখার সারি → ঐ শাখার মাসিক বিক্রি।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Executive\Services\Comparison;
    use App\Modules\Executive\Services\Figures;
    use App\Modules\Executive\Support\Go;

    $show = fn (?string $v) => match (true) {
        $v === null => '—',
        $v === Figures::HIDDEN => $v,
        default => Money::format($v, 0),
    };

    $points = array_map(fn (array $b, int $i) => ['label' => $b['label'], 'first' => $b['value'], 'second' => $lastYear[$i]['value'] ?? '0'], $trend, array_keys($trend));
    $series = $points === [] ? null : new \App\Core\Engines\Dashboard\Series(
        label: __('executive::compare.trend_of', ['figure' => __('executive::figure.'.$figure)]),
        points: $points,
        firstLabel: __('executive::figure.'.$figure),
        secondLabel: __('executive::compare.last_year'),
        chart: 'bars',
        range: \App\Core\Engines\Dashboard\DateRange::label($trend[0]['from'], $trend[count($trend) - 1]['to']),
    );
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('executive::compare.title') }}</x-slot:title>

    @include('executive::partials.open-form')

    <div data-executive-compare class="flex flex-col gap-3">
        <div class="flex items-center justify-between gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4"
             style="height: 56px">
            <h1 class="truncate text-lg font-bold text-(--color-ink)">{{ __('executive::compare.title') }}</h1>

            <form method="GET" action="{{ route('executive.compare') }}" class="flex items-center gap-2">
                <label class="sr-only" for="cmp-mode">{{ __('executive::compare.mode') }}</label>
                <select id="cmp-mode" name="mode" class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    @foreach ([Comparison::COMPANIES, Comparison::BRANCHES] as $mode)
                        <option value="{{ $mode }}" @selected($result['mode'] === $mode)>{{ __('executive::compare.mode_'.$mode) }}</option>
                    @endforeach
                </select>

                <label class="sr-only" for="cmp-company">{{ __('executive::today.company') }}</label>
                <select id="cmp-company" name="company" class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    @if ($result['mode'] === Comparison::COMPANIES)
                        <option value="">{{ __('executive::today.all_companies') }}</option>
                    @endif
                    @foreach ($result['companies'] as $company)
                        <option value="{{ $company['id'] }}" @selected($result['company'] === $company['id'])>{{ $company['name'] }}</option>
                    @endforeach
                </select>

                <label class="sr-only" for="cmp-against">{{ __('executive::compare.against') }}</label>
                <select id="cmp-against" name="against" class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    @foreach (Comparison::AGAINST as $against)
                        <option value="{{ $against }}" @selected($result['against'] === $against)>{{ __('executive::compare.against_'.$against) }}</option>
                    @endforeach
                </select>

                <input type="hidden" name="grain" value="{{ $grain }}">
                <input type="hidden" name="figure" value="{{ $figure }}">
                <x-ui.button type="submit">{{ __('executive::today.show') }}</x-ui.button>
            </form>
        </div>

        <section data-compare-grid class="flex min-h-0 flex-col overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)"
                 style="max-height: 470px">
            <div class="flex items-center justify-between gap-2 border-b border-(--color-border) px-4 py-2 text-2xs text-(--color-ink-muted)">
                <span>{{ __('executive::compare.now') }}: {{ \App\Core\Engines\Dashboard\DateRange::label($result['now']['from'], $result['now']['to']) }}</span>
                <span>{{ __('executive::compare.was') }}: {{ \App\Core\Engines\Dashboard\DateRange::label($result['was']['from'], $result['was']['to']) }}</span>
            </div>
            <div class="min-h-0 flex-1 overflow-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-(--color-surface-card)">
                        <tr class="text-left text-2xs text-(--color-ink-muted)">
                            <th class="px-3 py-2 font-semibold">{{ __('executive::today.col_place') }}</th>
                            @foreach (Figures::COMPARED as $key)
                                <th class="px-3 py-2 text-right font-semibold">{{ __('executive::figure.'.$key) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($result['rows'] as $row)
                            @php
                                [$route, $params] = $row['branch_id'] === null
                                    ? ['accounts.report.show', ['slug' => 'branches']]
                                    : ['sales.report.show', ['slug' => 'monthly']];
                            @endphp
                            <tr data-compare-row="{{ $row['id'] }}" class="border-t border-(--color-border)">
                                <td class="px-3 py-1.5 font-semibold text-(--color-ink)">{{ $row['name'] }}</td>
                                @foreach (Figures::COMPARED as $key)
                                    @php $change = $row['change'][$key]; @endphp
                                    <td class="tabular px-3 py-1.5 text-right" data-cell="{{ $key }}">
                                        @if ($row['now'][$key] === Figures::HIDDEN)
                                            <span class="text-(--color-ink-muted)" title="{{ __('executive::today.hidden') }}">{{ Figures::HIDDEN }}</span>
                                        @else
                                            <button type="submit" form="executive-open" name="go" class="tabular hover:underline"
                                                    value="{{ Go::to($row['company_id'], $row['branch_id'], $route, $params) }}">{{ $show($row['now'][$key]) }}</button>
                                            <span class="block text-2xs text-(--color-ink-muted)">{{ __('executive::compare.was') }} {{ $show($row['was'][$key]) }}</span>
                                            @if ($change !== null)
                                                <span @class(['block text-2xs', 'text-(--color-badge-success-ink)' => bccomp($change, '0', 2) >= 0, 'text-(--color-badge-danger-ink)' => bccomp($change, '0', 2) < 0])>
                                                    {{ bccomp($change, '0', 2) >= 0 ? '▲' : '▼' }} {{ ltrim($change, '-') }}%
                                                </span>
                                            @endif
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count(Figures::COMPARED) + 1 }}" class="px-4 py-3 text-(--color-ink-muted)">{{ __('executive::today.no_company') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="border-t border-(--color-border) px-4 py-1.5 text-2xs text-(--color-ink-muted)">{{ __('executive::compare.receivable_note') }}</p>
        </section>

        <section data-compare-trend class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) pb-2">
            <form method="GET" action="{{ route('executive.compare') }}" class="flex items-center gap-2 border-b border-(--color-border) px-4 py-2">
                <input type="hidden" name="mode" value="{{ $result['mode'] }}">
                <input type="hidden" name="company" value="{{ $result['company'] }}">
                <input type="hidden" name="against" value="{{ $result['against'] }}">
                <h2 class="min-w-0 flex-1 text-sm font-semibold text-(--color-ink)">{{ $series?->label }}</h2>

                <label class="sr-only" for="cmp-figure">{{ __('executive::compare.figure') }}</label>
                <select id="cmp-figure" name="figure" class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    @foreach (Figures::COMPARED as $key)
                        @if (Figures::definition($key)['period'])
                            <option value="{{ $key }}" @selected($figure === $key)>{{ __('executive::figure.'.$key) }}</option>
                        @endif
                    @endforeach
                </select>

                <label class="sr-only" for="cmp-grain">{{ __('executive::compare.grain') }}</label>
                <select id="cmp-grain" name="grain" class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    @foreach (\App\Core\Engines\Report\Trend::GRAINS as $g)
                        <option value="{{ $g }}" @selected($grain === $g)>{{ __('executive::compare.grain_'.$g) }}</option>
                    @endforeach
                </select>
                <x-ui.button type="submit">{{ __('executive::today.show') }}</x-ui.button>
            </form>

            @if ($series !== null)
                <x-dashboard.chart :panel="$series" wide />
            @else
                <p class="px-4 py-3 text-2xs text-(--color-ink-muted)">{{ __('executive::today.hidden') }}</p>
            @endif
        </section>
    </div>
</x-layouts.app>
