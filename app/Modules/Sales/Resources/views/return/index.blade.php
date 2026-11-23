{{--
    বিক্রয় ফেরতের তালিকা।

    কারণটা কলাম হিসেবে আছে: একই কারণ বারবার এলে সেটা পণ্যের সমস্যা, আর
    সেটা জানা দরকার — নাহলে প্রতি মাসে একই মাল ফেরত আসতেই থাকে।
--}}
@php
    $columns = [
        ['key' => 'trx_date', 'label' => __('sales::field.date'), 'width' => '7rem',
         'render' => fn ($r) => \App\Core\Support\DateFormat::format($r->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($r) => view('sales::components.doc-link', [
             'document' => $r, 'route' => 'sales.return.show'])],
        ['key' => 'customer', 'label' => __('sales::field.customer'),
         'render' => fn ($r) => $r->customer?->name()],
        // ⭐ গ্রাহকের পরে পয়েন্ট — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: সব তালিকায় অবশ্যই
        ['key' => 'point', 'label' => __('customer::field.place'), 'width' => '9rem',
         'render' => fn ($r) => view('customer::partials.place', ['customer' => $r->customer])],
        ['key' => 'reason', 'label' => __('sales::field.reason'), 'width' => '10rem',
         'render' => fn ($r) => $r->reasonCode?->name() ?: '—'],
        ['key' => 'total', 'total' => 'money', 'label' => __('sales::field.total'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($r) => \App\Core\Support\Money::format($r->total)],
        ['key' => 'status', 'label' => __('sales::field.state'), 'width' => '8rem',
         'render' => fn ($r) => view('sales::components.status-badge', ['document' => $r])],
    ];

    /*
     * ⭐ একসাথে কাজ — সারির টিক-বাক্স আর বার (তালিকার একসাথে কাজ, ধাপ ২; মালিক, ১৯ নভেম্বর ২০২৬; [[SalesBulkController]])।
     * ⛔ কেবল তিন ABOS রূপে ([[BulkRun::showsHere()]]) — SAP আর Dynamic-এ একটা পিক্সেলও বদলায় না।
     */
    $bulkActions = null;

    if (\App\Core\Bulk\BulkRun::showsHere() && (auth()->user()?->can('sales.return.view') ?? false)) {
        $bulkActions = \App\Modules\Sales\Http\Controllers\SalesBulkController::actionsFor('return', auth()->user());
    }

    // ⛔ কোনো কাজ খোলা নেই (চাবি নেই, রপ্তানিও নয়) — বারও নেই, টিক-বাক্সও নয় ([[BulkRun::barOpen()]])
    if ($bulkActions !== null && ! \App\Core\Bulk\BulkRun::barOpen($bulkActions)) {
        $bulkActions = null;
    }

    if ($bulkActions !== null) {
        array_unshift($columns, \App\Core\Bulk\BulkRun::pickColumn('sales-return-bulk', fn ($d) => (string) $d->document_no));
    }
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.returns') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('sales::menu.returns')" :count="trans_choice('core.count.records', $returns->total(), ['count' => $returns->total()])"
                :columns="$columns" :search-placeholder="__('sales::message.return_search')"
                          :sort="$sortOptions">
        <x-slot:actions>
            @can('sales.return.create')
                    <x-ui.button tone="primary" icon="plus" :href="route('sales.return.create')">
                        {{ __('sales::action.new_return') }}
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

        @if ($bulkActions !== null)
            <x-ui.bulk-form id="sales-return-bulk" :actions="$bulkActions" />
        @endif

        <x-ui.table
            :grand="$grand ?? []"
            :row-menu="fn ($d) => view('sales::return.partials.row-menu', ['return' => $d])"
            :empty="$q ? __('core.empty.no_results') : __('sales::message.no_returns')"
            :rows="$returns"
            :compact="\App\Core\Support\Ui::compact()"
            :columns="$columns" />

        <x-ui.pager :rows="$returns" />
        <x-ui.list-totals :rows="$returns" :grand="$grand ?? []" :columns="$columns" />
    </div>
</x-layouts.app>
