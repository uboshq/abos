{{-- একটা বিক্রয় বিল। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $invoice->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$invoice->document_no" :subtitle="$invoice->customer?->name()">
            <x-slot:actions>
                @can('update', $invoice)
                    <x-ui.button tone="secondary" :href="route('sales.invoice.edit', $invoice)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('sales.invoice.confirm', $invoice) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('sales::action.confirm') }}</x-ui.button>
                    </form>
                @endcan

                @if ($invoice->status === \App\Core\Support\DocumentStatus::CONFIRMED
                    && bccomp($invoice->dueAmount(), '0', 4) > 0)
                    @can('create', \App\Modules\Sales\Models\Collection::class)
                        <x-ui.button tone="primary"
                                     :href="route('sales.collection.create', ['sales_invoice_id' => $invoice->id])">
                            {{ __('sales::action.collect_against') }}
                        </x-ui.button>
                    @endcan
                @endif
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

    <div class="space-y-4">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'sales::field.date' => $invoice->trx_date?->format('d/m/Y'),
                    'sales::field.due_on' => $invoice->due_on?->format('d/m/Y') ?: '-',
                    'sales::field.collected' => number_format((float) $invoice->collectedAmount(), 2),
                    'sales::field.due' => number_format((float) $invoice->dueAmount(), 2),
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.state') }}</dt>
                    <dd class="mt-0.5"><x-sales::status-badge :document="$invoice" /></dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
                {{ __('sales::message.lines') }}
            </h2>

            <x-ui.table
                :empty="__('sales::validation.no_lines')"
                :rows="$invoice->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('sales::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('sales::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'unit', 'label' => __('sales::field.unit'), 'width' => '6rem',
                     'render' => fn ($l) => $l->product?->unit?->name()],
                    ['key' => 'qty', 'label' => __('sales::field.quantity'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => number_format((float) $l->qty, 2)],
                    ['key' => 'rate', 'label' => __('sales::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => number_format((float) $l->rate, 2)],
                    ['key' => 'discount', 'label' => __('sales::field.discount'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => number_format((float) $l->discount, 2)],
                    ['key' => 'tax', 'label' => __('sales::field.tax'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => number_format((float) $l->tax, 2)],
                    ['key' => 'amount', 'label' => __('sales::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => number_format((float) $l->amount, 2)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-sales::totals :rows="[
                    'sales::field.subtotal' => $invoice->subtotal,
                    'sales::field.discount' => $invoice->discount,
                    'sales::field.tax' => $invoice->tax,
                    'sales::field.cost_of_goods' => $invoice->cost_of_goods,
                    'sales::field.total' => $invoice->total,
                ]" />
            </div>
        </section>

        @can('delete', $invoice)
            @if ($invoice->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-sales::cancel-form :action="route('sales.invoice.cancel', $invoice)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
