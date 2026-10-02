{{--
    ডেলিভারি অর্ডার — প্রতিটা বিক্রির চালান, ধাপের ট্যাবে ([[DeliveryOrderTabs]])।
--}}
@php
    // ⓘ ডেলিভারির কথা এক প্রশ্নে, পুরো পাতার — [[DeliveryStageService::summaries()]]
    $delivery = app(\App\Modules\Sales\Services\DeliveryStageService::class)->summaries($orders->items());

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
            'width' => '12rem',
            'render' => fn ($d) => view('sales::components.doc-link', [
                'document' => $d,
                'route' => 'sales.challan.show',
            ]),
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
            'key' => 'warehouse_id',
            'label' => __('sales::field.warehouse'),
            'width' => '11rem',
            'render' => fn ($d) => $d->warehouse?->name(),
        ],
        [
            'key' => 'total',
            'total' => 'money',
            'label' => __('sales::field.total'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($d) => view('ui.amount-link', [
                'value' => $d->total,
                'href' => route('sales.challan.show', $d),
            ]),
        ],
        [
            'key' => 'status',
            'label' => __('sales::field.state'),
            'width' => '8rem',
            'render' => fn ($d) => view('sales::components.status-badge', ['document' => $d]),
        ],
        [
            'key' => 'delivery',
            'label' => __('sales::delivery.summary.column'),
            'width' => '10rem',
            'render' => fn ($d) => view('sales::delivery.partials.summary', ['summary' => $delivery[$d->id] ?? null]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.delivery_orders') }}</x-slot:title>

    @include('sales::do.partials.tabs', ['active' => $tab])

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <x-ui.toolbar :title="__('sales::menu.delivery_orders')" :count="__('sales::do.note.'.$tab)"
                :columns="$columns" :search-placeholder="__('sales::message.challan_search')"
                          :sort="$sortOptions">
                <x-ui.date-range :dates="$dates" />
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :grand="$grand"
            :view-url="fn ($d) => route('sales.challan.show', $d)"
            :empty="$q ? __('core.empty.no_results') : __('sales::do.empty')"
            :rows="$orders"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$orders" />
    </div>
</x-layouts.app>
