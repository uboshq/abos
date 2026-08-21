{{-- মাল বুঝে নেওয়া — তালিকা। --}}
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
                'route' => 'purchase.receipt.show',
            ]),
        ],
        [
            'key' => 'supplier_id',
            'label' => __('purchase::field.supplier'),
            'render' => fn ($d) => $d->supplier?->name(),
        ],
        [
            'key' => 'warehouse_id',
            'label' => __('purchase::field.warehouse'),
            'width' => '12rem',
            'render' => fn ($d) => $d->warehouse?->name(),
        ],

        [
            'key' => 'total',
            'label' => __('purchase::field.total'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($d) => view('ui.amount-link', [
                'value' => $d->total,
                'href' => route('purchase.receipt.show', $d),
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
    <x-slot:title>{{ __('purchase::menu.receipts') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('purchase::menu.receipts')" :count="__('purchase::message.receipt_note')"
                :columns="$columns" :search-placeholder="__('purchase::message.receipt_search')"
                          :sort="$sortOptions">
        <x-slot:actions>
            @can('create', \App\Modules\Purchase\Models\PurchaseReceipt::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('purchase.receipt.create')">
                        {{ __('purchase::action.new_receipt') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('purchase::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('purchase::message.no_receipts')"
            :rows="$receipts"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$receipts" />
    </div>
</x-layouts.app>
