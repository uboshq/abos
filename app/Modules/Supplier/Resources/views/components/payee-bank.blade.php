{{--
    ⭐ প্রাপকের ব্যাংক — পরিশোধের পর্দায়, কেবল পড়ার জন্য (নকশা দফা #৬, ২১ অক্টোবর ২০২৬; cb-র অনুরোধ)।
    ⓘ `<x-supplier::payee-bank :supplier="$supplier" />` — মডিউলের নিজের কম্পোনেন্ট (`supplier::components.*`, ক্রয়ের
    `x-purchase::line-editor`-এর একই পথ); কোর এই মডিউলের নাম জানে না ([[BoundariesTest]])।

    ⓘ দেখায় কেবল চালু, দুই হাতে সই-পাওয়া তথ্য ([[SupplierBank::signedFor()]]): ব্যাংক · শাখা · হিসাবের নাম · ••••শেষ চার ·
    রাউটিং · "✓ দুজনের সইয়ে যাচাই-করা"। ⛔ এখানে নম্বর কখনো পুরো নয় — পরিশোধকারীর পুরো নম্বর জানার দরকার নেই, আর
    ঘর বদলানোও যায় না (বদল সরবরাহকারীর পাতায়, দ্বিতীয় সইসহ)।

    ⓘ `data-payee-bank-signed` = 1 / 0; `data-payee-bank-state` = signed · pending · none। সই না থাকলে ব্যাংকে পরিশোধ
    আটকায় — বার্তা `supplier::bank.unsigned_blocks_payment`, নিয়ম সার্ভারে ([[SupplierBank::assertBankPayable()]])।
--}}
@props(['supplier'])

@php
    $bankService = app(\App\Modules\Supplier\Services\SupplierBank::class);
    $signedBank = $supplier ? $bankService->signedFor($supplier) : null;
    $pendingBank = $supplier && $signedBank === null ? $bankService->pendingFor($supplier) : null;
    $bankState = $signedBank !== null ? 'signed' : ($pendingBank !== null ? 'pending' : 'none');
@endphp

{{-- ⓘ শুধু-পড়ার বাক্স, তাই পাতার ধূসর জমিন — লেখার ঘরের সাদা নয় (নকশা পাতা ৪) --}}
<div {{ $attributes->merge(['class' => 'rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-3 py-2 text-sm']) }}
     data-payee-bank-signed="{{ $signedBank !== null ? '1' : '0' }}" data-payee-bank-state="{{ $bankState }}">
    @if ($signedBank !== null)
        {{-- ⭐ এক লাইনে, নকশার মতো: ব্যাংক · শাখা · নাম · হিসাব ••••শেষ চার · রাউটিং … · ✓ (টাকা দেওয়ার মিল-তালিকা #১১, ২২ অক্টোবর ২০২৬);
             সরু পর্দায় টুকরো ধরে ভাঙে, কথার মাঝে নয় --}}
        <p class="flex flex-wrap items-baseline gap-x-1" data-payee-bank-line>
            <span>{{ collect([$signedBank->bank_name, $signedBank->branch_name, $signedBank->account_name])->filter()->implode(' · ') }}</span>
            <span>· {{ __('supplier::bank.account_short') }} <span class="num" data-payee-bank-account>{{ $signedBank->maskedAccount() }}</span></span>
            @if (filled($signedBank->routing_no))
                <span>· {{ __('supplier::bank.routing_short') }} <span class="num">{{ $signedBank->routing_no }}</span></span>
            @endif
            <span class="font-semibold text-(--color-success)">· {{ __('supplier::bank.verified') }}</span>
        </p>
    @else
        <p class="text-(--color-ink-muted)">{{ __('supplier::bank.payee_none') }}</p>
        @if ($pendingBank !== null)
            <p class="mt-1"><x-ui.badge tone="pending">{{ __('supplier::bank.pending_badge') }}</x-ui.badge></p>
        @endif
        <p class="mt-1 text-2xs text-(--color-danger)" role="alert">{{ __('supplier::bank.unsigned_blocks_payment') }}</p>
    @endif
</div>
