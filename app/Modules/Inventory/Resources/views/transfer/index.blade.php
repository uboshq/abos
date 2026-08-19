{{--
    স্থানান্তরের তালিকা — রাস্তায় থাকাগুলো উপরে।

    এই পর্দার আসল প্রশ্ন "কোন মালটা এখনো পৌঁছায়নি"। সাম্প্রতিক দিয়ে
    সাজালে ওগুলো নিচে চাপা পড়ত, আর ঠিক ওগুলোই খোঁজ নেওয়ার জিনিস।
--}}
@php
    $columns = [
        ['key' => 'trx_date', 'label' => __('inventory::field.date'), 'width' => '7rem',
         'render' => fn ($t) => \App\Core\Support\DateFormat::format($t->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($t) => new \Illuminate\Support\HtmlString(
             '<a href=\'' . route('inventory.transfer.show', $t) . '\' '
             . 'class=\'text-(--color-brand-500) underline-offset-2 hover:underline\'>'
             . e($t->document_no) . '</a>')],
        ['key' => 'route', 'label' => __('inventory::field.from_to'),
         'render' => fn ($t) => ($t->fromWarehouse?->name() ?? '—') . ' → ' . ($t->toWarehouse?->name() ?? '—')],
        ['key' => 'lines', 'label' => __('inventory::field.items'), 'numeric' => true, 'width' => '7rem',
         'render' => fn ($t) => $t->lines()->count()],
        ['key' => 'status', 'label' => __('inventory::field.state'), 'width' => '9rem',
         'render' => fn ($t) => view('inventory::transfer.partials.status', ['transfer' => $t])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('inventory::menu.transfers') }}</x-slot:title>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('inventory::menu.transfers')" :count="trans_choice('core.count.records', $transfers->total(), ['count' => $transfers->total()])"
                :columns="$columns" :search-placeholder="__('inventory::message.transfer_search')"
                          :sort="$sortOptions">
        <x-slot:actions>
            @can('inventory.transfer.create')
                    <x-ui.button tone="primary" icon="plus" :href="route('inventory.transfer.create')">
                        {{ __('inventory::action.new_transfer') }}
                    </x-ui.button>
                @endcan
        </x-slot:actions>
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('inventory::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('inventory::message.no_transfers')"
            :rows="$transfers"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        @if ($transfers->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $transfers->links() }}</div>
        @endif
    </div>
</x-layouts.app>
