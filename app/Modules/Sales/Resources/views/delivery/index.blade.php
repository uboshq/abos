{{--
    ডেলিভারি — তালিকা, ধাপ ধরে।

    দিনের মাঝামাঝি প্রশ্নটা একটাই: **কোন চালানের মাল এখনো পৌঁছায়নি**।
    তাই প্রথম ট্যাব "হাতে থাকা কাজ" (অপেক্ষায় থেকে রওনা পর্যন্ত), আর
    প্রতিটা ট্যাবের পাশে গোনা — খুলে দেখার আগেই জানা যায় কোথায় ভিড়।
--}}
@php
    use App\Modules\Sales\Services\DeliveryStage;

    $columns = [
        [
            'key' => 'document_no',
            'label' => __('sales::delivery.column.challan'),
            'width' => '12rem',
            'render' => fn ($s) => $s->challan
                ? view('sales::components.doc-link', ['document' => $s->challan, 'route' => 'sales.delivery.show'])
                : '-',
        ],
        [
            'key' => 'trx_date',
            'label' => __('sales::delivery.column.date'),
            'width' => '8rem',
            'render' => fn ($s) => \App\Core\Support\DateFormat::format($s->challan?->trx_date),
        ],
        [
            'key' => 'customer',
            'label' => __('sales::delivery.column.customer'),
            'render' => fn ($s) => $s->challan?->customer?->name() ?? '-',
        ],
        [
            'key' => 'stage',
            'label' => __('sales::delivery.column.stage'),
            'width' => '10rem',
            'render' => fn ($s) => view('sales::delivery.partials.badge', ['stage' => $s->stage]),
        ],
        [
            'key' => 'stage_at',
            'label' => __('sales::delivery.column.since'),
            'width' => '11rem',
            'render' => fn ($s) => \App\Core\Support\DateFormat::formatWithTime($s->stage_at),
        ],
        [
            'key' => 'total',
            'label' => __('sales::delivery.column.total'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn ($s) => \App\Core\Support\Money::format($s->challan?->total ?? '0'),
        ],
    ];

    // ⭐ সারির "পরের ধাপ" — কেবল ধাপ বদলানোর চাবিধারীর জন্য ([[delivery/partials/row-action]])
    if ($next !== []) {
        $columns[] = [
            'key' => 'next',
            'label' => __('sales::delivery.column.next'),
            'width' => '12rem',
            'render' => fn ($s) => isset($next[$s->id]) && $s->challan
                ? view('sales::delivery.partials.row-action', [
                    'challan' => $s->challan,
                    'choices' => $next[$s->id]['choices'],
                    'trip' => $next[$s->id]['trip'],
                    'vehicles' => $vehicles,
                ])
                : '',
        ];
    }
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::delivery.title') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- ধাপের ট্যাব — খোঁজ ট্যাব বদলালেও থেকে যায় --}}
    <nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('sales::delivery.title') }}">
        @foreach ($tabs as $each)
            <a href="{{ route('sales.delivery.index', [...request()->except(['stage', 'page']), 'stage' => $each]) }}"
               @if ($tab === $each) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                      {{ $tab === $each
                          ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                          : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                {{ $each === 'open' ? __('sales::delivery.tab.open') : DeliveryStage::label($each) }}
                <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                    {{ $counts[$each] ?? 0 }}
                </span>
            </a>
        @endforeach
    </nav>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <input type="hidden" name="stage" value="{{ $tab }}">

            <x-ui.toolbar :title="$tab === 'open' ? __('sales::delivery.tab.open') : DeliveryStage::label($tab)"
                :quiet="['stage']"
                :count="__('sales::delivery.subtitle')"
                :columns="$columns"
                :search-placeholder="__('sales::delivery.column.challan')" />
        </form>

        <x-ui.table
            :empty="$q !== '' ? __('core.empty.no_results') : __('sales::delivery.empty')"
            :rows="$rows"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$rows" />
    </div>
</x-layouts.app>
