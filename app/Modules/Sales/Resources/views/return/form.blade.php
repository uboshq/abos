{{--
    বিক্রয় ফেরত — মাল ফিরে এসেছে।

    প্রতিটা লাইনে একটা টিক: মালটা আবার বেচা যাবে কি না। নষ্ট বা মেয়াদ
    পেরোনো মাল গুদামে ঢোকে ঠিকই, কিন্তু Hold-এ যায় — নাহলে ফেরত আসা
    নষ্ট মাল পরদিন আবার কারও কাছে চলে যেত।
--}}
@php
    $isNew = ! $return->exists;

    $rows = $isNew && $invoice
        ? $invoice->lines->map(fn ($l) => [
            'product_id' => (string) $l->product_id,
            'sales_invoice_line_id' => (string) $l->id,
            'qty' => '',
            'rate' => (string) $l->rate,
            'tax' => '',
            'to_hold' => false,
        ])->all()
        : $return->lines->map(fn ($l) => [
            'product_id' => (string) $l->product_id,
            'sales_invoice_line_id' => (string) ($l->sales_invoice_line_id ?? ''),
            'qty' => (string) $l->qty,
            'rate' => (string) $l->rate,
            'tax' => (string) $l->tax,
            'to_hold' => (bool) $l->to_hold,
        ])->all();

    $existing = old('lines', $rows);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('sales::action.new_return') : $return->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('sales::action.new_return') : $return->document_no"
            :subtitle="__('sales::message.return_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('sales.return.store') : route('sales.return.update', $return) }}"
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
                             :selected="$invoice?->customer_id ?? $return->customer_id"
                             placeholder="-" required />

                <x-ui.select name="warehouse_id" :label="__('sales::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             :selected="$return->warehouse_id" placeholder="-" required />

                <x-ui.field name="trx_date" type="date" :label="__('sales::field.date')"
                            :value="old('trx_date', $return->trx_date?->toDateString() ?? now()->toDateString())"
                            required />

                <x-ui.select name="sales_invoice_id" :label="__('sales::field.invoice')"
                             :options="$invoices->mapWithKeys(fn ($i) => [$i->id => $i->document_no.' — '.$i->customer?->name()])"
                             :selected="$invoice?->id ?? $return->sales_invoice_id" placeholder="-" />

                <x-ui.select name="reason_code_id" :label="__('sales::field.reason')"
                             :options="$reasons->mapWithKeys(fn ($r) => [$r->id => $r->name()])"
                             :selected="$return->reason_code_id" placeholder="-" />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('sales::field.narration')"
                            :value="old('narration', $return->narration)" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('sales::message.lines') }}</h2>

            <div x-data="{
                    rows: {{ Illuminate\Support\Js::from($existing) }},
                    add() { this.rows.push({ product_id: '', sales_invoice_line_id: '', qty: '', rate: '', tax: '', to_hold: false }); },
                    remove(i) { this.rows.splice(i, 1); if (this.rows.length === 0) this.add(); },
                    amount(row) {
                        return (parseFloat(row.qty) || 0) * (parseFloat(row.rate) || 0) + (parseFloat(row.tax) || 0);
                    },
                    get total() { return this.rows.reduce((s, r) => s + this.amount(r), 0); },
                 }"
                 x-init="if (rows.length === 0) add()">

                <div class="table-responsive">
                    <table class="table-cards w-full text-sm">
                        <thead class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                            <tr>
                                <th class="p-2 text-start font-medium">{{ __('sales::field.product') }}</th>
                                <th class="p-2 text-end font-medium">{{ __('sales::field.quantity') }}</th>
                                <th class="p-2 text-end font-medium">{{ __('sales::field.rate') }}</th>
                                <th class="p-2 text-end font-medium">{{ __('sales::field.tax') }}</th>
                                <th class="p-2 text-start font-medium">{{ __('sales::field.not_sellable') }}</th>
                                <th class="p-2 text-end font-medium">{{ __('sales::field.amount') }}</th>
                                <th class="p-2"><span class="sr-only">{{ __('sales::action.remove_line') }}</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr class="border-b border-(--color-border)">
                                    <td class="p-1" data-label="{{ __('sales::field.product') }}">
                                        <select :name="`lines[${i}][product_id]`" x-model="row.product_id"
                                                class="h-9 w-full rounded-(--radius-field) border border-(--color-border)
                                                       bg-(--color-surface-card) px-2">
                                            <option value="">-</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}">{{ $product->name() }}</option>
                                            @endforeach
                                        </select>

                                        <input type="hidden" :name="`lines[${i}][sales_invoice_line_id]`"
                                               x-model="row.sales_invoice_line_id">
                                    </td>

                                    <td class="p-1" data-label="{{ __('sales::field.quantity') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="`lines[${i}][qty]`" x-model="row.qty"
                                               class="num h-9 w-full sm:w-24 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>

                                    <td class="p-1" data-label="{{ __('sales::field.rate') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="`lines[${i}][rate]`" x-model="row.rate"
                                               class="num h-9 w-full sm:w-24 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>

                                    <td class="p-1" data-label="{{ __('sales::field.tax') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="`lines[${i}][tax]`" x-model="row.tax"
                                               class="num h-9 w-full sm:w-24 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>

                                    {{-- আবার বেচা যাবে না — টিক দিলে মালটা গুদামে
                                         ঢুকবে কিন্তু Hold-এ থাকবে --}}
                                    <td class="p-1" data-label="{{ __('sales::field.not_sellable') }}">
                                        <input type="hidden" :name="`lines[${i}][to_hold]`" value="0">
                                        <input type="checkbox" :name="`lines[${i}][to_hold]`" value="1"
                                               x-model="row.to_hold" class="size-4">
                                    </td>

                                    <td class="num p-1 text-end" data-label="{{ __('sales::field.amount') }}"
                                        x-text="amount(row).toFixed(2)"></td>

                                    <td class="p-1 text-end">
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
                                <td colspan="5" class="p-2 text-end font-medium">{{ __('sales::field.total') }}</td>
                                <td class="num p-2 text-end font-semibold" x-text="total.toFixed(2)"></td>
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
            <x-ui.button tone="secondary" :href="route('sales.return.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
