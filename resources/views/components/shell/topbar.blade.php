@props([
    'menu' => [],

    /**
     * সরু পর্দার জন্য — `lg`-এর নিচে দেখা যায়, উপরে নয়।
     *
     * ── কেন এই সুইচটা লাগল ───────────────────────────────────────────
     * `quiet` কঙ্কালে (Linear) উপরে কোনো বার থাকে না — সেটাই ওর পরিচয়।
     * কিন্তু সাইডবারটা `lg:flex`, অর্থাৎ ১০২৪px-এর নিচে সে-ও নেই, আর
     * নিচের bottom-nav `md:hidden`, অর্থাৎ ৭৬৮px-এর উপরে সে-ও নেই।
     *
     * ফলে বারটা তুলে নেওয়ার পর **৭৬৮ থেকে ১০২৩px-এ কোনো নেভিগেশনই
     * থাকত না** — মেনুর একশোটা লিংক DOM-এ থাকত, সবগুলো লুকানো
     * সাইডবারের ভেতরে। ধরা পড়েছে চারটা প্রস্থে মেপে, ১৪৪০px-এ দেখে নয়।
     *
     * Linear নিজেও সরু পর্দায় একটা হেডার দেখায়, তাই এটা নকলের সাথে
     * বিরোধ নয় — বরং নকলটা এখন সব প্রস্থেই সত্যি।
     */
    'narrow' => false,
])

{{--
    টপ নেভিগেশন — সেকশন ১৫.১।

    কোম্পানি+শাখা │ Search │ ⛶ │ ভাষা │ ব্যবহারকারী+রোল

    কোম্পানির ব্লকটা বাঁয়ে, ডানে নয়।

    আগে ডানে ছিল, আর তাতে দুইটা সমস্যা হত। এক, পড়ার ক্রম উল্টো: বাঁ
    থেকে ডানে পড়া চোখ প্রথমেই "আমি কোন প্রতিষ্ঠানের হয়ে কাজ করছি" জানতে
    চায়, আর সেটা পাওয়া যেত সবার শেষে। দুই, ডান কোণে কোম্পানি ও
    ব্যবহারকারী পাশাপাশি বসত — দুইটা আলাদা প্রশ্নের উত্তর একই জায়গায়
    জট পাকিয়ে থাকত।

    এখন বাঁয়ে "কোথায় কাজ করছি", ডানে "আমি কে"।
--}}
@php
    $user = auth()->user();
    $company = $user?->currentCompany;
    $branch = $user?->currentBranch;
@endphp

{{-- `data-topbar` — পাহারার জন্য, নকশার জন্য নয়।

     [[AClonePromisedAShapeAndDrewAnotherTest]] প্রতিটা রূপে মিলিয়ে দেখে
     ঘোষিত কঙ্কালটাই আঁকা হচ্ছে কি না। বারটা আছে কি নেই সেটা জানতে
     পর্দায় ধরার মতো একটা চিহ্ন লাগে, আর ক্লাস দিয়ে সেটা করা যায় না:
     ক্লাস নকশার সাথে বদলায়, আর তখন পাহারাটাও নিঃশব্দে অন্ধ হয়ে যেত।

     মানটাও দরকার — `full` না `narrow` — কারণ `quiet` রূপে বারটা
     থাকে, কিন্তু কেবল সরু পর্দায়। শুধু "আছে কি নেই" দেখলে পাহারাটা
     ওই দুইটার তফাত করতে পারত না। --}}
