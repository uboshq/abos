<x-page-css name="counter" />{{-- এই পাতার নিজের CSS ([[PageCss]]) --}}
{{--
    মাল গ্রহণ।

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
                // ⓘ আদেশের সারিতে আনলোড দর নেই — খালি; পণ্য বাছলে বা সংরক্ষণে পণ্যের নিজের দর বসে (মালিক, ১৮ নভেম্বর ২০২৬)
                'unload_rate' => '',
            ])->values()->all()
        : $receipt->lines->map(fn ($l) => [
            'product_id' => (string) $l->product_id,
            'qty' => (string) $l->received_qty,

            /*
             * ⛔ ফ্রি পরিমাণ সম্পাদনার ফর্মে ফিরে আসে — মালিকের অভিযোগ, ৩ অক্টোবর ২০২৬ (স্টার লাইন):
             * *"পারচেস চালানে ফ্রি হারা গেছে … ফ্রি আসতেছে না"*।
             *
             * ⓘ আগে এই সারিতে `free_qty` ছিলই না — ফ্রি ঘর খালি খুলত, "সংরক্ষণ" চাপলে সেবা পড়ত ০,
             * আর নিশ্চিত করলে `:free` মজুদে কিছুই ঢুকত না। বিলের ফর্মে একই ফাঁক বন্ধ হয়েছিল আগে।
             * ⚠️ মূল এককে, কারণ উপরের `qty` আর `rate`-ও মূল এককে আর এখানে কোনো `unit_id` বসে না।
             * পাহারা: [[TheFreeGoodsVanishedWhenTheReceiptWasEditedTest]]।
             */
            'free_qty' => bccomp((string) $l->free_qty, '0', 4) > 0 ? (string) $l->free_qty : '',

            // ⓘ লটের তিনটা ঘরও একই কারণে — না ফিরলে সম্পাদনায় লট নম্বর, মেয়াদ, ছাপা দাম মুছে যেত
            'batch_no' => (string) ($l->batch_no ?? ''),
            'expiry_date' => $l->expiry_date?->toDateString() ?? '',
            'mrp' => $l->mrp === null ? '' : (string) $l->mrp,

            /*
             * ⛔ লেখা দরটাই ফেরে, ভ্যাট বাদেরটা নয় (cb, ১১ অক্টোবর ২০২৬; [[EditingADraftReceiptKeepsTheVatOnceTest]])। ⚠️ ভ্যাট দামের
             * ভেতরে হলে জমা দর ভ্যাট বাদে (১০০); সেটা ফর্মে বসলে "সংরক্ষণ" আবার ভ্যাট আলাদা করত — ১০০ → ৮৬.৯৬, প্রতিবার আরও কম।
             */
            'rate' => $l->typedRate(),

            // খালি রাখা মানে "দাম বদলাব না" — তাই null-টা খালিই ফেরে,
            // শূন্য নয়। শূন্য দেখালে সম্পাদনার সময় প্রতিটা লাইন পণ্যের
            // দাম শূন্য করে দিত।
            'sales_price' => $l->sales_price === null ? '' : (string) $l->sales_price,
            'discount' => '',
            'tax' => '',
            'link' => (string) ($l->purchase_order_line_id ?? ''),

            // ⭐ সারির হাতের আনলোড দর ফেরে — না ফিরলে সম্পাদনায় "সংরক্ষণ" দরটা মুছে দিত (মালিক, ১৮ নভেম্বর ২০২৬)
            'unload_rate' => $l->unload_rate === null ? '' : (string) $l->unload_rate,
        ])->all();

    $existing = old('lines', $seed);

    $orderLines = ($order?->lines ?? collect())->mapWithKeys(fn ($l) => [
        $l->id => ($l->product?->code ?? '') . ' - ' . rtrim(rtrim((string) $l->ordered_qty, '0'), '.'),
    ]);

    /*
     * ⭐ আনলোডিং খরচ — প্রতিটা মাল গ্রহণে বাধ্যতামূলক (মালিক, ১৮ নভেম্বর ২০২৬: "ওকে"; [[LabourCost]], [[UnloadingSplit]])।
     * ⓘ চার পথ: নগদ (নিজের টিল) · ঠিকাদারের কাছে বাকি · সরবরাহকারী দিয়েছেন · লাগেনি (কারণসহ)।
     * ⓘ বাছাই না করেও সংরক্ষণ হয় — থামে কেবল পাকা করার সময় ([[ChargesUnloading::assertUnloadingChosen()]])।
     */
    $labour = app(\App\Modules\Supplier\Services\LabourCost::class);
    $unloadMode = (string) old('unload_mode', $receipt->unload_mode ?? '');
    $unloadTills = collect($labour->cashTills())->pluck('label', 'id');
    $unloadContractors = $labour->contractors()->pluck('label', 'id');

    // ⓘ প্রস্তাবিত অঙ্ক — কেবল সংরক্ষিত কাগজে (কার্টন সারির পণ্যের প্যাক ধরে, সার্ভারের একই অঙ্ক)
    $unloadExpected = null;

    if (! $isNew) {
        $unloadRows = \App\Modules\Purchase\Services\UnloadingSplit::rows($receipt->lines, 'received_qty');
        // ⓘ আজকের দর — সংরক্ষণ দরটা আবার বসায় ([[ChargesUnloading::stampUnloading()]]), তাই পুরনো ছবি নয়
        $unloadRate = \App\Modules\Purchase\Services\UnloadingSplit::rate();
        $unloadExpected = __('supplier::labour.expected', [
            'cartons' => \App\Core\Support\Money::quantity(\App\Modules\Purchase\Services\UnloadingSplit::cartonsOf($unloadRows), 2),
            'rate' => \App\Core\Support\Money::format($unloadRate, bccomp(\App\Core\Support\Money::round($unloadRate, 2), $unloadRate, 4) === 0 ? 2 : 4),
            'amount' => \App\Core\Support\Money::format(\App\Modules\Purchase\Services\UnloadingSplit::expected($unloadRows, $unloadRate)),
        ]);
    }
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

