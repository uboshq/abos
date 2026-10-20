@props(['menu' => []])
{{--
    ⭐ "কোথায় আছি" — পাতার মাথার উপরে, ABOS (navy) রূপে (পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬:
    *"উপরে 'কোথায় আছি' (যেমন বিক্রয় › অর্ডার), তারপর শিরোনাম, এক লাইনের ব্যাখ্যা, ডান কোণে একটাই প্রধান বোতাম"*)।

    ⓘ বাকি ন'টা রূপের নিজের crumbbar আছে, আর ওগুলো হিমায়িত (TheOtherNineLooksStillDrawTheSameTest) — তাই এটা কেবল
    navy-তে, লেআউটে বসানো, কোনো পাতাকে কিছু লিখতে হয় না। ⓘ পথটা মেনু থেকে, crumbbar-এর একই নিয়মে: যে মডিউলের কোনো সারি
    সক্রিয়, আর সেই সারি। কিছু না মিললে (হোম, প্রোফাইল) পথ আঁকা হয় না — খালি পথের চেয়ে কিছু না থাকা ভালো।

    ⭐ ডান কোণে "তারিখ · কোম্পানি · শাখা" — মালিকের অনুমোদিত নমুনা (ওয়েবের মান ধাপ ২, ২০ অক্টোবর ২০২৬; SAP Fiori-র
    পাতার মাথার প্রসঙ্গ-লাইন)। ছাপা কাগজের মতোই, পর্দার ছবি দেখেও বোঝা যায় কবে, কোন কোম্পানির কোন শাখার সংখ্যা।
    ⓘ বার, দিন আর মাসের নাম বাংলায়, অঙ্ক ইংরেজিতে (মালিকের "খ", ১৯ অক্টোবর); শাখা বারের একই উত্তর ([[ShellFacts::branchLabel()]])।
    ⓘ এটা পথ নয়, তাই breadcrumb-এর `nav`-এর বাইরে, নিজের `p`-তে।
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

    $user = auth()->user();
    $company = $user?->currentCompany;
    $context = null;

    if ($company !== null) {
        $today = now()->locale(app()->getLocale())->translatedFormat('l, j F Y');
        $today = strtr($today, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $context = implode(' · ', array_filter([
            $today,
            $company->name(),
            app(\App\Core\Services\ShellFacts::class)->branchLabel($user, $user->currentBranch),
        ]));
    }
@endphp

@if (($activeModule && $activeItem) || $context)
    <div class="print-hide mb-1 flex min-w-0 flex-wrap items-center gap-x-4 gap-y-0.5 text-xs text-(--color-ink-muted)">
        @if ($activeModule && $activeItem)
            <nav data-page-path class="flex min-w-0 items-center gap-1.5" aria-label="{{ __('core.a11y.breadcrumb') }}">
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

        @if ($context)
            <p data-page-context class="ms-auto min-w-0 truncate tabular-nums" title="{{ $context }}">{{ $context }}</p>
        @endif
    </div>
@endif
