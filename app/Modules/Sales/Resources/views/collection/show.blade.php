{{-- একটা আদায়। --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $collection->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$collection->document_no" :subtitle="$collection->customer?->name()">
            <x-slot:actions>
                @can('update', $collection)
                    <x-ui.button tone="secondary" :href="route('sales.collection.edit', $collection)">
                        {{ __('core.action.edit') }}
                    </x-ui.button>

                    <form method="POST" action="{{ route('sales.collection.confirm', $collection) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('sales::action.confirm') }}</x-ui.button>
                    </form>
                @endcan
                <x-ui.print-menu :documents="[
                    ['label' => __('sales::doc.collection'), 'url' => route('sales.print.receipt', $collection)],
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
                    'sales::field.date' => \App\Core\Support\DateFormat::format($collection->trx_date),
                    'sales::field.account' => $collection->account?->name() ?: '-',
                    'sales::field.instrument' => $collection->instrument ?: '-',
                    'sales::field.instrument_no' => $collection->instrument_no ?: '-',
                ] as $label => $value)
                    <div>
                        <dt class="text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="mt-0.5">{{ $value }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt class="text-(--color-ink-muted)">{{ __('sales::field.state') }}</dt>
                    <dd class="mt-0.5"><x-sales::status-badge :document="$collection" /></dd>
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
                :rows="$collection->lines"
                :columns="[
                    ['key' => 'line_no', 'label' => __('sales::field.line_no'), 'width' => '4rem'],
                    ['key' => 'sales_invoice_id', 'label' => __('sales::field.invoice'),
                     'render' => fn ($l) => $l->invoice
                         ? view('sales::components.doc-link', [
                             'document' => $l->invoice, 'route' => 'sales.invoice.show'])
                         : '-'],
                    ['key' => 'amount', 'label' => __('sales::field.amount'),
                     'numeric' => true, 'width' => '10rem',
                     'render' => fn ($l) => \App\Core\Support\Money::format($l->amount)],
                ]" />

            <div class="flex border-t border-(--color-border) p-4">
                <x-sales::totals :rows="[
                    'sales::field.amount' => $collection->amount,
                ]" />
            </div>
        </section>

        @can('delete', $collection)
            @if ($collection->status !== \App\Core\Support\DocumentStatus::CANCELLED)
                <x-sales::cancel-form :action="route('sales.collection.cancel', $collection)" />
            @endif
        @endcan
    </div>
</x-layouts.app>
