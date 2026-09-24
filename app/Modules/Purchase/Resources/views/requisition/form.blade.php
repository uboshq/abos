{{--
    একটা চাহিদা লেখা।

    ── ⚠️ এখানে সরবরাহকারীর ঘর নেই, আর সেটা ইচ্ছাকৃত ────────────────────
    ⓘ যিনি চান তিনি জানেন **কী** লাগবে; ⛔ **কার কাছ থেকে** সেটা ক্রয়
    বিভাগের সিদ্ধান্ত। ঘরটা রাখলে ঐ সিদ্ধান্তটা নীরবে বিভাগের হাত থেকে
    বেরিয়ে যেত, আর একদিন চাহিদাপত্রেই সরবরাহকারী বাছা হত।

    ── ⓘ দরের ঘরটা "আন্দাজ", দাম নয় ────────────────────────────────────
    ⚠️ যিনি চান তিনি দর জানেন না, আর জানার কথাও নয়। ⭐ তবু একটা আন্দাজ
    থাকলে অনুমোদনকারী বুঝতে পারেন কাগজটা দশ হাজারের না দশ লাখের — আর
    সেটাই অনুমোদনের ছকের প্রশ্ন।
--}}
@php
    $existing = old('lines', [['product_id' => '', 'qty' => '', 'estimated_rate' => '']]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('purchase::action.new_requisition') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('purchase::action.new_requisition')"
                          :subtitle="__('purchase::message.requisition_note')" />
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

    <form method="POST" action="{{ route('purchase.requisition.store') }}" class="space-y-4">
        @csrf

        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <x-ui.field name="trx_date" type="date" :label="__('purchase::field.date')"
                            :value="old('trx_date', now()->toDateString())" required />

                {{-- ⭐ কবে লাগবে — তালিকার ক্রম এই ঘরটাই ঠিক করে।
                     ⚠️ খালি রাখা যায়, কিন্তু তখন কাগজটা তালিকার শেষে
                     পড়ে থাকে, আর সেটাই সঠিক আচরণ: যে তারিখ বলেনি তার
                     তাড়া মাপার কোনো উপায় নেই। --}}
                <x-ui.field name="needed_by" type="date" :label="__('purchase::field.needed_by')"
                            :value="old('needed_by')" />

                <x-ui.field name="department" :label="__('purchase::field.department')"
                            :value="old('department')" />

                <x-ui.field name="purpose" :label="__('purchase::field.purpose')"
                            :value="old('purpose')" />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('purchase::field.narration')"
                            :value="old('narration')" />
            </div>
        </section>

        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('purchase::field.items') }}</h2>

            <div x-data="lineRows({
                rows: @js($existing),
                blank: { product_id: '', qty: '', estimated_rate: '' },
            })">
                <div class="table-responsive">
                    <table class="ui-lines table-cards w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-start">{{ __('purchase::field.product') }}</th>
                                <th class="text-end">{{ __('purchase::field.quantity') }}</th>
                                <th class="text-end">{{ __('purchase::field.estimated_rate') }}</th>
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

                                    <td class="cell-input" data-label="{{ __('purchase::field.estimated_rate') }}">
                                        <input type="number" step="0.01" inputmode="decimal"
                                               :name="'lines[' + i + '][estimated_rate]'"
                                               x-model="row.estimated_rate"
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
            <x-ui.button tone="secondary" :href="route('purchase.requisition.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
