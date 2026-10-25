{{--
    সেভ ও খসড়া — দুইটা আলাদা বোতাম, একই ফর্ম।

    "সেভ" মানে লেজারে বসে যাওয়া। খসড়া রাখতে হলে আলাদা করে চাপতে হয়,
    কারণ ডিফল্ট খসড়া হলে দিনের শেষে একগাদা ভাউচার পড়ে থাকত যেগুলো
    কোনো হিসাবে নেই, আর কেউ জানত না সেগুলো ভুলে যাওয়া নাকি ইচ্ছাকৃত।

    ⭐ ক্রম: বাতিল · খসড়া রাখুন · [মূল] — মূলটা সবসময় শেষে আর ডানে (fe, ২৪ অক্টোবর ২০২৬: পুরো ERP-তে একটাই ক্রম; নকশা পাতা ২
    আর ৪-এর নিচের সারি, টাকা দেওয়ার পর্দার মতো)। ⛔ আগে মূল বোতাম সবার আগে ছিল, আদায়ের অনুমোদিত নকশার উল্টো।
    ⓘ DOM-এর ক্রমও এটাই, তাই Tab পর্দার ক্রমে চলে। ⚠️ ঘরে Enter চাপলে ফর্মের প্রথম জমার বোতাম চলে — এখন "খসড়া রাখুন", পোস্ট
    নয়; টাকা দেওয়ার পর্দাতেও আগে থেকেই তাই। ভুলে Enter চাপলে খাতায় কিছু বসে না, খসড়া থাকে।
--}}
@php
    /*
     * ⭐ প্রধান বোতামের নাম বাইরে থেকে দেওয়া যায় — ১৮ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ খরচের নকশায় লেখা "অনুমোদনে পাঠান", আর আদায়-পরিশোধে
     * "সংরক্ষণ ও পোস্ট"। ⚠️ একটাই নাম হার্ডকোড করলে একটা পর্দায়
     * নকশা মিলত না — আর মালিক ওটাই ধরেন।
     */
    $primaryLabel ??= 'accounts::action.save_and_post';

    // ⓘ সংশোধনে একটাই বোতাম — পোস্ট হওয়া কাগজ খসড়া হয় না ([[VoucherController::saveRevision()]])
    $revising ??= false;
    // ⓘ কম-বেশিতে মালিকের সই লাগলে মূল বোতামের নাম — ফর্মের `ownerSigns` (রসিদ-পরিশোধ; cb-ma-5)
    $ownerSignsLabel ??= null;

    if ($revising) {
        $primaryLabel = 'accounts::revision.save';
    }
@endphp

<div class="flex flex-wrap gap-2" data-save-buttons>
    <x-ui.button tone="secondary"
                 :href="$voucher->exists
                     ? route('accounts.voucher.show', $voucher)
                     : route('accounts.voucher.index', $type)">
        {{ __('core.action.cancel') }}
    </x-ui.button>

    @unless ($revising)
        <button type="submit" name="save_as_draft" value="1"
                class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) border
                       border-(--color-border) px-4 text-sm font-medium transition-colors
                       hover:bg-(--color-surface-hover)"
                :class="busy && 'pointer-events-none opacity-70'">
            {{ __('accounts::action.save_draft') }}
        </button>
    @endunless

    <x-ui.button type="submit" tone="primary"
                 ::class="busy && 'pointer-events-none opacity-70'">
        {{-- ⓘ সই আগে থেকেই চালু হলে মূল নামটাই "সইয়ে পাঠান" — তখন একটাই নাম, দুইবার নয় (cb-ma-5 ফটক: "সইয়ে পাঠান সইয়ে পাঠান") --}}
        @if ($ownerSignsLabel !== null && ! $revising && $ownerSignsLabel !== $primaryLabel)
            <span x-show="! ownerSigns">{{ __($primaryLabel) }}</span>
            <span x-show="ownerSigns" x-cloak data-owner-signs-label>{{ __($ownerSignsLabel) }}</span>
        @else
            {{ __($primaryLabel) }}
        @endif
    </x-ui.button>
</div>
