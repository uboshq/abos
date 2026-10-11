{{--
    মালিকের কেন্দ্র — আজ (দলের নকশা, ৬ অক্টোবর ২০২৬)।

    ⭐ এক পর্দা, ১৯২০×১০৮০, পাতা সরে না: উপরের দণ্ড ৫৬px · আটটা সংখ্যা ৯০px · ছক + সতর্কতা ৪২০px · ধারা ২৬০px।
    ⓘ ছকে দশটার বেশি সারি হলে ছকটা নিজের ভিতরে সরে, পাতা নয়।

    ⓘ প্রতিটা সংখ্যা চাপা যায়: ঘর → ঐ কোম্পানি ও শাখার মডিউল-ড্যাশবোর্ড → রিপোর্ট → কাগজ।
    অন্য কোম্পানির পাতা খুলতে আগে কোম্পানি বদলাতে হয়, তাই চাপটা একটা ফর্ম (`executive.open`) —
    একটাই ফর্ম, প্রতিটা ঘর তার বোতাম, আর বোতামের মানে কোথায় যাবে ([[OpenController]])।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Executive\Services\Figures;

    $go = \App\Modules\Executive\Support\Go::to(...);

    $show = function (?string $value, string $key): string {
        if ($value === null) {
            return '—';
        }

        if ($value === Figures::HIDDEN) {
            return Figures::HIDDEN;
        }

        return Figures::definition($key)['money'] ? Money::format($value, 0) : (string) (int) $value;
    };

    $single = count($board['companies']) === 1 ? $board['companies'][0] : null;
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('executive::today.title') }}</x-slot:title>

    @include('executive::partials.open-form')
    @include('executive::partials.fit')

    <div data-executive-today class="flex flex-col gap-3">

        {{-- ── উপরের দণ্ড — ৫৬px ───────────────────────────────────────── --}}
        <div data-topbar data-fit class="flex flex-wrap items-center justify-between gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4"
             style="height: var(--exec-bar)">
            <div class="min-w-0">
                <h1 class="truncate text-lg font-bold text-(--color-ink)">{{ __('executive::today.title') }}</h1>
            </div>

            <form method="GET" action="{{ route('executive.today') }}" class="flex flex-wrap items-center gap-2">
                <label class="sr-only" for="ex-company">{{ __('executive::today.company') }}</label>
                <select id="ex-company" name="company"
                        class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('executive::today.all_companies') }}</option>
                    @foreach ($board['choices'] as $choice)
                        <option value="{{ $choice['id'] }}" @selected($filters['company'] === $choice['id'])>{{ $choice['name'] }}</option>
                    @endforeach
                </select>

                <label class="sr-only" for="ex-branch">{{ __('executive::today.branch') }}</label>
                <select id="ex-branch" name="branch"
                        class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    <option value="">{{ __('executive::today.all_branches') }}</option>
                    @foreach ($board['choices'] as $choice)
                        @if ($filters['company'] === $choice['id'])
                            @foreach ($choice['branches'] as $branch)
                                <option value="{{ $branch['id'] }}" @selected($filters['branch'] === $branch['id'])>{{ $branch['name'] }}</option>
                            @endforeach
                        @endif
                    @endforeach
                </select>

                <label class="sr-only" for="ex-period">{{ __('executive::today.period') }}</label>
                <select id="ex-period" name="period"
                        class="h-9 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                    @foreach (Figures::PERIODS as $period)
                        <option value="{{ $period }}" @selected($filters['period'] === $period)>{{ __('executive::today.period_'.$period) }}</option>
                    @endforeach
                </select>

                <x-ui.button type="submit">{{ __('executive::today.show') }}</x-ui.button>
            </form>

            <form method="POST" action="{{ route('executive.refresh') }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <span class="hidden text-2xs text-(--color-ink-muted) xl:inline">{{ __('executive::today.cached_note') }}</span>
                <x-ui.button type="submit">
                    <x-ui.icon name="refresh" :size="14" /> {{ __('executive::today.refresh') }}
                </x-ui.button>
            </form>
        </div>

        @if (session('saved'))
            <div role="status" class="rounded-(--radius-card) bg-(--color-badge-success-bg) px-4 py-2 text-sm text-(--color-badge-success-ink)">
                {{ session('saved') }}
            </div>
        @endif

        @if ($board['companies'] === [])
            <x-ui.empty-state :message="__('executive::today.no_company')" />
        @else

        {{-- ── আটটা সংখ্যা — ৯০px ──────────────────────────────────────── --}}
        <div data-headline class="grid gap-3">
            @foreach (Figures::KEYS as $key)
                @php $module = Figures::dashboardOf($key); @endphp
                @if ($single !== null && $board['total'][$key] !== Figures::HIDDEN)
                    <button type="submit" form="executive-open" name="go" data-figure="{{ $key }}"
                            value="{{ $go($single['id'], $single['only'], 'module.dashboard', ['module' => $module]) }}"
                            title="{{ __('executive::figure.'.$key.'_hint') }}"
                            class="flex min-w-0 flex-col items-start justify-center gap-1 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-3 text-left shadow-(--shadow-card) hover:bg-(--color-surface-hover)"
                            style="height: var(--exec-figure)">
                @else
                    <a href="#executive-grid" data-figure="{{ $key }}" title="{{ __('executive::figure.'.$key.'_hint') }}"
                       class="flex min-w-0 flex-col items-start justify-center gap-1 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-3 shadow-(--shadow-card) hover:bg-(--color-surface-hover)"
                       style="height: var(--exec-figure)">
                @endif
                        <span class="truncate text-sm font-medium text-(--color-ink-muted)">{{ __('executive::figure.'.$key) }}</span>
                        <span class="tabular truncate text-2xl font-bold leading-tight text-(--color-ink)">{{ $show($board['total'][$key], $key) }}</span>
                        @if ($board['partial'][$key])
                            <span class="truncate text-2xs text-(--color-badge-warning-ink)">{{ __('executive::today.partial') }}</span>
                        @endif
                @if ($single !== null && $board['total'][$key] !== Figures::HIDDEN)
                    </button>
                @else
                    </a>
                @endif
            @endforeach
        </div>

        {{-- ── ছক + সতর্কতা — ৪২০px ───────────────────────────────────── --}}
        <div data-fit class="grid gap-3 xl:grid-cols-4" style="height: var(--exec-board)">
            <section id="executive-grid" data-grid
                     class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) xl:col-span-3">
                <div class="flex items-center justify-between gap-2 border-b border-(--color-border) px-4 py-2">
                    <h2 class="text-sm font-semibold text-(--color-ink)">{{ __('executive::today.grid_title') }}</h2>
                    @if ($board['header_branch'] !== null)
                        <span class="text-2xs text-(--color-ink-muted)">{{ __('executive::today.header_branch') }}</span>
                    @endif
                </div>

                {{-- ⓘ দশটার বেশি সারি হলে ছকটা নিজের ভিতরে সরে — পাতা নয় --}}
                <div class="min-h-0 flex-1 overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-(--color-surface-card)">
                            <tr class="text-left text-2xs text-(--color-ink-muted)">
                                <th class="px-3 py-2 font-semibold">{{ __('executive::today.col_place') }}</th>
                                @foreach (Figures::KEYS as $key)
                                    <th class="px-3 py-2 text-right font-semibold" title="{{ __('executive::figure.'.$key.'_hint') }}">{{ __('executive::figure.'.$key) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($board['companies'] as $company)
                                @foreach ($company['rows'] as $row)
                                    <tr data-row="branch" data-company="{{ $company['id'] }}" data-branch="{{ $row['id'] }}" class="border-t border-(--color-border)">
                                        <td class="px-3 py-1.5">
                                            <span class="text-(--color-ink)">{{ $row['name'] }}</span>
                                            @if (count($board['companies']) > 1)
                                                <span class="text-2xs text-(--color-ink-muted)">· {{ $company['name'] }}</span>
                                            @endif
                                        </td>
                                        @foreach (Figures::KEYS as $key)
                                            @include('executive::partials.cell', ['value' => $row['values'][$key], 'key' => $key, 'company' => $company['id'], 'branch' => $row['id']])
                                        @endforeach
                                    </tr>
                                @endforeach

                                @if ($company['unsplit'] !== null)
                                    <tr data-row="unsplit" data-company="{{ $company['id'] }}" class="border-t border-(--color-border)">
                                        <td class="px-3 py-1.5 text-(--color-ink-muted)" title="{{ __('executive::today.unsplit_hint') }}">
                                            {{ __('executive::today.unsplit') }}
                                            @if (count($board['companies']) > 1)
                                                <span class="text-2xs">· {{ $company['name'] }}</span>
                                            @endif
                                        </td>
                                        @foreach (Figures::KEYS as $key)
                                            <td class="tabular px-3 py-1.5 text-right text-(--color-ink-muted)" data-cell="{{ $key }}">{{ $show($company['unsplit'][$key], $key) }}</td>
                                        @endforeach
                                    </tr>
                                @endif

                                <tr data-row="company" data-company="{{ $company['id'] }}" class="border-t border-(--color-border) bg-(--color-surface-hover) font-semibold">
                                    <td class="px-3 py-1.5">{{ __('executive::today.company_total', ['company' => $company['name']]) }}</td>
                                    @foreach (Figures::KEYS as $key)
                                        @include('executive::partials.cell', ['value' => $company['values'][$key], 'key' => $key, 'company' => $company['id'], 'branch' => $company['only']])
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="sticky bottom-0 bg-(--color-surface-card)">
                            @if ($board['eliminated'] !== null)
                                <tr data-row="eliminated" class="border-t border-(--color-border) text-(--color-ink-muted)">
                                    <td class="px-3 py-1.5" title="{{ __('executive::today.eliminated_hint') }}">{{ __('executive::today.eliminated') }}</td>
                                    @foreach (Figures::KEYS as $key)
                                        <td class="tabular px-3 py-1.5 text-right" data-cell="{{ $key }}">
                                            {{ isset($board['eliminated'][$key]) ? '−'.Money::format($board['eliminated'][$key], 0) : '' }}
                                        </td>
                                    @endforeach
                                </tr>
                            @endif
                            <tr data-row="group" class="border-t-2 border-(--color-border) font-bold">
                                <td class="px-3 py-2">{{ __('executive::today.group_total') }}</td>
                                @foreach (Figures::KEYS as $key)
                                    <td class="tabular px-3 py-2 text-right" data-cell="{{ $key }}"
                                        @if ($board['partial'][$key]) title="{{ __('executive::today.partial') }}" @endif>
                                        {{ $show($board['total'][$key], $key) }}@if ($board['partial'][$key])*@endif
                                    </td>
                                @endforeach
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            {{-- ── ডানে: সতর্কতা আর সই ─────────────────────────────────── --}}
            <aside class="flex min-h-0 min-w-0 flex-col gap-3">
                <section data-alerts class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                    <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('executive::today.alerts_title') }}</h2>
                    <ul class="min-h-0 flex-1 overflow-auto text-sm">
                        @foreach ($alerts as $alert)
                            <li data-alert="{{ $alert['kind'] }}" class="border-t border-(--color-border) px-4 py-1.5">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-(--color-ink)">{{ __('executive::alert.'.$alert['kind']) }}</span>
                                    <span @class(['tabular font-semibold', 'text-(--color-badge-danger-ink)' => $alert['total'] > 0, 'text-(--color-ink-muted)' => $alert['total'] === 0])>{{ $alert['total'] }}</span>
                                </div>
                                @if ($alert['total'] > 0)
                                    <div class="flex flex-wrap gap-x-3 text-2xs">
                                        @foreach ($alert['companies'] as $part)
                                            @if (($part['count'] ?? 0) > 0)
                                                <button type="submit" form="executive-open" name="go"
                                                        value="{{ $go($part['id'], null, $part['route'], $part['params']) }}"
                                                        class="text-(--color-brand-700) hover:underline">{{ $part['name'] }}: {{ $part['count'] }}</button>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section data-waiting class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                    <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('executive::today.waiting_title') }}</h2>
                    <ul class="min-h-0 flex-1 overflow-auto text-sm">
                        @forelse ($waiting as $paper)
                            <li class="border-t border-(--color-border) px-4 py-1.5">
                                <button type="submit" form="executive-open" name="go"
                                        value="{{ $go($paper['company_id'], null, $paper['route'], $paper['params']) }}"
                                        class="flex w-full items-center justify-between gap-2 text-left hover:underline">
                                    <span class="min-w-0 truncate text-(--color-ink)">{{ $paper['label'] }}
                                        <span class="text-2xs text-(--color-ink-muted)">· {{ $paper['company_name'] }}</span></span>
                                    @if ($paper['amount'] !== null)
                                        <span class="tabular shrink-0">{{ Money::format($paper['amount'], 0) }}</span>
                                    @endif
                                </button>
                            </li>
                        @empty
                            <li class="px-4 py-2 text-2xs text-(--color-ink-muted)">{{ __('executive::today.nothing_waiting') }}</li>
                        @endforelse
                    </ul>
                </section>
            </aside>
        </div>

        {{-- ── ধারা আর সেরা পাঁচ — ২৬০px ───────────────────────────────── --}}
        <div data-fit class="grid gap-3 xl:grid-cols-4" style="height: var(--exec-trend)">
            <section data-trend class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) xl:col-span-2">
                @if ($trend !== null)
                    <h2 class="px-4 pt-2 text-sm font-semibold text-(--color-ink)">{{ $trend->label }}</h2>
                    <x-dashboard.chart :panel="$trend" :show-range="true" />
                @endif
            </section>

            @foreach ([['top_customers', $topCustomers, 'customer_name', 'total'], ['top_products', $topProducts, 'product_name', 'revenue']] as [$title, $list, $labelKey, $measure])
                <section data-top="{{ $title }}" class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
                    <h2 class="border-b border-(--color-border) px-4 py-2 text-sm font-semibold text-(--color-ink)">{{ __('executive::today.'.$title) }}</h2>
                    <ol class="min-h-0 flex-1 overflow-auto text-sm">
                        @forelse ($list['rows'] as $row)
                            @php
                                [$route, $params] = $title === 'top_customers'
                                    ? ['accounts.report.show', ['slug' => 'customer-ledger', 'customer_id' => $row['customer_id'] ?? null]]
                                    : ['inventory.product.show', ['product' => $row['product_id'] ?? null]];
                            @endphp
                            <li class="border-t border-(--color-border) px-4 py-1.5">
                                <button type="submit" form="executive-open" name="go"
                                        value="{{ $go($row['company_id'], null, $route, $params) }}"
                                        class="flex w-full items-center justify-between gap-2 text-left hover:underline">
                                    <span class="min-w-0 truncate">{{ $row[$labelKey] ?? '—' }}
                                        @if (count($board['companies']) > 1)
                                            <span class="text-2xs text-(--color-ink-muted)">· {{ $row['company_name'] }}</span>
                                        @endif
                                    </span>
                                    <span class="tabular shrink-0">{{ Money::format($row[$measure] ?? '0', 0) }}</span>
                                </button>
                            </li>
                        @empty
                            <li class="px-4 py-2 text-2xs text-(--color-ink-muted)">{{ __('executive::today.nobody_yet') }}</li>
                        @endforelse
                    </ol>
                    @if ($list['refused'] !== [])
                        <p class="px-4 pb-1 text-2xs text-(--color-ink-muted)">{{ __('executive::today.refused', ['companies' => implode(', ', $list['refused'])]) }}</p>
                    @endif
                </section>
            @endforeach
        </div>
        @endif
    </div>
</x-layouts.app>
