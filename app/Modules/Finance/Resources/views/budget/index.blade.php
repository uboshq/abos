{{--
    বাজেট পরিকল্পনা — এক বছরের, খাত (আর বিভাগ) ধরে বারো মাস পাশাপাশি।
    ফিন্যান্সের মানচিত্র §১৬, ২০ সেপ্টেম্বর ২০২৬।

    ⓘ বারো মাস এক সারিতে, কারণ বাজেট মানুষ এভাবেই ভাবেন — "ভাড়া মাসে
    ৫০ হাজার, ঈদের মাসে বোনাসে বেশি"। সম্পাদনাও তাই পুরো বছরটা একসাথে।
--}}
@php
    $money = fn ($v) => bccomp((string) $v, '0', 4) === 0 ? '—' : \App\Core\Support\Money::format($v);

    $columns = [
        ['key' => 'account', 'label' => __('finance::budget.account'),
         'render' => fn ($r) => view('finance::budget.partials.account-link', [
             'account' => $r['account'], 'center' => $r['center'],
         ])],
    ];

    foreach (range(1, 12) as $m) {
        $columns[] = ['key' => 'm'.$m, 'label' => __('finance::budget.month_short.'.$m), 'numeric' => true,
                      'total' => 'money', 'raw' => fn ($r) => $r['months'][$m],
                      'render' => fn ($r) => $money($r['months'][$m])];
    }

    $columns[] = ['key' => 'total', 'total' => 'money', 'label' => __('finance::budget.total'), 'numeric' => true,
                  'render' => fn ($r) => \App\Core\Support\Money::format($r['total'])];

    if (auth()->user()?->can('finance.budget.create')) {
        $columns[] = ['key' => 'edit', 'label' => '', 'width' => '6rem',
                      'render' => fn ($r) => view('finance::budget.partials.edit-link', ['row' => $r, 'year' => $year])];
    }

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের দুই ঘর ([[budget/partials/filters]]), একই চাবি; বছরটা পাতা যা ধরে চলেছে তাই দেখায়
    //   (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::number('year', __('finance::budget.year'), 2000, 2100, shown: (string) $year),
        \App\Core\ListFilter\Facets::pick('center', __('finance::budget.center'),
            $centers->mapWithKeys(fn ($c) => [(string) $c->id => $c->code.' — '.$c->name()])->all(), __('finance::budget.all_centers')),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::budget.title') }}</x-slot:title>

    @if (session('saved'))
        <p role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                               text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    @include('finance::budget.partials.tabs')
    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('finance::budget.title')" :info="__('finance::budget.note')" :count="__('finance::budget.plan_for', ['year' => $year])"
                          :search="false" :columns="$columns" :facets="$facetPanel">
                <x-slot:actions>
                    @include('finance::budget.partials.actions')
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <div class="overflow-x-auto">
            <x-ui.table :compact="true" :empty="__('finance::budget.no_plan')" :rows="$plan" :columns="$columns"
                        :grand="$planGrand ?? []"
                        :view-url="fn ($r) => route('accounts.coa.show', $r['account']).'#transactions'" />
        </div>

        <x-ui.pager :rows="$plan" />
        <x-ui.list-totals :rows="$plan" :grand="$planGrand ?? []" :columns="$columns" />
    </section>
    </x-ui.filter-layout>
</x-layouts.app>
