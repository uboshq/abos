{{--
    সেভ ও খসড়া — দুইটা আলাদা বোতাম, একই ফর্ম।

    "সেভ" মানে লেজারে বসে যাওয়া। খসড়া রাখতে হলে আলাদা করে চাপতে হয়,
    কারণ ডিফল্ট খসড়া হলে দিনের শেষে একগাদা ভাউচার পড়ে থাকত যেগুলো
    কোনো হিসাবে নেই, আর কেউ জানত না সেগুলো ভুলে যাওয়া নাকি ইচ্ছাকৃত।
--}}
@php
    /*
     * ⭐ প্রধান বোতামের নাম বাইরে থেকে দেওয়া যায় — ১৮ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ খরচের নকশায় লেখা "অনুমোদনে পাঠান", আর আদায়-পরিশোধে
     * "সংরক্ষণ ও পোস্ট"। ⚠️ একটাই নাম হার্ডকোড করলে একটা পর্দায়
     * নকশা মিলত না — আর মালিক ওটাই ধরেন।
     */
    $primaryLabel ??= 'accounts::action.save_and_post';
@endphp

<div class="flex flex-wrap gap-2">
    <x-ui.button type="submit" tone="primary"
                 ::class="busy && 'pointer-events-none opacity-70'">
        {{ __($primaryLabel) }}
    </x-ui.button>

    <button type="submit" name="save_as_draft" value="1"
            class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) border
                   border-(--color-border) px-4 text-sm font-medium transition-colors
                   hover:bg-(--color-surface-hover)"
            :class="busy && 'pointer-events-none opacity-70'">
        {{ __('accounts::action.save_draft') }}
    </button>

    <x-ui.button tone="secondary"
                 :href="$voucher->exists
                     ? route('accounts.voucher.show', $voucher)
                     : route('accounts.voucher.index', $type)">
        {{ __('core.action.cancel') }}
    </x-ui.button>
</div>
