{{--
    বিক্রয় ফেরতের তালিকা।

    কারণটা কলাম হিসেবে আছে: একই কারণ বারবার এলে সেটা পণ্যের সমস্যা, আর
    সেটা জানা দরকার — নাহলে প্রতি মাসে একই মাল ফেরত আসতেই থাকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.returns') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('sales::menu.returns')"
            :subtitle="trans_choice('core.count.records', $returns->total(), ['count' => $returns->total()])">
            <x-slot:actions>
                @can('sales.return.create')
                    <x-ui.button tone="primary" icon="+" :href="route('sales.return.create')">
                        {{ __('sales::action.new_return') }}
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
            <x-ui.toolbar :search-placeholder="__('sales::message.return_search')"
                          :sort="$sortOptions">
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('sales::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('sales::message.no_returns')"
            :rows="$returns"
            :compact="request()->boolean('compact')"
            :columns="[
                ['key' => 'trx_date', 'label' => __('sales::field.date'), 'width' => '7rem',
                 'render' => fn ($r) => $r->trx_date?->format('d/m/Y')],
                ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
                 'render' => fn ($r) => view('sales::components.doc-link', [
                     'document' => $r, 'route' => 'sales.return.show'])],
                ['key' => 'customer', 'label' => __('sales::field.customer'),
                 'render' => fn ($r) => $r->customer?->name()],
                ['key' => 'reason', 'label' => __('sales::field.reason'), 'width' => '10rem',
                 'render' => fn ($r) => $r->reasonCode?->name() ?: '—'],
                ['key' => 'total', 'label' => __('sales::field.total'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($r) => number_format((float) $r->total, 2)],
                ['key' => 'status', 'label' => __('sales::field.state'), 'width' => '8rem',
                 'render' => fn ($r) => view('sales::components.status-badge', ['document' => $r])],
            ]" />

        @if ($returns->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $returns->links() }}</div>
        @endif
    </div>
</x-layouts.app>
