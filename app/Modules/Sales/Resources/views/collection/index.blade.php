{{--
    আদায় — তালিকা।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.collections') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('sales::menu.collections')"
            :subtitle="__('sales::message.collection_note')">
            <x-slot:actions>
                @can('create', \App\Modules\Sales\Models\Collection::class)
                    <x-ui.button tone="primary" icon="+" :href="route('sales.collection.create')">
                        {{ __('sales::action.new_collection') }}
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
            <x-ui.toolbar :search-placeholder="__('sales::message.collection_search')"
                          :sort="$sortOptions">
                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('sales::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('sales::message.no_collections')"
            :rows="$collections"
            :compact="request()->boolean('compact')"
            :columns="[
                [
                    'key' => 'trx_date',
                    'label' => __('sales::field.date'),
                    'width' => '7rem',
                    'render' => fn ($d) => $d->trx_date?->format('d/m/Y'),
                ],
                [
                    'key' => 'document_no',
                    'label' => __('sales::field.document_no'),
                    'width' => '12rem',
                    'render' => fn ($d) => view('sales::components.doc-link', [
                        'document' => $d,
                        'route' => 'sales.collection.show',
                    ]),
                ],
                [
                    'key' => 'customer_id',
                    'label' => __('sales::field.customer'),
                    'render' => fn ($d) => $d->customer?->name(),
                ],
                [
                    'key' => 'account_id',
                    'label' => __('sales::field.account'),
                    'width' => '12rem',
                    'render' => fn ($d) => $d->account?->name(),
                ],
                [
                    'key' => 'amount',
                    'label' => __('sales::field.total'),
                    'numeric' => true,
                    'width' => '10rem',
                    'render' => fn ($d) => view('ui.amount-link', [
                        'value' => $d->amount,
                        'href' => route('sales.collection.show', $d),
                    ]),
                ],
                [
                    'key' => 'status',
                    'label' => __('sales::field.state'),
                    'width' => '8rem',
                    'render' => fn ($d) => view('sales::components.status-badge', ['document' => $d]),
                ],
            ]" />

        @if ($collections->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $collections->links() }}</div>
        @endif
    </div>
</x-layouts.app>
