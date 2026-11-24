{{--
    ⭐ চূড়ান্ত পাওনা — কাগজের তালিকা আর নতুন কাগজের বাছাই (মালিক, ২১ অক্টোবর ২০২৬; [[FinalSettlementController]])।
    ⓘ ব্যাখ্যা নামের পাশে ⓘ-এ (page-header-এর subtitle) — "ABOS"/"Dynamic"-এ লাইন নয়।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $columns = [
        ['key' => 'employee', 'label' => __('hr::settlement.employee'),
         'render' => fn ($r) => new \Illuminate\Support\HtmlString('<a class="underline" href="'.e(route('hr.final_settlement.show', $r)).'">'.e($r->employee?->label() ?? '—').'</a>')],
        ['key' => 'last_day', 'label' => __('hr::settlement.last_day'), 'width' => '8rem', 'render' => fn ($r) => $r->last_day->format('d M Y')],
        ['key' => 'separation', 'label' => __('hr::settlement.separation'), 'width' => '9rem', 'render' => fn ($r) => __('hr::settlement.separations.'.$r->separation)],
        ['key' => 'net', 'total' => 'money', 'label' => __('hr::settlement.net'), 'numeric' => true, 'width' => '10rem', 'render' => fn ($r) => $money($r->net)],
        ['key' => 'status', 'label' => __('hr::settlement.status'), 'width' => '10rem', 'render' => fn ($r) => __('hr::settlement.statuses.'.$r->status)],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::settlement.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::settlement.title')" :subtitle="__('hr::settlement.info')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @can('hr.final_settlement.manage')
        @if ($leavers->isNotEmpty())
            <form method="GET" action="{{ route('hr.final_settlement.preview') }}" data-boxed data-settlement-leavers
                  class="mb-4 flex flex-wrap items-end gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <x-ui.select name="employee" :label="__('hr::settlement.leavers')"
                             :options="$leavers->mapWithKeys(fn ($e) => [$e->id => $e->label().' · '.$e->leaving_date->format('d M Y')])" />
                <x-ui.button type="submit" tone="primary">{{ __('hr::settlement.preview') }}</x-ui.button>
            </form>
        @endif
    @endcan

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('hr::settlement.title')" :columns="$columns" :sort="$sortOptions"
                          :search-placeholder="__('hr::settlement.employee')" />
        </form>
        <x-ui.table :rows="$rows" :columns="$columns" :grand="$grand" :compact="\App\Core\Support\Ui::compact()"
                    :view-url="fn ($r) => route('hr.final_settlement.show', $r)" :empty="__('core.empty.no_results')" />
        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" :grand="$grand" :columns="$columns" />
    </div>
</x-layouts.app>
