{{--
    বিক্রয় উদ্ধৃতি — তালিকা।
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
                'route' => 'sales.quotation.show',
            ]),
        ],
        [
            'key' => 'customer_id',
            'label' => __('sales::field.customer'),
            'render' => fn ($d) => $d->customer?->name(),
        ],
        [
            'key' => 'valid_until',
            'label' => __('sales::quotation.field.valid_until'),
            'width' => '7rem',
            'render' => fn ($d) => \App\Core\Support\DateFormat::format($d->valid_until),
        ],
        [
            'key' => 'total',
            'label' => __('sales::field.total'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($d) => view('ui.amount-link', [
                'value' => $d->total,
                'href' => route('sales.quotation.show', $d),
            ]),
        ],
        [
            'key' => 'status',
            'label' => __('sales::field.state'),
            'width' => '9rem',
            'render' => fn ($d) => view('sales::quotation.partials.status', ['quotation' => $d]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::quotation.menu') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::quotation.menu')" :count="__('sales::quotation.list_note')"
                :columns="$columns" :search-placeholder="__('sales::quotation.search')"
                          :sort="$sortOptions">
        <x-slot:actions>
            @can('create', \App\Modules\Sales\Models\SalesQuotation::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('sales.quotation.create')">
                        {{ __('sales::quotation.action.new') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <span class="sr-only">{{ __('sales::field.state') }}</span>
                    <select name="state"
                            class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-card) px-2">
                        <option value="">{{ __('sales::quotation.state_all') }}</option>
                        @foreach (\App\Modules\Sales\Models\SalesQuotation::STATES as $option)
                            <option value="{{ $option }}" @selected($state === $option)>
                                {{ __('sales::quotation.status.'.$option) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('sales::quotation.action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('sales::quotation.empty')"
            :rows="$quotations"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$quotations" />
    </div>
</x-layouts.app>
