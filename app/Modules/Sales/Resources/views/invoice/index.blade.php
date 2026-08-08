{{--
    বিক্রয় বিল — তালিকা।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::menu.invoices') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="__('sales::menu.invoices')"
            :subtitle="__('sales::message.invoice_note')">
            <x-slot:actions>
                @can('create', \App\Modules\Sales\Models\SalesInvoice::class)
                    <x-ui.button tone="primary" icon="+" :href="route('sales.invoice.create')">
                        {{ __('sales::action.new_invoice') }}
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
            <x-ui.toolbar :search-placeholder="__('sales::message.invoice_search')"
                          :sort="$sortOptions">
                <x-ui.date-range :dates="$dates" />

                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                    <input type="checkbox" name="cancelled" value="1" @checked($showCancelled) class="size-4">
                    {{ __('sales::action.show_cancelled') }}
                </label>
            </x-ui.toolbar>
        </form>

        <x-ui.table
            :empty="$q ? __('core.empty.no_results') : __('sales::message.no_invoices')"
            :rows="$invoices"
            :compact="request()->boolean('compact')"
            :columns="[
                [
                    'key' => 'trx_date',
                    'label' => __('sales::field.date'),
                    'width' => '7rem',
                    'render' => fn ($d) => \App\Core\Support\DateFormat::format($d->trx_date),
                ],
                [
                    'key' => 'document_no',
                    'label' => __('sales::field.document_no'),
                    'width' => '12rem',
                    'render' => fn ($d) => view('sales::components.doc-link', [
                        'document' => $d,
                        'route' => 'sales.invoice.show',
                    ]),
                ],
                [
                    'key' => 'customer_id',
                    'label' => __('sales::field.customer'),
                    'render' => fn ($d) => $d->customer?->name(),
                ],
                [
                    'key' => 'due_on',
                    'label' => __('sales::field.due_on'),
                    'width' => '8rem',
                    'render' => fn ($d) => \App\Core\Support\DateFormat::format($d->due_on),
                ],
                [
                    'key' => 'total',
                    'label' => __('sales::field.total'),
                    'numeric' => true,
                    'width' => '10rem',
                    'render' => fn ($d) => view('ui.amount-link', [
                        'value' => $d->total,
                        'href' => route('sales.invoice.show', $d),
                    ]),
                ],
                [
                    'key' => 'status',
                    'label' => __('sales::field.state'),
                    'width' => '8rem',
                    'render' => fn ($d) => view('sales::components.status-badge', ['document' => $d]),
                ],
            ]" />

        @if ($invoices->hasPages())
            <div class="border-t border-(--color-border) px-3 py-2">{{ $invoices->links() }}</div>
        @endif
    </div>
</x-layouts.app>
