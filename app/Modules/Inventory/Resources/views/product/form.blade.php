{{--
    পণ্য তৈরি ও সম্পাদনা — একটাই ফর্ম, দুই কাজে (One Form Standard)।
--}}
@php
    $isNew = ! $product->exists;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('inventory::action.new_product') : $product->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('inventory::action.new_product') : __('inventory::action.edit')"
            :subtitle="$isNew ? __('inventory::message.code_auto') : $product->code" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('inventory.product.store') : route('inventory.product.update', $product) }}"
          x-data="{ busy: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-3xl space-y-4">
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
            <h2 class="mb-3 font-semibold">{{ __('inventory::section.identity') }}</h2>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-ui.field name="code" :label="__('inventory::field.code')"
                            :value="old('code', $product->code)"
                            :hint="$isNew ? __('inventory::message.code_auto') : null" />

                <x-ui.field name="name_en" :label="__('inventory::field.name_en')"
                            :value="old('name_en', $product->name_en)" required />

                <x-ui.field name="name_bn" :label="__('inventory::field.name_bn')"
                            :value="old('name_bn', $product->name_bn)" />

                {{-- বারকোড — কাউন্টারে স্ক্যানার এই নম্বরটাই পাঠায় --}}
                <x-ui.field name="barcode" :label="__('inventory::field.barcode')"
                            :value="old('barcode', $product->barcode)"
                            :hint="__('inventory::message.barcode_hint')" />

                @if ($brandOn)
                    <x-ui.field name="brand" :label="__('inventory::field.brand')"
                                :value="old('brand', $product->brand)" />
                @endif

                <x-ui.field name="category" :label="__('inventory::field.category')"
                            :value="old('category', $product->category)" />

                <x-ui.select name="unit_id" :label="__('inventory::field.unit')"
                             :options="$units->mapWithKeys(fn ($u) => [$u->id => $u->name()])"
                             :selected="$product->unit_id"
                             placeholder="-" />

                <x-ui.select name="tax_id" :label="__('inventory::field.tax')"
                             :options="$taxes->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="$product->tax_id"
                             placeholder="-" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('inventory::section.pricing') }}</h2>

            <div class="grid gap-3 sm:grid-cols-3">
                {{-- inputmode="decimal" — টাকার ঘরে ফোনে সংখ্যার কী-বোর্ড --}}
                <x-ui.field name="purchase_price" type="number" step="0.01" inputmode="decimal"
                            :label="__('inventory::field.purchase_price')"
                            :value="old('purchase_price', $product->purchase_price)" numeric />

                <x-ui.field name="sale_price" type="number" step="0.01" inputmode="decimal"
                            :label="__('inventory::field.sale_price')"
                            :value="old('sale_price', $product->sale_price)" numeric />

                <x-ui.field name="reorder_level" type="number" step="0.01" inputmode="decimal"
                            :label="__('inventory::field.reorder_level')"
                            :value="old('reorder_level', $product->reorder_level)"
                            :hint="__('inventory::message.reorder_hint')" numeric />
            </div>
        </section>

        <x-ui.custom-fields :record="$product" />

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary"
                         ::class="busy && 'pointer-events-none opacity-70'">
                {{ __('core.action.save') }}
            </x-ui.button>

            <x-ui.button tone="secondary"
                         :href="$isNew ? route('inventory.product.index') : route('inventory.product.show', $product)">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
