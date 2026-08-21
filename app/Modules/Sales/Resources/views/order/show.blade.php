{{-- একটা বিক্রয় আদেশ। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $order->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$order->document_no" :subtitle="$order->customer?->name()">
            <x-slot:actions>
                @can('update', $order)
                    <x-ui.button tone="secondary" :href="route('sales.order.edit', $order)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('sales.order.confirm', $order) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('sales::action.confirm') }}</x-ui.button>
                    </form>
                @endcan

                @if ($order->status === \App\Core\Support\DocumentStatus::CONFIRMED)
                    @can('create', \App\Modules\Sales\Models\DeliveryChallan::class)
                        <x-ui.button tone="primary"
                                     :href="route('sales.challan.create', ['sales_order_id' => $order->id])">
                            {{ __('sales::action.deliver_against') }}
                        </x-ui.button>
                    @endcan
                @endif
                <x-ui.print-menu :documents="[
                    ['label' => __('sales::doc.order'), 'url' => route('sales.print.order', $order)],
                    ['label' => __('sales::doc.delivery_order'), 'url' => route('sales.print.delivery_order', $order)],
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
                    'sales::field.date' => \App\Core\Support\DateFormat::format($order->trx_date),
                    'sales::field.deliver_on' => \App\Core\Support\DateFormat::format($order->deliver_on) ?: '-',
                    'sales::field.warehouse' => $order->warehouse?->name() ?: '-',
                    'sales::field.narration' => $order->narration ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.state') }}</dt>
                    <dd class="mt-0.5"><x-sales::status-badge :document="$order" /></dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('sales::message.lines') }}
            </h2>

            <x-ui.table
                :empty="__('sales::validation.no_lines')"
                :rows="$order->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('sales::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('sales::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'unit', 'label' => __('sales::field.unit'), 'width' => '6rem',
                     'render' => fn ($l) => $l->product?->unit?->name()],
                    ['key' => 'ordered_qty', 'label' => __('sales::field.ordered'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->ordered_qty)],
                    ['key' => 'delivered', 'label' => __('sales::field.delivered'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->deliveredQty())],
                    ['key' => 'pending', 'label' => __('sales::field.pending'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->pendingQty())],
                    ['key' => 'rate', 'label' => __('sales::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    ['key' => 'amount', 'label' => __('sales::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-sales::totals :rows="[
                    'sales::field.subtotal' => $order->subtotal,
                    'sales::field.discount' => $order->discount,
                    'sales::field.tax' => $order->tax,
                    'sales::field.total' => $order->total,
                ]" />
            </div>
        </section>

        @can('delete', $order)
            @if ($order->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-sales::cancel-form :action="route('sales.order.cancel', $order)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
