{{--
    ⭐ নোটের তালিকা — এক ভাগ এক পাতা: ক্রেডিট নোট, গ্রাহকের ডেবিট নোট, সরবরাহকারীর ডেবিট নোট (মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬;
    [[NoteListController]])। ⓘ ট্যাব নেই — প্রতিটা ভাগ নিজের মেনু-সারি। ⛔ বর্ণনার লাইন নেই (মালিক, ১৯ নভেম্বর: শিরোনাম + বোতাম এক সারি)।

    ⛔ মোট কেবল পাকা নোটের; খসড়া আর অনুমোদনের অপেক্ষা আলাদা লাইনে। টাকা = নোটের সর্বমোট (নিজের সারি + ফেরতের ভাগ)।
--}}
@php
    use App\Core\Support\Money;

    $title = __('accounts::note.menu_'.$type);

    $columns = [
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '9rem',
         'render' => fn ($n) => view('accounts::note.partials.number', ['note' => $n])],
        ['key' => 'trx_date', 'label' => __('core.print.date'), 'width' => '7rem',
         'render' => fn ($n) => \App\Core\Support\DateFormat::format($n->trx_date)],

        /* ⭐ পক্ষের নাম তাঁর নিজের পাতায় যায় — মালিকের নিয়ম, ২০ সেপ্টেম্বর ২০২৬ */
        ['key' => 'party', 'label' => __('accounts::note.party'),
         'render' => fn ($n) => view('accounts::note.partials.party', [
             'note' => $n, 'names' => $names, 'routes' => $routes,
         ])],
        /* ⭐ "কাকে"-র পরেই পয়েন্ট — মালিকের নিয়ম, ২৮ সেপ্টেম্বর ২০২৬ */
        ['key' => 'point', 'label' => __('accounts::note.point'), 'width' => '9rem',
         'render' => fn ($n) => $places[$n->party_type.':'.$n->party_id] ?? '—'],
        ['key' => 'against_no', 'label' => __('accounts::note_list.col_bill_'.$type), 'width' => '10rem',
         // ⓘ বাঁধা বিল না হলে "রেফারেন্স:" আগে — বিলের বিপরীতে নয়
         'render' => fn ($n) => $n->against_no ? ($n->against_id === null ? __('accounts::note.reference').': '.$n->against_no : $n->against_no) : '—'],
    ];

    /* ⓘ সরবরাহকারীর ডেবিট নোটে তাঁর মূসক-৬.৭ নম্বর — থাকলে তবেই ক্রয়-ভ্যাট উল্টায় (নকশা v5) */
    if ($type === 'dn') {
        $columns[] = ['key' => 'supplier_note_no', 'label' => __('accounts::note_list.col_supplier_note'), 'width' => '10rem',
            'render' => fn ($n) => filled($n->supplier_note_no)
                ? $n->supplier_note_no.($n->supplier_note_date ? ' · '.\App\Core\Support\DateFormat::format($n->supplier_note_date) : '')
                : '—'];
    }

    $columns = array_merge($columns, [
        ['key' => 'reason', 'label' => __('accounts::note.reason'), 'width' => '12rem',
         'render' => fn ($n) => __('accounts::note.reason_'.$n->reason)],
        ['key' => 'grand_total', 'total' => 'money', 'label' => __('accounts::note_list.col_amount'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($n) => Money::format($n->grandTotal())],
        ['key' => 'status', 'label' => __('core.table.status'), 'width' => '9rem',
         'render' => fn ($n) => view('accounts::note.partials.status', ['note' => $n, 'waiting' => in_array((int) $n->id, $waitingIds, true)])],
        /* ⭐ যুক্ত ফেরতের কাগজ — "মাল ফেরত" সারির SR/PR (নকশা v5) */
        ['key' => 'returns', 'label' => __('accounts::note_list.col_returns'), 'width' => '9rem',
         'render' => fn ($n) => implode(', ', $returns[(int) $n->id] ?? []) ?: '—'],
        /* ⭐ সারি থেকেই ছাপা — মালিক, ৩ অক্টোবর ২০২৬ */
        ['key' => 'print', 'label' => __('core.action.print'), 'width' => '6rem',
         'render' => fn ($n) => view('accounts::note.partials.print', ['note' => $n])],
    ]);

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

    {{-- ⭐ রঙিন কার্ড নেই, তালিকা আর ডান পাশের ছাঁকনি — সব তালিকায় এক ছাঁচ ([[filter-layout]]) --}}
    <x-ui.filter-layout :filters="$filters" form="list-form" :noun="__('accounts::note_list.noun')">
    <div data-boxed data-note-list="{{ $type }}"
         class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="$title"
                          :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])"
                          :columns="$columns" :sort="$sortOptions" :facets="$filters"
                          :search-placeholder="__('accounts::note_list.search')">
                <x-slot:actions>
                    @can('accounts.note.manage')
                        <x-ui.button tone="primary" icon="plus" :href="route('accounts.note.'.$type.'.create')" data-note-new>
                            {{ __('accounts::note.new_'.$type) }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <x-ui.table :rows="$rows" :columns="$columns"
                    :empty="$q ? __('core.empty.no_results') : __('accounts::note.none_yet')"
                    :grand="$grand ?? []"
                    :compact="\App\Core\Support\Ui::compact()"
                    :view-url="fn ($n) => route('accounts.note.show', $n)" />

        <x-ui.pager :rows="$rows" />

        {{-- ⛔ মোট কেবল পাকা নোটের — খসড়া আর অপেক্ষমাণ আলাদা লাইনে ([[CollectionController]]-এর একই ছাঁচ) --}}
        <x-ui.list-totals :totals="[
            ['value' => __('core.list.rows', ['count' => number_format($totals['rows'])])],
            ['label' => __('accounts::note_list.total_posted'), 'value' => Money::format($totals['money'])],
            ['label' => __('accounts::note_list.total_waiting'), 'value' => $countMoney($totals['waiting'])],
            ['label' => __('accounts::note_list.total_drafts'), 'value' => $countMoney($totals['drafts'])],
        ]" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
