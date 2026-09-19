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

    {{--
        ⭐ তালিকা এখন টুলবারের নিচে, একই বাক্সে — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর
        ২০২৬: *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"*। শিরোনাম, বর্ণনা আর
        "নতুন চেক" ১ম লাইনে; খোঁজা-সাজানো-কলাম ২য় লাইনে।

        ⓘ খালি তালিকার আলাদা empty-state আর নেই — টেবিল নিজেই খালি লেখা
        দেখায়, আর তাতে টুলবারটা থাকে, তাই খোঁজা বা ছাঁকনি তুলে ফেরা যায়।
    --}}
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            {{-- উপরের দুই সংখ্যার লিংকের ছাঁকনি (open/ripe) — খুঁজলেও যেন
                 হারিয়ে না যায়; চিপ থেকে তুলে দিলে এটাও খালি আসে। --}}
            @if (in_array(request('filter'), ['open', 'ripe'], true))
                <input type="hidden" name="filter" value="{{ request('filter') }}">
            @endif

            <x-ui.toolbar :title="__('accounts::menu.cheques')"
                :subtitle="__('accounts::message.cheque_note')"
                :search-placeholder="__('accounts::message.cheque_search')"
                :sort="$sortOptions"
                :columns="$columns"
                :filter-labels="['status' => __('accounts::field.state'), 'direction' => __('accounts::field.cheque_direction')]">
                {{-- বসানোর পথটা উপরে, বিক্রয় বিলের মতোই — শর্তটা হুবহু
                     সেটাই যেটায় আগে নিচের ফর্মটা দেখা যেত। --}}
                <x-slot:actions>
                    @can('accounts.cheque.manage')
                        <x-ui.button tone="primary" icon="plus" :href="route('accounts.cheque.create')">
                            {{ __('accounts::action.new_cheque') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

                {{-- নিয়ামক আগে থেকেই `status` ও `direction` মানত, কিন্তু
                     পর্দায় বাছার জায়গা ছিল না — কেবল ঠিকানায় লিখে। --}}
                <select name="direction" aria-label="{{ __('accounts::field.cheque_direction') }}"
                        class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 text-sm">
                    <option value="">{{ __('accounts::field.cheque_direction') }}</option>
                    @foreach ([\App\Modules\Accounts\Models\Cheque::RECEIVED, \App\Modules\Accounts\Models\Cheque::ISSUED] as $d)
                        <option value="{{ $d }}" @selected(request('direction') === $d)>{{ __('accounts::field.cheque_'.$d) }}</option>
                    @endforeach
                </select>

                <select name="status" aria-label="{{ __('accounts::field.state') }}"
                        class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 text-sm">
                    <option value="">{{ __('accounts::field.state') }}</option>
                    @foreach ([
                        \App\Modules\Accounts\Models\Cheque::PENDING,
                        \App\Modules\Accounts\Models\Cheque::DEPOSITED,
                        \App\Modules\Accounts\Models\Cheque::CLEARED,
                        \App\Modules\Accounts\Models\Cheque::BOUNCED,
                        \App\Modules\Accounts\Models\Cheque::CANCELLED,
                    ] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ __('accounts::field.cheque_'.$s) }}</option>
                    @endforeach
                </select>
            </x-ui.toolbar>
        </form>

        <x-ui.table :rows="$cheques"
                    :columns="$columns"
                    :compact="request()->boolean('compact')"
                    :empty="$q ? __('core.empty.no_results') : __('accounts::message.no_cheques')" />
    </div>

    <div class="mt-3">{{ $cheques->links() }}</div>
</x-layouts.app>
