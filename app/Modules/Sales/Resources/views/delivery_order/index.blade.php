{{--
    ⭐ DO ডেস্ক — আসল ডেলিভারি অর্ডার, অবস্থার ট্যাবে ([[DeliveryOrderDeskController]])।
    ⓘ "নতুন DO" বোতাম তালিকার ভিতরেই — মালিক, ২ অক্টোবর ২০২৬: "Er vitorei thakbe DO Creat & List"।
--}}
@php
    $columns = [
        [
            'key' => 'trx_date',
            'label' => __('sales::field.date'),
            'width' => '7rem',
            'render' => fn ($d) => \App\Core\Support\DateFormat::format($d->trx_date),
        ],
        [
            'key' => 'document_no',
            'label' => __('sales::field.document_no'),
            'width' => '11rem',
            'render' => fn ($d) => $d->document_no,
        ],
        [
            'key' => 'customer_id',
            'label' => __('sales::field.customer'),
            'render' => fn ($d) => $d->customer?->name(),
        ],
        [
            // ⭐ গ্রাহকের পরে পয়েন্ট — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: সব তালিকায় অবশ্যই
            'key' => 'point',
            'label' => __('customer::field.point'),
            'width' => '9rem',
            'render' => fn ($d) => $d->customer?->location?->name() ?? '—',
        ],
        [
            'key' => 'total',
            'total' => 'money',
            'label' => __('sales::field.total'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($d) => view('ui.amount-link', [
                'value' => $d->total,
                'href' => route('sales.delivery_order.show', $d),
            ]),
        ],
        [
            'key' => 'status',
            'label' => __('sales::field.state'),
            'width' => '11rem',
            'render' => fn ($d) => \App\Modules\Sales\Support\DeliveryOrderStatus::label((string) $d->status),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::delivery_order.title') }}</x-slot:title>

    <nav class="mb-3 flex flex-wrap gap-2" aria-label="{{ __('sales::delivery_order.title') }}" data-do-tabs>
        @foreach (array_keys(\App\Modules\Sales\Http\Controllers\DeliveryOrderDeskController::TABS) as $key)
            <a href="{{ route('sales.delivery_order.index', ['tab' => $key]) }}"
               @if ($tab === $key) aria-current="page" @endif
               @class([
                   'inline-flex items-center gap-2 rounded-(--radius-field) border px-3 py-1.5 text-sm font-semibold',
                   'border-(--color-brand-600) bg-(--color-brand-600) text-white' => $tab === $key,
                   'border-(--color-border) text-(--color-ink) hover:bg-(--color-surface-hover)' => $tab !== $key,
               ])>
                {{ __('sales::delivery_order.tab.'.$key) }}
                <span class="num rounded-full bg-black/10 px-1.5 text-xs">{{ $counts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </nav>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <x-ui.toolbar :title="__('sales::delivery_order.title')" :columns="$columns"
                          :search-placeholder="__('sales::delivery_order.search')" :sort="$sortOptions" :quiet="['tab']">
                <x-slot:actions>
                    @can('sales.do.create')
                        <x-ui.button tone="primary" icon="plus" :href="route('sales.delivery_order.create')" data-new-do>
                            {{ __('sales::delivery_order.new') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
                <x-ui.date-range :dates="$dates" />
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :grand="$grand"
            :view-url="fn ($d) => route('sales.delivery_order.show', $d)"
            :empty="$q ? __('core.empty.no_results') : __('sales::delivery_order.none')"
            :rows="$orders"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$orders" />
    </div>
</x-layouts.app>
