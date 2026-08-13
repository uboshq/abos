{{-- একটা ক্রয় বিল। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $bill->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$bill->document_no" :subtitle="$bill->supplier?->name()">
            <x-slot:actions>
                @can('update', $bill)
                    <x-ui.button tone="secondary" :href="route('purchase.bill.edit', $bill)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('purchase.bill.confirm', $bill) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('purchase::action.confirm') }}</x-ui.button>
                    </form>
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

    <div class="space-y-4">
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'purchase::field.date' => \App\Core\Support\DateFormat::format($bill->trx_date),
                    'purchase::field.due_on' => \App\Core\Support\DateFormat::format($bill->due_on) ?: '-',
                    'purchase::field.supplier_bill_no' => $bill->supplier_bill_no ?: '-',
                    'purchase::field.narration' => $bill->narration ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('purchase::field.state') }}</dt>
                    <dd class="mt-0.5"><x-purchase::status-badge :document="$bill" /></dd>
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
                :rows="$bill->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('purchase::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product_id', 'label' => __('purchase::field.product'),
                     'render' => fn ($l) => $l->product?->code . ' - ' . $l->product?->name()],
                    ['key' => 'receipt', 'label' => __('purchase::field.receipt'), 'width' => '10rem',
                     'render' => fn ($l) => $l->receiptLine?->receipt?->document_no ?: '-'],
                    ['key' => 'qty', 'label' => __('purchase::field.quantity'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->qty)],
                    ['key' => 'rate', 'label' => __('purchase::field.rate'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    ['key' => 'discount', 'label' => __('purchase::field.discount'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->discount)],
                    ['key' => 'tax', 'label' => __('purchase::field.tax'),
                     'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->tax)],
                    ['key' => 'amount', 'label' => __('purchase::field.amount'),
                     'numeric' => true, 'width' => '9rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-purchase::totals :rows="[
                    'purchase::field.subtotal' => $bill->subtotal,
                    'purchase::field.discount' => $bill->discount,
                    'purchase::field.tax' => $bill->tax,
                    'purchase::field.total' => $bill->total,
                ]" />
            </div>
        </section>

        {{-- সরবরাহকারীর আসল বিলের ছবি বা স্ক্যান — মিলিয়ে দেখার জন্য
             ছয় মাস পরেও এটাই লাগে --}}
        <x-ui.attachments :document="$bill" />

        @can('delete', $bill)
            @if ($bill->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-purchase::cancel-form :action="route('purchase.bill.cancel', $bill)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
