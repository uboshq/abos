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
{{--
    ⭐ মুক্তা-সাদা জমিন — মালিকের বাছাই, ১ অক্টোবর ২০২৬ ("প্রিমিয়াম ২", নমুনার পাতা থেকে)।
    ⓘ নরম টিয়া বৃত্ত আর ABOS-এর "A" চিহ্নের হালকা জলছাপ; সবই `aria-hidden`, ক্লিক ধরে না।
    ⚠️ রংগুলো inline — নতুন CSS বানানো ছাড়াই লাইভে বসে।
--}}
<body class="relative min-h-dvh overflow-x-hidden" style="background: #EEF5F7;">

    <div aria-hidden="true" class="pointer-events-none fixed inset-0 overflow-hidden">
        <div style="position: absolute; width: 640px; height: 640px; border-radius: 50%; left: -200px; top: -260px; opacity: .55;
                    background: radial-gradient(circle at 35% 35%, #BFF0F8, #8EDCEA 60%, #6CCBDD);"></div>
        <div style="position: absolute; width: 520px; height: 520px; border-radius: 50%; right: -170px; bottom: -230px; opacity: .7;
                    background: radial-gradient(circle at 40% 40%, #D3F4EC, #A7E3D6);"></div>
        <div style="position: absolute; width: 170px; height: 170px; border-radius: 50%; right: 18%; top: 9%; opacity: .8; background: #CFEFF5;"></div>
        <img src="{{ asset('brand/abos-icon-transparent.png') }}" alt=""
             style="position: absolute; width: 520px; max-width: 90vw; height: auto; left: 4%; bottom: -120px; opacity: .07; transform: rotate(-12deg);">
    </div>

    <div class="relative mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">

        {{-- ভাষা — কোণে, কারণ এটা পাতার কাজ নয়, পাতার সেটিং --}}
        <a href="?locale={{ app()->getLocale() === 'bn' ? 'en' : 'bn' }}"
           class="absolute end-4 top-6 flex min-h-(--spacing-touch) items-center rounded-(--radius-field)
                  border border-(--color-border) bg-(--color-surface-card) px-3 text-xs
                  text-(--color-ink-muted) transition-colors hover:text-(--color-ink)">
            <x-ui.icon name="globe" :size="14" class="me-1.5" />
            {{ app()->getLocale() === 'bn' ? 'English' : 'বাংলা' }}
        </a>

        {{-- ⭐ কাচের মতো সাদা কার্ড — লোগো, সম্ভাষণ আর ফর্ম এক কার্ডে (মুক্তা-সাদা নকশা) --}}
        <div class="px-6 py-8 sm:px-8"
             style="border-radius: 28px; border: 1px solid #E3EDF0; background: rgba(255,255,255,.86);
                    box-shadow: 0 40px 90px rgba(11,60,72,.12); backdrop-filter: blur(6px);">

        {{-- ব্র্যান্ড — পুরো লকআপ, তারপর সম্ভাষণ --}}
        <div class="mb-6 text-center">
            {{--
                লোগোটাই পূর্ণ পাতায় ফেরার পথ।

                ── কেন পথটা থাকতেই হবে ──────────────────────────────
                একবার ঢোকার পর ব্রাউজারে চিহ্ন বসে যায়, আর `/login`
                তখন এখানেই ফেরত পাঠায়। কোনো পথ না থাকলে **পূর্ণ
                পাতাটায় আর কোনোদিন পৌঁছানোই যেত না** — আর যে পাতা কেউ
                দেখে না, সেটা একদিন নীরবে ভেঙে পড়ে থাকে।

                `?full` ওখানকার ফেরত-পাঠানোটা এড়ায়।

                ── কেন লেখাটা সরল (২ সেপ্টেম্বর ২০২৬) ───────────────
                আগে পাদটীকার সবচেয়ে নিচে "ABOS সম্পর্কে জানুন" লেখা
                একটা লিংক ছিল। মালিক ওটা বাদ দিতে বলেছেন — কিন্তু
                **আপত্তিটা ছিল লেখাটার, দরজাটার নয়**: শান্ত পাতাটার
                পুরো কারণই "কম", আর ওই বাক্যটা নিজের অস্তিত্ব ব্যাখ্যা
                করে বেড়াচ্ছিল।

                লোগো নিজেই সেই কাজটা করে, একটা শব্দও না লিখে। আর
                লোগোয় চাপ দিলে ঘরের পরিচয়ে ফেরা — মানুষ এমনিতেই আশা
                করেন।

                ── কেন wordmark-এর `alt` লিংকের নাম ─────────────────
                চিহ্নটা `aria-hidden`, তাই পর্দা-পাঠক লিংকটার নাম পায়
                wordmark-এর `alt` থেকে — "ABOS"। `title` তার সাথে
                যোগ করে **কোথায় নিয়ে যাবে**, যেটা শুধু নাম বলে না।
            --}}
            <a href="{{ route('login', ['full' => 1]) }}"
               title="{{ __('auth.about_abos') }}"
               class="mx-auto block w-fit rounded-(--radius-field) transition-opacity hover:opacity-80
                      focus-visible:outline-2 focus-visible:outline-offset-4
                      focus-visible:outline-(--color-brand-600)">
                <img src="{{ asset('brand/abos-logo-lockup-transparent.png') }}"
                     alt="{{ __('core.brand.name') }}"
                     width="748" height="239" class="mx-auto h-11 w-auto">
            </a>

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

            <p class="mt-5 font-semibold" style="font-size: 30px; line-height: 1.2; color: #0B2A31;">
                {{ $greeting }}
            </p>
            <p class="mt-1 text-sm" style="color: #5B7B83;">{{ __('auth.awaits_you') }}</p>
        </div>

        {{-- ফর্ম — পাতার আসল কাজ, তাই <main> --}}
        <main>
            <div>

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
        </div>

        {{--
            পাদটীকা — কে বানাল, কার নামে, আর কোন সংস্করণ।

            `/login`-এ এগুলো বাঁ পাশের প্যানেলে; এখানে প্যানেল নেই, তাই
            নিচে। তিনটাই থাকতে হবে — সংস্করণটা সবচেয়ে বেশি কাজে লাগে
            যখন কেউ ফোন থেকে সমস্যার কথা বলেন।
        --}}
        {{--
            পাদটীকা এখন একটা তলের উপর, কার্ডের সমান চওড়া।

            ── কেন বাক্স (২ সেপ্টেম্বর ২০২৬) ────────────────────────────
            আগে লেখাগুলো খোলা জমিনে ভাসত, আর কার্ডের নিচে চার লাইন ধূসর
            লেখা **পাতা শেষ হওয়ার পর ফেলে রাখা কিছু** মনে হত। একই
            প্রস্থের একটা তল দিলে ওগুলো নকশার অংশ হয়ে যায় — কার্ড আর
            পাদটীকা একই কলামে দাঁড়ায়।

            তলটা `surface-card` নয়, `surface-muted` — নাহলে দুইটা সাদা
            বাক্স পাশাপাশি বসত আর চোখ ঠিক করতে পারত না **কোনটায় কাজ**।
        --}}
        <footer class="mt-7">
            <div class="rounded-2xl border border-(--color-border) bg-(--color-surface-muted)
                        px-5 py-4 text-center text-2xs text-(--color-ink-muted)">

                {{--
                    UNIVER-এর লকআপ — চিহ্ন বাঁয়ে, নাম আর স্লোগান ডানে।

                    ── কেন গঠনটা বদলাল ──────────────────────────────
                    আগে তিনটা জিনিস এক সারিতে ছিল আর স্লোগানটা পুরো
                    সারির নিচে মাঝখানে — অর্থাৎ স্লোগানটা "Powered by"
                    কথাটারও নিচে পড়ত, যেন ওটা ABOS-এর স্লোগান।
                    **কিন্তু "Empowering Tomorrow" UNIVER-এর কথা**, তাই
                    ওটা UNIVER-এর নামের নিচেই বসতে হবে, আর নামের বাঁ
                    কিনারায় মিলে — মালিকের পাঠানো ছবিটা ঠিক তাই।

                    `items-center` চিহ্নটাকে দুই লাইনের ব্লকের মাঝ
                    বরাবর রাখে; `text-start` ব্লকটাকে বাঁয়ে মেলায়,
                    বাইরের `text-center` থাকা সত্ত্বেও।
                --}}
                <div class="flex items-center justify-center gap-2">
                    <span>{{ __('core.brand.powered_by') }}</span>

                    {{-- aria-hidden — নামটা পাশেই লেখা, পর্দা-পাঠক
                         যেন একই কথা দুবার না বলে --}}
                    <img src="{{ asset('brand/univer-mark.png') }}" alt="" aria-hidden="true"
                         class="size-8 shrink-0 object-contain">

                    <span class="text-start leading-tight">
                        <span class="block font-medium tracking-wide text-(--color-ink)">
                            {{ __('core.brand.powered_by_name') }}
                        </span>

                        <span class="block text-(--color-brand-gold-deep)">
                            {{ __('core.brand.powered_by_slogan') }}
                        </span>
                    </span>
                </div>

                {{-- হালকা ভাগরেখা — উপরে কে বানাল, নিচে কী চলছে --}}
                <div class="mx-auto my-3 h-px w-16 bg-(--color-border)"></div>

                <p>{{ __('core.brand.developed_by') }}</p>

                <p class="mt-1">{{ __('core.brand.name') }} · v{{ config('app.version', '0.1.0') }}</p>
            </div>
        </footer>
    </div>
</body>
</html>
