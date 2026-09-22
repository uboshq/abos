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

    {{--
        ⭐ অর্ডারের ডেস্ক — ২১ সেপ্টেম্বর ২০২৬।

        মালিকের নির্দেশ: *"New order form ali update koro zate direct sales er
        activiti gulo hoy ekhane, ekhon to ekhane kichui hoyna"*।

        ⛔ কিন্তু সরাসরি বিক্রয়ের সবটা নয়। জিজ্ঞেস করা হয়েছিল অর্ডার নেওয়ার
        সময় টাকাও নেন কি না, আর উত্তর: **না, অর্ডার আলাদা, টাকা পরে**। তাই
        টাকা নেওয়ার প্যানেল, ক্যাশ ড্রয়ার আর নোট গোনা এখানে নেই — বসালে
        ব্যবহারকারী ভাবতেন টাকা নেওয়া হয়ে গেছে, অথচ কিছুই হয়নি।

        ⓘ যে চারটা তিনি বেছেছেন: ক্রেতার বকেয়া ও সীমা · বারকোড ও পণ্য খোঁজা
        · মজুদের ইঙ্গিত · চলমান যোগফল।
    --}}
    <form method="POST"
          action="{{ $isNew ? route('sales.order.store') : route('sales.order.update', $order) }}"
          class="space-y-4"
          x-data="salesOrderDesk({
              terms: @js($customerTerms),
              barcodes: @js((object) $barcodes),
              packBarcodes: @js((object) $packBarcodes),
              customerId: @js((string) old('customer_id', $order->customer_id)),
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
                             :selected="$order->customer_id" placeholder="-" required
                             x-model="customerId" />
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

            {{--
                ⭐ ক্রেতার খাতা — নাম বাছার সাথে সাথেই।

                ⓘ abos-8b-র কথা: সীমা ছাড়ানো অর্ডার নেওয়ার **আগেই** জানা
                দরকার, ডেলিভারির দিন নয়। ⚠️ তালিকাটা পাতার সাথেই একবার যায়,
                তাই ক্রেতা বদলালে নতুন কোনো অনুরোধ লাগে না।

                ⛔ সংখ্যাটা **আজকের** বকেয়া — এই অর্ডারটা এখনো খাতায় ওঠেনি,
                তাই ওটা এর মধ্যে ধরা নেই। লেখাটা সেটাই বলে, নাহলে কেউ ধরে
                নিতেন এই অর্ডারসহ হিসাব।
            --}}
            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-(--radius-field)
                        bg-(--color-surface-app) px-3 py-2 text-sm"
                 x-show="party !== null" x-cloak>
                <span class="text-(--color-ink-muted)">{{ __('sales::field.outstanding') }}:</span>
                <span class="tabular font-medium" x-text="party ? party.due_text : ''"></span>

                <span class="text-(--color-ink-muted)">{{ __('sales::field.credit_limit') }}:</span>
                <span class="tabular font-medium" x-text="party ? party.limit_text : ''"></span>

                <span class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-2 py-0.5
                             text-(--color-badge-danger-ink)"
                      x-show="overLimit" x-cloak>
                    {{ __('sales::message.credit_limit_crossed') }}
                </span>
            </div>
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('sales::message.lines') }}</h2>

            {{--
                ⭐ বারকোড — স্ক্যান করলে সারিটা নিজেই বসে।

                ⓘ ঘরটা সারির সম্পাদককে সরাসরি ছোঁয় না: সে আগে থেকেই
                `bulk-applied` সংকেতটা শোনে (বাল্ক শীটের জন্য বানানো), তাই
                বারকোডটা ঐ একই সংকেতই পাঠায়। ⭐ একই পণ্য দুইবার স্ক্যান
                করলে পরিমাণ বসানোর নিয়মটাও এমনিই পাওয়া যায়।

                ⚠️ `@keydown.enter.prevent` — নাহলে স্ক্যানারের শেষের Enter
                পুরো ফর্মটা জমা দিয়ে দিত, আর অর্ডারটা এক পণ্যেই সেভ হয়ে যেত।
            --}}
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
                                  qty-field="ordered_qty"
                                  :link-field="null"
                                  :link-options="[]"
                                  :show-discount="true"
                                  :stock="$stock"
                                  :show-breakdown="true" />
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('sales.order.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
