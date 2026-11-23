{{--
    ডেলিভারি চালান — তালিকা।
--}}
@php
    // ⓘ ডেলিভারির কথা এক প্রশ্নে, পুরো পাতার — [[DeliveryStageService::summaries()]]
    $delivery = app(\App\Modules\Sales\Services\DeliveryStageService::class)->summaries($challans->items());

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
            'label' => __('customer::field.place'),
            'width' => '9rem',
            'render' => fn ($d) => view('customer::partials.place', ['customer' => $d->customer]),
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
            // ⭐ মালিকের পরিকল্পনা, ধাপ ৪: "ডেলিভারির অপেক্ষায়" বা "ডেলিভার্ড", প্রতিটা তালিকায়
            'key' => 'delivery',
            'label' => __('sales::delivery.summary.column'),
            'width' => '10rem',
            'render' => fn ($d) => view('sales::delivery.partials.summary', ['summary' => $delivery[$d->id] ?? null]),
        ],
    ];

    /*
     * ⭐ সারি থেকেই "পৌঁছেছে" — মালিক, ৩ অক্টোবর ২০২৬: "sob jaygathekei"। কেবল রওনার পরে আর ধাপ বদলানোর চাবিতে
     * ([[DeliveryRowActions]]); ফর্ম যায় পুরনো `sales.delivery.move`-এ, আর পাশের ⋯-এ আংশিক/পৌঁছায়নি।
     */
    $arrive = app(\App\Modules\Sales\Services\DeliveryRowActions::class)->forChallans(collect($challans->items())->pluck('id'));

    if ($arrive !== []) {
        $columns[] = [
            'key' => 'arrive',
            'label' => __('sales::delivery.column.next'),
            'width' => '10rem',
            'render' => fn ($d) => isset($arrive[$d->id])
                ? view('sales::delivery.partials.row-action', ['challan' => $d, 'choices' => $arrive[$d->id]['choices'], 'trip' => null, 'vehicles' => collect()])
                : '',
        ];
    }

    /*
     * ⭐ একসাথে কাজ — সারির টিক-বাক্স আর বার (তালিকার একসাথে কাজ, ধাপ ২; মালিক, ১৯ নভেম্বর ২০২৬; [[SalesBulkController]])।
     * ⛔ কেবল তিন ABOS রূপে ([[BulkRun::showsHere()]]) — SAP আর Dynamic-এ একটা পিক্সেলও বদলায় না।
     */
    $bulkActions = null;

    if (\App\Core\Bulk\BulkRun::showsHere() && (auth()->user()?->can('sales.challan.view') ?? false)) {
        $bulkActions = \App\Modules\Sales\Http\Controllers\SalesBulkController::actionsFor('challan', auth()->user());
    }

    // ⛔ কোনো কাজ খোলা নেই (চাবি নেই, রপ্তানিও নয়) — বারও নেই, টিক-বাক্সও নয় ([[BulkRun::barOpen()]])
    if ($bulkActions !== null && ! \App\Core\Bulk\BulkRun::barOpen($bulkActions)) {
        $bulkActions = null;
    }

    if ($bulkActions !== null) {
        array_unshift($columns, \App\Core\Bulk\BulkRun::pickColumn('sales-challan-bulk', fn ($d) => (string) $d->document_no));
    }
@endphp

<x-layouts.app :menu="$menu" :process-band="$processBand ?? []">
    <x-slot:title>{{ __('sales::menu.challans') }}</x-slot:title>

    {{-- ⭐ চালানের ধাপের ট্যাব — আগে আলাদা "DO" পাতায় ছিল; আসল DO-র এখন নিজের ডেস্ক (৩ অক্টোবর ২০২৬, [[DeliveryOrderTabs]]) --}}
    @include('sales::do.partials.tabs', ['active' => $tab ?? ''])

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- ⭐ রঙিন কার্ড, তালিকা আর ডান পাশের ছাঁকনি — সব তালিকায় এক ছাঁচ ([[filter-layout]], [[ChallanListFilters]]; ১৫ নভেম্বর ২০২৬) --}}
    <x-ui.filter-layout :filters="$filters" form="list-form" :noun="__('sales::filter.challan_noun')">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            {{-- ⓘ উপরের ধাপের ট্যাব খোঁজা বা ছাঁকনিতে হারায় না --}}
            @if ($tab)<input type="hidden" name="tab" value="{{ $tab }}">@endif
            <x-ui.toolbar :title="__('sales::menu.challans')" :count="__('sales::message.challan_note')"
                :columns="$columns" :search-placeholder="__('sales::message.challan_search')"
                          :sort="$sortOptions" :facets="$filters" :quiet="['tab']">
        {{-- ⛔ "নতুন চালান" নেই — মালিকের পরিকল্পনা, ধাপ ৪ (২৮ সেপ্টেম্বর ২০২৬): চালান জন্মায়
             বিক্রি থেকে (অনুমোদনের পরে বিলের সাথে), তালিকা থেকে নয়। এই পাতা কেবল তালিকা। --}}
            </x-ui.toolbar>
        </form>

        @if ($bulkActions !== null)
            <x-ui.bulk-form id="sales-challan-bulk" :actions="$bulkActions" />
        @endif

        <x-ui.table
            :grand="$grand"
            :row-menu="fn ($d) => view('sales::challan.partials.row-menu', ['challan' => $d])"
            :empty="$q ? __('core.empty.no_results') : __('sales::message.no_challans')"
            :rows="$challans"
            :compact="\App\Core\Support\Ui::compact()"
            :columns="$columns" />

        <x-ui.pager :rows="$challans" />
        <x-ui.list-totals :rows="$challans" :grand="$grand ?? []" :columns="$columns" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
