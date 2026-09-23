{{--
    মাল গোনার তালিকা — যেগুলো এখনো মেনে নেওয়া হয়নি, উপরে।

    ⭐ এই পর্দার আসল প্রশ্ন *"কোন গোনাটা ঝুলে আছে"*। ⚠️ একটা অমীমাংসিত
    গোনা মানে খাতা আর তাক এখনো দুই কথা বলছে, আর কেউ সিদ্ধান্ত নেয়নি।
    ⛔ সাম্প্রতিক দিয়ে সাজালে ওগুলোই নিচে চাপা পড়ত।
--}}
@php
    $columns = [
        ['key' => 'count_date', 'label' => __('inventory::field.date'), 'width' => '7rem',
         'render' => fn ($c) => \App\Core\Support\DateFormat::format($c->count_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($c) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('inventory.count.show', $c) . '\' '
             . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($c->document_no) . '</a>')],
        ['key' => 'warehouse', 'label' => __('inventory::field.warehouse'),
         'render' => fn ($c) => $c->warehouse?->name() ?? '—'],
        ['key' => 'counter', 'label' => __('inventory::field.counted_by'),
         'render' => fn ($c) => $c->counter?->name ?? '—'],
        ['key' => 'lines', 'label' => __('inventory::field.items'), 'numeric' => true, 'width' => '7rem',
         'render' => fn ($c) => $c->lines_count],
        ['key' => 'status', 'label' => __('inventory::field.state'), 'width' => '9rem',
         'render' => fn ($c) => view('inventory::count.partials.status', ['count' => $c])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.counts') }}</x-slot:title>

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
            <x-ui.toolbar :title="__('inventory::menu.counts')"
                          :count="trans_choice('core.count.records', $counts->total(), ['count' => $counts->total()])"
                          :columns="$columns"
                          :sort="$sortOptions">
                <x-slot:actions>
                    @can('create', \App\Modules\Inventory\Models\StockCount::class)
                        <x-ui.button tone="primary" icon="plus" :href="route('inventory.count.create')">
                            {{ __('inventory::action.new_count') }}
                        </x-ui.button>
                    @endcan
                </x-slot:actions>

                <x-ui.date-range :dates="$dates" />
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="__('inventory::message.no_counts')"
            :rows="$counts"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        <x-ui.pager :rows="$counts" />
    </div>
</x-layouts.app>
