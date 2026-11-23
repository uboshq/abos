{{--
    বাজেট বনাম প্রকৃত — তিন পাতায় একই তুলনা:
      · actual   খাত ধরে (§১৬ "বাজেট বনাম প্রকৃত")
      · centers  খাত × বিভাগ ধরে (§১৬ "বিভাগভিত্তিক বাজেট")
      · report   গোটা তুলনা ছাপা/CSV-র জন্য, টুলবারসহ (§২৯ "বাজেটের রিপোর্ট")

    ⓘ প্রকৃত সরাসরি খতিয়ান থেকে ([[BudgetService::vsActual()]])।
    ⚠️ ফারাকের রং খাতের ধরনে: খরচে বেশি লাল, আয়ে কম লাল — একই "বেশি"
    দুই খাতে দুই অর্থ।
--}}
@php
    $isReport = $tab === 'report';
    $money = fn ($v) => \App\Core\Support\Money::format($v);

    $columns = [
        ['key' => 'account', 'label' => __('finance::budget.account'),
         'render' => fn ($r) => view('finance::budget.partials.account-link', ['account' => $r['account']])],
    ];

    if ($byCenter) {
        $columns[] = ['key' => 'center', 'label' => __('finance::budget.center'),
                      'render' => fn ($r) => $r['center']?->name() ?? __('finance::budget.no_center')];
    }

    $columns = [...$columns,
        ['key' => 'budget', 'label' => __('finance::budget.budget'), 'numeric' => true,
         'render' => fn ($r) => $money($r['budget'])],
        ['key' => 'actual', 'label' => __('finance::budget.actual'), 'numeric' => true,
         'render' => fn ($r) => $money($r['actual'])],
        ['key' => 'variance', 'label' => __('finance::budget.variance'), 'numeric' => true,
         'render' => fn ($r) => view('finance::budget.partials.variance', ['row' => $r])],
        ['key' => 'used_pct', 'label' => __('finance::budget.used'), 'numeric' => true,
         'render' => fn ($r) => $r['used_pct'] === null ? '—' : $r['used_pct'].'%'],
    ];

    $sum = fn (string $field) => $rows->reduce(fn (string $s, array $r) => bcadd($s, $r[$field], 4), '0');

    // ⓘ অর্থবছরের পরিসর দুই বছর ছোঁয় — লেবেলটা কন্ট্রোলার থেকে ([[BudgetController::comparison()]], অর্থ M29)
    $periodLabel = $fyLabel ?? ($from === $to
        ? __('finance::budget.month_long.'.$from).' '.$year
        : __('finance::budget.month_long.'.$from).' – '.__('finance::budget.month_long.'.$to).' '.$year);

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো ([[budget/partials/filters]] + [[budget/partials/period]]), একই চাবি;
    //   বছরটা পাতা যা ধরে চলেছে তাই দেখায় (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::number('year', __('finance::budget.year'), 2000, 2100, shown: (string) $year),
        \App\Core\ListFilter\Facets::pick('center', __('finance::budget.center'),
            $centers->mapWithKeys(fn ($c) => [(string) $c->id => $c->code.' — '.$c->name()])->all(), __('finance::budget.all_centers')),
        \App\Core\ListFilter\Facets::pick('month', __('finance::budget.period'),
            collect(range(1, 12))->mapWithKeys(fn ($m) => [(string) $m => __('finance::budget.month_long.'.$m)])->all(),
            __('finance::budget.scope_'.$scope)),
        $isReport ? \App\Core\ListFilter\Facets::flag('by_center', __('finance::budget.by_center')) : null,
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isReport ? __('finance::budget.report') : __('finance::budget.title') }}</x-slot:title>

    <x-slot:header>
        @include('finance::budget.partials.header')
    </x-slot:header>

    @unless ($isReport)
        @include('finance::budget.partials.tabs')
    @endunless

    <x-ui.filter-layout :filters="$facetPanel" form="list-form" :report="true">
        <form id="list-form" method="GET" class="contents" data-report>
            <input type="hidden" name="scope" value="{{ $scope }}">
            <x-ui.filter-bar :facets="$facetPanel">
                {{-- ⭐ অর্থবছর — জুলাই থেকে এ মাস, বছর পেরিয়ে (অর্থ M29, ১০ অক্টোবর ২০২৬); ছাঁকনির ঘর নয়, লিংক --}}
                <a href="{{ request()->fullUrlWithQuery(['scope' => $scope === 'fy' ? 'ytd' : 'fy', 'month' => null]) }}" data-scope-fy
                   class="text-sm {{ $scope === 'fy' ? 'font-semibold text-(--color-ink)' : 'text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                    {{ __('finance::budget.scope_fy') }}{{ $scope === 'fy' ? ' ✕' : '' }}
                </a>
            </x-ui.filter-bar>
        </form>
    </x-ui.filter-layout>

    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        @if ($isReport)
            {{-- ⓘ টুলবার: ছাপা আর CSV — রিপোর্টের কাজটাই এটা --}}
            {{-- ⭐ প্রিন্ট ভিউ বারেই, নতুন ট্যাবে; কাগজের মাথায় সময়কাল (মালিক, ২৩ নভেম্বর ২০২৬; [[ReportPaper]]) --}}
            @php \App\Core\Support\ReportPaper::describe((string) __('finance::budget.report'), (string) $periodLabel); @endphp
            <x-ui.toolbar :title="__('finance::budget.report')" :subtitle="$periodLabel" :columns="$columns" :report="true"
                          :search="false" :filter="false" :share="false" :print-view="true"
                          :quiet="['year', 'center', 'month', 'scope', 'by_center']" />
        @else
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ $periodLabel }}
            </h2>
        @endif

        <x-ui.table :compact="true" :empty="__('finance::budget.no_plan_for_period')" :rows="$rows" :columns="$columns" />

        @if ($rows->isNotEmpty())
            <dl class="flex flex-wrap gap-x-8 gap-y-2 border-t border-(--color-border) px-4 py-3 text-sm">
                <div><dt class="text-(--color-ink-muted)">{{ __('finance::budget.budget') }}</dt>
                    <dd class="num font-semibold">{{ $money($sum('budget')) }}</dd></div>
                <div><dt class="text-(--color-ink-muted)">{{ __('finance::budget.actual') }}</dt>
                    <dd class="num font-semibold">{{ $money($sum('actual')) }}</dd></div>
            </dl>
        @endif
    </section>
</x-layouts.app>
