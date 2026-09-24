{{--
    পরিদর্শনের তালিকা — যেগুলোর রায় বাকি, উপরে।

    ⭐ এই পর্দার আসল প্রশ্ন *"কোন মালটা এখনো দেখা বাকি"*। ⚠️ ততক্ষণ ঐ মাল
    বিক্রির বাইরে পড়ে থাকে, আর সেটা কাগজের দেরি নয় — টাকার ক্ষতি।
--}}
@php
    $columns = [
        ['key' => 'inspected_on', 'label' => __('inventory::field.date'), 'width' => '7rem',
         'render' => fn ($i) => \App\Core\Support\DateFormat::format($i->inspected_on)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($i) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('inventory.qc.show', $i) . '\' '
             . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($i->document_no) . '</a>')],
        ['key' => 'product', 'label' => __('inventory::field.product'),
         'render' => fn ($i) => $i->product?->name() ?? '—'],
        ['key' => 'warehouse', 'label' => __('inventory::field.warehouse'),
         'render' => fn ($i) => $i->warehouse?->name() ?? '—'],
        ['key' => 'inspected_qty', 'label' => __('inventory::field.quantity'), 'numeric' => true,
         'width' => '7rem', 'render' => fn ($i) => $i->inspected_qty],
        ['key' => 'rejected_qty', 'label' => __('inventory::field.qc_rejected'), 'numeric' => true,
         'width' => '7rem', 'render' => fn ($i) => $i->rejected_qty],
        ['key' => 'status', 'label' => __('inventory::field.state'), 'width' => '9rem',
         'render' => fn ($i) => view('inventory::quality.partials.status', ['inspection' => $i])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.quality') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div data-boxed
         class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('inventory::menu.quality')"
                          :count="trans_choice('core.count.records', $inspections->total(), ['count' => $inspections->total()])"
                          :columns="$columns"
                          :sort="$sortOptions">
                <x-slot:actions>
                    @can('create', \App\Modules\Inventory\Models\QualityInspection::class)
                        <x-ui.button tone="primary" icon="plus" :href="route('inventory.qc.create')">
                            {{ __('inventory::action.new_inspection') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

                <x-ui.date-range :dates="$dates" />
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="__('inventory::message.no_inspections')"
            :rows="$inspections"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$inspections" />
    </div>
</x-layouts.app>
