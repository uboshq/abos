{{--
    প্রথম দরজা — `/setup`।

    ── কেন এই পাতাটার নিজের `<html>` ─────────────────────────────────────
    অ্যাপের লেআউটে সাইডবার, কোম্পানি-সুইচার আর ব্যবহারকারীর মেনু আছে —
    তিনটাই এমন জিনিস যা এই মুহূর্তে **অস্তিত্বহীন**। ওই লেআউট ধার করলে
    পাতাটা লোড হওয়ার আগেই ভেঙে পড়ত।

    তাই গঠনটা `auth/signin` থেকে নেওয়া: একই জমিন, একই ব্র্যান্ড, একই
    সোনালি দাগ — কারণ এটাও একটা দরজা, শুধু জীবনে একবার খোলে।

    ── কেন কার্ডটা চওড়া (max-w-2xl, signin-এ max-w-md) ───────────────────
    signin-এ ঘর দুইটা; এখানে ছয়টা, আর প্রতিটার পাশে **কেন** লেখা আছে।
    সরু কলামে ওই ব্যাখ্যাগুলো লম্বা ফিতার মতো দেখাত আর মানুষ পড়তেন না —
    অথচ এই পাতায় ব্যাখ্যাটাই আসল কাজ, কারণ পাঠকের ফোন করার কেউ নেই।
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light"
      style="{{ \App\Core\Support\Accent::styleFor(\App\Core\Support\Accent::DEFAULT) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('system_admin::setup.title') }} — ABOS</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-dvh bg-(--color-surface-app)">

    <div class="relative mx-auto flex min-h-dvh w-full max-w-2xl flex-col justify-center px-4 py-10">

        {{-- ভাষা — কোণে, কারণ এটা পাতার কাজ নয়, পাতার সেটিং।

             ⚠️ এই পাতায় এটা signin-এর চেয়েও জরুরি: যিনি নিজের সার্ভারে
             বসিয়েছেন তিনি হয়তো ইংরেজিতে পড়েন, আর এটাই তাঁর দেখা ABOS-এর
             **প্রথম** পর্দা। এখানে ভাষা বদলাতে না পারলে তিনি পুরো
             সেটআপটাই না-বোঝা ভাষায় করতেন। --}}
        <a href="?locale={{ app()->getLocale() === 'bn' ? 'en' : 'bn' }}"
           class="absolute end-4 top-6 flex min-h-(--spacing-touch) items-center rounded-(--radius-field)
                  border border-(--color-border) bg-(--color-surface-card) px-3 text-xs
                  text-(--color-ink-muted) transition-colors hover:text-(--color-ink)">
            <x-ui.icon name="globe" :size="14" class="me-1.5" />
            {{ app()->getLocale() === 'bn' ? 'English' : 'বাংলা' }}
        </a>

        {{-- ব্র্যান্ড — চিহ্ন, শব্দ, তারপর একটা দাগ।

             ⓘ লোগোটা এখানে লিংক নয়, signin-এ যেমন। যাওয়ার জায়গা নেই:
             লগইনের পাতায় পাঠালে সেখান থেকে ঢোকার কোনো অ্যাকাউন্টই নেই। --}}
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

            <h1 class="mt-5 text-base font-semibold text-(--color-ink)">
                {{ __('system_admin::setup.title') }}
            </h1>

            <p class="mx-auto mt-2 max-w-lg text-sm text-(--color-ink-muted)">
                {{ __('system_admin::setup.lead') }}
            </p>
        </div>

        <main>
            <div class="rounded-2xl border border-(--color-border) bg-(--color-surface-card)
                        px-5 py-6 shadow-sm sm:px-6">

                @if ($errors->any())
                    <div role="alert"
                         class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2
                                text-sm text-(--color-badge-danger-ink)">
                        {{ $errors->first() }}
                    </div>
                @endif

                {{-- ডাবল ক্লিক ঠেকানো form-এর submit ইভেন্টে, বোতামে নয়।

                     বোতামে `:disabled` বসালে Alpine ক্লিকের মুহূর্তেই সেটা
                     প্রয়োগ করে, আর নিষ্ক্রিয় বোতাম ফর্ম সাবমিটই করে না —
                     লগইনের পাতায় ঠিক এটাই ঘটেছিল, আর ধরা পড়েছিল ব্রাউজারে
                     চালিয়ে, কোড পড়ে নয়।

                     ⚠️ এখানে ডাবল-সাবমিট ঠেকানোটা signin-এর চেয়ে বেশি
                     জরুরি: দুইটা অনুরোধ একসাথে গেলে দুইজন প্রথম মালিক
                     হওয়ার চেষ্টা করত। সেটা অবশ্য ডাটাবেসই থামায়
                     ([[FirstRun::open]]) — এটা তার উপরের নরম স্তর, একমাত্র
                     স্তর নয়। --}}
                <form method="POST" action="{{ route('system_admin.setup.store') }}"
                      x-data="{ busy: false }"
                      @submit="busy ? $event.preventDefault() : (busy = true)"
                      class="space-y-7">
                    @csrf

                    {{-- ── আপনি ─────────────────────────────────────── --}}
                    <section class="space-y-4">
                        <div>
                            <h2 class="text-sm font-semibold text-(--color-ink)">
                                {{ __('system_admin::setup.you') }}
                            </h2>
                            <p class="mt-1 text-xs text-(--color-ink-muted)">
                                {{ __('system_admin::setup.you_note') }}
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="name" class="mb-1 block text-sm font-medium text-(--color-ink)">
                                    {{ __('system_admin::setup.name') }}
                                </label>
                                <input id="name" name="name" type="text" value="{{ old('name') }}"
                                       autocomplete="name" required autofocus
                                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-3
                                              text-(--color-ink)">
                            </div>

                            <div>
                                <label for="email" class="mb-1 block text-sm font-medium text-(--color-ink)">
                                    {{ __('system_admin::setup.email') }}
                                </label>
                                <input id="email" name="email" type="email" value="{{ old('email') }}"
                                       autocomplete="username" required
                                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-3
                                              text-(--color-ink)">
                                <p class="mt-1 text-xs text-(--color-ink-muted)">
                                    {{ __('system_admin::setup.email_note') }}
                                </p>
                            </div>

                            <div>
                                <label for="password" class="mb-1 block text-sm font-medium text-(--color-ink)">
                                    {{ __('system_admin::setup.password') }}
                                </label>
                                {{-- autocomplete ছাড়া পাসওয়ার্ড ম্যানেজার নতুন
                                     পাসওয়ার্ডটা মনে রাখে না — আর এটা এমন একটা
                                     অ্যাকাউন্ট যেটা ভুলে গেলে ফেরার পথ নেই,
                                     কারণ রিসেট করে দেওয়ার মতো কোনো প্রশাসকও নেই। --}}
                                <input id="password" name="password" type="password"
                                       autocomplete="new-password" required
                                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-3
                                              text-(--color-ink)">
                                <p class="mt-1 text-xs text-(--color-ink-muted)">
                                    {{ __('system_admin::setup.password_note') }}
                                </p>
                            </div>

                            <div>
                                <label for="password_confirmation"
                                       class="mb-1 block text-sm font-medium text-(--color-ink)">
                                    {{ __('system_admin::setup.password_confirmation') }}
                                </label>
                                <input id="password_confirmation" name="password_confirmation" type="password"
                                       autocomplete="new-password" required
                                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-3
                                              text-(--color-ink)">
                            </div>
                        </div>
                    </section>

                    <div class="h-px bg-(--color-border)"></div>

                    {{-- ── আপনার প্রতিষ্ঠান ──────────────────────────── --}}
                    <section class="space-y-4">
                        <div>
                            <h2 class="text-sm font-semibold text-(--color-ink)">
                                {{ __('system_admin::setup.company') }}
                            </h2>
                            <p class="mt-1 text-xs text-(--color-ink-muted)">
                                {{ __('system_admin::setup.company_note') }}
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="company_name" class="mb-1 block text-sm font-medium text-(--color-ink)">
                                    {{ __('system_admin::setup.company_name') }}
                                </label>
                                <input id="company_name" name="company_name" type="text"
                                       value="{{ old('company_name') }}"
                                       autocomplete="organization" required
                                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-3
                                              text-(--color-ink)">
                                {{-- ⚠️ কারণটা ভুল হওয়ার **আগে** লেখা।

                                     নামটা থেকে কোড বসে, আর ওই কোড প্রতিটা
                                     ডকুমেন্টের নম্বরে ও ছাপায় যায় — সব
                                     জায়গায় ইংরেজি অক্ষরে। পুরো বাংলা নাম
                                     দিলে `CodeFromName` **খালি স্ট্রিং**
                                     ফেরত দেয়, আর কোথাও কিছু ভাঙে না।

                                     বার্তাটা জমা দেওয়ার পরও আসে, কিন্তু
                                     ততক্ষণে মানুষ নামটা লিখে ফেলেছেন। --}}
                                <p class="mt-1 text-xs text-(--color-ink-muted)">
                                    {{ __('system_admin::setup.latin_note') }}
                                </p>
                            </div>

                            <div>
                                <label for="branch_name" class="mb-1 block text-sm font-medium text-(--color-ink)">
                                    {{ __('system_admin::setup.branch_name') }}
                                </label>
                                <input id="branch_name" name="branch_name" type="text"
                                       value="{{ old('branch_name', __('system_admin::setup.branch_default')) }}"
                                       required
                                       class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                              border-(--color-border) bg-(--color-surface-card) px-3
                                              text-(--color-ink)">
                                <p class="mt-1 text-xs text-(--color-ink-muted)">
                                    {{ __('system_admin::setup.branch_note') }}
                                </p>
                            </div>
                        </div>

                        {{-- অর্থবছর ঘর হিসেবে নেই, ইচ্ছাকৃতভাবে।

                             বাংলাদেশে ওটা জুলাই–জুন, ব্যতিক্রম নেই, আর
                             `CompanyProvisioner::currentBangladeshiYear()`
                             তারিখ দুইটা নিজেই বের করে। ঘর দিলে কেউ ক্যালেন্ডার
                             বছর বসাতেন, আর তখন **প্রতিটা রিপোর্ট কর বিভাগের
                             সাথে অমিল** হত — একটা ভুল যেটা ধরা পড়ে বছর শেষে।
                             পরে বদলানোর পর্দা আছে। --}}
                        <p class="flex items-start gap-2 rounded-(--radius-field)
                                  bg-(--color-surface-muted) px-3 py-2 text-xs text-(--color-ink-muted)">
                            <x-ui.icon name="help" :size="14" class="mt-px shrink-0" />
                            <span>{{ __('system_admin::setup.year_note') }}</span>
                        </p>
                    </section>

                    <button type="submit"
                            class="h-(--spacing-field) w-full rounded-(--radius-field)
                                   bg-(--color-brand-600) px-4 text-sm font-medium text-white
                                   transition-opacity hover:opacity-90">
                        {{ __('system_admin::setup.submit') }}
                    </button>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
