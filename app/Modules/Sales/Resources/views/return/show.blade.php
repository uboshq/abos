{{-- একটা বিক্রয় ফেরত। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $return->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$return->document_no" :subtitle="$return->customer?->name()">
            <x-slot:actions>
                @can('update', $return)
                    <x-ui.button tone="secondary" :href="route('sales.return.edit', $return)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('sales.return.confirm', $return) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('sales::action.confirm') }}</x-ui.button>
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
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    'sales::field.date' => \App\Core\Support\DateFormat::format($return->trx_date),
                    'sales::field.warehouse' => $return->warehouse?->name() ?: '-',
                    'sales::field.invoice' => $return->invoice?->document_no ?: '-',
                    'sales::field.reason' => $return->reasonCode?->name() ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.state') }}</dt>
                    <dd class="mt-0.5"><x-sales::status-badge :document="$return" /></dd>
                </div>
            </dl>
        </section>

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('sales::message.lines') }}
            </h2>

            <x-ui.table
                :empty="__('sales::validation.no_lines')"
                :rows="$return->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('sales::field.line_no'), 'width' => '4rem'],
                    ['key' => 'product', 'label' => __('sales::field.product'),
                     'render' => fn ($l) => $l->product?->name()],
                    ['key' => 'qty', 'label' => __('sales::field.quantity'), 'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => rtrim(rtrim((string) $l->qty, '0'), '.').' '.($l->product?->unit?->code ?? '')],
                    ['key' => 'rate', 'label' => __('sales::field.rate'), 'numeric' => true, 'width' => '8rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->rate)],
                    ['key' => 'to_hold', 'label' => __('sales::field.not_sellable'), 'width' => '9rem',
                     'render' => fn ($l) => $l->to_hold ? __('core.yes') : __('core.no')],
                    ['key' => 'amount', 'label' => __('sales::field.amount'), 'numeric' => true, 'width' => '10rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-sales::totals :rows="[
                    'sales::field.subtotal' => $return->subtotal,
                    'sales::field.tax' => $return->tax,
                    'sales::field.total' => $return->total,
                ]" />
            </div>
        </section>

        @can('delete', $return)
            @if ($return->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-sales::cancel-form :action="route('sales.return.cancel', $return)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
