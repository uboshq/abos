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

<header class="topbar sticky top-0 z-30 flex h-(--spacing-header) shrink-0 items-center gap-2
               border-b border-(--color-topbar-border) bg-(--color-topbar) px-3 md:px-5">

    {{-- মোবাইলে সাইডবার নেই, তাই লোগোটা এখানে দেখা যায় --}}
    <img src="{{ asset('brand/adi-icon-64.png') }}" alt="ADI | ABOS"
         class="size-8 shrink-0 md:hidden">

    {{--
        মেনু গুটানো-খোলার একমাত্র সুইচ।

        ── কেন এটা এখানে, সাইডবারের ভেতরে নয় ───────────────────────────
        আগে বোতামটা ছিল সাইডবারের ভেতরে, একটা `«` চিহ্ন। কিন্তু সে যা
        গুটায় তার ভেতরেই বসে — গুটিয়ে ফেললে সে নিজেই উধাও, আর খোলার
        কোনো পথ থাকত না। ব্যবহারকারীকে তখন হয় পাতা রিফ্রেশ করতে হত, নয়
        localStorage মুছতে হত।

        টপবারের এই ঘরটা দুই অবস্থাতেই একই জায়গায় থাকে, আর সেটাই একমাত্র
        রূপ যেটা মানুষ শিখতে পারে।

        অবস্থাটা Alpine store-এ ($store.sidebar), কারণ সুইচ এখানে আর যা
        নড়ে সেটা অন্য শাখায় — প্রতিটার নিজের x-data রাখলে দুইজনের দুই
        রকম উত্তর থাকত।

        সীমানাসহ, কারণ এটা একটা বোতাম: পাশের লোগো ও কোম্পানির ব্লক
        দুইটাই লিংক, আর সীমানা ছাড়া তিনটাই একই রকম লাগত।
    --}}
    <button type="button"
            @click="$store.sidebar.toggle()"
            class="hidden size-9 shrink-0 items-center justify-center rounded-(--radius-field)
                   border border-(--color-topbar-border) text-(--color-topbar-ink-muted) transition-colors
                   hover:bg-(--color-topbar-hover) hover:text-(--color-topbar-ink) md:flex"
            :aria-expanded="$store.sidebar.collapsed ? 'false' : 'true'"
            :aria-label="$store.sidebar.collapsed
                ? '{{ __('core.a11y.expand_sidebar') }}'
                : '{{ __('core.a11y.collapse_sidebar') }}'">
        <x-ui.icon name="list" :size="18" />
    </button>

    @if ($company)
        {{-- কোম্পানি ও শাখা — ব্যবহারকারী সবসময় জানবে সে কোথায় কাজ করছে।
             একাধিক কোম্পানি থাকলেই সুইচার সক্রিয় (সেকশন ১৫.১৫)।

             ৩৭৫px-এ লুকানো: লোগো, সার্চ, ভাষা, কোম্পানির লম্বা নাম ও
             প্রোফাইল একসাথে ধরে না। মোবাইলে কোম্পানির নাম পাতার
             শিরোনামেই থাকে, তাই তথ্যটা হারায় না। --}}
        <div class="hidden shrink-0 sm:block">
            <x-shell.company-switcher :company="$company" :branch="$branch" />
        </div>

        <span class="hidden h-8 w-px shrink-0 bg-(--color-topbar-border) sm:block" aria-hidden="true"></span>
    @endif

    {{-- Ctrl+K — সেকশন ১৫.৩। মোবাইলে শুধু আইকন, বড় স্ক্রিনে পূর্ণ বাক্স। --}}
    {{-- min-w-0 ছাড়া flex আইটেম নিজের কনটেন্টের চেয়ে ছোট হতে পারে না,
         আর তখন পুরো বারটা উপচে পড়ে — ৩৭৫px-এ ঠিক সেটাই হচ্ছিল। --}}
    {{-- আগে ২৮rem পর্যন্ত চওড়া হতো — বাক্সটা তার নিজের লেখার চেয়ে তিনগুণ,
         আর ভেতরে বড় ফাঁকা জায়গা। এখন ৯rem: প্রায় এক-তৃতীয়াংশ, আর টপবারের
         বাকি জায়গাটা কোম্পানি ও শাখার নামের জন্য থাকে, যা সারাদিন পড়া হয়।

         "Ctrl K" চিপটা সরানো হয়েছে — এই মাপে ওটা বসালে খোঁজার লেখাটাই কেটে
         যেত, আর যে ইঙ্গিত নিজের লেখাটাকেই ঢেকে দেয় সেটা ইঙ্গিত নয়। শর্টকাটটা
         title-এ আছে, আর কাজ করে আগের মতোই। --}}
    <button type="button"
            x-data
            @click="$dispatch('open-command-center')"
            title="{{ __('core.action.search_anything') }} (Ctrl K)"
            class="flex min-h-(--spacing-touch) min-w-0 flex-1 items-center gap-2 rounded-(--radius-field)
                   border border-(--color-topbar-border) bg-(--color-topbar-field) px-3
                   text-start text-(--color-topbar-ink-muted) transition-colors
                   hover:border-(--color-topbar-ink-muted) sm:max-w-36">
        <svg viewBox="0 0 24 24" class="size-(--spacing-icon) shrink-0 fill-current" aria-hidden="true">
            <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
        </svg>
        <span class="truncate text-sm">{{ __('core.action.search_anything') }}</span>
    </button>

    {{-- ডান দিকের ঝাঁক, DMS-এর ক্রমেই: যা জানায় → যা পর্দা বদলায় → কে
         বসে আছে। জানানোর জিনিস আগে, কারণ ওটাই একমাত্র যেটা নিজে থেকে
         বদলায়; বাকিগুলো যেখানে রাখা হয়েছে সেখানেই থাকে, আর হাত সেটা
         মুখস্থ করে ফেলে। --}}
    <div class="ms-auto flex items-center gap-1">
        {{-- ঝাঁকের প্রথম, কারণ এটাই একমাত্র যেটা কাজ শুরু করে। --}}
        <x-shell.create-menu />

        <x-shell.approval-quick-access />
        <x-shell.notification-bell />

        <x-shell.fullscreen-toggle />

        {{-- ভাষা — নিয়ম ৯। সেভ হয় ব্যবহারকারীর রেকর্ডে, সেশনে নয়।

             আইকন **ও** লেখা, একটা নয়।

             ── কেন দুইটাই ─────────────────────────────────────────────
             শুধু পৃথিবীর আইকন হলে বোঝা যেত না কোন ভাষায় যাচ্ছে — আর
             পাশের বোতামগুলো সব আইকন, তাই একটা একা "EN" লেখা বোতাম ওই
             সারিতে বেমানান লাগত (মালিক ধরেছেন, ১৪ আগস্ট)।

             লেখাটা গন্তব্যের ভাষায়: বাংলায় থাকলে "EN", ইংরেজিতে থাকলে
             "বাং" — অর্থাৎ বোতামটা বলে কোথায় যাবে, কোথায় আছে তা নয়। --}}
        <form method="POST" action="{{ route('locale.switch') }}" class="contents">
            @csrf
            <button type="submit" name="locale" value="{{ app()->getLocale() === 'bn' ? 'en' : 'bn' }}"
                    class="flex h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) px-2
                           text-sm font-medium text-(--color-topbar-ink-muted) transition-colors
                           hover:bg-(--color-topbar-hover) hover:text-(--color-topbar-ink)"
                    title="{{ __('core.action.switch_language') }}">
                <x-ui.icon name="globe" :size="18" />
                {{ app()->getLocale() === 'bn' ? 'EN' : 'বাং' }}
            </button>
        </form>

        {{-- ভাষার পাশে, কারণ দুটোই একই জাতের: পর্দাটা এই মানুষটা কেমন
             চান, পর্দাটা কী নিয়ে তা নয়। --}}
        <x-shell.theme-toggle />

        {{-- ব্যবহারকারীর নাম ও রোল — ছবির পাশে, ছবির ভেতরে নয়।

             আগে শুধু ছবিটা ছিল, আর নামটা জানতে হলে হয় hover করতে হত
             (মোবাইলে যা নেই) নয় মেনু খুলতে হত। "আমি কার হয়ে লগইন করা"
             প্রশ্নটা সবচেয়ে বেশি জিজ্ঞেস করা হয়, বিশেষ করে যেখানে
             একটা কম্পিউটার কয়েকজন ভাগ করে ব্যবহার করেন — ডিপোতে ঠিক
             সেটাই হয়। --}}
        @if ($user)
            <span class="hidden min-w-0 text-end lg:block">
                <span class="block max-w-40 truncate text-sm font-medium text-(--color-topbar-ink)">
                    {{ $user->name }}
                </span>
                @if ($role = $user->getRoleNames()->first())
                    <span class="block max-w-40 truncate text-2xs text-(--color-topbar-ink-muted)">
                        {{ __('core.role.'.$role) }}
                    </span>
                @endif
            </span>
        @endif

        <x-shell.profile-menu :user="$user" />
    </div>
</header>
