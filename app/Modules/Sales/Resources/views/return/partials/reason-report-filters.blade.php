{{--
    কারণ ধরে ফেরত — এই রিপোর্টের নিজের দুইটা ছাঁকনি। NEXUS §২৪।

    ⓘ ভাগ করা রিপোর্ট-পর্দা (accounts::report.show) টুলবারের ভিতরে এটা
    `$extraFilters` দিয়ে টানে, তাই ঘর দুইটা একই ফর্মে যায় আর পাতা
    বদলালেও (`fullUrlWithQuery`) হারায় না।

    ⚠️ মানটা `$filters` থেকে — ইঞ্জিন যা পেয়েছে তাই দেখানো হয়। ঠিকানা
    থেকে আলাদা করে পড়লে পর্দা একটা বলত আর সংখ্যা আরেকটা ধরে গোনা হত।
--}}
<label class="min-w-0 flex-1 sm:max-w-xs">
    <span class="sr-only">{{ __('sales::field.customer') }}</span>
    <select name="customer_id"
            class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                   bg-(--color-surface-app) px-2 text-sm">
        <option value="">{{ __('sales::return_reason.any_customer') }}</option>
        @foreach ($customers as $customer)
            <option value="{{ $customer->id }}" @selected(($filters['customer_id'] ?? null) == $customer->id)>
                {{ $customer->name() }}
            </option>
        @endforeach
    </select>
</label>

<label class="min-w-0 flex-1 sm:max-w-xs">
    <span class="sr-only">{{ __('sales::field.product') }}</span>
    <select name="product_id"
            class="h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border)
                   bg-(--color-surface-app) px-2 text-sm">
        <option value="">{{ __('sales::return_reason.any_product') }}</option>
        @foreach ($products as $product)
            <option value="{{ $product->id }}" @selected(($filters['product_id'] ?? null) == $product->id)>
                {{ $product->name() }}
            </option>
        @endforeach
    </select>
</label>
