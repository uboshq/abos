{{--
    ⭐ অফিসের নতুন DO — ডিলার, তারপর এক লাইনে এক পণ্য আর পরিমাণ (মালিকের নিয়ম)।
    ⛔ দামের ঘর নেই — দাম পণ্যের ([[DeliveryOrderService::create()]])। "খসড়া রাখুন" বা "জমা দিন"; জমার পরে লেখক আর বদলান না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::delivery_order.new') }}</x-slot:title>

    <x-ui.errors />

    <form method="POST" action="{{ route('sales.delivery_order.store') }}" class="grid gap-4" data-do-form>
        @csrf

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <x-ui.select name="customer_id" :label="__('sales::field.customer')"
                             :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                             :selected="old('customer_id')" placeholder="-" required />
                <x-ui.field name="deliver_on" type="date" :label="__('sales::delivery_order.deliver_on')"
                            :value="old('deliver_on')" />
                <x-ui.field name="narration" :label="__('sales::field.narration')" :value="old('narration')" />
            </div>
        </section>

        <section data-boxed class="grid gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            @for ($i = 0; $i < 10; $i++)
                <div class="grid gap-2 sm:grid-cols-2">
                    <select name="lines[{{ $i }}][product_id]" aria-label="{{ __('sales::delivery_order.pick_product') }}"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-3">
                        <option value="">{{ __('sales::delivery_order.pick_product') }}</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected((string) old("lines.$i.product_id") === (string) $product->id)>
                                {{ $product->name() }} — {{ \App\Core\Support\Money::format($product->sale_price) }}
                            </option>
                        @endforeach
                    </select>
                    <input type="number" step="1" min="0" name="lines[{{ $i }}][qty]" value="{{ old("lines.$i.qty") }}"
                           placeholder="{{ __('sales::delivery_order.qty') }}" aria-label="{{ __('sales::delivery_order.qty') }}"
                           class="num h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-3 text-end">
                </div>
            @endfor
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary" name="submit" value="1">{{ __('sales::delivery_order.submit') }}</x-ui.button>
            {{-- ⓘ ধূসর — মালিক, ২৭ সেপ্টেম্বর ২০২৬: "খসড়া রাখুন" ধূসর --}}
            <x-ui.button type="submit" tone="neutral">{{ __('sales::delivery_order.keep_draft') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
