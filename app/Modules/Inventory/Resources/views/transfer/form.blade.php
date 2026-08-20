{{--
    স্টক স্থানান্তর — কোন গুদাম থেকে কোন গুদামে, আর কী কী।

    দর নেই: গুদাম বদলালে মালের মূল্য বদলায় না। দরের ঘর থাকলে কেউ ভাবতেন
    ওটা দিয়ে কিছু একটা হিসাব হচ্ছে।
--}}
@php
    $isNew = ! $transfer->exists;

    $rows = $transfer->lines->map(fn ($l) => [
        'product_id' => (string) $l->product_id,
        'qty' => (string) $l->qty,
    ])->all();

    $existing = old('lines', $rows);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('inventory::action.new_transfer') : $transfer->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('inventory::action.new_transfer') : $transfer->document_no"
            :subtitle="__('inventory::message.transfer_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('inventory.transfer.store') : route('inventory.transfer.update', $transfer) }}"
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
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <x-ui.select name="from_warehouse_id" :label="__('inventory::field.from_warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             :selected="$transfer->from_warehouse_id" placeholder="-" required />

                <x-ui.select name="to_warehouse_id" :label="__('inventory::field.to_warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             :selected="$transfer->to_warehouse_id" placeholder="-" required />

                <x-ui.field name="trx_date" type="date" :label="__('inventory::field.date')"
                            :value="old('trx_date', $transfer->trx_date?->toDateString() ?? now()->toDateString())"
                            required />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('inventory::field.narration')"
                            :value="old('narration', $transfer->narration)" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('inventory::message.lines') }}</h2>

            <div x-data="{
                    rows: {{ Illuminate\Support\Js::from($existing) }},
                    add() { this.rows.push({ product_id: '', qty: '' }); },
                    remove(i) { this.rows.splice(i, 1); if (this.rows.length === 0) this.add(); },
                 }"
                 x-init="if (rows.length === 0) add()">

                <div class="table-responsive">
                    <table class="ui-lines table-cards w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('inventory::field.product') }}</th>
                                <th class="text-end">{{ __('inventory::field.quantity') }}</th>
                                <th><span class="sr-only">{{ __('inventory::action.remove_line') }}</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr class="border-b border-(--color-border)">
                                    <td class="cell-input" data-label="{{ __('inventory::field.product') }}">
                                        <select :name="`lines[${i}][product_id]`" x-model="row.product_id"
                                                class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                                                       bg-(--color-surface-card) px-2">
                                            <option value="">-</option>
                                            @foreach ($products as $product)
                                                <option value="{{ $product->id }}">{{ $product->name() }}</option>
                                            @endforeach
                                        </select>
                                    </td>

                                    <td class="cell-input" data-label="{{ __('inventory::field.quantity') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="`lines[${i}][qty]`" x-model="row.qty"
                                               class="num h-(--spacing-field-compact) w-full sm:w-28 rounded-(--radius-field)
                                                      border border-(--color-border)
                                                      bg-(--color-surface-card) px-2 text-end">
                                    </td>

                                    <td class="cell-input text-end">
                                        <button type="button" @click="remove(i)"
                                                class="rounded-(--radius-field) px-2 py-1 text-(--color-ink-muted)
                                                       hover:bg-(--color-surface-hover)">
                                            &times;<span class="sr-only">{{ __('inventory::action.remove_line') }}</span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <button type="button" @click="add()"
                        class="mt-2 rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm
                               transition-colors hover:bg-(--color-surface-hover)">
                    + {{ __('inventory::action.add_line') }}
                </button>
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('inventory.transfer.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
