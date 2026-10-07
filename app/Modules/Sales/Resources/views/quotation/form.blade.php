{{--
    বিক্রয় উদ্ধৃতি — তৈরি ও সম্পাদনা।

    ⓘ সম্পাদনা কেবল খসড়ায়। জমা বা অনুমোদনের পরে বদলাতে হলে "আবার খসড়ায়"
    — তাতে আগের সই আর খাটে না, আর নতুন দরে নতুন সই লাগে।

    ⓘ সারির সম্পাদক আর বারকোড আদেশের পর্দার হুবহু (`salesLineEditor`,
    `salesOrderDesk`) — নতুন কোনো JS নেই। ⛔ বাকির পটি ইচ্ছা করেই নেই:
    উদ্ধৃতিতে সীমা দেখা হয় না (মালিক, ২৬ সেপ্টেম্বর ২০২৬)।
--}}
@php
    $isNew = ! $quotation->exists;

    /*
     * ⚠️ ভ্যাটের ঘর কেবল তখনই ভরা যায় যখন হাতে লেখা হয়েছিল (`tax_variance`
     * আছে)। হারের অঙ্ক ভরে দিলে আবার সেভে সেটা "হাতে লেখা" হয়ে যেত, আর
     * পুরো কাগজের ছাড় বদলালেও ভ্যাট আর নতুন করে গোনা হত না।
     */
    $seed = $quotation->lines->map(fn ($l) => [
        'product_id' => (string) $l->product_id,
        'qty' => (string) $l->qty,
        'rate' => (string) $l->rate,
        'discount' => (string) $l->discount,
        'tax' => $l->tax_variance === null ? '' : (string) $l->tax,
        'link' => '',
    ])->all();

    $existing = old('lines', $seed);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $isNew ? __('sales::quotation.action.new') : $quotation->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$isNew ? __('sales::quotation.action.new') : $quotation->document_no"
            :subtitle="__('sales::quotation.form_note')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $isNew ? route('sales.quotation.store') : route('sales.quotation.update', $quotation) }}"
          class="space-y-4"
          x-data="salesOrderDesk({
              terms: {},
              barcodes: @js((object) $barcodes),
              packBarcodes: @js((object) $packBarcodes),
              customerId: @js((string) old('customer_id', $quotation->customer_id)),
          })">
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
                             :selected="$quotation->customer_id" placeholder="-" required />
                <x-ui.field name="trx_date" type="date" :label="__('sales::field.date')"
                            :value="old('trx_date', $quotation->trx_date?->toDateString() ?? now()->toDateString())"
                            required />
                <x-ui.field name="valid_until" type="date" :label="__('sales::quotation.field.valid_until')"
                            :value="old('valid_until', $quotation->valid_until?->toDateString())"
                            required />
                <x-ui.field name="header_discount" type="number" step="0.01" inputmode="decimal" numeric
                            :label="__('sales::quotation.field.header_discount')"
                            :value="old('header_discount', $quotation->header_discount !== null ? (string) $quotation->header_discount : '')" />
                <x-ui.select name="price_list_id" :label="__('sales::quotation.field.price_list')"
                             :options="$priceLists->mapWithKeys(fn ($p) => [$p->id => $p->name()])"
                             :selected="$quotation->price_list_id" placeholder="-" />
                <x-ui.select name="payment_term_id" :label="__('sales::quotation.field.payment_term')"
                             :options="$paymentTerms->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="$quotation->payment_term_id" placeholder="-" />
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <x-ui.field name="delivery_terms" :label="__('sales::quotation.field.delivery_terms')"
                            :value="old('delivery_terms', $quotation->delivery_terms)" />
                <x-ui.field name="narration" :label="__('sales::field.narration')"
                            :value="old('narration', $quotation->narration)" />
            </div>

            <p class="mt-3 text-xs text-(--color-ink-muted)">{{ __('sales::quotation.credit_note') }}</p>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('sales::quotation.lines') }}</h2>

            {{-- ⚠️ `@keydown.enter.prevent` — নাহলে স্ক্যানারের শেষের Enter পুরো ফর্ম জমা দিত --}}
            <label class="mb-3 block max-w-sm">
                <span class="mb-1 block text-sm text-(--color-ink-muted)">
                    {{ __('inventory::field.barcode') }}
                </span>
                <input type="text" x-model="code" @keydown.enter.prevent="scan()"
                       autocomplete="off"
                       class="h-(--spacing-field-compact) w-full rounded-(--radius-field)
                              border border-(--color-border) bg-(--color-surface-card) px-2">
                <span class="mt-1 block text-xs text-(--color-badge-danger-ink)"
                      x-show="missed !== ''" x-cloak
                      x-text="missed"></span>
            </label>

            <x-sales::line-editor :products="$products" :lines="$existing"
                                  :prices-url="route('sales.price_list.quote')"
                                  qty-field="qty"
                                  :link-field="null"
                                  :link-options="[]"
                                  :show-discount="true"
                                  :show-breakdown="true" />
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('sales.quotation.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
