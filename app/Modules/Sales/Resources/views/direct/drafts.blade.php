{{--
    খসড়া তালিকা — কাউন্টারে "খসড়া রাখুন" চাপা বিলগুলো।

    ⭐ মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"খসড়া list ei menuta … সরাসরি
    বিক্রয় er pore ekhoni bosaw"*। ⓘ কাউন্টারের Pending ড্রপডাউনের একই তালিকা
    ([[DirectSaleController::pendingDrafts()]]-এর একই ছাঁকনি), এখানে এক
    পাতায় — কোনটা কতদিন পড়ে আছে দেখার জন্য।

    ⓘ এখানে কেবল দেখা — কেন আটকে, আর পপ-আপে কী আছে (মালিকের নির্দেশ, ২৮
    সেপ্টেম্বর ২০২৬)। খোলা, পাকা বা বাতিল কেবল কাউন্টারের Pending ড্রপডাউন থেকে।
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
            'width' => '10rem',
            'render' => fn ($d) => $d->document_no,
        ],
        [
            'key' => 'challan_no',
            'label' => __('sales::field.challan_no_short'),
            'width' => '10rem',
            'render' => fn ($d) => $d->lines->first()?->challanLine?->challan?->document_no ?? '—',
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
            'label' => __('sales::field.total'),
            'numeric' => true,
            'width' => '9rem',
            'render' => fn ($d) => \App\Core\Support\Money::format($d->total),
        ],
        [
            // ⭐ কেন আটকে — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬ ([[DirectSaleController::whyStuck()]])
            'key' => 'status',
            'label' => __('sales::field.state'),
            'render' => fn ($d) => $why[$d->id] ?? '',
        ],
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'width' => '24rem',
            'render' => fn ($d) => view('sales::direct.partials.draft-actions', ['draft' => $d, 'why' => $why[$d->id] ?? '', 'held' => $held[$d->id] ?? false]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.direct_drafts') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            {{ $errors->first() }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::menu.direct_drafts')"
                          :columns="$columns" :search-placeholder="__('sales::message.invoice_search')">
                <a href="{{ route('sales.direct.create') }}"
                   class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) bg-(--color-brand-600)
                          px-3 text-sm font-semibold text-white hover:bg-(--color-brand-700)">
                    {{ __('sales::menu.direct') }}
                </a>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q !== '' ? __('core.empty.no_results') : __('sales::field.pending_drafts_none')"
            :rows="$drafts"
            :columns="$columns" />

        <x-ui.pager :rows="$drafts" />
    </div>
</x-layouts.app>
