{{-- ক্রয় বিল — তালিকা। --}}
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
                'route' => 'purchase.bill.show',
            ]),
        ],
        [
            'key' => 'supplier_id',
            'label' => __('purchase::field.supplier'),
            'render' => fn ($d) => $d->supplier?->name(),
        ],
        [
            'key' => 'due_on',
            'label' => __('purchase::field.due_on'),
            'width' => '8rem',
            'render' => fn ($d) => \App\Core\Support\DateFormat::format($d->due_on),
        ],

        [
            'key' => 'total',
            'label' => __('purchase::field.total'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($d) => view('ui.amount-link', [
                'value' => $d->total,
                'href' => route('purchase.bill.show', $d),
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
    <x-slot:title>{{ __('purchase::menu.bills') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('purchase::menu.bills')" :count="__('purchase::message.bill_note')"
                :columns="$columns" :search-placeholder="__('purchase::message.bill_search')"
                          :sort="$sortOptions">
        <x-slot:actions>
            @can('create', \App\Modules\Purchase\Models\PurchaseBill::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('purchase.bill.create')">
                        {{ __('purchase::action.new_bill') }}
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
            :empty="$q ? __('core.empty.no_results') : __('purchase::message.no_bills')"
            :rows="$bills"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$bills" />
    </div>
</x-layouts.app>
