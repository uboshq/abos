{{--
    একটা ক্রয় চুক্তি লেখা।

    ── ⚠️ সীমার ঘর দুইটা, আর দুইটাই খালি রাখা যায় ──────────────────────
    ⓘ কিছু চুক্তি বলে *"এই দরে দশ টন দেব"*, কিছু বলে *"এই দরে পাঁচ লাখ
    টাকার মাল দেব"*। ⛔ একটামাত্র সীমা রাখলে দ্বিতীয় জাতের চুক্তিটা
    লেখাই যেত না, আর মানুষ পরিমাণের ঘরে টাকার অঙ্ক বসাতেন।
--}}
@php
    $existing = old('lines', [['product_id' => '', 'agreed_rate' => '', 'qty_limit' => '', 'value_limit' => '']]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::action.new_contract') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('purchase::action.new_contract')"
                          :subtitle="__('purchase::message.contract_note')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($suppliers->isEmpty())
        <x-ui.empty-state :message="__('purchase::message.rfq_no_suppliers')" />
    @else
        <form method="POST" action="{{ route('purchase.contract.store') }}" class="space-y-4">
            @csrf

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <x-ui.select name="supplier_id" :label="__('purchase::field.supplier')"
                                 :options="$suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name()])"
                                 :selected="old('supplier_id')" placeholder="-" required />

                    <x-ui.field name="supplier_ref" :label="__('purchase::field.supplier_ref')"
                                :value="old('supplier_ref')" />

                    <x-ui.field name="starts_on" type="date" :label="__('purchase::field.starts_on')"
                                :value="old('starts_on', now()->toDateString())" required />

                    {{-- ⛔ শেষ তারিখ শুরুর আগে হলে সেবা থামিয়ে দেয় — ⚠️
                         নাহলে চুক্তিটা লেখা থাকত অথচ কোনোদিন খাটত না,
                         আর সেই ব্যর্থতাটা সম্পূর্ণ নীরব। --}}
                    <x-ui.field name="ends_on" type="date" :label="__('purchase::field.ends_on')"
                                :value="old('ends_on', now()->addYear()->toDateString())" required />
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="terms" :label="__('purchase::field.terms')" :value="old('terms')" />
                    <x-ui.field name="narration" :label="__('purchase::field.narration')"
                                :value="old('narration')" />
                </div>
            </section>

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('purchase::field.agreed_rates') }}</h2>
                <p class="mb-3 text-xs text-(--color-ink-muted)">
                    {{ __('purchase::message.contract_limit_note') }}
                </p>

                <div x-data="lineRows({
                    rows: @js($existing),
                    blank: { product_id: '', agreed_rate: '', qty_limit: '', value_limit: '' },
                })">
                    <div class="table-responsive">
                        <table class="ui-lines table-cards w-full text-sm">
                            <thead>
                                <tr>
                                    <th class="text-start">{{ __('purchase::field.product') }}</th>
                                    <th class="text-end">{{ __('purchase::field.agreed_rate') }}</th>
                                    <th class="text-end">{{ __('purchase::field.qty_limit') }}</th>
                                    <th class="text-end">{{ __('purchase::field.value_limit') }}</th>
                                    <th><span class="sr-only">{{ __('purchase::action.remove_line') }}</span></th>
                                </tr>
                            </thead>

                            <tbody>
                                <template x-for="(row, i) in rows" :key="i">
                                    <tr class="border-b border-(--color-border)">
                                        <td class="cell-input" data-label="{{ __('purchase::field.product') }}">
                                            <select :name="'lines[' + i + '][product_id]'" x-model="row.product_id"
                                                    class="h-(--spacing-field-compact) w-full rounded-(--radius-field)
                                                           border border-(--color-border)
                                                           bg-(--color-surface-card) px-2">
                                                <option value="">-</option>
                                                @foreach ($products as $product)
                                                    <option value="{{ $product->id }}">{{ $product->name() }}</option>
                                                @endforeach
                                            </select>
                                        </td>

                                        <td class="cell-input" data-label="{{ __('purchase::field.agreed_rate') }}">
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   :name="'lines[' + i + '][agreed_rate]'" x-model="row.agreed_rate"
                                                   class="num h-(--spacing-field-compact) w-full sm:w-28
                                                          rounded-(--radius-field) border border-(--color-border)
                                                          bg-(--color-surface-card) px-2 text-end">
                                        </td>

                                        <td class="cell-input" data-label="{{ __('purchase::field.qty_limit') }}">
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   :name="'lines[' + i + '][qty_limit]'" x-model="row.qty_limit"
                                                   class="num h-(--spacing-field-compact) w-full sm:w-28
                                                          rounded-(--radius-field) border border-(--color-border)
                                                          bg-(--color-surface-card) px-2 text-end">
                                        </td>

                                        <td class="cell-input" data-label="{{ __('purchase::field.value_limit') }}">
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   :name="'lines[' + i + '][value_limit]'" x-model="row.value_limit"
                                                   class="num h-(--spacing-field-compact) w-full sm:w-28
                                                          rounded-(--radius-field) border border-(--color-border)
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
                <x-ui.button tone="secondary" :href="route('purchase.contract.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.app>
