{{--
    শান্ত দরজা — `/signin`।

    ── কেন দুইটা দরজা ───────────────────────────────────────────────────
    `/login` পূর্ণ ব্র্যান্ড প্যানেলসহ — যিনি ABOS প্রথমবার দেখছেন, তাঁর
    জন্য। এই পাতাটা তার উল্টো: **যিনি রোজ ঢোকেন**, তাঁর কাছে আটটা
    বৈশিষ্ট্যের তালিকা পঞ্চাশতম দিনে আর কোনো খবর নয় — কেবল দুইটা ঘর আর
    একটা বোতামের মাঝে দাঁড়ানো একটা দেয়াল।

    NEXUS-এ এই ভাগটা আগে থেকেই আছে (`/welcome` আর `/login`), আর সেখানে
    কাজ করতে দেখে মালিক ABOS-এও চেয়েছেন।

    ── ফর্মটা এখানে লেখা নেই ────────────────────────────────────────────
    দুইটা দরজার একটাই ফর্ম — [[auth._form]]। কপি করে রাখলে একদিন দুইটা
    আলাদা হয়ে যেত, আর ভুলটা ধরা পড়ত যে দরজাটা কম ব্যবহার হয় সেখানে।

    ── কোম্পানির ঘর নেই, আর সেটা ইচ্ছাকৃত ───────────────────────────────
    NEXUS-এর পাতায় "কোম্পানি আইডি" নামে একটা ঘর আছে; ABOS-এ নেই, আর
    কারণটা [[LoginController]]-এ লেখা: কোম্পানির নাম চাওয়া মানে **সার্ভারে
    কোন কোন প্রতিষ্ঠান আছে তা বাইরে বলে দেওয়া**। এখানে মানুষ শুধু নিজের
    পরিচয় দেন; কোম্পানি ঠিক হয় লগইনের পরে, তাঁর নিজের রেকর্ড থেকে।
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light"
      style="{{ \App\Core\Support\Accent::styleFor(\App\Core\Support\Accent::DEFAULT) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.sign_in') }} — ABOS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

{{--
    জমিন হালকা, আলপনা নেই।

    ── কেন এখানে সোনা প্রায় নেই ─────────────────────────────────────────
    আলপনা আর সোনা ABOS-এর অলংকার, আর তার জায়গা `/login`। এই পাতাটার
    পুরো কারণই "কম" — নকশা সরিয়ে দিলে যেটুকু থাকে সেটাই কাজ। তবু
    একটামাত্র সোনালি চুল-দাগ রাখা হয়েছে লোগোর নিচে: ওটুকু না থাকলে
    পাতাটা যেকোনো লগইন পাতার মতো দেখাত, ABOS-এর মতো নয়।
--}}
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

        {{-- ব্র্যান্ড — চিহ্ন, শব্দ, তারপর একটা দাগ --}}
        <div class="mb-7 text-center">
            <img src="{{ asset('brand/abos-icon-transparent.png') }}"
                 alt="" aria-hidden="true"
                 width="512" height="456" class="mx-auto mb-4 h-14 w-auto">

            <img src="{{ asset('brand/abos-wordmark-transparent.png') }}"
                 alt="{{ __('core.brand.name') }}"
                 width="556" height="198" class="mx-auto h-9 w-auto">

            {{-- সোনালি চুল-দাগ — মাঝ থেকে দুই দিকে মিলিয়ে যায় --}}
            <div class="mx-auto mt-5 h-px w-28"
                 style="background: linear-gradient(90deg,
                        transparent, var(--color-brand-gold), transparent);"></div>

            @php
                /*
                 * সম্ভাষণটা সার্ভারে ঠিক হয়, ব্রাউজারে নয়।
                 *
                 * ব্রাউজারের ঘড়ি ব্যবহারকারীর নিজের, আর সেটা যেকোনো কিছু
                 * হতে পারে। অ্যাপটা ঢাকার ঘড়িতে চলে (Asia/Dhaka), আর
                 * বাকি সব তারিখও সেই ঘড়িতেই — সম্ভাষণ আলাদা হলে একই
                 * পাতায় দুইটা সময় থাকত।
                 */
                $hour = (int) now()->format('G');
                $part = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');
                $greeting = __('auth.greeting.' . $part);
                $words = explode(' ', $greeting, 2);
            @endphp

            <p class="mt-5 text-base font-semibold text-(--color-ink)">
                {{ $words[0] }}
                @isset($words[1])
                    <span class="text-(--color-brand-600)">{{ $words[1] }}</span>
                @endisset
            </p>
        </div>

        {{-- কার্ড — পাতার আসল কাজ, তাই <main> --}}
        <main>
            <div class="rounded-2xl border border-(--color-border) bg-(--color-surface-card)
                        px-5 py-6 shadow-sm sm:px-6">

                {{--
                    সার্ভার চলছে কিনা — এখানেও।

                    `/login`-এ এটা আছে, আর কারণটা এখানেও একই: লগইন আটকে
                    গেলে দোষটা পাসওয়ার্ডের না সার্ভারের, সেটা না জানলে
                    মানুষ একই পাসওয়ার্ড পাঁচবার লেখেন।
                --}}
                <div class="mb-4 flex items-center gap-1.5 text-xs text-(--color-ink-muted)">
                    <span class="size-2 rounded-full bg-(--color-success)" aria-hidden="true"></span>
                    {{ __('core.status_bar.operational') }}
                </div>

                @include('auth._form')
            </div>
        </main>

        {{--
            পাদটীকা — কে বানাল, কার নামে, আর কোন সংস্করণ।

            `/login`-এ এগুলো বাঁ পাশের প্যানেলে; এখানে প্যানেল নেই, তাই
            নিচে। তিনটাই থাকতে হবে — সংস্করণটা সবচেয়ে বেশি কাজে লাগে
            যখন কেউ ফোন থেকে সমস্যার কথা বলেন।
        --}}
        <footer class="mt-7 text-center text-2xs text-(--color-ink-muted)">
            <div class="flex items-center justify-center gap-2">
                <span>{{ __('core.brand.powered_by') }}</span>

                <img src="{{ asset('brand/univer-mark.png') }}" alt="" aria-hidden="true"
                     class="size-6 shrink-0 object-contain">

                <span class="font-medium tracking-wide text-(--color-ink)">
                    {{ __('core.brand.powered_by_name') }}
                </span>
            </div>

            <p class="mt-1.5 text-(--color-brand-gold-deep)">
                {{ __('core.brand.powered_by_slogan') }}
            </p>

            <p class="mt-3">{{ __('core.brand.developed_by') }}</p>

            <p class="mt-1">{{ __('core.brand.name') }} · v{{ config('app.version', '0.1.0') }}</p>

            {{--
                পূর্ণ পাতায় ফেরার পথ।

                ── কেন এটা থাকতেই হবে ──────────────────────────────────
                একবার ঢোকার পর ব্রাউজারে চিহ্ন বসে যায়, আর `/login`
                তখন এখানেই ফেরত পাঠায়। এই লিংকটা না থাকলে **পূর্ণ
                পাতাটায় আর কোনোদিন পৌঁছানোই যেত না** — আর যে পাতা কেউ
                দেখে না, সেটা একদিন নীরবে ভেঙে পড়ে থাকে।

                `?full` ওখানকার ফেরত-পাঠানোটা এড়ায়।
            --}}
            <p class="mt-3">
                <a href="{{ route('login', ['full' => 1]) }}"
                   class="underline decoration-dotted underline-offset-2
                          transition-colors hover:text-(--color-ink)">
                    {{ __('auth.about_abos') }}
                </a>
            </p>
        </footer>
    </div>
</body>
</html>
