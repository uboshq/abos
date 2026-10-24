@props([
    /* সই চালু কি না — ApprovalEngine::requires() পর্দার নিজের মডিউল আর কাজ দিয়ে */
    'signatures' => false,

    /* লেখক/সইদাতা ≠ পোস্টকারী — সরবরাহকারী-পরিশোধে `purchase.payment_three_hands`, ভাউচারে `accounts.voucher_maker_checker` */
    'threeHands' => false,

    /* `in` টাকা নেওয়া, `out` টাকা দেওয়া — সইয়ের লাইনের কথা দিক ধরে */
    'direction' => 'out',
])
{{--
    ⭐ নিয়ন্ত্রণ — টাকা নেওয়া আর দেওয়ার পর্দার একই কার্ড (নকশা পাতা ২ আর ৪, ডানের কলামের শেষে; fe, ২৪ অক্টোবর ২০২৬: "ভাগ করা
    অংশ, আলাদা আলাদা নকল নয়")। সই চালু/বন্ধ, তিন হাতের নিয়ম; পর্দার নিজের বাড়তি সতর্কতা `slot`-এ (সরবরাহকারী-পরিশোধে প্রাপকের
    ব্যাংক তথ্য)। ⓘ চিহ্ন (`data-pay-controls`, `data-voucher-controls`) হোস্ট দেয়।
--}}
<section data-boxed {{ $attributes->merge(['class' => 'rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4']) }}>
    <h2 class="mb-3 font-semibold">{{ __('money.screen.controls') }}</h2>
    <div class="flex flex-col gap-2 text-xs">
        <p class="border-s-2 border-(--color-brand-500) bg-(--color-badge-info-bg) px-3 py-2 text-(--color-badge-info-ink)" data-control="signatures">
            @if ($direction === 'in')
                {{ $signatures ? __('money.screen.control.signatures_on_in') : __('money.screen.control.signatures_off_in') }}
            @else
                {{ $signatures ? __('money.screen.control.signatures_on') : __('money.screen.control.signatures_off') }}
            @endif
        </p>
        @if ($threeHands)
            <p class="border-s-2 border-(--color-brand-500) bg-(--color-badge-info-bg) px-3 py-2 text-(--color-badge-info-ink)" data-control="three-hands">
                {{ __('money.screen.control.three_hands') }}
            </p>
        @endif
        {{ $slot }}
    </div>
</section>
