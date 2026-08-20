{{--
    আদায় — টাকা এসেছে।

    লাইনগুলো পণ্যের নয়, বিলের: এক টাকা কয়েকটা বিলে ভাগ হতে পারে। ভাগটা
    না রাখলে "কোন বিলটা এখনো বাকি" প্রশ্নের উত্তর থাকত না, শুধু মোট বকেয়া
    জানা যেত।
--}}
@php
    $isNew = ! $collection->exists;

    // বিল ধরে খোলা হলে ওই বিলের বকেয়াটাই আগে থেকে বসে
    $rows = $isNew && $invoice
        ? [['sales_invoice_id' => (string) $invoice->id, 'amount' => $invoice->dueAmount()]]
        : $collection->lines->map(fn ($l) => [
            'sales_invoice_id' => (string) $l->sales_invoice_id,
            'amount' => (string) $l->amount,
        ])->all();

    $existing = old('lines', $rows);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('sales::action.new_collection') : $collection->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('sales::action.new_collection') : $collection->document_no"
            :subtitle="__('sales::message.collection_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('sales.collection.store') : route('sales.collection.update', $collection) }}"
          class="space-y-4">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        @if ($errors->any())
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.select name="customer_id" :label="__('sales::field.customer')"
                             :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                             :selected="$invoice?->customer_id ?? $collection->customer_id"
                             placeholder="-" required />

                <x-ui.select name="account_id" :label="__('sales::field.account')"
                             :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->name()])"
                             :selected="$collection->account_id" placeholder="-" required />

                <x-ui.field name="trx_date" type="date" :label="__('sales::field.date')"
                            :value="old('trx_date', $collection->trx_date?->toDateString() ?? now()->toDateString())"
                            required />

                <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal"
                            :label="__('sales::field.amount')" numeric required
                            :value="old('amount', $isNew ? ($invoice?->dueAmount()) : $collection->amount)" />

                <x-ui.field name="instrument" :label="__('sales::field.instrument')"
                            :value="old('instrument', $collection->instrument)" />

                <x-ui.field name="instrument_no" :label="__('sales::field.instrument_no')"
                            :value="old('instrument_no', $collection->instrument_no)" />

                <x-ui.field name="instrument_date" type="date" :label="__('sales::field.instrument_date')"
                            :value="old('instrument_date', $collection->instrument_date?->toDateString())" />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('sales::field.narration')"
                            :value="old('narration', $collection->narration)" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('sales::message.lines') }}</h2>

            <div x-data="{
                    rows: {{ Illuminate\Support\Js::from($existing) }},
                    add() { this.rows.push({ sales_invoice_id: '', amount: '' }); },
                    remove(i) { this.rows.splice(i, 1); if (this.rows.length === 0) this.add(); },
                    get allocated() {
                        return this.rows.reduce((s, r) => s + (parseFloat(r.amount) || 0), 0);
                    },
                 }"
                 x-init="if (rows.length === 0) add()">

                <div class="table-responsive">
                    <table class="ui-lines table-cards w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('sales::field.invoice') }}</th>
                                <th class="text-end">{{ __('sales::field.amount') }}</th>
                                <th><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr class="border-b border-(--color-border)">
                                    <td class="cell-input" data-label="{{ __('sales::field.invoice') }}">
                                        <select :name="`lines[${i}][sales_invoice_id]`" x-model="row.sales_invoice_id"
                                                class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                                                       bg-(--color-surface-card) px-2">
                                            <option value="">-</option>
                                            @foreach ($openInvoices as $open)
                                                <option value="{{ $open->id }}">
                                                    {{ $open->document_no }} — {{ \App\Core\Support\Money::format($open->dueAmount()) }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="cell-input" data-label="{{ __('sales::field.amount') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="`lines[${i}][amount]`" x-model="row.amount"
                                               class="num h-(--spacing-field-compact) w-full sm:w-32 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>
                                    <td class="cell-input text-end">
                                        <button type="button" @click="remove(i)"
                                                class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                       hover:bg-(--color-surface-hover)">
                                            &times;<span class="sr-only">{{ __('sales::action.remove_line') }}</span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>

                        <tfoot>
                            <tr>
                                <td class="cell text-end font-medium">{{ __('sales::field.total') }}</td>
                                <td class="num cell font-semibold" x-text="allocated.toFixed(2)"></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <button type="button" @click="add()"
                        class="mt-2 rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm
                               transition-colors hover:bg-(--color-surface-hover)">
                    + {{ __('sales::action.add_line') }}
                </button>
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('sales.collection.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
