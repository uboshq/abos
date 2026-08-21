{{--
    বিক্রয় আদেশ — তৈরি ও সম্পাদনা।

    সম্পাদনা কেবল খসড়া অবস্থায়। নিশ্চিত হওয়ার পর বদলাতে হলে বাতিল করে
    নতুন করতে হয়, কারণ ওই ডকুমেন্টের উপর স্টক বা খতিয়ান ভর করে আছে।
--}}
@php
    $isNew = ! $order->exists;

    $seed = $order->lines->map(fn ($l) => [
        'product_id' => (string) $l->product_id,
        'qty' => (string) $l->ordered_qty,
        'rate' => (string) $l->rate,
        'discount' => (string) $l->discount,
        'tax' => (string) $l->tax,
        'link' => '',
    ])->all();

    $existing = old('lines', $seed);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('sales::action.new_order') : $order->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('sales::action.new_order') : $order->document_no"
            :subtitle="__('sales::message.order_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('sales.order.store') : route('sales.order.update', $order) }}"
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
                <x-ui.select name="customer_id" :label="__('sales::field.customer')"
                             :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                             :selected="$order->customer_id" placeholder="-" required />
                <x-ui.select name="warehouse_id" :label="__('sales::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             :selected="$order->warehouse_id" placeholder="-" />
                <x-ui.field name="trx_date" type="date" :label="__('sales::field.date')"
                            :value="old('trx_date', $order->trx_date?->toDateString() ?? now()->toDateString())"
                            required />
                <x-ui.field name="deliver_on" type="date" :label="__('sales::field.deliver_on')"
                            :value="old('deliver_on', $order->deliver_on?->toDateString())" />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('sales::field.narration')"
                            :value="old('narration', $order->narration)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('sales::message.lines') }}</h2>

            <x-sales::line-editor :products="$products" :lines="$existing"
                                  qty-field="ordered_qty"
                                  :link-field="null"
                                  :link-options="[]"
                                  :show-discount="true" />
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('sales.order.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
