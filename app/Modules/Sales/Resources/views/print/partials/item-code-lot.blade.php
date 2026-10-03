{{--
    পণ্যের নামের নিচে কোড · লট — বিলের দুই সুইচ (মালিক, ৩ অক্টোবর ২০২৬; [[InvoicePrintLook::SHOWS]])।

    ⓘ দুইটারই নিজের চিহ্ন (`data-line-code` / `data-line-lot`) — সুইচ বন্ধ করলে ঠিক ঐ অংশটা কাগজ থেকে যায় কি না,
    সেটা পরীক্ষা এই চিহ্ন ধরেই মাপে। ⚠️ কোনটা দেখাবে সেটা এখানে ঠিক হয় না: সুইচ বন্ধ থাকলে নিয়ন্ত্রক ঘরটা
    খালি পাঠায় ([[SalesPrintController::withoutCodeUnlessShown()]], `lotsForInvoice()`), এখানে কেবল আঁকা।

    `inline` — থার্মালের এক লাইনে " · " দিয়ে; নইলে নামের নিচে নিজের সারি (`class` বা `style`)।
--}}
@php
    $code = (string) ($item['code'] ?? '');
    $lot = (string) ($item['lot'] ?? '');
@endphp
@if ($code !== '' || $lot !== '')
    @if ($inline ?? false)
        · @if ($code !== '')<span data-line-code>{{ $code }}</span>@endif
        @if ($code !== '' && $lot !== '') · @endif
        @if ($lot !== '')<span data-line-lot>{{ $lot }}</span>@endif
    @else
        <div @if (isset($class)) class="{{ $class }}" @endif @if (isset($style)) style="{{ $style }}" @endif>
            @if ($code !== '')<span data-line-code>{{ $code }}</span>@endif
            @if ($code !== '' && $lot !== '') · @endif
            @if ($lot !== '')<span data-line-lot>{{ $lot }}</span>@endif
        </div>
    @endif
@endif
