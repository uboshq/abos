{{--
    পরিশোধ — টাকা যাচ্ছে।

    লাইনগুলো পণ্যের নয়, বিলের: এক চেকে কয়েকটা চালানের টাকা যেতে পারে।
    ভাগটা না রাখলে "কোন বিলটা এখনো বাকি" প্রশ্নের উত্তর থাকত না, আর
    সরবরাহকারীর সাথে বসে মেলানোর সময় দুই পক্ষই আন্দাজ করত।
--}}
@php
    $isNew = ! $payment->exists;

    // বিল ধরে খোলা হলে ওই বিলের বকেয়াটাই আগে থেকে বসে
    $rows = $isNew && $bill
        ? [['purchase_bill_id' => (string) $bill->id, 'amount' => $bill->dueAmount()]]
        : $payment->lines->map(fn ($l) => [
            'purchase_bill_id' => (string) $l->purchase_bill_id,
            'amount' => (string) $l->amount,
        ])->all();

    $existing = old('lines', $rows);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('purchase::action.new_payment') : $payment->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('purchase::action.new_payment') : $payment->document_no"
            :subtitle="__('purchase::message.payment_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('purchase.payment.store') : route('purchase.payment.update', $payment) }}"
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

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.select name="supplier_id" :label="__('purchase::field.supplier')"
                             :options="$suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name()])"
                             :selected="$bill?->supplier_id ?? $payment->supplier_id"
                             placeholder="-" required />

                <x-ui.select name="account_id" :label="__('purchase::field.account')"
                             :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->name()])"
                             :selected="$payment->account_id" placeholder="-" required />

                <x-ui.field name="trx_date" type="date" :label="__('purchase::field.date')"
                            :value="old('trx_date', $payment->trx_date?->toDateString() ?? now()->toDateString())"
                            required />

                <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal"
                            :label="__('purchase::field.amount')" numeric required
                            :value="old('amount', $isNew ? ($bill?->dueAmount()) : $payment->amount)" />

                <x-ui.field name="instrument" :label="__('purchase::field.instrument')"
                            :value="old('instrument', $payment->instrument)" />

                <x-ui.field name="instrument_no" :label="__('purchase::field.instrument_no')"
                            :value="old('instrument_no', $payment->instrument_no)" />

                <x-ui.field name="instrument_date" type="date" :label="__('purchase::field.instrument_date')"
                            :value="old('instrument_date', $payment->instrument_date?->toDateString())" />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('purchase::field.narration')"
                            :value="old('narration', $payment->narration)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('purchase::message.payment_lines') }}</h2>

            <div x-data="{
                    rows: {{ Illuminate\Support\Js::from($existing) }},
                    add() { this.rows.push({ purchase_bill_id: '', amount: '' }); },
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
                                <th class="text-start">{{ __('purchase::field.bill') }}</th>
                                <th class="text-end">{{ __('purchase::field.amount') }}</th>
                                <th><span class="sr-only">{{ __('purchase::action.remove_line') }}</span></th>
                            </tr>
                        </thead>

                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr class="border-b border-(--color-border)">
                                    <td class="cell-input" data-label="{{ __('purchase::field.bill') }}">
                                        <select :name="`lines[${i}][purchase_bill_id]`" x-model="row.purchase_bill_id"
                                                class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                                                       bg-(--color-surface-card) px-2">
                                            <option value="">-</option>
                                            @foreach ($openBills as $open)
                                                <option value="{{ $open->id }}">
                                                    {{ $open->document_no }} — {{ \App\Core\Support\Money::format($open->dueAmount()) }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="cell-input" data-label="{{ __('purchase::field.amount') }}">
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
                                            &times;<span class="sr-only">{{ __('purchase::action.remove_line') }}</span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>

                        <tfoot>
                            <tr>
                                <td class="cell text-end font-medium">{{ __('purchase::field.total') }}</td>
                                <td class="num cell font-semibold" x-text="allocated.toFixed(2)"></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <button type="button" @click="add()"
                        class="mt-2 rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm
                               transition-colors hover:bg-(--color-surface-hover)">
                    + {{ __('purchase::action.add_line') }}
                </button>
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('purchase.payment.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
