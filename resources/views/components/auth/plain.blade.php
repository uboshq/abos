{{--
    পাসওয়ার্ড ফেরানোর পর্দাগুলোর সাধারণ খোল।

    ── কেন একটা ভাগ করা খোল, দুইটা আলাদা পাতা নয় ────────────────────────
    দুইটা পর্দা — ঠিকানা চাওয়া (`auth.forgot`) আর নতুন পাসওয়ার্ড বসানো
    (`auth.reset`) — একই যাত্রার দুই ধাপ, আর দেখতে এক হওয়াই উচিত।

    ⚠️ [[auth._form]]-এর মন্তব্যে এই রিপোর নিজের শিক্ষাটা লেখা আছে: কপি
    করে দুই জায়গায় রাখলে **একদিন দুইটা আলাদা হয়ে যেত** — কেউ একটায়
    ভাষার সুইচ ঠিক করত, অন্যটায় নয়; আর ভুলটা ধরা পড়ত সেই পর্দায় যেটা
    কম ব্যবহার হয়, অর্থাৎ দেরিতে।

    ── কেন `auth.signin`-এর খোলটাই পুনর্ব্যবহার করা হয়নি ────────────────
    ⓘ ওটা একটা পূর্ণ পাতা, উপাদান নয় — ওর ভেতরে লগইনের ফর্মটা সরাসরি
    বসানো। উপাদানে ভাঙতে গেলে **একটা কাজ-করা দরজা বদলাতে হত**, আর
    আজকের কাজ নতুন একটা পথ বানানো, পুরনোটা নাড়ানো নয়।

    ⭐ তাই নকশাটা মিলিয়ে নেওয়া হয়েছে (একই টোকেন, একই লোগো, একই সোনালি
    চুল-দাগ), আর পুরনো পাতাটা অছোঁয়া রয়ে গেছে।

    @property string $title  ব্রাউজারের ট্যাবে ও পর্দার শিরোনামে
    @property string $lead   শিরোনামের নিচের এক লাইন ব্যাখ্যা
--}}
@props(['title', 'lead' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light"
      style="{{ \App\Core\Support\Accent::styleFor(\App\Core\Support\Accent::DEFAULT) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — ABOS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">

    {{--
        ⛔ রিসেটের পাতা সার্চ ইঞ্জিনে যায় না।

        ⓘ ঠিকানায় টোকেন থাকে। কোনো ক্রলার ওটা তুলে নিলে বা কেউ লিংকটা
        শেয়ার করলে টোকেনসহ পাতাটা বাইরে চলে যেত — আর ঐ টোকেনই তো চাবি।
    --}}
    <meta name="robots" content="noindex, nofollow">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-dvh bg-(--color-surface-app)">

    <div class="relative mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">

        {{-- ভাষা — কোণে, কারণ এটা পাতার কাজ নয়, পাতার সেটিং --}}
        <a href="?locale={{ app()->getLocale() === 'bn' ? 'en' : 'bn' }}"
           class="absolute end-4 top-6 flex min-h-(--spacing-touch) items-center rounded-(--radius-field)
                  border border-(--color-border) bg-(--color-surface-card) px-3 text-xs
                  text-(--color-ink-muted) transition-colors hover:text-(--color-ink)">
            <x-ui.icon name="globe" :size="14" class="me-1.5" />
            {{ app()->getLocale() === 'bn' ? 'English' : 'বাংলা' }}
        </a>

        <div class="mb-7 text-center">
            <a href="{{ route('login') }}"
               class="mx-auto block w-fit rounded-(--radius-field) transition-opacity hover:opacity-80
                      focus-visible:outline-2 focus-visible:outline-offset-4
                      focus-visible:outline-(--color-brand-600)">
                <img src="{{ asset('brand/abos-icon-transparent.png') }}"
                     alt="" aria-hidden="true"
                     width="512" height="456" class="mx-auto mb-4 h-14 w-auto">

                <img src="{{ asset('brand/abos-wordmark-transparent.png') }}"
                     alt="{{ __('core.brand.name') }}"
                     width="556" height="198" class="mx-auto h-9 w-auto">
            </a>

            {{-- সোনালি চুল-দাগ — শান্ত দরজার মতোই --}}
            <div class="mx-auto mt-5 h-px w-28"
                 style="background: linear-gradient(90deg,
                        transparent, var(--color-brand-gold), transparent);"></div>

            <h1 class="mt-5 text-base font-semibold text-(--color-ink)">{{ $title }}</h1>

            @if ($lead)
                <p class="mt-1.5 text-sm text-(--color-ink-muted)">{{ $lead }}</p>
            @endif
        </div>

        <div class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-6 shadow-sm">
            {{ $slot }}
        </div>

        {{-- ফেরার পথ — সবসময়, এমনকি সফল হওয়ার পরেও।

             ⚠️ যিনি ভুল করে এই পাতায় এসেছেন তাঁর জন্য এটাই একমাত্র
             বেরোনোর দরজা; না থাকলে তিনি ব্রাউজারের back বোতাম খুঁজতেন। --}}
        <p class="mt-6 text-center text-sm">
            <a href="{{ route('login') }}"
               class="text-(--color-brand-600) underline-offset-4 hover:underline">
                {{ __('auth.back_to_sign_in') }}
            </a>
        </p>

        <footer class="mt-10 text-center text-xs text-(--color-ink-muted)">
            <p>{{ __('core.brand.developed_by') }}</p>
            <p class="mt-1">{{ __('core.brand.name') }} · v{{ config('app.version', '0.1.0') }}</p>
        </footer>
    </div>
</body>
</html>
