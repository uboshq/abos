{{--
    শিপমেন্ট — তালিকা।

    দিনের মাঝামাঝি এই পর্দায় এসে যে প্রশ্নটার উত্তর খোঁজা হয় সেটা
    একটাই: **কোন গাড়িগুলো এখনো পথে**। তাই অবস্থাটা কলামে আছে, আর
    সাজানোর একটা ধরনই ওই প্রশ্নটার — পথে থাকাগুলো আগে।
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
            'label' => __('sales::shipment.trip_no'),
            'width' => '12rem',
            'render' => fn ($d) => view('sales::components.doc-link', [
                'document' => $d,
                'route' => 'sales.shipment.show',
            ]),
        ],
        [
            'key' => 'vehicle_id',
            'label' => __('sales::shipment.vehicle'),
            'width' => '11rem',
            'render' => fn ($d) => $d->vehiclePlate(),
        ],
        [
            'key' => 'driver_name',
            'label' => __('sales::shipment.driver'),
            'render' => fn ($d) => $d->driverName(),
        ],
        [
            'key' => 'route_location_id',
            'label' => __('sales::shipment.route'),
            'render' => fn ($d) => $d->route?->name(),
        ],
        [
            'key' => 'lines_count',
            'label' => __('sales::shipment.challans'),
            'numeric' => true,
            'width' => '8rem',
            'render' => fn ($d) => $d->lines_count,
        ],
        [
            'key' => 'status',
            'label' => __('sales::field.state'),
            'width' => '9rem',
            'render' => fn ($d) => view('sales::components.status-badge', ['document' => $d]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::shipment.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('sales::shipment.title')"
            :subtitle="__('sales::shipment.subtitle')">
            <x-slot:actions>
                @can('create', \App\Modules\Sales\Models\Shipment::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('sales.shipment.create')">
                        {{ __('sales::shipment.new') }}
                    </x-ui.button>
                @endcan
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

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar
                :columns="$columns" :search-placeholder="__('sales::shipment.subtitle')"
                :sort="$sortOptions">
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('sales::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('sales::shipment.empty')"
            :rows="$shipments"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        @if ($shipments->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $shipments->links() }}</div>
        @endif
    </div>
</x-layouts.app>
