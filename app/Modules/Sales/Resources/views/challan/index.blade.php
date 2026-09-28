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
@endphp

<x-layouts.app :menu="$menu" :process-band="$processBand ?? []">
    <x-slot:title>{{ __('sales::menu.challans') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::menu.challans')" :count="__('sales::message.challan_note')"
                :columns="$columns" :search-placeholder="__('sales::message.challan_search')"
                          :sort="$sortOptions">
        {{-- ⛔ "নতুন চালান" নেই — মালিকের পরিকল্পনা, ধাপ ৪ (২৮ সেপ্টেম্বর ২০২৬): চালান জন্মায়
             বিক্রি থেকে (অনুমোদনের পরে বিলের সাথে), তালিকা থেকে নয়। এই পাতা কেবল তালিকা। --}}
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('sales::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('sales::message.no_challans')"
            :rows="$challans"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$challans" />
    </div>
</x-layouts.app>
