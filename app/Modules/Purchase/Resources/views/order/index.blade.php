{{-- ক্রয় আদেশ — তালিকা। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::menu.orders') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('purchase::menu.orders')"
            :subtitle="__('purchase::message.order_note')">
            <x-slot:actions>
                @can('create', \App\Modules\Purchase\Models\PurchaseOrder::class)
                    <x-ui.button tone="primary" icon="+" :href="route('purchase.order.create')">
                        {{ __('purchase::action.new_order') }}
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
            <x-ui.toolbar :search-placeholder="__('purchase::message.order_search')"
                          :sort="$sortOptions">
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('purchase::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('purchase::message.no_orders')"
            :rows="$orders"
            :compact="request()->boolean('compact')"
            :columns="[
                [
                    'key' => 'trx_date',
                    'label' => __('purchase::field.date'),
                    'width' => '7rem',
                    'render' => fn ($d) => $d->trx_date?->format('d/m/Y'),
                ],
                [
                    'key' => 'document_no',
                    'label' => __('purchase::field.document_no'),
                    'width' => '12rem',
                    'render' => fn ($d) => view('purchase::components.doc-link', [
                        'document' => $d,
                        'route' => 'purchase.order.show',
                    ]),
                ],
                [
                    'key' => 'supplier_id',
                    'label' => __('purchase::field.supplier'),
                    'render' => fn ($d) => $d->supplier?->name(),
                ],

                [
                    'key' => 'total',
                    'label' => __('purchase::field.total'),
                    'numeric' => true,
                    'width' => '10rem',
                    'render' => fn ($d) => view('ui.amount-link', [
                        'value' => $d->total,
                        'href' => route('purchase.order.show', $d),
                    ]),
                ],
                [
                    'key' => 'status',
                    'label' => __('purchase::field.state'),
                    'width' => '8rem',
                    'render' => fn ($d) => view('purchase::components.status-badge', ['document' => $d]),
                ],
            ]" />

        @if ($orders->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $orders->links() }}</div>
        @endif
    </div>
</x-layouts.app>
