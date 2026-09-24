{{--
    একটা দরপত্রের অনুরোধ লেখা।

    ── ⚠️ এখানে দরের কোনো ঘর নেই, আর সেটাই পুরো কথা ────────────────────
    ⓘ RFQ-র উদ্দেশ্যই দর **জিজ্ঞেস করা**। ⛔ আমাদের একটা আন্দাজ পাঠিয়ে
    দিলে সরবরাহকারী ওটাই বলতেন, আর দরপত্রের কোনো মানে থাকত না।

    ── ⓘ সরবরাহকারীর তালিকা কেন টিক-ঘরে ────────────────────────────────
    ⚠️ একজন বাছার ড্রপডাউন দিলে RFQ-র গোটা কারণটাই নষ্ট হত: একাধিক
    জনের কাছে জিজ্ঞেস করাই তো তুলনার ভিত্তি।
--}}
@php
    $existing = old('lines', [['product_id' => '', 'qty' => '', 'specification' => '']]);
    $chosen = old('suppliers', []);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::action.new_rfq') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('purchase::action.new_rfq')"
                          :subtitle="__('purchase::message.rfq_note')" />
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
        <form method="POST" action="{{ route('purchase.rfq.store') }}" class="space-y-4">
            @csrf

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <x-ui.field name="trx_date" type="date" :label="__('purchase::field.date')"
                                :value="old('trx_date', now()->toDateString())" required />

                    {{-- ⭐ কবের মধ্যে জবাব চাই — ⚠️ তারিখ ছাড়া একটা অনুরোধের
                         কোনো শেষ নেই, আর *"কার জবাব আসেনি"* প্রশ্নটাই ওঠে না। --}}
                    <x-ui.field name="respond_by" type="date" :label="__('purchase::field.respond_by')"
                                :value="old('respond_by')" />

                    <x-ui.select name="warehouse_id" :label="__('purchase::field.warehouse')"
                                 :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                                 :selected="old('warehouse_id')" placeholder="-" />

                    <x-ui.field name="terms" :label="__('purchase::field.terms')"
                                :value="old('terms')" />
                </div>

                <div class="mt-3">
                    <x-ui.field name="narration" :label="__('purchase::field.narration')"
                                :value="old('narration')" />
                </div>
            </section>

            {{-- ── কাকে কাকে জিজ্ঞেস করব ───────────────────────────────── --}}
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 font-semibold">{{ __('purchase::field.ask_these') }}</h2>
                <p class="mb-3 text-xs text-(--color-ink-muted)">
                    {{ __('purchase::message.rfq_ask_note') }}
                </p>

                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($suppliers as $supplier)
                        <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm">
                            <input type="checkbox" name="suppliers[]" value="{{ $supplier->id }}"
                                   @checked(in_array((string) $supplier->id, array_map('strval', $chosen), true))
                                   class="mt-1 size-4">
                            <span>{{ $supplier->name() }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            {{-- ── কী চাই ───────────────────────────────────────────────── --}}
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('purchase::field.items') }}</h2>

                <div x-data="lineRows({
                    rows: @js($existing),
                    blank: { product_id: '', qty: '', specification: '' },
                })">
                    <div class="table-responsive">
                        <table class="ui-lines table-cards w-full text-sm">
                            <thead>
                                <tr>
                                    <th class="text-start">{{ __('purchase::field.product') }}</th>
                                    <th class="text-end">{{ __('purchase::field.quantity') }}</th>
                                    <th class="text-start">{{ __('purchase::field.specification') }}</th>
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

                                        <td class="cell-input" data-label="{{ __('purchase::field.quantity') }}">
                                            <input type="number" step="0.01" inputmode="decimal"
                                                   :name="'lines[' + i + '][qty]'" x-model="row.qty"
                                                   class="num h-(--spacing-field-compact) w-full sm:w-28
                                                          rounded-(--radius-field) border border-(--color-border)
                                                          bg-(--color-surface-card) px-2 text-end">
                                        </td>

                                        {{-- ⓘ মাপ, ব্র্যান্ড, গুণমান — ⚠️ এটা না
                                             লিখলে তিনজন তিন জিনিসের দর দেবেন, আর
                                             তুলনাটা অর্থহীন হয়ে যাবে। --}}
                                        <td class="cell-input" data-label="{{ __('purchase::field.specification') }}">
                                            <input type="text"
                                                   :name="'lines[' + i + '][specification]'"
                                                   x-model="row.specification"
                                                   class="h-(--spacing-field-compact) w-full rounded-(--radius-field)
                                                          border border-(--color-border)
                                                          bg-(--color-surface-card) px-2">
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
                <x-ui.button tone="secondary" :href="route('purchase.rfq.index')">
                    {{ __('core.action.cancel') }}
                </x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.app>
