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
            'total' => 'money',
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

    /*
     * ⭐ সারি বাছাই — একসাথে নিশ্চিত/অনুমোদনে, খসড়া বাতিল, অনুমোদন, ছাপা, Excel (মালিক, ১৯ নভেম্বর ২০২৬; [[PurchaseBulkController]])।
     * ⛔ কেবল তিন ABOS রূপে — SAP আর Dynamic-এ টিক-বাক্সের কলাম আর বার কিছুই নয় ([[BulkRun::showsHere()]])।
     * ⓘ রপ্তানির ফাইলে টিক-বাক্সের কলাম নয় (`?export=`) — ছাপা আর Excel কেবল কাগজের কলাম।
     */
    $bulkActions = \App\Core\Bulk\BulkRun::showsHere() && (auth()->user()?->can('purchase.order.view') ?? false)
        ? \App\Modules\Purchase\Http\Controllers\PurchaseBulkController::actionsFor('order', auth()->user())
        : [];
    // ⛔ কোনো কাজ খোলা নেই (চাবি নেই, রপ্তানিও নয়) — বারও নেই, টিক-বাক্সও নয় ([[BulkRun::barOpen()]])
    $bulk = \App\Core\Bulk\BulkRun::showsHere() && (auth()->user()?->can('purchase.order.view') ?? false) && \App\Core\Bulk\BulkRun::barOpen($bulkActions);

    if ($bulk && ! request()->filled('export')) {
        array_unshift($columns, \App\Core\Bulk\BulkRun::pickColumn('purchase-order-bulk', fn ($d) => (string) $d->document_no));
    }

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
        \App\Core\ListFilter\Facets::flag('cancelled', __('purchase::action.show_cancelled')),
    ]);
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

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :facets="$facetPanel" :title="__('purchase::menu.orders')" :count="__('purchase::message.order_note')"
                :columns="$columns" :search-placeholder="__('purchase::message.order_search')"
                          :sort="$sortOptions">
        <x-slot:actions>
            @can('create', \App\Modules\Purchase\Models\PurchaseOrder::class)
                    <x-ui.button tone="primary" icon="plus" :href="route('purchase.order.create')">
                        {{ __('purchase::action.new_order') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
            </x-ui.toolbar>

            {{-- ধাপের স্ট্রিপ — কয়টা কোথায় আটকে, আর কত টাকার।
                 কোন রূপে কেমন দেখাবে সেটা CSS ঠিক করে: D365-এ শেভরন,
                 খতিয়ানে চৌকো ঘর। কথাটা সব রূপেই এক। --}}
            <x-ui.stage-strip :stages="$stages" class="m-3" />
        </form>

        @if ($bulk)
            <x-ui.bulk-form id="purchase-order-bulk"
                :actions="$bulkActions" />
        @endif

        <x-ui.table
            :grand="$grand ?? []"
            :row-menu="fn ($d) => view('purchase::order.partials.row-menu', ['order' => $d])"
            :empty="$q ? __('core.empty.no_results') : __('purchase::message.no_orders')"
            :rows="$orders"
            :compact="\App\Core\Support\Ui::compact()"
            :columns="$columns" />

        <x-ui.pager :rows="$orders" />
        <x-ui.list-totals :rows="$orders" :grand="$grand ?? []" :columns="$columns" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
