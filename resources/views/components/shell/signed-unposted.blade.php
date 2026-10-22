{{--
    সই হয়েছে, পোস্ট হয়নি — মালিক, ১১ অক্টোবর ২০২৬: *"Approval er pore Vautcher inv, & challan & others post korte hoy
    tookhon khuje ber korle somoy lage tai ekta icon"* (ক)।

    শেষ সইয়ে কাগজ নিজেই পোস্ট হয় ([[SignedPaperFinisher]]); যেটা আটকে যায় (বাকির সীমা, মাস বন্ধ, মজুদ…) সেটা এখানে গোনা —
    অনুমোদনের সিলের ঠিক পাশে, কারণ প্রশ্নটা একই ধারার: "সইয়ের পরে কী বাকি"। চাপলে তালিকা ([[SignedButNotPosted]])।

    <b>শূন্যে ব্যাজ নেই</b> — পাশের সিলের একই নিয়ম; আইকনটা থাকে, যাতে মানুষ জানেন খোঁজার জায়গা কোথায়।
--}}
{{-- ⓘ `bar` — উপরের বারে, অনুমোদনের সিলের পাশে (মালিকের নিজের চাওয়া আইকন; লাইভ-হটফিক্স, ২১ অক্টোবর ২০২৬: ওয়েবের মান ধাপ ২ এটাকে
     ঘণ্টার ভেতরে নিয়ে গিয়েছিল, আর মালিক বারে খুঁজে পাননি)। বারের রঙে, পাশের সিলের মতো; ঘণ্টার প্যানেলেরটা পাতার রঙে। --}}
@props(['bar' => false])
@php
    $stuck = auth()->check()
        ? app(\App\Core\Engines\Approval\SignedButNotPosted::class)->count(auth()->user())
        : 0;
@endphp

@auth
    <a href="{{ route('approval.inbox.signed_unposted') }}" data-signed-unposted @if ($bar) data-signed-unposted-bar="{{ $stuck }}" @endif data-live="signed-unposted"
       @class([
           'relative flex size-(--spacing-touch) items-center justify-center rounded-(--radius-field) transition-colors',
           'text-(--color-topbar-ink-muted) hover:bg-(--color-topbar-hover) hover:text-(--color-topbar-ink)' => $bar,
           'text-(--color-ink-muted) hover:bg-(--color-surface-hover)' => ! $bar,
       ])
       title="{{ __('core.notice.signed_unposted') }}"
       aria-label="{{ __('core.notice.signed_unposted') }}">
        <x-ui.icon name="receipt" :size="18" />

        @if ($stuck > 0)
            <span data-signed-unposted-count
                  class="pops-onto-page absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full
                         bg-(--color-danger) px-1 text-[10px] font-semibold leading-4 text-white">
                {{ $stuck }}
            </span>
        @endif
    </a>
@endauth
