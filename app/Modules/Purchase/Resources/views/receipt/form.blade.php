{{--
    মাল বুঝে নেওয়া।

    আদেশ ধরে খোলা হলে লাইনগুলো আগে থেকে ভরা থাকে — যা বাকি ঠিক ততটুকু
    নিয়ে। গুদামের লোক ট্রাকের পাশে দাঁড়িয়ে এটা লেখেন; প্রতিটা লাইন হাতে
    খুঁজতে বললে তাড়াহুড়োয় ভুল পণ্য বাছা হত।
--}}
@php
    $isNew = ! $receipt->exists;

    // আদেশ থেকে আসা লাইন, নাকি এই চালানের নিজের লাইন
    $seed = $isNew && $order
        ? $order->lines
            ->filter(fn ($l) => bccomp($l->pendingQty(), '0', 4) > 0)
            ->map(fn ($l) => [
                'product_id' => (string) $l->product_id,
                'qty' => rtrim(rtrim($l->pendingQty(), '0'), '.'),
                'rate' => (string) $l->rate,
                'discount' => '',
                'tax' => '',
                'link' => (string) $l->id,
            ])->values()->all()
        : $receipt->lines->map(fn ($l) => [
            'product_id' => (string) $l->product_id,
            'qty' => (string) $l->received_qty,
            'rate' => (string) $l->rate,

            // খালি রাখা মানে "দাম বদলাব না" — তাই null-টা খালিই ফেরে,
            // শূন্য নয়। শূন্য দেখালে সম্পাদনার সময় প্রতিটা লাইন পণ্যের
            // দাম শূন্য করে দিত।
            'sales_price' => $l->sales_price === null ? '' : (string) $l->sales_price,
            'discount' => '',
            'tax' => '',
            'link' => (string) ($l->purchase_order_line_id ?? ''),
        ])->all();

    $existing = old('lines', $seed);

    $orderLines = ($order?->lines ?? collect())->mapWithKeys(fn ($l) => [
        $l->id => ($l->product?->code ?? '') . ' - ' . rtrim(rtrim((string) $l->ordered_qty, '0'), '.'),
    ]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('purchase::action.new_receipt') : $receipt->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('purchase::action.new_receipt') : $receipt->document_no"
            :subtitle="__('purchase::message.receipt_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('purchase.receipt.store') : route('purchase.receipt.update', $receipt) }}"
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
                <x-ui.select name="purchase_order_id" :label="__('purchase::field.order')"
                             :options="$orders->mapWithKeys(fn ($o) => [$o->id => $o->document_no . ' - ' . $o->supplier?->name()])"
                             :selected="$order?->id ?? $receipt->purchase_order_id"
                             placeholder="-" />

                <x-ui.select name="supplier_id" :label="__('purchase::field.supplier')"
                             :options="$suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name()])"
                             :selected="$order?->supplier_id ?? $receipt->supplier_id"
                             placeholder="-" required />

                <x-ui.select name="warehouse_id" :label="__('purchase::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             :selected="$receipt->warehouse_id ?? $order?->warehouse_id"
                             placeholder="-" required />

                <x-ui.field name="trx_date" type="date" :label="__('purchase::field.date')"
                            :value="old('trx_date', $receipt->trx_date?->toDateString() ?? now()->toDateString())"
                            required />
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-ui.field name="supplier_challan_no" :label="__('purchase::field.supplier_challan_no')"
                            :value="old('supplier_challan_no', $receipt->supplier_challan_no)" />

                <x-ui.field name="narration" :label="__('purchase::field.narration')"
                            :value="old('narration', $receipt->narration)" />
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('purchase::message.lines') }}</h2>

            <x-purchase::line-editor lots :products="$products" :lines="$existing"
                                     qty-field="received_qty"
                                     :link-field="$orderLines->isNotEmpty() ? 'purchase_order_line_id' : null"
                                     :link-options="$orderLines"
                                     :show-discount="false"
                                     show-sales-price />
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('purchase.receipt.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
