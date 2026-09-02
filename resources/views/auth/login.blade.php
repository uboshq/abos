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
{{--
    ABOS-এর নিজের রংটা এখানেই বসে — ২ সেপ্টেম্বর ২০২৬।

    ── কী ভাঙা ছিল ──────────────────────────────────────────────────
    ভেতরের পর্দাগুলো `app.blade.php`-এ [[Accent::styleFor]] বসায়, কিন্তু
    লগইনের নিজের `<html>` আলাদা — সে কিছুই বসাত না। ফলে রংগুলো আসত
    `tokens.css`-এর ফলব্যাক থেকে, আর সেগুলো **হার্ডকোড করা নীল**।

    ফল: ABOS-এর নিজের লোগোর ঠিক পাশে একটা সাধারণ নীল বোতাম — আর এটাই
    সেই পর্দা যেটা গ্রাহক সবার আগে দেখেন।

    ── কেন `Accent::DEFAULT`, হাতে লেখা রং নয় ───────────────────────
    এখানে `#087F91` লিখে দিলে ব্র্যান্ড বদলানোর দিন **দুই জায়গায়**
    বদলাতে হত, আর একটা বাদ পড়লে কেউ টের পেত না। ডিফল্টটা পড়লে
    উৎস একটাই থাকে।
--}}
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
                    {{--
                        লকআপটা তার **নিজের রঙে**, সাদা করা রূপে নয়।

                        ── কেন এখানে টিয়াটাই চলে ───────────────────────
                        প্যানেলের জমিন এখন ব্র্যান্ডের গাঢ় টিয়া
                        (#06323C), আর লোগোর উজ্জ্বল টিয়া (#08B8C8) তার
                        উপরে ৬.৫:১ — পড়তে কোনো অসুবিধা নেই।

                        আগে এখানে সাদা রূপটা ছিল, কারণ জমিনটা ছিল গাঢ়
                        **নীল** — সেখানে টিয়া লোগো নিজের রং হারাত। জমিন
                        বদলে যাওয়ায় কারণটাও আর নেই, আর মালিকের চাওয়াও
                        তা-ই: লোগো তার আসল রঙে।

                        সাদা রূপটা মুছে ফেলা হয়নি — সাইডবারের গাঢ়
                        প্যানেলে ওটাই এখনো লাগে।
                    --}}
                    <img src="{{ asset('brand/abos-lockup.png') }}"
                         alt="{{ __('core.brand.full_name') }}"
                         width="806" height="258" class="mb-5 w-64 max-w-full">

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

                    {{--
                        এই দরজায় ঘর নেই, একটাই বোতাম।

                        ── কেন ফর্মটা সরানো হলো (২ সেপ্টেম্বর ২০২৬) ──────
                        এই পাতাটার কাজ **পরিচয় করানো**, কাজ করানো নয়।
                        যিনি ABOS প্রথমবার দেখছেন, তাঁকে একই সাথে
                        "এটা কী" আর "ঢোকো" — দুইটা বলা মানে দুইটাই
                        আধাআধি বলা।

                        ফর্মটা মুছে যায়নি, সরেছে: শান্ত দরজায়
                        ([[auth.signin]]) সেটাই একমাত্র জিনিস, আর
                        ফাইলটাও একটাই ([[auth._form]])।

                        NEXUS-এর `/welcome` ঠিক এভাবেই কাজ করে, আর
                        মালিক সেটা দেখে ABOS-এও চেয়েছেন।
                    --}}
                    <h2 class="text-xl font-semibold text-(--color-ink)">{{ __('core.brand.name') }}</h2>

                    <p class="mt-1 text-sm text-(--color-ink-muted)">
                        {{ __('core.brand.full_name') }}
                    </p>

                    <p class="mt-3 text-sm text-(--color-ink)">
                        {{ __('core.brand.tagline') }}
                    </p>

                    <a href="{{ route('login.calm') }}"
                       class="mt-6 flex h-(--spacing-field) w-full items-center justify-center gap-2
                              rounded-(--radius-field) bg-(--color-brand-600) font-medium
                              text-(--color-brand-ink) transition-colors hover:bg-(--color-brand-700)">
                        {{ __('auth.sign_in') }}
                        <span aria-hidden="true">→</span>
                    </a>

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
