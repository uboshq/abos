{{--
    ডেলিভারি চালান — তৈরি ও সম্পাদনা।

    সম্পাদনা কেবল খসড়া অবস্থায়। নিশ্চিত হওয়ার পর বদলাতে হলে বাতিল করে
    নতুন করতে হয়, কারণ ওই ডকুমেন্টের উপর স্টক বা খতিয়ান ভর করে আছে।
--}}
@php
    $isNew = ! $challan->exists;

    // অর্ডার ধরে খোলা হলে যা বাকি ঠিক ততটুকু নিয়ে লাইন ভরে
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
        : $challan->lines->map(fn ($l) => [
            'product_id' => (string) $l->product_id,
            'qty' => (string) $l->delivered_qty,
            'rate' => (string) $l->rate,
            'discount' => '',
            'tax' => '',
            'link' => (string) ($l->sales_order_line_id ?? ''),
        ])->all();

    $orderLines = ($order?->lines ?? collect())->mapWithKeys(fn ($l) => [
        $l->id => ($l->product?->code ?? '') . ' - ' . rtrim(rtrim($l->pendingQty(), '0'), '.'),
    ]);

    $existing = old('lines', $seed);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('sales::action.new_challan') : $challan->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('sales::action.new_challan') : $challan->document_no"
            :subtitle="__('sales::message.challan_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('sales.challan.store') : route('sales.challan.update', $challan) }}"
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
                <x-ui.select name="sales_order_id" :label="__('sales::field.order')"
                             :options="$orders->mapWithKeys(fn ($o) => [$o->id => $o->document_no . ' - ' . $o->customer?->name()])"
                             :selected="$order?->id ?? $challan->sales_order_id"
                             placeholder="-" />
                <x-ui.select name="customer_id" :label="__('sales::field.customer')"
                             :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                             :selected="$order?->customer_id ?? $challan->customer_id" placeholder="-" required />
                <x-ui.select name="warehouse_id" :label="__('sales::field.warehouse')"
                             :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->name()])"
                             :selected="$challan->warehouse_id ?? $order?->warehouse_id"
                             placeholder="-" required />
                <x-ui.field name="trx_date" type="date" :label="__('sales::field.date')"
                            :value="old('trx_date', $challan->trx_date?->toDateString() ?? now()->toDateString())"
                            required />
                {{-- বহরের তালিকা থাকলে সেখান থেকে বাছা যায়, আর তার পাশে
                     লেখা নম্বরের ঘরটাও থাকে।

                     দুইটাই রাখার কারণ: ভাড়ার ট্রাকও মাল নিয়ে যায়, আর
                     শুধু ড্রপডাউন রাখলে ওই চালানটা লেখাই যেত না — তখন
                     মানুষ যেকোনো একটা গাড়ি বেছে নিত ফর্ম পার করতে, আর
                     গেটপাসে ভুল নম্বর ছাপত। --}}
                @if ($vehicles->isNotEmpty())
                    <x-ui.select name="vehicle_id" :label="__('sales::field.vehicle')"
                                 :options="$vehicles->mapWithKeys(fn ($v) => [$v->id => $v->registration_no . ' — ' . $v->name()])"
                                 :selected="old('vehicle_id', $challan->vehicle_id)"
                                 :placeholder="__('sales::field.vehicle_not_in_fleet')" />
                @endif

                <x-ui.field name="vehicle_no" :label="__('sales::field.vehicle_no')"
                            :value="old('vehicle_no', $challan->vehicle_no)" />

                <x-ui.field name="driver_name" :label="__('sales::field.driver_name')"
                            :value="old('driver_name', $challan->driver_name)" />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('sales::field.narration')"
                            :value="old('narration', $challan->narration)" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">{{ __('sales::message.lines') }}</h2>

                {{--
                    চার্ট / বাল্ক DO — গোটা ক্যাটালগ এক শীটে।

                    ── কেন ফ্রি-র ঘরটা এখানে নেই ────────────────────────
                    `sal_challan_lines`-এ `free_qty` কলামটা আছে, কিন্তু
                    চালানের সেবা ওটা লেখে না আর নিশ্চিত করার সময় ফ্রি
                    মাল নড়েও না। ঘরটা দেখালে মানুষ সংখ্যা লিখতেন আর
                    সেটা নীরবে হারিয়ে যেত — ঠিক যে ফাঁদে ক্রয়ের
                    free_qty পড়েছিল। সরাসরি বিক্রয়ে ঘরটা আছে, কারণ
                    ওখানে পথটা শেষ পর্যন্ত আছে।
                --}}
                <x-sales::bulk-sheet :products="$products" :stock="$stock" :free-qty="false" />
            </div>

            <x-sales::line-editor :products="$products" :lines="$existing"
                                  qty-field="delivered_qty"
                                  :link-field="$orderLines->isNotEmpty() ? 'sales_order_line_id' : null"
                                  :link-options="$orderLines"
                                  :show-discount="false" />
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('sales.challan.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
