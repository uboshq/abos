{{--
    ক্রয় বিল।

    চালান ধরে খোলা হলে লাইনগুলো আগে থেকে ভরা — যতটুকুর বিল এখনো হয়নি ঠিক
    ততটুকু নিয়ে, আর চালানের দরেই। সরবরাহকারী অন্য দর পাঠালে হাতে বদলানো
    যায়, আর তখন পার্থক্যটা আলাদা খাতে গিয়ে বসে।
--}}
@php
    $isNew = ! $bill->exists;

    /*
     * তিনটা অবস্থা: চালান ধরে, আদেশ ধরে, নয়তো ফাঁকা/সংরক্ষিত।
     *
     * আদেশ ধরে এলে "আর কত আসা বাকি" (pendingQty) নেওয়া হয়, চালানের
     * ক্ষেত্রে "আর কতটার বিল বাকি" (unbilledQty) — দুইটা আলাদা প্রশ্ন,
     * কারণ আদেশে মাল এখনো আসেইনি আর চালানে এসে গেছে।
     */
    $seed = $isNew && $receipt
        ? $receipt->lines
            ->filter(fn ($l) => bccomp($l->unbilledQty(), '0', 4) > 0)
            ->map(fn ($l) => [
                'product_id' => (string) $l->product_id,
                'qty' => rtrim(rtrim($l->unbilledQty(), '0'), '.'),
                'rate' => (string) $l->rate,
                'discount' => '',
                'tax' => '',
                'link' => (string) $l->id,
            ])->values()->all()
        : ($isNew && $order
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
        : $bill->lines->map(fn ($l) => [
            'product_id' => (string) $l->product_id,
            'qty' => (string) $l->qty,
            'rate' => (string) $l->rate,
            'discount' => (string) $l->discount,
            'tax' => (string) $l->tax,
            'link' => (string) ($l->purchase_receipt_line_id ?? $l->purchase_order_line_id ?? ''),

            /*
             * সংরক্ষিত লাইনে দামটা ফিরে আসে, কিন্তু anchor খালি —
             * markup ও margin জমা থাকে না, আর জমা রাখাও উচিত নয়
             * (একই জিনিস দুই জায়গায়)। খুলে দর বদলালে তাই দামটাই টেকে,
             * যেটা "দাম টাইপ করেছিলেন" ধরে নেওয়ারই সমান — আর সংরক্ষিত
             * একটা দামের ক্ষেত্রে ওটাই সঠিক অনুমান।
             */
            'sales_price' => $l->sales_price === null ? '' : (string) $l->sales_price,
            'markup' => '',
            'margin' => '',
            'anchor' => $l->sales_price === null ? '' : 'sales_price',
        ])->all());

    $existing = old('lines', $seed);

    $receiptLines = ($receipt?->lines ?? collect())->mapWithKeys(fn ($l) => [
        $l->id => ($l->product?->code ?? '') . ' - ' . rtrim(rtrim($l->unbilledQty(), '0'), '.'),
    ]);

    $orderLines = ($order?->lines ?? collect())
        ->filter(fn ($l) => bccomp($l->pendingQty(), '0', 4) > 0)
        ->mapWithKeys(fn ($l) => [
            $l->id => ($l->product?->code ?? '') . ' - ' . rtrim(rtrim($l->pendingQty(), '0'), '.'),
        ]);

    /*
     * সারিটা কার সাথে জোড়া — চালান, আদেশ, নাকি কিছুই না।
     *
     * সম্পাদনার সময় বিলের নিজের সারি দেখে ঠিক করা হয়, কারণ তখন
     * $receipt ও $order দুইটাই খালি (ঠিকানায় কিছু নেই)।
     */
    $linkField = match (true) {
        $receiptLines->isNotEmpty() => 'purchase_receipt_line_id',
        $orderLines->isNotEmpty() => 'purchase_order_line_id',
        ! $isNew && $bill->lines->contains(fn ($l) => $l->purchase_order_line_id !== null) => 'purchase_order_line_id',
        ! $isNew && $bill->lines->contains(fn ($l) => $l->purchase_receipt_line_id !== null) => 'purchase_receipt_line_id',
        default => null,
    };
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('purchase::action.new_bill') : $bill->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('purchase::action.new_bill') : $bill->document_no"
            :subtitle="__('purchase::message.bill_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('purchase.bill.store') : route('purchase.bill.update', $bill) }}"
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
                <x-ui.select name="supplier_id" :label="__('purchase::field.supplier')"
                             :options="$suppliers->mapWithKeys(fn ($s) => [$s->id => $s->name()])"
                             :selected="$receipt?->supplier_id ?? $order?->supplier_id ?? $bill->supplier_id"
                             placeholder="-" required />

                <x-ui.field name="trx_date" type="date" :label="__('purchase::field.date')"
                            :value="old('trx_date', $bill->trx_date?->toDateString() ?? now()->toDateString())"
                            required />

                <x-ui.field name="due_on" type="date" :label="__('purchase::field.due_on')"
                            :value="old('due_on', $bill->due_on?->toDateString())" />

                <x-ui.field name="supplier_bill_no" :label="__('purchase::field.supplier_bill_no')"
                            :value="old('supplier_bill_no', $bill->supplier_bill_no)" />
            </div>

            <div class="mt-3">
                <x-ui.field name="narration" :label="__('purchase::field.narration')"
                            :value="old('narration', $bill->narration)" />
            </div>
        </section>

        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('purchase::message.lines') }}</h2>

            {{--
                বিক্রয়মূল্যের তিনটা ঘর কেবল বিলেই।

                মালিকের কথা: "direct purchase-এর সময়েই sales price দেব।"
                ট্রাক গেটে দাঁড়িয়ে, নতুন দরে মাল এসেছে, আর ওই দর দেখেই
                ঠিক হয় আজ কত দামে বেচা হবে। আদেশ বা চালানে নয় — আদেশে
                দর এখনো চূড়ান্ত নয়, আর চালানে টাকার কথাই ওঠে না।
            --}}
            <x-purchase::line-editor :products="$products" :lines="$existing" qty-field="qty"
                                     :link-field="$linkField"
                                     :link-options="$receiptLines->isNotEmpty() ? $receiptLines : $orderLines"
                                     show-sales-price />
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('purchase.bill.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
