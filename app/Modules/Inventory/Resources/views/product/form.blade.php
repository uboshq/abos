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

    {{-- enctype বাধ্যতামূলক — ছাড়া থাকলে ব্রাউজার শুধু ফাইলের নাম পাঠায়,
         সার্ভারে কোনো ত্রুটি হয় না, ছবিটা নীরবে হারায়। --}}
    <form method="POST"
          enctype="multipart/form-data"
          action="{{ $isNew ? route('inventory.product.store') : route('inventory.product.update', $product) }}"
          x-data="{ busy: false }"
          @submit="busy ? $event.preventDefault() : (busy = true)"
          class="max-w-6xl space-y-4">
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

        {{-- ডান অর্ধেক আর খালি নয় — পরিচয় বাঁয়ে, দাম ও ছবি ডানে, পাশাপাশি।
             চওড়া পর্দায় (lg = এই থিমে ~১২৮০px) দুই কলাম, সরু পর্দায় একটার নিচে একটা। --}}
        <div class="grid items-start gap-4 lg:grid-cols-2">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
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

                {{--
                    ব্র্যান্ড ও শ্রেণি — বাছাই, টাইপ করা নয়।

                    আগে দুইটাই মুক্ত লেখার ঘর ছিল, আর তাতে একই ব্র্যান্ড
                    কয়েক বানানে বসত ("Nestle", "nestle", "নেসলে")। রোজকার
                    কাজে কেউ টের পেত না — পাতায় লেখাটা ঠিকই দেখাত। টের
                    পাওয়া যেত ব্র্যান্ড ধরে বিক্রয় খুললে: এক ব্র্যান্ড চার
                    সারিতে ভাগ, প্রতিটার অঙ্ক আসলের এক-চতুর্থাংশ।

                    তালিকায় না থাকলে সেটিংস থেকে যোগ করতে হয়, আর সেটাই
                    ঠিক: নতুন একটা ব্র্যান্ড বসানো একটা সিদ্ধান্ত, টাইপো
                    নয়।
                --}}
                @if ($brandOn)
                    <x-ui.select name="brand_id" :label="__('inventory::field.brand')"
                                 :options="$brands->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                                 :selected="$product->brand_id"
                                 placeholder="-" />
                @endif

                <x-ui.select name="category_id" :label="__('inventory::field.category')"
                             :options="$categories->mapWithKeys(fn ($c) => [$c->id => $c->name()])"
                             :selected="$product->category_id"
                             placeholder="-" />

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

        {{-- ডান কলাম: দাম (মার্জিন/মার্কআপ) ও ছবি --}}
        @php $showCost = \App\Core\Security\FieldSecurity::visible($product, 'purchase_price'); @endphp
        <div class="space-y-4">

        {{--
            দাম — ক্রয়, বিক্রয়, আর দুইটা শতাংশ।

            মার্কআপ = ক্রয়ের উপরে কত চড়ল · মার্জিন = বিক্রয়ের কত অংশ লাভ —
            দুইটা আলাদা সংখ্যা। যেকোনো একটা ঘর লিখলে বাকিগুলো নিজে বসে।

            ⚠️ শতাংশ দুইটা কোনো কলাম নয় — জমা পড়ে কেবল ক্রয় ও বিক্রয় দর।
            কলামে বসালে দর একবার বদলানোর পর সংখ্যাটা পুরনো হয়ে তালিকা মিথ্যা
            বলত। সূত্রটা একই bcmath-এ সার্ভারেও (App\Modules\Inventory\Support\
            Margin) — তালিকা-পর্দা-ফর্ম সবাই এক জায়গা থেকে গোনে।

            নিচের JS ওই সূত্রেরই যমজ; ব্যবহারকারী যে ঘরটা শেষ লিখলেন সেটাই
            ধ্রুব, বাকিগুলো কেবল আঁকা — নাহলে ট্যাব চাপলেই সংখ্যা নাচত।
        --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4"
                 @if ($showCost)
                 x-data="{
                     cost: '{{ old('purchase_price', $product->purchase_price) }}',
                     sale: '{{ old('sale_price', $product->sale_price) }}',
                     markup: '',
                     margin: '',
                     init() { this.fromPrices(); },
                     n(v) { v = (v ?? '').toString().trim(); return v !== '' && !isNaN(v) ? parseFloat(v) : null; },
                     fromPrices() {
                         const c = this.n(this.cost), s = this.n(this.sale);
                         this.markup = (c !== null && c > 0 && s !== null) ? ((s - c) / c * 100).toFixed(2) : '';
                         this.margin = (s !== null && s > 0 && c !== null) ? ((s - c) / s * 100).toFixed(2) : '';
                     },
                     fromMarkup() {
                         const c = this.n(this.cost), m = this.n(this.markup);
                         if (c === null || c <= 0 || m === null || m <= -100) return;
                         this.sale = (c * (100 + m) / 100).toFixed(4);
                         const s = this.n(this.sale);
                         this.margin = (s !== null && s > 0) ? ((s - c) / s * 100).toFixed(2) : '';
                     },
                     fromMargin() {
                         const c = this.n(this.cost), m = this.n(this.margin);
                         if (c === null || c <= 0 || m === null || m >= 100) return;
                         this.sale = (c * 100 / (100 - m)).toFixed(4);
                         const s = this.n(this.sale);
                         this.markup = (s !== null && s > 0) ? ((s - c) / c * 100).toFixed(2) : '';
                     }
                 }"
                 @endif>
            <h2 class="mb-3 font-semibold">{{ __('inventory::section.pricing') }}</h2>

            @if ($showCost)
                <div class="grid gap-3 sm:grid-cols-2">
                    {{-- inputmode="decimal" — টাকার ঘরে ফোনে সংখ্যার কী-বোর্ড --}}
                    <x-ui.field name="purchase_price" type="number" step="0.0001" inputmode="decimal"
                                :label="__('inventory::field.purchase_price')"
                                :value="old('purchase_price', $product->purchase_price)"
                                x-model="cost" @input="fromPrices()" numeric />

                    <x-ui.field name="sale_price" type="number" step="0.0001" inputmode="decimal"
                                :label="__('inventory::field.sale_price')"
                                :value="old('sale_price', $product->sale_price)"
                                x-model="sale" @input="fromPrices()" numeric />

                    {{-- markup_pct/margin_pct জমা পড়ে না — ProductRequest ওগুলো
                         চেনে না, তাই validated() ছেঁটে ফেলে; শুধু হিসাবের ঘর। --}}
                    <x-ui.field name="markup_pct" type="number" step="0.01" inputmode="decimal"
                                :label="__('inventory::field.markup')"
                                :hint="__('inventory::message.markup_hint')"
                                x-model="markup" @input="fromMarkup()" numeric />

                    <x-ui.field name="margin_pct" type="number" step="0.01" inputmode="decimal"
                                :label="__('inventory::field.margin')"
                                :hint="__('inventory::message.margin_hint')"
                                x-model="margin" @input="fromMargin()" numeric />
                </div>
            @else
                {{-- ক্রয় দর দেখার অনুমতি নেই — তখন মার্কআপ/মার্জিন অর্থহীন
                     (ক্রয় ছাড়া গোনা যায় না), তাই কেবল বিক্রয় দর। --}}
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="sale_price" type="number" step="0.0001" inputmode="decimal"
                                :label="__('inventory::field.sale_price')"
                                :value="old('sale_price', $product->sale_price)" numeric />
                </div>
            @endif

            <div class="mt-3 sm:w-1/2">
                <x-ui.field name="reorder_level" type="number" step="0.0001" inputmode="decimal"
                            :label="__('inventory::field.reorder_level')"
                            :value="old('reorder_level', $product->reorder_level)"
                            :hint="__('inventory::message.reorder_hint')" numeric />
            </div>
        </section>

        {{-- পণ্যের ছবি — সংরক্ষণ ও যাচাই ব্যাকএন্ডে (A3)। এখানে কেবল ঘর;
             নাম product_image, আর ফর্মে enctype (উপরে) — নাহলে নীরবে হারাত। --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('inventory::section.image') }}</h2>

            {{-- বর্তমান ছবি — storage/app/private-এ, তাই attachment.download রুট
                 দিয়ে (asset() নয়): ওই রুট আগে অনুমতি যাচাই করে, নাহলে URL অনুমান
                 করেই অন্য কোম্পানির লোক ছবি দেখত (বহু-টেন্যান্ট)। সম্পর্কটা A3-এর। --}}
            @if (! $isNew && $product->primaryImage)
                <img src="{{ route('attachment.download', $product->primaryImage) }}"
                     alt="{{ $product->name() }}"
                     class="mb-3 size-(--spacing-thumb) rounded-(--radius-field) border border-(--color-border) object-cover" />
            @endif

            <label for="product_image" class="mb-1 block text-sm font-medium">
                {{ __('inventory::message.image_label') }}
            </label>
            <input id="product_image" name="product_image" type="file"
                   accept="image/jpeg,image/png,image/webp"
                   class="w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-card) px-3 py-2 text-sm
                          file:mr-3 file:rounded-(--radius-field) file:border-0
                          file:bg-(--color-surface-app) file:px-3 file:py-1 file:text-sm" />
            @error('product_image')
                <p class="mt-1 text-2xs text-(--color-danger)">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-2xs text-(--color-ink-muted)">{{ __('inventory::message.image_hint') }}</p>
        </section>

        </div>
        </div>

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
