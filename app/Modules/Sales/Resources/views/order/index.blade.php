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
            'label' => __('customer::field.place'),
            'width' => '9rem',
            'render' => fn ($d) => view('customer::partials.place', ['customer' => $d->customer]),
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
            // ⭐ অবস্থা, চালান, বিল — পাশে ব্যাক অর্ডার আর লাল "পুরনো খসড়া" (মালিক, ৪ অক্টোবর ২০২৬; [[OrderProgress]])
            'key' => 'status',
            'label' => __('sales::field.state'),
            'width' => '14rem',
            'render' => fn ($d) => view('sales::order.partials.state-chip', [
                'status' => (string) $d->status,
                'delivery' => $states[$d->id]['delivery'] ?? null,
                'billing' => $states[$d->id]['billing'] ?? null,
                'back' => $states[$d->id]['back'] ?? false,
                'stale' => $states[$d->id]['stale'] ?? false,
                'days' => $states[$d->id]['age_days'] ?? 0,
                'paperDate' => \App\Core\Support\DateFormat::format($d->trx_date),
            ]),
        ],
    ];

    /*
     * ⭐ একসাথে কাজ — সারির টিক-বাক্স আর বার (তালিকার একসাথে কাজ, ধাপ ২; মালিক, ১৯ নভেম্বর ২০২৬; [[SalesBulkController]])।
     * ⛔ কেবল তিন ABOS রূপে ([[BulkRun::showsHere()]]) — SAP আর Dynamic-এ একটা পিক্সেলও বদলায় না।
     */
    $bulkActions = null;

    if (\App\Core\Bulk\BulkRun::showsHere() && (auth()->user()?->can('sales.order.view') ?? false)) {
        $bulkActions = \App\Modules\Sales\Http\Controllers\SalesBulkController::actionsFor('order', auth()->user());
    }

    // ⛔ কোনো কাজ খোলা নেই (চাবি নেই, রপ্তানিও নয়) — বারও নেই, টিক-বাক্সও নয় ([[BulkRun::barOpen()]])
    if ($bulkActions !== null && ! \App\Core\Bulk\BulkRun::barOpen($bulkActions)) {
        $bulkActions = null;
    }

    if ($bulkActions !== null) {
        array_unshift($columns, \App\Core\Bulk\BulkRun::pickColumn('sales-order-bulk', fn ($d) => (string) $d->document_no));
    }
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

    {{-- ⭐ এক তালিকা, পাঁচটা ট্যাব — আগে মেনুর পাঁচটা সারি (নকশার পর্যালোচনা, ধাপ ৭-এর ২, ১ অক্টোবর ২০২৬) --}}
    <x-ui.list-tabs :tabs="$tabs" :label="__('sales::order_tabs.label')" />

    {{-- ⭐ রঙিন কার্ড, তালিকা আর ডান পাশের ছাঁকনি — সব তালিকায় এক ছাঁচ ([[filter-layout]], [[OrderListFilters]]; ১৫ নভেম্বর ২০২৬) --}}
    <x-ui.filter-layout :filters="$filters" form="list-form" :noun="__('sales::filter.order_noun')">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            {{-- ⓘ খুঁজলে ট্যাব বদলায় না --}}
            @if ($tab !== 'all')
                <input type="hidden" name="tab" value="{{ $tab }}">
            @endif
            <x-ui.toolbar :title="__('sales::menu.orders')" :count="__('sales::message.order_note')"
                :columns="$columns" :search-placeholder="__('sales::message.order_search')"
                          :sort="$sortOptions" :facets="$filters" :quiet="['tab', 'mine']">
        <x-slot:actions>
            @can('create', \App\Modules\Sales\Models\SalesOrder::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('sales.order.create')">
                        {{ __('sales::action.new_order') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                {{-- ⭐ "কেবল আমার সইয়ের অপেক্ষায়" — সইয়ের ট্যাবে (নকশার §৪, ধাপ ৮) --}}
                @if ($tab === 'awaiting')
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="checkbox" name="mine" value="1" @checked($mine ?? false) class="size-4" data-only-mine>
                        {{ __('sales::order_status.only_mine') }}
                    </label>
                @endif

            </x-ui.toolbar>
        </form>

        @if ($bulkActions !== null)
            <x-ui.bulk-form id="sales-order-bulk" :actions="$bulkActions" />
        @endif

        <x-ui.table
            :grand="$grand"
            :row-menu="fn ($d) => view('sales::order.partials.row-menu', ['order' => $d])"
            :empty="$q ? __('core.empty.no_results') : __('sales::message.no_orders')"
            :rows="$orders"
            :compact="\App\Core\Support\Ui::compact()"
            :columns="$columns" />

        <x-ui.pager :rows="$orders" />
        <x-ui.list-totals :rows="$orders" :grand="$grand ?? []" :columns="$columns"
                           :totals="[['value' => __('sales::message.total_leaves_out_order')]]" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