{{--
                    ⛔ তারিখটা আজকের দিয়ে ভরে দেওয়া হয় না — ২১ সেপ্টেম্বর ২০২৬।

                    ── ⭐ মালিকের নির্দেশ ─────────────────────────────────
                    *"Received on, Billing date egulo faka thakbe, hate fill
                    korar por create hobe"*।

                    ── ⚠️ কেন কথাটা ন্যায্য ───────────────────────────────
                    কাগজটা আজকের নাও হতে পারে: সরবরাহকারীর বিল তিন দিন আগের,
                    মাল পরশু এসেছে। ⓘ ঘরটা আগে থেকে ভরা থাকলে মানুষ সেটা
                    **পড়েন না** — চোখ পরের ঘরে চলে যায়, আর আজকের তারিখেই
                    কাগজটা বসে যায়।

                    ⛔ ফল নীরব: খাতায় ভুল দিনে ভুক্তি, মাসের হিসাব মেলে না,
                    আর কেউ বলতে পারে না কেন। ⚠️ খালি ঘর জোর করে প্রশ্নটা
                    করায় — "কোন তারিখ?"

                    ⓘ `required` থেকে যায়, তাই খালি রেখে সংরক্ষণ করা যায় না।
                --}}
                <x-ui.field name="trx_date" type="date" :label="__('purchase::field.date')"
                            :value="old('trx_date', $receipt->trx_date?->toDateString())"
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

            <x-purchase::line-editor lots show-free :lot-hint="$lotHint ?? null" :products="$products" :lines="$existing"
                                     qty-field="received_qty"
                                     :link-field="$orderLines->isNotEmpty() ? 'purchase_order_line_id' : null"
                                     :link-options="$orderLines"
                                     :show-discount="false"
                                     show-sales-price
                                     unload />
        </section>

        {{-- ⭐ আনলোডিং খরচ — চার পথের একটা বাছতেই হবে, নইলে পাকা হয় না (মালিক, ১৮ নভেম্বর ২০২৬: "ওকে")।
             ⓘ ঘরগুলো পথ ধরে দেখা দেয় (x-show); লুকানো ঘর গেলেও সেবা পথের বাইরেরটা পড়ে না ([[LabourCost::read()]])। --}}
        <section data-boxed data-receipt-unloading
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
                 x-data="{ unloadMode: @js($unloadMode) }">
            <h2 class="mb-1 font-semibold">{{ __('supplier::labour.unloading') }}</h2>
            <p class="mb-2 text-sm text-(--color-ink-muted)">{{ __('supplier::labour.how') }}</p>

            <div class="ds-seg" role="radiogroup" aria-label="{{ __('supplier::labour.how') }}">
                @foreach (\App\Modules\Supplier\Services\LabourCost::UNLOADING as $choice)
                    <button type="button" role="radio" @click="unloadMode = '{{ $choice }}'"
                            :class="unloadMode === '{{ $choice }}' ? 'is-on' : ''"
                            :aria-checked="unloadMode === '{{ $choice }}' ? 'true' : 'false'">{{ __('supplier::labour.mode_'.$choice) }}</button>
                @endforeach
            </div>
            <input type="hidden" name="unload_mode" :value="unloadMode" value="{{ $unloadMode }}">
            @error('unload_mode')
                <p class="mt-1 text-sm text-(--color-danger)">{{ $message }}</p>
            @enderror

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div x-show="unloadMode === 'cash' || unloadMode === 'due'">
                    <x-ui.field name="unload_amount" type="number" step="0.01" min="0" inputmode="decimal" numeric
                                :label="__('supplier::labour.amount')"
                                :value="old('unload_amount', $receipt->unload_mode !== null && \App\Modules\Supplier\Services\LabourCost::costsUs($receipt->unload_mode) ? \App\Core\Support\Money::round((string) $receipt->unload_amount, 2) : '')"
                                :hint="__('supplier::labour.amount_hint')" />
                </div>

                <div x-show="unloadMode === 'cash'">
                    <x-ui.select name="unload_account_id" :label="__('supplier::labour.till')"
                                 :options="$unloadTills"
                                 :selected="$receipt->unload_account_id"
                                 placeholder="-" />
                </div>

                <div x-show="unloadMode === 'due'">
                    @if ($unloadContractors->isEmpty())
                        <p class="rounded-(--radius-field) bg-(--color-badge-pending-bg) px-3 py-2 text-sm text-(--color-badge-pending-ink)">
                            {{ __('supplier::labour.no_contractor_party') }}
                        </p>
                    @else
                        <x-ui.select name="unload_contractor_id" :label="__('supplier::labour.contractor')"
                                     :options="$unloadContractors"
                                     :selected="$receipt->unload_contractor_id"
                                     placeholder="-" />
                    @endif
                </div>

                <div x-show="unloadMode === 'none'">
                    <x-ui.field name="unload_reason" maxlength="255" :label="__('supplier::labour.reason')"
                                :value="old('unload_reason', $receipt->unload_reason)" />
                </div>
            </div>

            <p class="mt-2 text-2xs text-(--color-ink-muted)" data-unload-expected>
                {{ $unloadExpected ?? __('purchase::unloading.expected_after_save') }}
            </p>
            <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ __('supplier::labour.split_hint') }}</p>
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('purchase.receipt.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
