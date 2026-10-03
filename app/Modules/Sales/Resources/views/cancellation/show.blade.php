{{--
    বাতিল-ইনভয়েস — নিজের নম্বরে, উল্টানো ইনভয়েসের সূত্রসহ (মালিক, ৪ অক্টোবর ২০২৬; [[SalesInvoiceCancellationService]])।
    ⓘ সারি আর অঙ্ক উল্টানো ইনভয়েসেরই — এই কাগজ পুরোটাই উল্টো; ছাপায় শিরোনামই তা বলে।
--}}
@php
    $state = match ($cancellation->status) {
        \App\Core\Support\DocumentStatus::CONFIRMED => 'state_confirmed',
        \App\Modules\Sales\Models\SalesInvoiceCancellation::AWAITING => 'state_awaiting',
        default => 'state_cancelled',
    };
    $collected = $invoice->collectedAmount();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $cancellation->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$cancellation->document_no" :subtitle="__('sales::cancellation.title').' · '.($cancellation->customer?->name() ?? '')">
            <x-slot:actions>
                <x-ui.button tone="secondary" :href="route('sales.invoice.show', $invoice)">
                    {{ $invoice->document_no }}
                </x-ui.button>

                @if ($cancellation->status === \App\Core\Support\DocumentStatus::CONFIRMED)
                    <x-ui.print-menu :documents="[[
                        'label' => __('sales::cancellation.title'),
                        'url' => route('sales.cancellation.print', $cancellation),
                        'paper_setting' => 'sales.print.paper.invoice',
                        'type' => 'sales_invoice_cancellation',
                        'id' => $cancellation->id,
                        'no' => $cancellation->document_no,
                    ]]" />
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </p>
    @endif

    <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'sales::field.date' => \App\Core\Support\DateFormat::format($cancellation->trx_date),
                    'sales::cancellation.of_invoice' => null,
                    'sales::field.state' => __('sales::cancellation.'.$state),
                    'sales::cancellation.reason' => $cancellation->reason,
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ $label === 'sales::cancellation.of_invoice' ? __('sales::doc.invoice') : __($label) }}</dt>
                        <dd class="mt-0.5" @if ($label === 'sales::field.state') data-cancellation-state="{{ $cancellation->status }}" @endif>
                            @if ($label === 'sales::cancellation.of_invoice')
                                <a href="{{ route('sales.invoice.show', $invoice) }}" class="underline">{{ $invoice->document_no }}</a>
                            @else
                                {{ $value }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>

            @if (bccomp($collected, '0', 4) > 0)
                <p class="mt-3 text-sm text-(--color-ink-muted)" data-advance-note>
                    {{ __('sales::cancellation.advance_note', ['amount' => \App\Core\Support\Money::format($collected)]) }}
                </p>
            @endif
        </section>

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('sales::message.lines') }}
            </h2>

            <x-ui.table
                :empty="__('sales::validation.no_lines')"
                :rows="$invoice->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('sales::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('sales::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'qty', 'label' => __('sales::field.quantity'), 'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->qty)],
                    ['key' => 'rate', 'label' => __('sales::field.rate'), 'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    ['key' => 'amount', 'label' => __('sales::field.amount'), 'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-sales::totals :rows="[
                    'sales::field.subtotal' => $cancellation->subtotal,
                    'sales::field.tax' => $cancellation->tax,
                    'sales::field.total' => $cancellation->total,
                ]" />
            </div>
        </section>
    </div>
</x-layouts.app>
