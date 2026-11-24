{{--
    বীমা — মূলধনের পাতার ধাঁচে: শিরোনাম · বর্ণনা · [+ নতুন] → ট্যাব → তালিকা।

    ⭐ উপরে সতর্কবার্তা, যখন কোনো পলিসির নবায়ন ৩০ দিনের মধ্যে বা মেয়াদ
    পেরিয়ে গেছে — ট্যাবে না গিয়েও চোখে পড়ে। ⚠️ ট্যাবে লুকিয়ে রাখলে
    ঠিক যে মানুষটার দেখা দরকার তিনি ট্যাবটা খুলতেন না।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Finance\Models\InsurancePremium;

    $tabs = [
        'all' => __('finance::insurance.tab_all'),
        'due' => __('finance::insurance.tab_due'),
        'unpaid' => __('finance::insurance.tab_unpaid'),
        'inactive' => __('finance::insurance.tab_inactive'),
    ];

    $columns = [
        ['key' => 'policy_no', 'label' => __('finance::insurance.policy_no'), 'width' => '9rem',
         'render' => fn ($p) => view('finance::insurance.partials.policy-link', ['policy' => $p])],
        ['key' => 'subject', 'label' => __('finance::insurance.subject'),
         'render' => fn ($p) => $p->subject],
        ['key' => 'covers', 'label' => __('finance::insurance.covers'), 'width' => '8rem',
         'render' => fn ($p) => __('finance::insurance.covers_'.$p->covers)],
        /* ⭐ বিমাকারীর নাম তার নিজের পাতায় যায় — মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬:
           *"সব জায়গায় হাইপার লিংক দেওয়ার কথা"*। ⓘ পলিসি দেখে পরের প্রশ্নটা
           প্রায় সবসময় "ঐ ব্যাংকে আর কী কী আছে" — উত্তরটা ঐ পাতাতেই। */
        ['key' => 'insurer', 'label' => __('finance::insurance.insurer'), 'width' => '12rem',
         'render' => fn ($p) => view('finance::institution.partials.link', [
             'id' => $p->institution_id, 'label' => $p->institution?->label() ?? '—',
         ])],
        ['key' => 'sum_insured', 'total' => 'money', 'label' => __('finance::insurance.sum_insured'), 'numeric' => true, 'width' => '9rem',
         'render' => fn ($p) => Money::format($p->sum_insured)],
        ['key' => 'premium', 'total' => 'money', 'label' => __('finance::insurance.premium'), 'numeric' => true, 'width' => '8rem',
         'render' => fn ($p) => Money::format($p->premium)],
        ['key' => 'ends_on', 'label' => __('finance::insurance.ends_on'), 'width' => '9rem',
         'render' => fn ($p) => view('finance::insurance.partials.renewal', ['policy' => $p])],
        ['key' => 'unpaid', 'label' => __('finance::insurance.premiums'), 'width' => '8rem',
         'render' => fn ($p) => $p->premiums->contains(fn ($x) => $x->status === InsurancePremium::DRAFT)
             ? view('finance::insurance.partials.unpaid', ['policy' => $p])
             : '—'],
        ['key' => 'actions', 'label' => __('core.table.action_menu'), 'width' => '8rem',
         'render' => fn ($p) => view('finance::insurance.partials.row-actions', ['policy' => $p])],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে (মালিক, ২৪ নভেম্বর ২০২৬: সব তালিকায় একই মাথা আর একই ছাঁকনি; [[Facets]])
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('covers', __('finance::insurance.covers'), collect(\App\Modules\Finance\Models\InsurancePolicy::COVERS)->mapWithKeys(fn ($v) => [$v => __('finance::insurance.covers_'.$v)])->all()),
        \App\Core\ListFilter\Facets::dates(__('finance::insurance.ends_on'), bar: false),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::insurance.title') }}</x-slot:title>

    {{-- ⛔ কেবল ABOS Smart, Navy, Blue-এ — বাকি রূপ হুবহু আগের মতো ([[AbosHead]]) --}}
    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('finance::insurance.title')"
                          :subtitle="__('finance::insurance.subtitle')">
            <x-slot:actions>
                {{-- ⭐ প্রিমিয়ামের সূচি — সব পলিসির কিস্তি, দিন আর অবস্থা (অর্থ-মডিউলের পরিকল্পনা ৬.২) --}}
                <x-ui.button tone="secondary" icon="calendar" data-premium-schedule
                             :href="route('finance.report.show', ['slug' => 'insurance-premiums'])">
                    {{ __('finance::insurance.premium_schedule') }}
                </x-ui.button>
                @can('finance.insurance.manage')
                    <x-ui.button tone="primary" icon="plus" :href="route('finance.insurance.create')">
                        {{ __('finance::insurance.new') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>
    @endunless

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    {{-- ⓘ ট্যাব ABOS রূপে মাথার সারিতে নামের পাশে বসে ([[compactPageHead]]) --}}
    @if ($abosHead)
        <x-ui.list-tabs :label="__('finance::insurance.title')" :tabs="collect($tabs)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'url' => route('finance.insurance.index', $key === 'all' ? [] : ['tab' => $key]), 'active' => $tab === $key, 'count' => $counts[$key] ?? 0])->values()->all()" />
    @endif

    @unless ($abosHead)
        @include('finance::insurance.partials.above-list')
    @endunless

    @unless ($abosHead)
    <nav class="mb-3 flex flex-wrap gap-1 border-b border-(--color-border) text-sm"
         aria-label="{{ __('finance::insurance.title') }}">
        @foreach ($tabs as $key => $label)
            @php $on = $tab === $key; @endphp
            <a href="{{ route('finance.insurance.index', $key === 'all' ? [] : ['tab' => $key]) }}"
               @if ($on) aria-current="page" @endif
               class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                      {{ $on
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

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        {{-- ⭐ সাধারণ টুলবার (মালিক, ৩ অক্টোবর ২০২৬: "sob jaygay toolbar dibe") — ট্যাবটা লুকানো ঘরে --}}
        <form id="list-form" method="GET" class="contents">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <x-ui.toolbar :title="__('finance::insurance.title')" :info="$abosHead ? __('finance::insurance.subtitle').' '.__('finance::insurance.prepaid_note') : null"
                          :columns="$columns" :search-placeholder="__('finance::insurance.policy_no')" :quiet="['tab']" :facets="$facetPanel"
                          :count="trans_choice('core.count.records', $policies->total(), ['count' => $policies->total()])">
                @if ($abosHead)
                <x-slot:actions>
                    {{-- ⭐ প্রিমিয়ামের সূচি — সব পলিসির কিস্তি, দিন আর অবস্থা (অর্থ-মডিউলের পরিকল্পনা ৬.২) --}}
                    <x-ui.button tone="secondary" icon="calendar" data-premium-schedule
                                 :href="route('finance.report.show', ['slug' => 'insurance-premiums'])">
                        {{ __('finance::insurance.premium_schedule') }}
                    </x-ui.button>
                    @can('finance.insurance.manage')
                        <x-ui.button tone="primary" icon="plus" :href="route('finance.insurance.create')">
                            {{ __('finance::insurance.new') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>
                @endif
            </x-ui.toolbar>
        </form>

        @if ($abosHead)
            <div class="px-3 pt-3">
                @include('finance::insurance.partials.above-list')
            </div>
        @endif

        <x-ui.table :rows="$policies" :columns="$columns"
                    :grand="$grand ?? []"
                    :empty="__('finance::insurance.none_yet')" />

        <x-ui.pager :rows="$policies" />
        <x-ui.list-totals :rows="$policies" :grand="$grand ?? []" :columns="$columns" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
