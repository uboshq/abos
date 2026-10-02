{{--
    বিক্রয় আদেশ — তালিকা।
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
            'width' => '12rem',
            'render' => fn ($d) => view('sales::components.doc-link', [
                'document' => $d,
                'route' => 'sales.order.show',
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
            'key' => 'total',
            'total' => 'money',
            'label' => __('sales::field.total'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($d) => view('ui.amount-link', [
                'value' => $d->total,
                'href' => route('sales.order.show', $d),
            ]),
        ],
        [
            'key' => 'status',
            'label' => __('sales::field.state'),
            'width' => '8rem',
            'render' => fn ($d) => view('sales::components.status-badge', ['document' => $d]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.orders') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::menu.orders')" :count="__('sales::message.order_note')"
                :columns="$columns" :search-placeholder="__('sales::message.order_search')"
                          :sort="$sortOptions">
        <x-slot:actions>
            @can('create', \App\Modules\Sales\Models\SalesOrder::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('sales.order.create')">
                        {{ __('sales::action.new_order') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('sales::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :grand="$grand"
            :view-url="fn ($d) => route('sales.order.show', $d)"
            :empty="$q ? __('core.empty.no_results') : __('sales::message.no_orders')"
            :rows="$orders"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$orders" />
    </div>
</x-layouts.app>
