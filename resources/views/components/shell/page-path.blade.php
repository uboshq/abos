@props(['menu' => []])
{{--
    ⭐ "কোথায় আছি" — পাতার মাথার উপরে, ABOS (navy) রূপে (পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬:
    *"উপরে 'কোথায় আছি' (যেমন বিক্রয় › অর্ডার), তারপর শিরোনাম, এক লাইনের ব্যাখ্যা, ডান কোণে একটাই প্রধান বোতাম"*)।

    ⓘ বাকি ন'টা রূপের নিজের crumbbar আছে, আর ওগুলো হিমায়িত (TheOtherNineLooksStillDrawTheSameTest) — তাই এটা কেবল
    navy-তে, লেআউটে বসানো, কোনো পাতাকে কিছু লিখতে হয় না। ⓘ পথটা মেনু থেকে, crumbbar-এর একই নিয়মে: যে মডিউলের কোনো সারি
    সক্রিয়, আর সেই সারি। কিছু না মিললে (হোম, প্রোফাইল) কিছুই আঁকে না — খালি পথের চেয়ে কিছু না থাকা ভালো।
--}}
@php
    $activeModule = collect($menu)->first(
        fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
    );
    $activeItem = $activeModule
        ? collect($activeModule['groups'])->flatten(1)->firstWhere('active', true)
        : null;
    $moduleUrl = $activeModule
        ? collect($activeModule['groups'])->flatten(1)->firstWhere('url', '!==', null)['url'] ?? null
        : null;
@endphp

@if ($activeModule && $activeItem)
    <nav data-page-path class="print-hide mb-1 flex min-w-0 items-center gap-1.5 text-xs text-(--color-ink-muted)"
         aria-label="{{ __('core.a11y.breadcrumb') }}">
        @if ($moduleUrl)
            <a href="{{ $moduleUrl }}" class="truncate hover:text-(--color-brand-600) hover:underline">{{ $activeModule['label'] }}</a>
        @else
            <span class="truncate">{{ $activeModule['label'] }}</span>
        @endif

        <span class="text-(--color-ink-disabled)" aria-hidden="true">›</span>

        {{-- শেষেরটা লিংক নয় — যে পাতায় আছি সেখানে যাওয়ার লিংক কিছুই করে না --}}
        <span class="truncate text-(--color-ink-body)" aria-current="page">{{ $activeItem['label'] }}</span>
    </nav>
@endif
