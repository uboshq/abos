{{-- একটা ডেলিভারি চালান। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $challan->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$challan->document_no" :subtitle="$challan->customer?->name()">
            <x-slot:actions>
                @can('update', $challan)
                    <x-ui.button tone="secondary" :href="route('sales.challan.edit', $challan)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('sales.challan.confirm', $challan) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('sales::action.confirm') }}</x-ui.button>
                    </form>
                @endcan

                @if ($challan->status === \App\Core\Support\DocumentStatus::CONFIRMED)
                    @can('create', \App\Modules\Sales\Models\SalesInvoice::class)
                        <x-ui.button tone="primary"
                                     :href="route('sales.invoice.create', ['delivery_challan_id' => $challan->id])">
                            {{ __('sales::action.invoice_against') }}
                        </x-ui.button>
                    @endcan
                @endif
                <x-ui.print-menu :documents="[
                    ['label' => __('sales::doc.challan'), 'url' => route('sales.print.challan', $challan)],
                    ['label' => __('sales::doc.gatepass'), 'url' => route('sales.print.gatepass', $challan)],
                ]" />
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
                    'sales::field.date' => $challan->trx_date?->format('d/m/Y'),
                    'sales::field.warehouse' => $challan->warehouse?->name() ?: '-',
                    'sales::field.vehicle_no' => $challan->vehicle_no ?: '-',
                    'sales::field.driver_name' => $challan->driver_name ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.state') }}</dt>
                    <dd class="mt-0.5"><x-sales::status-badge :document="$challan" /></dd>
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
                :rows="$challan->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('sales::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('sales::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'unit', 'label' => __('sales::field.unit'), 'width' => '6rem',
                     'render' => fn ($l) => $l->product?->unit?->name()],
                    ['key' => 'delivered_qty', 'label' => __('sales::field.delivered'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => number_format((float) $l->delivered_qty, 2)],
                    ['key' => 'uninvoiced', 'label' => __('sales::field.uninvoiced'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => number_format((float) $l->uninvoicedQty(), 2)],
                    ['key' => 'rate', 'label' => __('sales::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => number_format((float) $l->rate, 2)],
                    ['key' => 'amount', 'label' => __('sales::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => number_format((float) $l->amount, 2)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-sales::totals :rows="[
                    'sales::field.total' => $challan->total,
                ]" />
            </div>
        </section>

        {{-- গ্রাহকের সই করা চালানের কপি — ডেলিভারি নিয়ে প্রশ্ন উঠলে
             এটাই একমাত্র প্রমাণ --}}
        <x-ui.attachments :document="$challan" />

        @can('delete', $challan)
            @if ($challan->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-sales::cancel-form :action="route('sales.challan.cancel', $challan)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