<header data-topbar="{{ $narrow ? 'narrow' : 'full' }}"
        @class([
            'topbar sticky top-0 z-30 flex h-(--spacing-header) shrink-0 items-center gap-2',
            'border-b border-(--color-topbar-border) bg-(--color-topbar) px-3 md:px-5',
            'lg:hidden' => $narrow,
        ])>

    {{-- রূপের নিজের অংশ — Odoo-র লঞ্চার, D365-এর ওয়াফল, ইত্যাদি।
         যে রূপের কিছু নেই তার জন্য এটা কিছুই আঁকে না। --}}
    <x-shell.chrome region="topbar-start" :menu="$menu ?? []" />

    {{--
        ⭐ SAP Fiori shell bar — ওয়েবের মান ধাপ ২ (মালিকের আদেশ "100% int. standard", ২০ অক্টোবর ২০২৬; নকশা:
        E:\ABOS\মালিকের-ফাইল\ওয়েবের-চেহারা-ভুল-ও-পরিকল্পনা-২০-অক্টোবর.pdf, পাতা ৩)।

        বাঁ থেকে ডানে, আর কিছু নয়:  [মেনু] লোগো · কোম্পানি · শাখা ▾ │ একটাই খোঁজা │ ＋ তৈরি · 🔔 · ছবি

        ⛔ আগে বারে দশটার বেশি জিনিস ছিল (⊞ লঞ্চার, তৈরি, সবুজ টিক, কাগজ, 🔔, পূর্ণপর্দা, EN, 🌙, কোম্পানি, ⇄, ছবি) — Fiori-র
        বারে থাকে কেবল পরিচয়, খোঁজা আর কয়েকটা কাজ। ⓘ কিছুই হারায়নি, জায়গা বদলেছে, অ্যাপের বারের এক নিয়মে:
          · আমার সইয়ের অপেক্ষা বারেই থাকে, ＋ তৈরি আর 🔔-এর মাঝে, কেবল সইদাতার (মালিক, ২০ অক্টোবর); "সই হয়েছে, পোস্ট হয়নি" → ঘণ্টার প্যানেলের মাথায় "আপনার কাজ";
          · ভাষা, রং (ফোনের মতো · আলো · অন্ধকার), পূর্ণপর্দা, কোম্পানি বদল → ছবির মেনুতে;
          · মডিউলের লঞ্চার → বাঁয়ের রেলেই সব মডিউল আছে; সরু পর্দায় নিচের নেভিগেশন;
          · চলতি মডিউলের নাম → মডিউল-প্যানেলের মাথায় আর পাতার পথে।
        ⓘ কোম্পানির সাথে শাখা সবসময় ([[shell.company-switcher]], মালিকের স্থায়ী নিয়ম)।
    --}}
    {{-- মেনু গুটানো-খোলার একমাত্র সুইচ — দুই অবস্থাতেই একই জায়গায় ($store.sidebar; [[AListenerNobodyEverBoundTest]]) --}}
    <button type="button"
            x-data
            @click="$store.sidebar.toggle()"
            class="hidden size-9 shrink-0 items-center justify-center rounded-(--radius-field)
                   border border-(--color-topbar-border) text-(--color-topbar-ink-muted) transition-colors
                   hover:bg-(--color-topbar-hover) hover:text-(--color-topbar-ink) md:flex"
            aria-label="{{ __('core.a11y.collapse_sidebar') }}"
            :aria-expanded="$store.sidebar.collapsed ? 'false' : 'true'"
            :aria-label="$store.sidebar.collapsed
                ? '{{ __('core.a11y.expand_sidebar') }}'
                : '{{ __('core.a11y.collapse_sidebar') }}'">
        <x-ui.icon name="list" :size="18" />
    </button>

    <a href="{{ route('dashboard') }}" data-shell-logo class="flex shrink-0 items-center rounded-(--radius-field)"
       title="{{ __('core.menu.dashboard') }}" aria-label="ABOS · {{ __('core.menu.dashboard') }}">
        <img src="{{ asset('brand/abos-icon-64.png') }}" alt="" aria-hidden="true" class="size-8">
    </a>

    {{-- কোম্পানি · শাখা ▾ — লোগোর ঠিক পাশে, সব মাপে (ফোনে নাম ছোট হয়ে কাটে, বার নয়) --}}
    @if ($company)
        <div class="min-w-0 shrink" data-shell-company>
            <x-shell.company-switcher :company="$company" :branch="$branch" />
        </div>
    @endif

    {{-- একটাই খোঁজা — মাঝে, চওড়া; পাতা, মেনু, গ্রাহক, পণ্য আর কাগজের নম্বর সবই এখানে ([[SearchController::pagesMatching()]])।
         "/" বা Ctrl+K চাপলেও খোলে ([[commandCenter]])। --}}
    <button type="button"
            x-data
            data-shell-search
            @click="$dispatch('open-command-center')"
            title="{{ __('core.action.search_everything') }} (/ · Ctrl K)"
            aria-keyshortcuts="/ Control+K"
            class="mx-auto flex min-h-(--spacing-touch) min-w-0 flex-1 items-center gap-2 rounded-(--radius-field)
                   border border-(--color-topbar-border) bg-(--color-topbar-field) px-3
                   text-start text-(--color-topbar-ink-muted) transition-colors
                   hover:border-(--color-topbar-ink-muted) sm:max-w-xl">
        <svg viewBox="0 0 24 24" class="size-(--spacing-icon) shrink-0 fill-current" aria-hidden="true">
            <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
        </svg>
        <span class="truncate text-sm">{{ __('core.action.search_everything') }}</span>
        <kbd class="ms-auto hidden rounded border border-(--color-topbar-border) px-1.5 text-xs md:inline" aria-hidden="true">/</kbd>
    </button>

    {{-- ডানে কেবল এগুলো: কাজ শুরু (＋ তৈরি), আমার সইয়ের অপেক্ষা (সইদাতা হলে), কী ঘটেছে (🔔), আর আমি কে (ছবি) --}}
    <div class="flex shrink-0 items-center gap-1" data-shell-actions>
        <x-shell.create-menu />
        {{-- ⭐ অনুমোদন — ＋ তৈরি আর 🔔-এর মাঝে, কেবল সইদাতার বারে (মালিক, ২০ অক্টোবর ২০২৬) --}}
        <x-shell.approval-quick-access />
        <x-shell.notification-bell />
        <x-shell.profile-menu :user="$user" :company="$company" :branch="$branch" />
    </div>
</header>
