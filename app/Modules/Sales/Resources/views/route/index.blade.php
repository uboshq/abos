{{--
    রুটের খাতা — সব রুট, এক মাসের অঙ্কসহ (NEXUS §২৭)।

    ⓘ অঙ্ক কেবল এই পাতার রুটগুলোর, এক কোয়েরিতে ([[RouteMetrics::forRoutes()]])।
    ⭐ রুটের নামটাই লিংক — নিয়ম ১: সংখ্যা দেখে পরের প্রশ্নটা সবসময় "কোন ডিলার?"
--}}
@php
    use App\Core\Support\Money;
    use Illuminate\Support\HtmlString;

    $link = fn ($r) => new HtmlString('<a href="'.e(route('sales.route.show', [$r, 'month' => $month->format('Y-m')])).'"'
        .' class="text-(--color-brand-500) underline-offset-2 hover:underline">'.e($r->name()).'</a>');

    $money = fn (string $v) => Money::format($v);

    $columns = [
        ['key' => 'code', 'label' => __('sales::route.code'), 'width' => '7rem', 'render' => fn ($r) => $r->code],
        ['key' => 'route', 'label' => __('sales::route.route'), 'render' => $link],
        ['key' => 'point', 'label' => __('master_data::level.point'), 'render' => fn ($r) => $r->parent?->name() ?? '—'],
        ['key' => 'customers', 'label' => __('sales::route.customers'), 'numeric' => true, 'width' => '6rem',
            'render' => fn ($r) => (string) $figures[$r->id]['customers']],
        ['key' => 'salespeople', 'label' => __('sales::route.salespeople'),
            'render' => fn ($r) => $people[$r->id] === [] ? '—' : implode(', ', $people[$r->id])],
        ['key' => 'sales', 'label' => __('sales::route.sales'), 'numeric' => true,
            'render' => fn ($r) => $money($figures[$r->id]['sales'])],
        ['key' => 'collections', 'label' => __('sales::route.collections'), 'numeric' => true,
            'render' => fn ($r) => $money($figures[$r->id]['collections'])],
        ['key' => 'outstanding', 'label' => __('sales::route.outstanding'), 'numeric' => true,
            'render' => fn ($r) => $money($figures[$r->id]['outstanding'])],
        ['key' => 'target', 'label' => __('sales::route.target'), 'numeric' => true,
            'render' => fn ($r) => $targets[$r->id]['target'] === null ? '—' : $money($targets[$r->id]['target'])],
        ['key' => 'percent', 'label' => __('sales::route.percent'), 'numeric' => true, 'width' => '7rem',
            'render' => fn ($r) => view('sales::target.partials.percent', ['row' => $targets[$r->id]])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::route.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('sales::route.title')" :subtitle="__('sales::route.subtitle')">
            <x-slot:actions>
                <form method="GET" class="flex items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-(--color-ink-muted)">{{ __('sales::route.search') }}</span>
                        <input type="search" name="q" value="{{ $q }}"
                               class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2">
                    </label>
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

    @if ($unrouted > 0)
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-sm
                    text-(--color-badge-warning-ink)">
            {{ __('sales::route.unrouted', ['count' => $unrouted]) }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <x-ui.table :rows="$routes" :columns="$columns" :empty="__('sales::route.no_routes')" />
    </div>

    <p class="mt-2 text-xs text-(--color-ink-muted)">{{ __('sales::route.ledger_note') }}</p>
    <p class="mt-1 text-xs text-(--color-ink-muted)">{{ __('sales::route.target_note') }}</p>

    <x-ui.pager :rows="$routes" />
    <x-ui.list-totals :rows="$routes" />
</x-layouts.app>
