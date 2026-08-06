{{-- একটা চালান — কী এসেছে, আর তার কতটুকুর বিল হয়েছে। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $receipt->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$receipt->document_no" :subtitle="$receipt->supplier?->name()">
            <x-slot:actions>
                @can('update', $receipt)
                    <x-ui.button tone="secondary" :href="route('purchase.receipt.edit', $receipt)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('purchase.receipt.confirm', $receipt) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('purchase::action.confirm') }}</x-ui.button>
                    </form>
                @endcan

                @if ($receipt->status === \App\Core\Support\DocumentStatus::CONFIRMED)
                    @can('create', \App\Modules\Purchase\Models\PurchaseBill::class)
                        <x-ui.button tone="primary"
                                     :href="route('purchase.bill.create', ['purchase_receipt_id' => $receipt->id])">
                            {{ __('purchase::action.bill_against') }}
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
                    'purchase::field.date' => $receipt->trx_date?->format('d/m/Y'),
                    'purchase::field.warehouse' => $receipt->warehouse?->name() ?: '-',
                    'purchase::field.supplier_challan_no' => $receipt->supplier_challan_no ?: '-',
                    'purchase::field.narration' => $receipt->narration ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('purchase::field.order') }}</dt>
                    <dd class="mt-0.5">
                        @if ($receipt->order)
                            <x-purchase::doc-link :document="$receipt->order" route="purchase.order.show" />
                        @else
                            -
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('purchase::field.state') }}</dt>
                    <dd class="mt-0.5"><x-purchase::status-badge :document="$receipt" /></dd>
                </div>
            </dl>
        </section>

        <section class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-4 py-3 font-semibold">
                {{ __('purchase::message.lines') }}
            </h2>

            <x-ui.table
                :empty="__('purchase::validation.no_lines')"
                :rows="$receipt->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('purchase::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('purchase::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'unit', 'label' => __('purchase::field.unit'), 'width' => '6rem',
                     'render' => fn ($l) => $l->product?->unit?->name()],
                    ['key' => 'received_qty', 'label' => __('purchase::field.received'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => number_format((float) $l->received_qty, 2)],
                    ['key' => 'unbilled', 'label' => __('purchase::field.unbilled'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => number_format((float) $l->unbilledQty(), 2)],
                    ['key' => 'rate', 'label' => __('purchase::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => number_format((float) $l->rate, 2)],
                    ['key' => 'amount', 'label' => __('purchase::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => number_format((float) $l->amount, 2)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-purchase::totals :rows="['purchase::field.total' => $receipt->total]" />
            </div>
        </section>

        <x-ui.attachments :document="$receipt" />

        @can('delete', $receipt)
            @if ($receipt->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-purchase::cancel-form :action="route('purchase.receipt.cancel', $receipt)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
