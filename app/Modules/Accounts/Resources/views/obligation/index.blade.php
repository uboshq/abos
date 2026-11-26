{{--
    ⭐ করণীয় — আইনের দায়, শেষ তারিখসহ (টাকা দেওয়ার মিল-তালিকা #৩; fe, ২২ অক্টোবর ২০২৬)।

    কাটা কর আর ভ্যাট কোষাগারে জমা, আর সরবরাহকারীকে মূসক-৬.৬ — কোন কাগজ থেকে, কত, কবের মধ্যে। সময় পেরোলে লাল। "করা হয়েছে"
    চালান বা সনদের নম্বরেই — নম্বরটাই প্রমাণ, আর নিরীক্ষায় ওঠে।
--}}
@php
    $drill = app(\App\Core\Engines\Drill\DrillResolver::class);

    $columns = [
        [
            'key' => 'kind',
            'label' => __('accounts::obligation.col.kind'),
            'render' => fn ($o) => __('accounts::obligation.kind.'.$o->kind.($o->subject !== '' ? '_'.$o->subject : '')),
        ],
        [
            'key' => 'source',
            'label' => __('accounts::obligation.col.source'),
            'render' => fn ($o) => view('accounts::obligation.partials.source', ['o' => $o, 'd' => $drill->describe($o->source_type, $o->source_id)]),
        ],
        [
            'key' => 'amount',
            'label' => __('accounts::obligation.col.amount'),
            'numeric' => true,
            'total' => 'money',
            'width' => '9rem',
            'render' => fn ($o) => \App\Core\Support\Money::format((string) $o->amount),
        ],
        [
            'key' => 'due_on',
            'label' => __('accounts::obligation.col.due_on'),
            'width' => '9rem',
            'render' => fn ($o) => view('accounts::obligation.partials.due', ['o' => $o]),
        ],
        [
            'key' => 'actions',
            'label' => __('core.table.actions'),
            'render' => fn ($o) => view('accounts::obligation.partials.actions', ['o' => $o]),
        ],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে (মালিক, ২৪ নভেম্বর ২০২৬: সব তালিকায় একই মাথা আর একই ছাঁকনি; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::dates(__('accounts::obligation.col.due_on'), bar: false),
        \App\Core\ListFilter\Facets::pick('kind', __('accounts::obligation.col.kind'), collect(['kosagar_deposit_ait', 'kosagar_deposit_vds', 'mushak_6_6_issue_vds'])->mapWithKeys(fn ($k) => [$k => __('accounts::obligation.kind.'.$k)])->all()),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.obligations') }}</x-slot:title>

    {{-- ⛔ কেবল ABOS Smart, Navy, Blue-এ — বাকি রূপ হুবহু আগের মতো ([[AbosHead]]) --}}
    @unless (\App\Core\Support\AbosHead::on())
    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.obligations')"
                          :subtitle="__('accounts::obligation.subtitle')" />
    </x-slot:header>
    @endunless

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <x-ui.list-tabs :tabs="$tabs" :label="__('accounts::menu.obligations')" />

    {{-- ⓘ তালিকার মান: টুলবার (খোঁজা), সর্বমোট আর যোগফলের পট্টি — অন্য তালিকার মতোই --}}
    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed data-obligations class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <x-ui.toolbar :sort="\App\Core\ListFilter\ListSort::labels(['due_soon', 'newest', 'oldest'])" :title="__('accounts::menu.obligations')"
                          :info="\App\Core\Support\AbosHead::on() ? __('accounts::obligation.subtitle') : null"
                          :search-placeholder="__('accounts::obligation.search')"
                          :columns="$columns" :facets="$facetPanel" :quiet="['tab']" />
        </form>

        <x-ui.table :rows="$rows"
                    :grand="$grand ?? []"
                    :columns="$columns"
                    :empty="$q !== '' || filled(request('kind')) || filled(request('from')) || filled(request('to')) ? __('core.empty.no_results') : __('accounts::obligation.empty.'.$tab)" />
    </div>

    <div class="mt-3">{{ $rows->links() }}</div>
    <x-ui.list-totals :rows="$rows" :grand="$grand ?? []" :columns="$columns" />
    </x-ui.filter-layout>
</x-layouts.app>
