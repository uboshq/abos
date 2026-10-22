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
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.obligations') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.obligations')"
                          :subtitle="__('accounts::obligation.subtitle')" />
    </x-slot:header>

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
    <div data-boxed data-obligations class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <x-ui.toolbar :title="__('accounts::menu.obligations')"
                          :search-placeholder="__('accounts::obligation.search')"
                          :columns="$columns" />
        </form>

        <x-ui.table :rows="$rows"
                    :grand="$grand ?? []"
                    :columns="$columns"
                    :empty="$q ? __('core.empty.no_results') : __('accounts::obligation.empty.'.$tab)" />
    </div>

    <div class="mt-3">{{ $rows->links() }}</div>
    <x-ui.list-totals :rows="$rows" :grand="$grand ?? []" :columns="$columns" />
</x-layouts.app>
