{{--
    ⭐ স্টক ছাড়া বিক্রয় ভাউচার (SV) / ক্রয় ভাউচার (PUV) — তালিকা (মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬; [[TradeVoucherListController]])।
    ⛔ বর্ণনার লাইন নেই — শিরোনাম + বোতাম এক সারি (মালিক, ১৯ নভেম্বর)। ⛔ মোট কেবল পাকা ভাউচারের; অপেক্ষমাণ আর খসড়া আলাদা লাইনে।
--}}
@php
    use App\Core\Support\DateFormat;
    use App\Core\Support\Money;

    $sale = $kind === 'sale';
    $title = __('accounts::trade.menu_'.$kind);
    $open = fn ($v) => route('accounts.trade_voucher.show', ['kind' => $v->kind, 'voucher' => $v->id]);

    $columns = [
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '9rem',
         'render' => fn ($v) => new \Illuminate\Support\HtmlString('<a href="'.e($open($v)).'" class="font-medium text-(--color-brand-600) underline-offset-2 hover:underline">'.e($v->document_no).'</a>')],
        ['key' => 'trx_date', 'label' => __('core.print.date'), 'width' => '7rem',
         'render' => fn ($v) => DateFormat::format($v->trx_date)],
        /* ⭐ পক্ষের নাম তাঁর নিজের পাতায় যায় */
        ['key' => 'party', 'label' => __('accounts::note_list.party_'.$kind),
         'render' => fn ($v) => view('accounts::note.partials.party', ['note' => $v, 'names' => $names, 'routes' => $routes])],
        ['key' => 'point', 'label' => __('accounts::note.point'), 'width' => '9rem',
         'render' => fn ($v) => $places[$v->party_type.':'.$v->party_id] ?? '—'],
        ['key' => 'ref_no', 'label' => __('accounts::note_list.col_ref_'.$kind), 'width' => '9rem',
         'render' => fn ($v) => filled($v->ref_no) ? $v->ref_no : '—'],
    ];

    if (! $sale) {
        /* ⓘ সরবরাহকারীর মূসক-৬.৩ — নম্বর ও তারিখ */
        $columns[] = ['key' => 'mushak_no', 'label' => __('accounts::note_list.col_mushak_63'), 'width' => '10rem',
            'render' => fn ($v) => filled($v->mushak_no)
                ? $v->mushak_no.($v->mushak_date ? ' · '.DateFormat::format($v->mushak_date) : '')
                : '—'];
    }

    $columns[] = ['key' => 'due_on', 'label' => __('accounts::note_list.col_due'), 'width' => '7rem',
        'render' => fn ($v) => $v->due_on ? DateFormat::format($v->due_on) : '—'];
    $columns[] = ['key' => 'total', 'total' => 'money', 'label' => __('accounts::note_list.col_total'), 'numeric' => true, 'width' => '9rem',
        'render' => fn ($v) => Money::format($v->total)];

    if (! $sale) {
        $columns[] = ['key' => 'tds_amount', 'total' => 'money', 'label' => __('accounts::note_list.col_tds'), 'numeric' => true, 'width' => '8rem',
            'render' => fn ($v) => Money::format($v->tds_amount)];
    }

    $columns[] = ['key' => 'party_total', 'total' => 'money', 'label' => __('accounts::note_list.col_party_total_'.$kind), 'numeric' => true, 'width' => '9rem',
        'render' => fn ($v) => Money::format($v->party_total)];
    $columns[] = ['key' => 'status', 'label' => __('core.table.status'), 'width' => '9rem',
        'render' => fn ($v) => view('accounts::note.partials.status', ['note' => $v, 'waiting' => in_array((int) $v->id, $waitingIds, true)])];
    $columns[] = ['key' => 'print', 'label' => __('core.action.print'), 'width' => '6rem',
        'render' => fn ($v) => view('accounts::trade-voucher.partials.list-print', ['voucher' => $v])];

    $countMoney = fn (array $part) => __('accounts::note_list.count_money', [
        'count' => number_format($part['count']), 'money' => Money::format($part['money']),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $title }}</x-slot:title>

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <x-ui.filter-layout :filters="$filters" form="list-form" :noun="__('accounts::note_list.noun_voucher')">
    <div data-boxed data-trade-voucher-list="{{ $kind }}"
         class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="$title"
                          :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])"
                          :columns="$columns" :sort="$sortOptions" :facets="$filters"
                          :search-placeholder="__('accounts::note_list.search_voucher')">
                <x-slot:actions>
                    @can('accounts.voucher.create')
                        <x-ui.button tone="primary" icon="plus" :href="route('accounts.trade_voucher.create', ['kind' => $kind])" data-trade-voucher-new>
                            {{ __('accounts::trade.new_'.$kind) }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table :rows="$rows" :columns="$columns"
                    :empty="$q ? __('core.empty.no_results') : __('accounts::note_list.none_voucher')"
                    :grand="$grand ?? []"
                    :compact="\App\Core\Support\Ui::compact()"
                    :view-url="$open" />

        <x-ui.pager :rows="$rows" />

        <x-ui.list-totals :totals="[
            ['value' => __('core.list.rows', ['count' => number_format($totals['rows'])])],
            ['label' => __('accounts::note_list.total_posted'), 'value' => Money::format($totals['money'])],
            ['label' => __('accounts::note_list.total_waiting'), 'value' => $countMoney($totals['waiting'])],
            ['label' => __('accounts::note_list.total_drafts'), 'value' => $countMoney($totals['drafts'])],
        ]" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
