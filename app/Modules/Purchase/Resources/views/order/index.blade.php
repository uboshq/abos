{{-- ক্রয় আদেশ — তালিকা। --}}
@php
    $columns = [
        [
            'key' => 'trx_date',
            'label' => __('purchase::field.date'),
            'width' => '7rem',
            'render' => fn ($d) => \App\Core\Support\DateFormat::format($d->trx_date),
        ],
        [
            'key' => 'document_no',
            'label' => __('purchase::field.document_no'),
            'width' => '12rem',
            'render' => fn ($d) => view('purchase::components.doc-link', [
                'document' => $d,
                'route' => 'purchase.order.show',
            ]),
        ],
        [
            'key' => 'supplier_id',
            'label' => __('purchase::field.supplier'),
            'render' => fn ($d) => $d->supplier?->name(),
        ],

        [
            'key' => 'total',
            'label' => __('purchase::field.total'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($d) => view('ui.amount-link', [
                'value' => $d->total,
                'href' => route('purchase.order.show', $d),
            ]),
        ],
        [
            'key' => 'status',
            'label' => __('purchase::field.state'),
            'width' => '8rem',
            'render' => fn ($d) => view('purchase::components.status-badge', ['document' => $d]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::menu.orders') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('purchase::menu.orders')" :count="__('purchase::message.order_note')"
                :columns="$columns" :search-placeholder="__('purchase::message.order_search')"
                          :sort="$sortOptions">
        <x-slot:actions>
            @can('create', \App\Modules\Purchase\Models\PurchaseOrder::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('purchase.order.create')">
                        {{ __('purchase::action.new_order') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('purchase::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>

            {{-- ধাপের স্ট্রিপ — কয়টা কোথায় আটকে, আর কত টাকার।
                 কোন রূপে কেমন দেখাবে সেটা CSS ঠিক করে: D365-এ শেভরন,
                 খতিয়ানে চৌকো ঘর। কথাটা সব রূপেই এক। --}}
            <x-ui.stage-strip :stages="$stages" class="m-3" />
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('purchase::message.no_orders')"
            :rows="$orders"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$orders" />
    </div>
</x-layouts.app>
