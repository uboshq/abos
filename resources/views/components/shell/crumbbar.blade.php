@props(['menu' => []])

{{--
    টপবারের নিচের সরু বার — "আমি কোথায়", আর এই পর্দার নিজের কাজ।

    ── কেন টপবারের সাথে মেশানো হয়নি ────────────────────────────────────
    টপবার পুরো অ্যাপের: কোম্পানি, খোঁজা, বিজ্ঞপ্তি, ব্যবহারকারী — এগুলো
    পর্দা বদলালেও এক থাকে, আর সেজন্যই মানুষ ওগুলোর জায়গা মুখস্থ করতে
    পারে। এই বারটা এই পর্দার: পথ, আর এই পর্দায় যা করা যায়। দুইটা এক
    সারিতে মেশালে টপবারের বোতামগুলো প্রতি পর্দায় সরে যেত, আর তখন কোনটা
    কোথায় সেটা আর শেখা যেত না।

    ── পথটা মেনু থেকেই আসে, হাতে লেখা হয় না ────────────────────────────
    প্রতিটা পর্দায় আলাদা করে breadcrumb লিখতে হলে দুইশোর বেশি জায়গায়
    লিখতে হত, আর একটা মেনু সারির নাম বদলালে পথটা পুরনো নামেই থেকে যেত।
    MenuBuilder ইতিমধ্যেই জানে কোন মডিউল খোলা ও কোন সারি সক্রিয়, তাই
    উত্তরটা ওখানেই আছে।

    কোনো মডিউল না মিললে (যেমন ড্যাশবোর্ড) বারটা শুধু "হোম" দেখায় —
    একটা খালি বার রাখার চেয়ে সেটা ভালো, কারণ ডান পাশের কাজগুলো তখনো
    দরকার।
--}}
@php
    $activeModule = collect($menu)->first(
        fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
    );

    $activeItem = $activeModule
        ? collect($activeModule['groups'])->flatten(1)->firstWhere('active', true)
        : null;

    /*
     * মডিউলের প্রথম পর্দাটা, যাতে মডিউলের নামটাও একটা লিংক হয়।
     *
     * লিংক ছাড়া breadcrumb কেবল একটা লেবেল — "উপরে ফেরা" যায় না, অথচ
     * ওটাই তার একমাত্র কাজ।
     */
    $moduleUrl = $activeModule
        ? collect($activeModule['groups'])->flatten(1)->firstWhere('url', '!==', null)['url'] ?? null
        : null;
@endphp

{{--
    টপবারের ঠিক নিচে আটকে থাকে, তার সাথেই।

    টপবার `sticky top-0`, আর এটা `sticky top-(--spacing-header)` — অর্থাৎ
    ঠিক তার নিচে। না করলে স্ক্রল করার সাথে সাথেই পথটা উপরে উঠে যেত, আর
    লম্বা তালিকার মাঝখানে "আমি কোথায়" প্রশ্নের উত্তর আর থাকত না — অথচ
    ওই প্রশ্নটা ঠিক তখনই ওঠে।

    `z` টপবারের চেয়ে এক ধাপ কম, যাতে টপবারের ড্রপডাউনগুলো এর উপর দিয়ে
    খোলে, নিচে চাপা না পড়ে।
--}}
<div class="sticky top-(--spacing-header) z-20 flex h-9 shrink-0 items-center gap-2
            border-b border-(--color-border) bg-(--color-surface-card) px-3 md:px-5 print-hide">

    <nav class="flex min-w-0 items-center gap-1.5 text-xs text-(--color-ink-muted)"
         aria-label="{{ __('core.a11y.breadcrumb') }}">

        <a href="{{ route('dashboard') }}" class="shrink-0 hover:text-(--color-brand-600) hover:underline">
            {{ __('core.menu.dashboard') }}
        </a>

        @if ($activeModule)
            <span class="text-(--color-ink-disabled)" aria-hidden="true">/</span>

            @if ($moduleUrl)
                <a href="{{ $moduleUrl }}" class="truncate hover:text-(--color-brand-600) hover:underline">
                    {{ $activeModule['label'] }}
                </a>
            @else
                <span class="truncate">{{ $activeModule['label'] }}</span>
            @endif
        @endif

        @if ($activeItem)
            <span class="text-(--color-ink-disabled)" aria-hidden="true">/</span>
            {{-- শেষেরটা লিংক নয় — যে পাতায় আছি সেখানে যাওয়ার লিংক
                 কিছুই করে না, আর ক্লিকযোগ্য দেখালে মানুষ চাপে --}}
            <span class="truncate font-semibold text-(--color-ink-body)"
                  aria-current="page">{{ $activeItem['label'] }}</span>
        @endif
    </nav>

    <div class="flex-1"></div>

    {{-- এই পর্দার কাজ।

         পর্দাগুলো নিজের কাজ এখানে পাঠায় `crumb_actions` স্ট্যাক দিয়ে,
         তাই খালি থাকলে বারটা শুধু পথটাই দেখায় — ফাঁকা বোতামের সারি নয়। --}}
    @stack('crumb_actions')
</div>
