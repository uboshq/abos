{{--
    টপ নেভিগেশন — সেকশন ১৫.১।

    ABOS │ Workspace │ Command Search │ +Create │ Notification │ Help │ Profile
--}}
@php
    $user = auth()->user();
    $company = $user?->currentCompany;
    $branch = $user?->currentBranch;
@endphp

<header class="sticky top-0 z-30 flex h-(--spacing-header) shrink-0 items-center gap-2
               border-b border-(--color-border) bg-(--color-surface-card) px-3 md:px-5">

    {{-- মোবাইলে সাইডবার নেই, তাই লোগোটা এখানে দেখা যায় --}}
    <img src="{{ asset('brand/abos-icon-64.png') }}" alt="ABOS"
         class="size-8 shrink-0 md:hidden">

    {{-- Ctrl+K — সেকশন ১৫.৩। মোবাইলে শুধু আইকন, বড় স্ক্রিনে পূর্ণ বাক্স। --}}
    {{-- min-w-0 ছাড়া flex আইটেম নিজের কনটেন্টের চেয়ে ছোট হতে পারে না,
         আর তখন পুরো বারটা উপচে পড়ে — ৩৭৫px-এ ঠিক সেটাই হচ্ছিল। --}}
    <button type="button"
            x-data
            @click="$dispatch('open-command-center')"
            class="flex min-h-(--spacing-touch) min-w-0 flex-1 items-center gap-2 rounded-(--radius-field)
                   border border-(--color-border) bg-(--color-surface-app) px-3
                   text-start text-(--color-ink-placeholder) transition-colors
                   hover:border-(--color-border-strong) sm:max-w-md">
        <svg viewBox="0 0 24 24" class="size-(--spacing-icon) shrink-0 fill-current" aria-hidden="true">
            <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
        </svg>
        <span class="truncate text-sm">{{ __('core.action.search_anything') }}</span>
        <kbd class="ms-auto hidden rounded border border-(--color-border) px-1.5 py-0.5 text-2xs lg:block">
            Ctrl K
        </kbd>
    </button>

    <div class="ms-auto flex items-center gap-1">
        <x-shell.fullscreen-toggle />

        {{-- ভাষা — নিয়ম ৯। সেভ হয় ব্যবহারকারীর রেকর্ডে, সেশনে নয়। --}}
        <form method="POST" action="{{ route('locale.switch') }}" class="contents">
            @csrf
            <button type="submit" name="locale" value="{{ app()->getLocale() === 'bn' ? 'en' : 'bn' }}"
                    class="flex size-(--spacing-touch) items-center justify-center rounded-(--radius-field)
                           text-sm font-medium text-(--color-ink-muted) transition-colors
                           hover:bg-(--color-surface-hover)"
                    title="{{ __('core.action.switch_language') }}">
                {{ app()->getLocale() === 'bn' ? 'EN' : 'বাং' }}
            </button>
        </form>

        @if ($company)
            {{-- কোম্পানি ও শাখা — ব্যবহারকারী সবসময় জানবে সে কোথায় কাজ করছে।
                 একাধিক কোম্পানি থাকলেই কেবল সুইচার (সেকশন ১৫.১৫)।

                 ৩৭৫px-এ লুকানো: লোগো, সার্চ, ভাষা, কোম্পানির লম্বা নাম ও
                 প্রোফাইল একসাথে ধরে না, আর প্রোফাইল বোতামটা পর্দার বাইরে
                 চলে যাচ্ছিল। মোবাইলে কোম্পানির নাম পাতার শিরোনামেই থাকে,
                 তাই তথ্যটা হারায় না। --}}
            <div class="hidden sm:contents">
                <x-shell.company-switcher :company="$company" :branch="$branch" />
            </div>
        @endif

        <x-shell.profile-menu :user="$user" />
    </div>
</header>
