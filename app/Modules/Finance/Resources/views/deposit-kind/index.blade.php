{{--
    জমার ধরনের তালিকা — FDR · DPS · সঞ্চয়পত্র · বন্ড।

    ── ⭐ অর্থের মানচিত্র §১৪ক, ২০ সেপ্টেম্বর ২০২৬ ───────────────────────
    ⛔ ধরনগুলো ডিপ্লয়ে বসত আর তারপর আর বদলানো যেত না। ⚠️ ব্যাংক প্রতি
    বছর নতুন স্কিম আনে — তখন ব্যবহারকারীর হাতে কোনো পথ ছিল না।
--}}
@php
    $columns = [
        ['key' => 'code', 'label' => __('finance::field.kind_code'), 'width' => '8rem',
         'render' => fn ($k) => view('finance::deposit-kind.partials.name-link', [
             'kind' => $k, 'text' => $k->code,
         ])],

        ['key' => 'name', 'label' => __('finance::field.kind_name'),
         'render' => fn ($k) => view('finance::deposit-kind.partials.name-link', [
             'kind' => $k, 'text' => $k->name(),
         ])],

        ['key' => 'issuer', 'label' => __('finance::field.kind_issuer'), 'width' => '11rem',
         'render' => fn ($k) => __('finance::menu.deposit_'.($k->issuer === 'national_savings' ? 'savings' : $k->issuer))],

        /* ⓘ ছাঁদটাই ঠিক করে ফর্মে কোন ঘর খোলে — কিস্তি, না মুনাফা তোলার খাত */
        ['key' => 'shape', 'label' => __('finance::field.kind_shape'), 'width' => '12rem',
         'render' => fn ($k) => __('finance::field.shape_'.$k->shape)],

        /*
         * ⭐ সংখ্যাটা ক্লিকযোগ্য — মালিকের নির্দেশ, ২০ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ নামে "সব জমা"র পাতায়, এই ধরনে ছাঁকা। ⚠️ ইস্যুকারীর নিজের
         * পাতায় নয়: ওটা অবস্থার ট্যাবে ছাঁকা থাকে, আর এই সংখ্যাটা সব
         * অবস্থার — দুইটা আলাদা কথা বলত।
         *
         * ⓘ শূন্য হলে লিংক নয়: ফাঁকা তালিকায় নামানোর চেয়ে চুপ থাকা ভালো।
         */
        ['key' => 'used', 'label' => __('finance::field.kind_used'), 'numeric' => true, 'width' => '8rem',
         'render' => fn ($k) => view('finance::deposit-kind.partials.used', ['kind' => $k])],

        ['key' => 'state', 'label' => __('finance::field.state'), 'width' => '8rem',
         'render' => fn ($k) => view('finance::deposit-kind.partials.state', ['kind' => $k])],

        ['key' => 'do', 'label' => __('core.table.action_menu'), 'width' => '8rem',
         'render' => fn ($k) => view('finance::deposit-kind.partials.actions', ['kind' => $k])],
    ];

    $tabs = ['all' => __('finance::field.hl_tab_all')];

    foreach (\App\Modules\Finance\Models\DepositKind::ISSUERS as $each) {
        $tabs[$each] = __('finance::menu.deposit_'.($each === 'national_savings' ? 'savings' : $each));
    }

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে (মালিক, ২৪ নভেম্বর ২০২৬: সব তালিকায় একই মাথা আর একই ছাঁকনি; [[Facets]])
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('shape', __('finance::field.kind_shape'), collect(\App\Modules\Finance\Models\DepositKind::SHAPES)->mapWithKeys(fn ($v) => [$v => __('finance::field.shape_'.$v)])->all()),
        \App\Core\ListFilter\Facets::pick('state', __('finance::field.state'), ['active' => __('finance::institution.active'), 'inactive' => __('finance::institution.inactive')]),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.deposit_kinds') }}</x-slot:title>

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <x-ui.errors />

    {{-- ⓘ ট্যাব ABOS রূপে মাথার সারিতে নামের পাশে বসে ([[compactPageHead]]) --}}
    {{-- ⛔ কেবল ABOS Smart, Navy, Blue-এ — বাকি রূপ হুবহু আগের মতো ([[AbosHead]]) --}}
    @if ($abosHead)
        <x-ui.list-tabs :label="__('finance::menu.deposit_kinds')" :tabs="collect($tabs)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'url' => route('finance.deposit_kind.index', array_filter(['tab' => $key === 'all' ? null : $key, 'q' => request('q')])), 'active' => $tab === $key, 'count' => $counts[$key] ?? 0])->values()->all()" />
    @endif

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            @if ($tab !== 'all')
                <input type="hidden" name="tab" value="{{ $tab }}">
            @endif

            <x-ui.toolbar :title="__('finance::menu.deposit_kinds')" :sort="\App\Core\ListFilter\ListSort::labels(['code', 'name_az', 'newest'])"
                          :subtitle="__('finance::message.kind_note')"
                          :columns="$columns"
                          :search-placeholder="__('finance::message.kind_search')"
                          :facets="$facetPanel" :quiet="['tab']">
                <x-slot:actions>
                    @can('finance.deposit_kind.manage')
                        <x-ui.button tone="primary" icon="plus" :href="route('finance.deposit_kind.create')">
                            {{ __('finance::action.new_kind') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        @unless ($abosHead)
        <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm"
             aria-label="{{ __('finance::menu.deposit_kinds') }}">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('finance.deposit_kind.index', array_filter([
                        'tab' => $key === 'all' ? null : $key,
                        'q' => request('q'),
                    ])) }}"
                   @if ($tab === $key) aria-current="page" @endif
                   class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                          {{ $tab === $key
                              ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                              : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                    {{ $label }}
                    <span class="rounded-full bg-(--color-surface-sunken) px-2 text-2xs text-(--color-ink-muted)">
                        {{ $counts[$key] ?? 0 }}
                    </span>
                </a>
            @endforeach
        </nav>
        @endunless

        <x-ui.table :rows="$kinds"
                    :columns="$columns"
                    :compact="\App\Core\Support\Ui::compact()"
                    :empty="filled(request('q')) ? __('core.empty.no_results') : __('finance::message.no_kinds')" />
    </div>

    <div class="mt-3">{{ $kinds->links() }}</div>
    <x-ui.list-totals :rows="$kinds" />
    </x-ui.filter-layout>
</x-layouts.app>
