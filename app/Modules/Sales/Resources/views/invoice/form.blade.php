{--
    বিক্রয় বিল — তৈরি ও সম্পাদনা।

    সম্পাদনা কেবল খসড়া অবস্থায়। নিশ্চিত হওয়ার পর বদলাতে হলে বাতিল করে
    নতুন করতে হয়, কারণ ওই ডকুমেন্টের উপর স্টক বা খতিয়ান ভর করে আছে।
--}
@php
    $isNew = ! $invoice->exists;

    // চালান ধরে খোলা হলে যতটুকুর বিল হয়নি ঠিক ততটুকু
    $seed = $isNew && $challan
        ? $challan->lines
            ->filter(fn ($l) => bccomp($l->uninvoicedQty(), '0', 4) > 0)
            ->map(fn ($l) => [
                'product_id' => (string) $l->product_id,
                'qty' => rtrim(rtrim($l->uninvoicedQty(), '0'), '.'),
                'rate' => (string) $l->rate,
                'discount' => '',
                'tax' => '',
                'link' => (string) $l->id,
            ])->values()->all()
        : $invoice->lines->map(fn ($l) => [
            'product_id' => (string) $l->product_id,
            'qty' => (string) $l->qty,
            'rate' => (string) $l->rate,
            'discount' => (string) $l->discount,
            'tax' => (string) $l->tax,
            'link' => (string) ($l->delivery_challan_line_id ?? ''),
        ])->all();

    $challanLines = ($challan?->lines ?? collect())->mapWithKeys(fn ($l) => [
        $l->id => ($l->product?->code ?? '') . ' - ' . rtrim(rtrim($l->uninvoicedQty(), '0'), '.'),
    ]);

    $existing = old('lines', $seed);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('sales::action.new_invoice') : $invoice->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('sales::action.new_invoice') : $invoice->document_no"
            :subtitle="__('sales::message.invoice_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('sales.invoice.store') : route('sales.invoice.update', $invoice) }}"
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
                             :selected="$challan?->customer_id ?? $invoice->customer_id" placeholder="-" required />
                <x-ui.select name="warehouse_id" :label="__('sales::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             :selected="$invoice->warehouse_id ?? $challan?->warehouse_id"
                             placeholder="-" />
                <x-ui.field name="trx_date" type="date" :label="__('sales::field.date')"
                            :value="old('trx_date', $invoice->trx_date?->toDateString() ?? now()->toDateString())"
                            required />
                <x-ui.field name="due_on" type="date" :label="__('sales::field.due_on')"
                            :value="old('due_on', $invoice->due_on?->toDateString())" />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('sales::field.narration')"
                            :value="old('narration', $invoice->narration)" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('sales::message.lines') }}</h2>

            <x-sales::line-editor :products="$products" :lines="$existing"
                                  qty-field="qty"
                                  :link-field="$challanLines->isNotEmpty() ? 'delivery_challan_line_id' : null"
                                  :link-options="$challanLines"
                                  :show-discount="true" />
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('sales.invoice.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
