{{--
    স্ট্যাটাস বার — সেকশন ১৫.১।

    Status │ Notice │ FY │ User │ Version

    কোম্পানি ও শাখা এখানে ছিল, এখন নেই — দুটোই টপবারের বাঁ কোণে বড় করে
    দেখা যায়। একই তথ্য দুই জায়গায় থাকা মানে পর্দার নিচের পুরো একটা সারি
    নষ্ট, আর ওই সারিটা আরও কাজের জিনিসে লাগে।

    ওই জায়গায় এখন নোটিশ: এই মুহূর্তে কী নজর দেওয়া দরকার। কিছু না থাকলে
    বারটা চুপ থাকে — "সব ঠিক আছে" লিখে জায়গা ভরাট করার মানে নেই।

    মোবাইলে লুকানো: ৩২০px-এ এতগুলো তথ্য ধরে না, আর কেটে দেখানোর চেয়ে না
    দেখানো ভালো।
--}}
@php
    $user = auth()->user();
    $year = $user?->currentCompany?->currentFinancialYear();
    $notices = $user ? app(\App\Core\Services\StatusNotices::class)->all() : [];
@endphp

<footer class="fixed inset-x-0 bottom-0 z-20 hidden h-(--spacing-status-bar) items-center gap-4
               border-t border-(--color-border) bg-(--color-surface-card) px-3
               text-2xs text-(--color-ink-muted) md:flex">

    <span class="flex shrink-0 items-center gap-1.5">
        <span class="size-2 rounded-full bg-(--color-success)" aria-hidden="true"></span>
        {{ __('core.status_bar.operational') }}
    </span>

    {{-- কার তৈরি — সেকশন ১৭.২।

         প্রিন্টের মতো এখানেও, কারণ পর্দাটা সারাদিন খোলা থাকে। বন্ধ করার
         সুইচটা প্রিন্টের জন্য আলাদা: কাগজ বাইরে যায়, পর্দা যায় না। --}}
    <span class="hidden shrink-0 items-center gap-1.5 lg:flex">
        {{ __('core.brand.powered_by') }}
        {{-- চিহ্নটা লেখার মাঝখানে, দুই শব্দের পরে আর নামের আগে — যেভাবে
             লকআপটা আঁকা। ছবিটা ABOS-এ ছিলই না, তাই লাইনটা কেবল লেখা হয়ে
             ছিল: যে চিহ্ন কোথাও নেই সেটা কেউ খুঁজতেও যায় না।

             aria-hidden, কারণ নামটা পাশেই লেখা আছে — পর্দা-পাঠক যেন একই
             কথা দুবার না বলে। --}}
        <img src="{{ asset('brand/univer-mark.png') }}" alt="" aria-hidden="true"
             class="size-4 shrink-0 object-contain">
        <span>{{ __('core.brand.powered_by_name') }}</span>
    </span>

    {{-- নোটিশগুলো — ক্লিকযোগ্য, কারণ জানানোই যথেষ্ট নয়।

         "৩টা খসড়া ভাউচার পোস্ট হয়নি" পড়ে ব্যবহারকারী সমস্যাটা জানেন,
         কিন্তু কোথায় গিয়ে ঠিক করবেন তা নয়। লিংক ছাড়া বার্তাটা অভিযোগ,
         সাহায্য নয় (নিয়ম ১)।

         যেগুলোর কোনো পর্দা নেই (ব্যাকআপ — কমান্ড লাইনের কাজ; প্রতিষ্ঠানের
         নোটিশ — ওটা পড়ার জিনিস), সেখানে লিংকও নেই: যে লিংক কোথাও নিয়ে
         যায় না সেটাই মৃত লিংক।

         marquee বা স্ক্রল করা লেখা নয়: চলন্ত লেখা পড়তে চোখ ধাওয়া করতে
         হয়, আর যে বার্তাটা সবচেয়ে জরুরি সেটাই সবচেয়ে কঠিন হয়ে ওঠে।
         লম্বা হলে কেটে যায়, আর পুরোটা title-এ থাকে। --}}
    <span class="flex min-w-0 flex-1 items-center gap-3 overflow-hidden">
        @foreach ($notices as $notice)
            @php
                $tone = match ($notice['tone']) {
                    'danger' => 'text-(--color-danger)',
                    'pending' => 'text-(--color-badge-pending-ink)',
                    default => 'text-(--color-badge-info-ink)',
                };
            @endphp

            @if ($notice['url'])
                <a href="{{ $notice['url'] }}"
                   class="flex min-w-0 shrink-0 items-center gap-1.5 {{ $tone }} hover:underline"
                   title="{{ $notice['text'] }}">
                    <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
                    <span class="truncate">{{ $notice['text'] }}</span>
                </a>
            @else
                <span class="flex min-w-0 items-center gap-1.5 {{ $tone }}"
                      title="{{ $notice['text'] }}">
                    <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
                    <span class="truncate">{{ $notice['text'] }}</span>
                </span>
            @endif
        @endforeach
    </span>

    @if ($year)
        <span class="shrink-0">{{ __('core.company.financial_year') }}: {{ $year->name }}</span>
    @endif

    {{-- ব্যবহারকারীর নাম এখানে ছিল, এখন নেই।

         যুক্তিটা ছিল "আমি কে হয়ে বসে আছি" — ডিপোতে একটা কম্পিউটার
         কয়েকজন ভাগ করে ব্যবহার করেন, তাই প্রশ্নটা ঘন ঘন আসে। কিন্তু
         উত্তরটা টপবারের ডান কোণে আগে থেকেই আছে, নামের নিচে ভূমিকাসহ, আর
         পাশে অক্ষরের গোল ছবিটাও। একই কথা দুই জায়গায় লেখা মানে একটা
         জায়গা নষ্ট — কোম্পানি আর শাখাকে ঠিক এই কারণেই এই বার থেকে সরানো
         হয়েছিল (উপরের নোট)। --}}

    <span class="hidden shrink-0 lg:inline">
        © {{ now()->year }} {{ config('app.name', 'ABOS') }}
    </span>

    <span class="shrink-0">v{{ config('app.version', '0.1.0') }}</span>
</footer>
