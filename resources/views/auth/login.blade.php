{{--
    লগইন — প্ল্যান সেকশন ১৬।

    ── কেন পাতাজোড়া নয়, মাঝখানে একটা কার্ড ─────────────────────────────
    আগে দুইটা প্যানেল পুরো পর্দা জুড়ে ছড়ানো ছিল। বড় মনিটরে সেটা এক
    হাত ফাঁকা নীল আর এক হাত ফাঁকা সাদা হয়ে দাঁড়াত, আর চোখ কোথায়
    দাঁড়াবে বোঝা যেত না। কার্ডটা ছোট রাখলে দুইটা ঘর আর একটা বোতাম —
    যে কাজে এসেছেন, সেটাই সামনে।

    ── বাইরের জমিনটা আলপনা ──────────────────────────────────────────────
    খালি জায়গা সাদা রাখলে কার্ডটা ভেসে থাকত। আলপনা ওই জায়গাটাকে
    জমিন করে দেয় — আর সোনা এখানেই চলে: লগইন, ছাপা আর খালি পর্দা।
    কাজের কোনো টেবিল বা ফর্মে এই নকশা যায় না।
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

{{--
    জমিনের রঙটা ব্র্যান্ডের গাঢ় নীল, তার উপরে আলপনার টালি।

    background-image আগে, রঙ পরে — নকশাটা রঙের উপরে বসে। ছবিটা না
    এলেও (ধীর সংযোগ, ব্লক করা) রঙটা থাকে, তাই পাতাটা কখনো সাদা
    ঝলসানো অবস্থায় দেখা যায় না।
--}}
<body class="min-h-dvh bg-(--color-brand-900) bg-[length:240px_240px] bg-repeat"
      style="background-image: url('{{ asset('brand/alpona.svg') }}')">

    <div class="flex min-h-dvh items-center justify-center p-4 sm:p-6">

        {{--
            কার্ডটাই পুরো পাতা — ভেতরে বাঁয়ে ব্র্যান্ডিং, ডানে ফর্ম।

            ── কার্ডটা <main> নয়, ফর্মটা ─────────────────────────────────
            পুরো কার্ডে <main> বসানো হয়েছিল, আর তাতেই ব্র্যান্ডিংয়ের সাদা
            শিরোনামটা কালো হয়ে গাঢ় নীলে মিলিয়ে গিয়েছিল — app.css-এ নিয়ম
            আছে `main h1 { color: ink }`, যাতে সাদা পাতায় শিরোনাম গাঢ়
            হয়। ওই নিয়মের পাশেই লেখা আছে এই ফাঁদটা আগেও একবার ধরা
            পড়েছিল, আর ঠিক একইভাবে: স্ক্রিনশটে।
        --}}
        <div class="flex w-full max-w-4xl flex-col overflow-hidden rounded-2xl
                    bg-(--color-surface-card) shadow-2xl ring-1 ring-black/10 lg:flex-row">

            {{-- ব্র্যান্ডিং প্যানেল — মোবাইলে নিচে সরে যায় (লগইন আগে) --}}
            <aside class="order-2 hidden flex-col justify-center bg-(--color-brand-900) px-8 py-10
                          text-(--color-ink-inverse) md:flex lg:order-1 lg:w-1/2">
                <div class="mx-auto w-full max-w-sm">
                    {{-- হালকা SVG ও CSS, ভারী অ্যানিমেশন নয় — লগইন পাতাই
                         প্রথম অভিজ্ঞতা, আর ডিপোর ধীর সংযোগে ভারী অ্যানিমেশন
                         মানে প্রথম ছাপটাই "স্লো" (সেকশন ১৬.১০)। --}}
                    {{--
                        পুরো লকআপ — আইকন নয়।

                        ── কেন ট্যাগলাইনের আলাদা লাইনটা আর নেই ──────────
                        লকআপের নিচেই "BUILT AROUND YOUR BUSINESS" লেখা
                        আছে। পাশে আবার একই কথা বসালে একই বাক্য দুইবার
                        পড়তে হত, আর নকশাটা এলোমেলো দেখাত।

                        ── কেন এই ছবিটা আলাদা ফাইল ─────────────────────
                        মূল ফাইলটার পেছনে গাঢ় জমিন বসানো ছিল, তাই সেটা
                        সরাসরি বসালে প্যানেলের উপর একটা কালো চতুর্ভুজ
                        দেখা যেত। উজ্জ্বলতা ধরে আলফা বসিয়ে জমিনটা স্বচ্ছ
                        করা হয়েছে, আর অক্ষরের চারপাশের আভাটা রাখা হয়েছে
                        — শক্ত করে কেটে ফেললে কিনারায় একটা রেখা থাকত।
                    --}}
                    <img src="{{ asset('brand/abos-lockup-dark.png') }}"
                         alt="{{ __('core.brand.full_name') }}"
                         width="720" height="263" class="mb-5 w-64 max-w-full">

                    <h1 class="text-lg font-semibold">{{ __('core.brand.full_name') }}</h1>

                    <ul class="mt-4 grid grid-cols-2 gap-x-5 gap-y-1.5 text-xs">
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

                    {{--
                        কে বানাল আর কার নামে — ব্র্যান্ডিং প্যানেলে, ফর্মের
                        নিচে নয়।

                        ── কেন সরানো হল ─────────────────────────────────
                        এটা ব্র্যান্ডিং, আর ব্র্যান্ডিংয়ের জায়গা এই পাশটাই।
                        ফর্মের নিচে থাকলে লগইন করতে আসা লোকটার চোখ শেষ
                        মুহূর্তে ওখানে যেত — যেখানে তার একটাই কাজ, বোতামে
                        চাপ দেওয়া।

                        ── কেন লাইনটা ভাঙা ছিল ──────────────────────────
                        লেখা ছিল "Developed by … · Powered by" — তারপর
                        কিছুই না। নামটা (powered_by_name) আর চিহ্নটা
                        দুইটাই বাদ পড়েছিল, তাই বাক্যটা মাঝপথে থেমে থাকত।
                        স্ট্যাটাসবারে তিনটাই ছিল, এখানে ছিল না।
                    --}}
                    <div class="mt-6 border-t border-white/15 pt-4 text-2xs text-white/70">
                        <p>{{ __('core.brand.developed_by') }}</p>

                        <div class="mt-2 flex items-center gap-2">
                            <span>{{ __('core.brand.powered_by') }}</span>

                            {{-- aria-hidden — নামটা পাশেই লেখা, পর্দা-পাঠক
                                 যেন একই কথা দুবার না বলে --}}
                            <img src="{{ asset('brand/univer-mark.png') }}" alt="" aria-hidden="true"
                                 class="size-7 shrink-0 object-contain">

                            <span class="font-medium tracking-wide text-white">
                                {{ __('core.brand.powered_by_name') }}
                            </span>
                        </div>

                        <p class="mt-1 text-(--color-brand-gold)">
                            {{ __('core.brand.powered_by_slogan') }}
                        </p>

                        <p class="mt-3 text-white/50">v{{ config('app.version', '0.1.0') }}</p>
                    </div>
                </div>
            </aside>

            {{-- লগইন প্যানেল — পাতার আসল কাজ, তাই <main> এখানে --}}
            <main class="order-1 flex flex-1 items-center justify-center px-6 py-8 lg:order-2 lg:w-1/2">
                <div class="w-full max-w-(--spacing-login-card)">
                    {{--
                        ভাষা ও চালু-অবস্থা — পাতার হেডার নয়, কার্ডের ভেতরে।

                        পাতাজোড়া হেডারটা তুলে দেওয়া হয়েছে (কার্ডের বাইরে
                        এখন আলপনার জমিন), কিন্তু দুইটা কথা হারানো চলে না:
                        বাংলা/English বদলানোর পথ, আর সার্ভার চলছে কিনা।
                        দ্বিতীয়টা না থাকলে লগইন আটকে গেলে কেউ বলতে পারত
                        না দোষটা পাসওয়ার্ডের না সার্ভারের।
                    --}}
                    <div class="mb-5 flex items-center justify-between gap-3 text-xs">
                        <span class="flex items-center gap-1.5 text-(--color-ink-muted)">
                            <span class="size-2 rounded-full bg-(--color-success)" aria-hidden="true"></span>
                            {{ __('core.status_bar.operational') }}
                        </span>

                        <a href="?locale={{ app()->getLocale() === 'bn' ? 'en' : 'bn' }}"
                           class="flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2
                                  text-(--color-ink-muted) transition-colors hover:text-(--color-ink)">
                            <x-ui.icon name="globe" :size="15" class="me-1.5" />
                            {{ app()->getLocale() === 'bn' ? 'English' : 'বাংলা' }}
                        </a>
                    </div>

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
                                    {{-- দুইটা আলাদা আঁকা, একটা ঘুরিয়ে নয়: কাটা-চোখ
                                         মানে "এখন লুকানো", আর সেটা চোখের উপর একটা
                                         দাগ — ইমোজিতে ওই দ্বিতীয় অবস্থাটা ছিলই না,
                                         তাই বোতামটা চাপার পর কিছু বদলাত না। --}}
                                    <span x-show="! show"><x-ui.icon name="eye" :size="18" /></span>
                                    <span x-show="show" x-cloak><x-ui.icon name="eye_off" :size="18" /></span>
                                </button>
                            </div>
                            <p x-show="caps" x-cloak
                               class="mt-1 text-xs text-(--color-badge-pending-ink)">
                                {{ __('auth.caps_lock_on') }}
                            </p>
                        </div>

                        {{--
                            দ্বিতীয় ধাপের কোড — কেবল যখন চাওয়া হয়েছে।

                            ── কেন সবসময় দেখানো হয় না ──────────────────
                            বেশিরভাগ ব্যবহারকারীর MFA চালু নেই। ঘরটা
                            সবসময় থাকলে তাঁরা প্রতিবার ভাবতেন কিছু একটা
                            লিখতে হবে, আর খালি রেখে জমা দিয়ে ভুল বার্তা
                            পাওয়ার ভয় পেতেন।

                            ── কেন `autofocus` ────────────────────────
                            দ্বিতীয় ধাপে মানুষ ফোন হাতে নিয়ে দাঁড়ানো —
                            কার্সার ঘরটাতেই থাকা উচিত, নাহলে তিনি ছয়টা
                            অঙ্ক টাইপ করে দেখতেন কোথাও কিছু বসেনি।
                        --}}
                        @if (session('mfa') || $errors->has('code'))
                            <div>
                                <label for="code" class="mb-1 block text-sm font-medium text-(--color-ink)">
                                    {{ __('auth.code') }}
                                </label>

                                <input id="code" name="code" type="text"
                                       inputmode="numeric" autocomplete="one-time-code"
                                       autofocus
                                       class="num h-(--spacing-field) w-full rounded-(--radius-field)
                                              border border-(--color-border) bg-(--color-surface-app)
                                              px-3 text-center tracking-[0.3em]">

                                <p class="mt-1 text-xs text-(--color-ink-muted)">
                                    {{ __('auth.code_hint') }}
                                </p>

                                @error('code')
                                    <p class="mt-1 text-xs text-(--color-danger)">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif

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

                    {{--
                        কে বানাল, তার নাম এখানে আর নেই — বাঁ পাশের
                        ব্র্যান্ডিং প্যানেলে গেছে।

                        তবে সংস্করণটা থেকে যায়, আর ছোট পর্দাতেই সবচেয়ে
                        দরকারি: ওখানে বাঁ পাশের প্যানেলটা লুকানো থাকে
                        (md:flex), তাই "কোন সংস্করণ চলছে" প্রশ্নের উত্তর
                        আর কোথাও থাকত না — অথচ ফোন থেকে কেউ সমস্যার কথা
                        বললে ওটাই প্রথম প্রশ্ন।
                    --}}
                    <div class="mt-6 border-t border-(--color-border) pt-3 text-center text-2xs
                                text-(--color-ink-muted) md:hidden">
                        <p>{{ __('core.brand.developed_by') }}</p>
                        <p class="mt-1">
                            {{ __('core.brand.powered_by') }} {{ __('core.brand.powered_by_name') }}
                        </p>
                        <p class="mt-1">v{{ config('app.version', '0.1.0') }}</p>
                    </div>

                    {{-- বড় পর্দায় শুধু সংস্করণ — বাকিটা বাঁ পাশে দেখা যাচ্ছে --}}
                    <p class="mt-6 hidden border-t border-(--color-border) pt-3 text-center text-2xs
                              text-(--color-ink-muted) md:block">
                        v{{ config('app.version', '0.1.0') }}
                    </p>
                </div>
            </main>
        </div>
    </div>
</body>
</html>
