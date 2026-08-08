{{--
    নগদ গণনার তালিকা।

    পার্থক্যের কলামটাই সবচেয়ে জরুরি — শূন্য হলে চোখে পড়ার দরকার নেই,
    শূন্য না হলে অবশ্যই।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.cash_count') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.cash_count')">
            <x-slot:actions>
                <x-ui.button tone="primary" icon="+" :href="route('accounts.count.create')">
                    {{ __('accounts::action.new_count') }}
                </x-ui.button>
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
            <x-ui.toolbar />
        </form>

        <x-ui.table
            :compact="request()->boolean('compact')"
            :empty="$q ? __('core.empty.no_results') : __('accounts::message.no_counts')"
            :rows="$counts"
            :columns="[
                ['key' => 'trx_date', 'label' => __('core.table.date'), 'width' => '8rem',
                 'render' => fn ($c) => \App\Core\Support\DateFormat::format($c->trx_date)],
                ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '13rem',
                 'render' => fn ($c) => view('accounts::count.partials.number', ['count' => $c])],
                ['key' => 'cash_till_id', 'label' => __('accounts::menu.cash_tills'),
                 'render' => fn ($c) => $c->till?->name()],
                ['key' => 'counted_amount', 'label' => __('accounts::field.counted'), 'numeric' => true,
                 'width' => '10rem', 'render' => fn ($c) => view('ui.amount-link', [
                     'value' => $c->counted_amount,
                     'href' => route('accounts.count.show', $c),
                 ])],
                ['key' => 'difference', 'label' => __('accounts::field.difference'), 'numeric' => true,
                 'width' => '10rem', 'render' => fn ($c) => view('accounts::count.partials.difference', ['count' => $c])],
                ['key' => 'status', 'label' => __('accounts::field.state'), 'width' => '8rem',
                 'render' => fn ($c) => view('accounts::count.partials.status', ['count' => $c])],
            ]" />

        @if ($counts->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $counts->links() }}</div>
        @endif
    </div>
</x-layouts.app>
