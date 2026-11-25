{{--
    ⭐ চূড়ান্ত পাওনা — কাগজের তালিকা আর নতুন কাগজের বাছাই (মালিক, ২১ অক্টোবর ২০২৬; [[FinalSettlementController]])।
    ⓘ ব্যাখ্যা নামের পাশে ⓘ-এ (page-header-এর subtitle) — "ABOS"/"Dynamic"-এ লাইন নয়।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $columns = [
        ['key' => 'employee', 'label' => __('hr::settlement.employee'),
         'render' => fn ($r) => new \Illuminate\Support\HtmlString('<a class="underline" href="'.e(route('hr.final_settlement.show', $r)).'">'.e($r->employee?->label() ?? '—').'</a>')],
        ['key' => 'last_day', 'label' => __('hr::settlement.last_day'), 'width' => '8rem', 'render' => fn ($r) => \App\Core\Support\DateFormat::format($r->last_day)],
        ['key' => 'separation', 'label' => __('hr::settlement.separation'), 'width' => '9rem', 'render' => fn ($r) => __('hr::settlement.separations.'.$r->separation)],
        ['key' => 'net', 'total' => 'money', 'label' => __('hr::settlement.net'), 'numeric' => true, 'width' => '10rem', 'render' => fn ($r) => $money($r->net)],
        ['key' => 'status', 'label' => __('hr::settlement.status'), 'width' => '10rem', 'render' => fn ($r) => __('hr::settlement.statuses.'.$r->status)],
    ];
    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('status', __('hr::settlement.status'), __('hr::settlement.statuses')),
        \App\Core\ListFilter\Facets::pick('separation', __('hr::settlement.separation'), __('hr::settlement.separations')),
        \App\Core\ListFilter\Facets::dates(__('hr::settlement.last_day')),
    ]);
    $mayStart = auth()->user()?->can('hr.final_settlement.manage') && $leavers->isNotEmpty();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::settlement.title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('hr::settlement.title')" :subtitle="__('hr::settlement.info')" />
    </x-slot:header>
    @endunless

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($mayStart && ! $abosHead)
            <form method="GET" action="{{ route('hr.final_settlement.preview') }}" data-boxed data-settlement-leavers
                  class="mb-4 flex flex-wrap items-end gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <x-ui.select name="employee" :label="__('hr::settlement.leavers')"
                             :options="$leavers->mapWithKeys(fn ($e) => [$e->id => $e->label().' · '.\App\Core\Support\DateFormat::format($e->leaving_date)])" />
                <x-ui.button type="submit" tone="primary">{{ __('hr::settlement.preview') }}</x-ui.button>
            </form>
    @endif

    @if ($mayStart && $abosHead)
        {{-- ⓘ ব্রাউজারের নিজের popover, কোনো স্ক্রিপ্ট নয় --}}
        <div id="pop-settlement" popover="auto" role="dialog" aria-labelledby="pop-settlement-title" data-settlement-leavers
             class="fixed inset-0 m-auto h-fit max-h-[90vh] w-[32rem] max-w-[92vw] overflow-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-5 text-(--color-ink) shadow-lg">
            <h2 id="pop-settlement-title" class="mb-3 text-base font-semibold">{{ __('hr::settlement.new') }}</h2>
            <form method="GET" action="{{ route('hr.final_settlement.preview') }}" class="space-y-3">
                <x-ui.select name="employee" :label="__('hr::settlement.leavers')"
                             :options="$leavers->mapWithKeys(fn ($e) => [$e->id => $e->label().' · '.\App\Core\Support\DateFormat::format($e->leaving_date)])" />
                <div class="flex justify-end"><x-ui.button type="submit" tone="primary">{{ __('hr::settlement.preview') }}</x-ui.button></div>
            </form>
        </div>
    @endif

    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('hr::settlement.title')" :info="$abosHead ? __('hr::settlement.info') : null" :columns="$columns" :sort="$sortOptions"
                          :facets="$facetPanel" :search-placeholder="__('hr::settlement.employee')">
                @if ($mayStart && $abosHead)
                    <x-slot:actions>
                        <x-ui.button type="button" tone="primary" icon="plus" popovertarget="pop-settlement">{{ __('hr::settlement.new') }}</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.toolbar>
        </form>
        <x-ui.table :rows="$rows" :columns="$columns" :grand="$grand" :compact="\App\Core\Support\Ui::compact()"
                    :view-url="fn ($r) => route('hr.final_settlement.show', $r)" :empty="request()->except(['page', 'per_page']) !== [] ? __('core.empty.no_results') : __('core.empty.nothing_here')" />
        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" :grand="$grand" :columns="$columns" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
