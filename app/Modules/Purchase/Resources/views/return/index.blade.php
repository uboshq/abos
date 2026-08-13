{{--
    ক্রয় ফেরতের তালিকা।

    কারণটা কলাম হিসেবে আছে: "কেন ফেরত গেল" প্রশ্নটাই এই তালিকার আসল
    প্রশ্ন, আর একই কারণ বারবার এলে সেটা সরবরাহকারীর সাথে কথা বলার বিষয়।
--}}
@php
    $columns = [
        ['key' => 'trx_date', 'label' => __('purchase::field.date'), 'width' => '7rem',
         'render' => fn ($r) => \App\Core\Support\DateFormat::format($r->trx_date)],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
         'render' => fn ($r) => view('purchase::components.doc-link', [
             'document' => $r, 'route' => 'purchase.return.show'])],
        ['key' => 'supplier', 'label' => __('purchase::field.supplier'),
         'render' => fn ($r) => $r->supplier?->name()],
        ['key' => 'reason', 'label' => __('purchase::field.reason'), 'width' => '10rem',
         'render' => fn ($r) => $r->reasonCode?->name() ?: '—'],
        ['key' => 'total', 'label' => __('purchase::field.total'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($r) => \App\Core\Support\Money::format($r->total)],
        ['key' => 'status', 'label' => __('purchase::field.state'), 'width' => '8rem',
         'render' => fn ($r) => view('purchase::components.status-badge', ['document' => $r])],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::menu.returns') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('purchase::menu.returns')"
            :subtitle="trans_choice('core.count.records', $returns->total(), ['count' => $returns->total()])">
            <x-slot:actions>
                @can('purchase.return.create')
                    <x-ui.button tone="primary" icon="+" :href="route('purchase.return.create')">
                        {{ __('purchase::action.new_return') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar
                :columns="$columns" :search-placeholder="__('purchase::message.return_search')"
                          :sort="$sortOptions">
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('purchase::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('purchase::message.no_returns')"
            :rows="$returns"
            :compact="request()->boolean('compact')"
            :columns="$columns" />

        @if ($returns->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $returns->links() }}</div>
        @endif
    </div>
</x-layouts.app>
