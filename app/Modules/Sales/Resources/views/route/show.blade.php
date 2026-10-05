{{--
    একটা রুট — তার ডিলার, সাপ্তাহিক ছক, মাসের লক্ষ্য (NEXUS §২৭)।

    ⓘ উপরের যোগফল আর নিচের ডিলারদের সারি একই সংজ্ঞা থেকে
    ([[RouteMetrics]]) — দুইটা যোগ করলে মেলে, আর পরীক্ষা সেটাই দাবি করে।
    ⛔ ছকের সারি মোছার বোতাম নেই, ইচ্ছাকৃত: হাতবদলের ইতিহাস থাকে।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Sales\Models\RouteVisit;
    use Illuminate\Support\HtmlString;

    $canManage = auth()->user()?->can('sales.route.manage') ?? false;
    $money = fn (string $v) => Money::format($v);

    $customerColumns = [
        ['key' => 'code', 'label' => __('sales::route.code'), 'width' => '7rem', 'render' => fn ($c) => $c->code],
        ['key' => 'name', 'label' => __('sales::route.customers'),
            'render' => fn ($c) => new HtmlString('<a href="'.e(route('customer.show', $c)).'"'
                .' class="text-(--color-brand-500) underline-offset-2 hover:underline">'.e($c->name()).'</a>')],
        ['key' => 'opening', 'label' => __('sales::route.opening'), 'numeric' => true,
            'render' => fn ($c) => $money($figures[$c->id]['opening'])],
        ['key' => 'sales', 'label' => __('sales::route.sales'), 'numeric' => true,
            'render' => fn ($c) => $money($figures[$c->id]['sales'])],
        ['key' => 'returns', 'label' => __('sales::route.returns'), 'numeric' => true,
            'render' => fn ($c) => $money($figures[$c->id]['returns'])],
        ['key' => 'collections', 'label' => __('sales::route.collections'), 'numeric' => true,
            'render' => fn ($c) => $money($figures[$c->id]['collections'])],
        ['key' => 'other', 'label' => __('sales::route.other'), 'numeric' => true,
            'render' => fn ($c) => $money($figures[$c->id]['other'])],
        ['key' => 'outstanding', 'label' => __('sales::route.outstanding'), 'numeric' => true,
            'render' => fn ($c) => $money($figures[$c->id]['outstanding'])],
    ];

    $state = function (RouteVisit $v) use ($today): string {
        if ($v->effective_from->gt($today)) {
            return __('sales::route.upcoming');
        }

        return $v->isActiveOn($today) ? __('sales::route.active') : __('sales::route.ended');
    };

    $scheduleColumns = [
        ['key' => 'weekday', 'label' => __('sales::route.weekday'), 'width' => '8rem',
            'render' => fn ($v) => __('sales::route.weekdays.'.$v->weekday)],
        ['key' => 'who', 'label' => __('sales::route.who'), 'render' => fn ($v) => (string) $v->user?->name],
        ['key' => 'from', 'label' => __('sales::route.from'), 'width' => '8rem',
            'render' => fn ($v) => $v->effective_from->toDateString()],
        ['key' => 'to', 'label' => __('sales::route.to'), 'width' => '8rem',
            'render' => fn ($v) => $v->effective_to?->toDateString() ?? __('sales::route.ongoing')],
        ['key' => 'status', 'label' => __('sales::route.status'), 'width' => '7rem', 'render' => $state],
    ];

    if ($canManage) {
        $scheduleColumns[] = ['key' => 'end', 'label' => __('sales::route.end'), 'width' => '16rem',
            'render' => fn ($v) => view('sales::route.partials.end', ['visit' => $v, 'today' => $today])];
    }

    /* শনিবার থেকে — এদেশে সপ্তাহ শনিবারে শুরু */
    $weekOrder = array_flip(RouteVisit::WEEK);
    $scheduleRows = $schedule->sortBy(fn ($v) => [$v->effective_to === null ? 0 : 1, $weekOrder[$v->weekday] ?? 9])->values();

    $tiles = [
        'opening' => $totals['opening'],
        'sales' => $totals['sales'],
        'returns' => $totals['returns'],
        'collections' => $totals['collections'],
        'other' => $totals['other'],
        'outstanding' => $totals['outstanding'],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $route->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$route->code.' — '.$route->name()" :subtitle="$route->path()">
            <x-slot:actions>
                <form method="GET" class="flex items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('sales::route.month') }}</span>
                        <input type="month" name="month" value="{{ $month->format('Y-m') }}"
                               class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2">
                    </label>
                    <x-ui.button type="submit" tone="secondary">{{ __('core.action.apply') }}</x-ui.button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            {{ $errors->first() }}
        </div>
    @endif

    <p class="mb-2 text-xs text-(--color-ink-muted)">{{ __('sales::route.period', ['from' => $from, 'to' => $to]) }}</p>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($tiles as $key => $value)
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-2">
                <p class="text-2xs text-(--color-ink-muted)">{{ __('sales::route.'.$key) }}</p>
                <p class="tabular text-lg font-semibold">{{ $money($value) }}</p>
            </div>
        @endforeach

        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-2">
            <p class="text-2xs text-(--color-ink-muted)">{{ __('sales::route.target') }} · {{ __('sales::route.achieved') }}</p>
            <p class="tabular text-lg font-semibold">
                {{ $target['target'] === null ? '—' : $money($target['target']) }}
                · {{ $money($target['achieved']) }}
            </p>
            <p class="text-sm">@include('sales::target.partials.percent', ['row' => $target])</p>
        </div>
    </div>

    <p class="mb-4 text-xs text-(--color-ink-muted)">{{ __('sales::route.ledger_note') }}</p>

    {{-- ── ডিলার ─────────────────────────────────────────────────────── --}}
    <section class="mb-6">
        <h2 class="mb-3 font-semibold">{{ __('sales::route.customers') }} ({{ $totals['customers'] }})</h2>

        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <x-ui.table :rows="$customers" :columns="$customerColumns" :empty="__('sales::route.no_customers')" />
        </div>

        <x-ui.pager :rows="$customers" />
        <x-ui.list-totals :rows="$customers" />
    </section>

    {{-- ── সাপ্তাহিক ছক ─────────────────────────────────────────────── --}}
    <section class="mb-6">
        <h2 class="mb-3 font-semibold">{{ __('sales::route.schedule') }}</h2>
        <p class="mb-2 text-xs text-(--color-ink-muted)">{{ __('sales::route.schedule_note') }}</p>

        <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <x-ui.table :rows="$scheduleRows" :columns="$scheduleColumns" :empty="__('sales::route.no_schedule')" />
        </div>

        @if ($canManage)
            @include('sales::route.partials.assign')
        @endif
    </section>

    {{-- ── মাসের লক্ষ্য ──────────────────────────────────────────────── --}}
    @if ($canManage)
        <section class="mb-6">
            <h2 class="mb-3 font-semibold">{{ __('sales::route.target') }} — {{ $month->format('Y-m') }}</h2>

            <form method="POST" action="{{ route('sales.route.target', $route) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <input type="hidden" name="month" value="{{ $month->toDateString() }}">
                <input type="number" step="0.01" min="0" inputmode="decimal" name="amount"
                       value="{{ $target['target'] !== null ? rtrim(rtrim($target['target'], '0'), '.') : '' }}"
                       class="num h-(--spacing-field-compact) w-32 rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-card) px-2 text-end">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </form>

            <p class="mt-2 text-xs text-(--color-ink-muted)">{{ __('sales::route.empty_means_none') }}</p>
            <p class="mt-1 text-xs text-(--color-ink-muted)">{{ __('sales::route.target_note') }}</p>
        </section>
    @endif
</x-layouts.app>
