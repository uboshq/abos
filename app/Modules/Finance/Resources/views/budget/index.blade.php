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
                      'render' => fn ($r) => $money($r['months'][$m])];
    }

    $columns[] = ['key' => 'total', 'label' => __('finance::budget.total'), 'numeric' => true,
                  'render' => fn ($r) => \App\Core\Support\Money::format($r['total'])];

    if (auth()->user()?->can('finance.budget.create')) {
        $columns[] = ['key' => 'edit', 'label' => '', 'width' => '6rem',
                      'render' => fn ($r) => view('finance::budget.partials.edit-link', ['row' => $r, 'year' => $year])];
    }
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::budget.title') }}</x-slot:title>

    <x-slot:header>
        @include('finance::budget.partials.header')
    </x-slot:header>

    @if (session('saved'))
        <p role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                               text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    @include('finance::budget.partials.tabs')
    @include('finance::budget.partials.filters')

    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('finance::budget.plan_for', ['year' => $year]) }}
        </h2>

        <div class="overflow-x-auto">
            <x-ui.table :compact="true" :empty="__('finance::budget.no_plan')" :rows="$plan" :columns="$columns" />
        </div>

        <x-ui.pager :rows="$plan" />
    </section>
</x-layouts.app>
