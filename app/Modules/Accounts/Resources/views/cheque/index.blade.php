{{--
    চেকের খাতা।

    উপরে দুইটা সংখ্যা: কত টাকার চেক এখনো ঝুলছে, আর তার মধ্যে কয়টার
    তারিখ পেরিয়ে গেছে। দ্বিতীয়টাই রোজ সকালে দেখার জিনিস — আগাম
    তারিখের চেক ফেলে রাখা স্বাভাবিক, তারিখ পেরোনোর পরেও ফেলে রাখা নয়।
--}}
@php
    /* কলাম ধরে — `x-ui.table` স্লট পড়ে না, সারি আসে :rows থেকে। */
    $columns = [
        ['key' => 'cheque_no', 'label' => __('accounts::field.cheque_no'),
         'render' => fn ($c) => view('accounts::cheque.partials.no', ['cheque' => $c])],
        ['key' => 'cheque_date', 'label' => __('accounts::field.cheque_date'), 'width' => '9rem',
         'render' => fn ($c) => view('accounts::cheque.partials.date', ['cheque' => $c])],
        ['key' => 'bank_name', 'label' => __('accounts::field.bank_name'),
         'render' => fn ($c) => $c->bank_name ?: '—'],
        ['key' => 'amount', 'label' => __('accounts::field.amount'),
         'numeric' => true, 'width' => '11rem',
         'render' => fn ($c) => \App\Core\Support\Money::format($c->amount)],
        ['key' => 'state', 'label' => __('accounts::field.state'), 'width' => '11rem',
         'render' => fn ($c) => view('accounts::cheque.partials.state', ['cheque' => $c])],
        ['key' => 'actions', 'label' => __('core.table.actions'),
         'render' => fn ($c) => view('accounts::cheque.partials.actions',
             ['cheque' => $c, 'banks' => $banks])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.cheques') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.cheques')"
                          :subtitle="__('accounts::message.cheque_note')">
            {{-- বসানোর পথটা এখন উপরে, বিক্রয় বিলের মতোই — শর্তটা হুবহু
                 সেটাই যেটায় আগে নিচের ফর্মটা দেখা যেত। --}}
            <x-slot:actions>
                @can('accounts.cheque.manage')
                    <x-ui.button tone="primary" icon="plus" :href="route('accounts.cheque.create')">
                        {{ __('accounts::action.new_cheque') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

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
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- দুইটা সংখ্যাই ক্লিকযোগ্য — চাপলে ঠিক ওই চেকগুলোর তালিকা (মালিকের
         নিয়ম: প্রতিটা সংখ্যা থেকে উৎসে যাওয়া যাবে)। --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <a href="{{ route('accounts.cheque.index', ['filter' => 'open']) }}"
           data-boxed class="block rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) px-4 py-3 transition hover:border-(--color-brand-600)">
            <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                {{ __('accounts::field.cheques_open_total') }}
            </p>
            <p class="num text-2xl font-semibold">{{ \App\Core\Support\Money::format($openTotal) }}</p>
        </a>

        <a href="{{ route('accounts.cheque.index', ['filter' => 'ripe']) }}" @class([
            'block rounded-(--radius-card) border px-4 py-3 transition hover:border-(--color-brand-600)',
            'border-(--color-border) bg-(--color-surface-card)' => $ripe === 0,
            'border-(--color-badge-danger-ink)/30 bg-(--color-badge-danger-bg)' => $ripe > 0,
        ])>
            <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                {{ __('accounts::field.cheques_ripe') }}
            </p>
            <p class="num text-2xl font-semibold">{{ $ripe }}</p>
        </a>
    </div>

    @if ($cheques->isEmpty())
        <x-ui.empty-state :message="__('accounts::message.no_cheques')" />
    @else
        <div data-boxed class="overflow-x-auto rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <x-ui.table :rows="$cheques"
                    :columns="$columns"
                    :empty="__('accounts::message.no_cheques')" />
        </div>

        <div class="mt-3">{{ $cheques->links() }}</div>
    @endif
</x-layouts.app>
