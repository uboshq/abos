{{--
    লগইন — প্ল্যান সেকশন ১৬।

    ডেস্কটপে ৬০/৪০, ট্যাবলেটে ৫০/৫০, মোবাইলে এক কলাম — লগইন আগে,
    ব্র্যান্ডিং সংক্ষিপ্ত (সেকশন ১৬.৭)।
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.sign_in') }} — ABOS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-dvh bg-(--color-surface-app)">
    <div class="flex min-h-dvh flex-col">

        {{-- হেডার — সেকশন ১৬.২ --}}
        <header class="flex h-(--spacing-header) shrink-0 items-center gap-4 border-b
                       border-(--color-border) bg-(--color-surface-card) px-4 md:px-6">
            <img src="{{ asset('brand/abos-wordmark.png') }}" alt="ABOS" class="h-6 w-auto">

            <div class="ms-auto flex items-center gap-3 text-sm">
                <a href="?locale={{ app()->getLocale() === 'bn' ? 'en' : 'bn' }}"
                   class="flex min-h-(--spacing-touch) items-center px-2 text-(--color-ink-muted)
                          transition-colors hover:text-(--color-ink)">
                    🌐 {{ app()->getLocale() === 'bn' ? 'English' : 'বাংলা' }}
                </a>

                <span class="hidden items-center gap-1.5 text-(--color-ink-muted) sm:flex">
                    <span class="size-2 rounded-full bg-(--color-success)" aria-hidden="true"></span>
                    {{ __('core.status_bar.operational') }}
                </span>
            </div>
        </header>

        <div class="flex flex-1 flex-col lg:flex-row">

            {{-- ব্র্যান্ডিং প্যানেল — মোবাইলে নিচে সরে যায় (লগইন আগে) --}}
            <section class="order-2 hidden flex-col justify-center bg-(--color-brand-900) px-8 py-12
                            text-(--color-ink-inverse) md:flex lg:order-1 lg:w-1/2 xl:w-3/5">
                <div class="mx-auto max-w-lg">
                    {{-- হালকা SVG ও CSS, ভারী অ্যানিমেশন নয় — লগইন পাতাই
                         প্রথম অভিজ্ঞতা, আর ডিপোর ধীর সংযোগে ভারী অ্যানিমেশন
                         মানে প্রথম ছাপটাই "স্লো" (সেকশন ১৬.১০)। --}}
                    <img src="{{ asset('brand/abos-icon-192.png') }}" alt=""
                         class="mb-6 size-20" aria-hidden="true">

                    <h1 class="text-2xl font-semibold">{{ __('core.brand.full_name') }}</h1>
                    <p class="mt-2 text-lg text-(--color-brand-gold)">{{ __('core.brand.tagline') }}</p>

                    <ul class="mt-8 grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                        @foreach ([
                            'multi_company', 'multi_branch', 'secure', 'fast',
                            'audit_trail', 'daily_backup', 'role_based', 'hyperlinked',
                        ] as $highlight)
                            <li class="flex items-center gap-2">
                                <span class="text-(--color-brand-gold)" aria-hidden="true">✔</span>
                                {{ __('auth.highlight.' . $highlight) }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>

            {{-- লগইন প্যানেল --}}
            <section class="order-1 flex flex-1 items-center justify-center px-4 py-10 lg:order-2 lg:w-1/2 xl:w-2/5">
                <div class="w-full max-w-(--spacing-login-card)">
                    <h2 class="text-xl font-semibold text-(--color-ink)">{{ __('auth.welcome_back') }}</h2>
                    <p class="mt-1 text-sm text-(--color-ink-muted)">{{ __('auth.sign_in_to_workspace') }}</p>

                    @if ($errors->any())
                        <div role="alert"
                             class="mt-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                    text-sm text-(--color-badge-danger-ink)">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    {{-- ডাবল ক্লিক ঠেকানো form-এর submit ইভেন্টে, বোতামে নয়।

                         আগে বোতামে :disabled="busy" ছিল, আর Alpine ক্লিকের
                         মুহূর্তেই সেটা প্রয়োগ করত — নিষ্ক্রিয় বোতাম ফর্ম সাবমিট
                         করে না, তাই লগইন কাজই করত না। ব্রাউজারে চালিয়ে দেখতে
                         গিয়ে ধরা পড়েছে; কোড পড়ে ধরা পড়ত না। --}}
                    <form method="POST" action="{{ route('login.store') }}"
                          x-data="{ busy: false }"
                          @submit="busy ? $event.preventDefault() : (busy = true)"
                          class="mt-6 space-y-4">
                        @csrf

                        <div>
                            <label for="identifier" class="mb-1 block text-sm font-medium text-(--color-ink)">
                                {{ __('auth.identifier') }}
                            </label>
                            {{-- autocomplete ছাড়া "Password Manager Support" কথাটা
                                 লেখা থাকলেও বাস্তবে কাজ করে না (সেকশন ১৬.৭) --}}
                            <input id="identifier" name="identifier" type="text"
                                   value="{{ old('identifier') }}"
                                   autocomplete="username" required autofocus
                                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                          border-(--color-border) bg-(--color-surface-card) px-3
                                          text-(--color-ink)">
                        </div>

                        <div x-data="{ show: false, caps: false }">
                            <label for="password" class="mb-1 block text-sm font-medium text-(--color-ink)">
                                {{ __('auth.password') }}
                            </label>
                            <div class="relative">
                                <input id="password" name="password"
                                       :type="show ? 'text' : 'password'"
                                       autocomplete="current-password" required
                                       @keyup="caps = $event.getModifierState && $event.getModifierState('CapsLock')"
                                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-3 pe-12
                                              text-(--color-ink)">
                                <button type="button" @click="show = !show"
                                        class="absolute inset-y-0 end-0 flex w-12 items-center justify-center
                                               text-(--color-ink-muted)"
                                        :aria-label="show ? '{{ __('auth.hide_password') }}' : '{{ __('auth.show_password') }}'">
                                    👁
                                </button>
                            </div>
                            <p x-show="caps" x-cloak
                               class="mt-1 text-xs text-(--color-badge-pending-ink)">
                                {{ __('auth.caps_lock_on') }}
                            </p>
                        </div>

                        <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                            <input type="checkbox" name="remember" value="1" class="size-4">
                            {{ __('auth.remember_device') }}
                        </label>

                        {{-- দ্বিতীয় ক্লিক pointer-events দিয়ে আটকানো, disabled
                             দিয়ে নয় — নাহলে প্রথম ক্লিকটাও হারিয়ে যায়
                             (সেকশন ১৬.৩)। --}}
                        <button type="submit"
                                :aria-busy="busy"
                                :class="busy && 'pointer-events-none opacity-70'"
                                class="h-(--spacing-field) w-full rounded-(--radius-field)
                                       bg-(--color-brand-500) font-medium text-(--color-ink-inverse)
                                       transition-colors hover:bg-(--color-brand-600)">
                            <span x-show="!busy">{{ __('auth.sign_in') }}</span>
                            <span x-show="busy" x-cloak>{{ __('auth.authenticating') }}</span>
                        </button>
                    </form>

                    {{-- Passkey / Microsoft / Google — V1-এ দেখানো হয় না
                         (সেকশন ১৬.৪): অকার্যকর বোতাম ব্যবহারকারীকে হতাশ করে। --}}

                    <div class="mt-8 border-t border-(--color-border) pt-4 text-center text-2xs
                                text-(--color-ink-muted)">
                        <p>{{ __('core.brand.developed_by') }} · {{ __('core.brand.powered_by') }}</p>
                        <p class="mt-1">v{{ config('app.version', '0.1.0') }}</p>
                    </div>
                </div>
            </section>
        </div>
    </div>
</body>
</html>
