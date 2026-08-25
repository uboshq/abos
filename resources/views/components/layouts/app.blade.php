{{--
    Application Shell — প্ল্যান সেকশন ১৫.১।

    একটাই শেল, সব ডিভাইসে। কোন অংশ দেখা যাবে সেটা শুধু CSS ঠিক করে —
    Blade-এ @if($isMobile) জাতীয় কিছু নেই এবং থাকবে না (সেকশন ২০.৭), কারণ
    সার্ভার স্ক্রিনের মাপ জানে না আর জানার দরকারও নেই।
--}}
<!DOCTYPE html>
{{-- অ্যাকসেন্ট রংটা সার্ভারেই বসে, JavaScript-এ নয়। JS-এ করলে পাতাটা
     আগে ডিফল্ট রঙে আঁকা হত, তারপর বদলাত — প্রতিটা লোডে একবার ঝলকানি। --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-theme="{{ auth()->user()?->theme ?? 'light' }}"
      {{-- দশটা চেহারার যেটা বাছা, সেটা এখানে — একটাই জায়গা।

           পাতার কোনো অংশ জানে না সে কোন চেহারায় আছে, আর জানার কথাও
           নয়: যা বদলায় তা টোকেন, পাতার কোড নয়।

           attribute-টা থেকে যাচ্ছে যদিও টোকেনগুলো এখন ইনলাইনে আসে —
           কারণ কিছু নিয়ম রূপ ধরে বসে যা টোকেন নয় (খোলসের markup,
           রেলের আইটেমের গড়ন), আর পরীক্ষাগুলোও এটা ধরেই মেলায়। --}}
      data-ui="{{ \App\Core\Support\LookRegistry::lookFor(\App\Core\Support\LookPreview::orChosen(auth()->user()?->ui)) }}"

      {{--
          চলতি রূপের টোকেনগুলো — সার্ভারে বাছাই করে, কেবল একটার।

          ── কী বদলাল, আর কেন ────────────────────────────────────────
          আগে দশটা রূপের সব টোকেন স্টাইলশিটে যেত, আর ব্রাউজার
          `[data-ui]` দেখে একটা বেছে নিত। অর্থাৎ যিনি Navy চালান
          তিনিও Odoo, Fiori ও Linear-এর রং প্রতিবার নামাতেন।

          সার্ভার জানে মানুষটা কোন রূপে ও কোন থিমে আছেন, তাই বাছাইটা
          এখানেই হয় — নিচে যায় ষাটের মতো ঘোষণা, দশ সেটের বদলে একটা।

          ── আর একটা লড়াইও মিটল ──────────────────────────────────────
          CSS-এ রূপ ও গাঢ় থিমের মধ্যে নির্দিষ্টতার তিন স্তরের নিয়ম
          ছিল — (০,১,০) রূপ, (০,২,০) গাঢ়, (০,৩,০) রূপের নিজের গাঢ়।
          একটা ভুল করলে রং নীরবে অন্যটা হয়ে যেত। মিলিয়ে দেওয়াটা এখন
          সার্ভারে হয়, তাই লড়াইটাই আর নেই।
      --}}
      style="{{ \App\Core\Support\Accent::styleFor(auth()->user()?->accent ?? \App\Core\Support\Accent::DEFAULT) }}{{ \App\Core\Support\LookPreview::style(auth()->user()?->theme ?? 'light') ?? \App\Core\Support\LookRegistry::styleForUser(auth()->user()?->ui, auth()->user()?->theme ?? 'light') }}">
<head>
    <meta charset="utf-8">

    {{-- এই লাইনটা ছাড়া মোবাইল ব্রাউজার ৯৮০px চওড়া একটা কাল্পনিক ডেস্কটপ
         ধরে নেয় আর কোনো media query কাজ করে না (সেকশন ২০.১)। --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ isset($title) ? $title . ' — ABOS' : 'ABOS' }}</title>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{--
        মাউস পৌঁছালেই পাতাটা আনা শুরু — ক্লিকের অপেক্ষা নয়।

        ── কেন এটা লাগল ────────────────────────────────────────────────
        সার্ভার একটা পাতা ১৩০ মিলিসেকেন্ডে দেয়, সেটা ধীর নয়। কিন্তু
        ABOS-এর প্রতিটা ক্লিকে গোটা পাতা নতুন করে আসে, আর ওই ১৩০
        মিলিসেকেন্ড ফাঁকা পর্দা হিসেবে চোখে পড়ে। ব্যবহারকারী তুলনা করেন
        এমন সফটওয়্যারের সাথে যেখানে ক্লিকে শুধু ভেতরটা বদলায়।

        মেনুর উপর মাউস আসা আর ক্লিক পড়ার মাঝে গড়ে ২০০ মিলিসেকেন্ড থাকে।
        eagerness: moderate ঠিক ওই সময়টাতেই আনা শুরু করে, তাই ক্লিক পড়ার
        সময় পাতাটা ব্রাউজারের হাতেই থাকে।

        ── কোনগুলো বাদ, আর কেন ─────────────────────────────────────────
        prefetch মানে সার্ভারে সত্যিকারের একটা GET যায়। যে ঠিকানাগুলো
        শুধু দেখায় না, কিছু করে বা ভারী কাজ করে, সেগুলো মাউস ছুঁলেই
        চলে গেলে ভুল হত:

          • ছাপার পাতা — mPDF পুরো কাগজ বানায়, শুধু হাত সরে গেলেই নয়
          • সংযুক্তি ও storage — ফাইল নামানো শুরু হয়ে যেত
          • export=csv — ওটাও ফাইল, আর তালিকা পুরোটা টানে
          • login/logout — সেশনের সাথে খেলা চলে না

        নতুন কোনো লিংক যদি দেখানো ছাড়া আর কিছু করে, তাতে
        data-no-prefetch বসালেই সে বাদ পড়ে — নিচের শেষ নিয়মটা তা ধরে।
        prerender নয়, prefetch — prerender পাতার JavaScript-ও চালিয়ে
        ফেলত, আর তখন "দেখা" আর "করা"-র সীমারেখা ঝাপসা হয়ে যেত।

        যে ব্রাউজার এটা বোঝে না সে চুপচাপ উপেক্ষা করে; কিছুই ভাঙে না।
    --}}
    <script type="speculationrules">
    {
        "prefetch": [{
            "where": {
                "and": [
                    { "href_matches": "/*" },
                    { "not": { "href_matches": "/sales/print/*" } },
                    { "not": { "href_matches": "/hr/payslips/*" } },
                    { "not": { "href_matches": "/hr/payroll/*/payslips" } },
                    { "not": { "href_matches": "/hr/payroll/*/bank-file" } },
                    { "not": { "href_matches": "/attachments/*" } },
                    { "not": { "href_matches": "/storage/*" } },
                    { "not": { "href_matches": "/login" } },
                    { "not": { "href_matches": "/logout" } },
                    { "not": { "href_matches": { "pathname": "/*", "search": "*export=*" } } },
                    { "not": { "selector_matches": "[data-no-prefetch]" } },
                    { "not": { "selector_matches": "[download]" } },
                    { "not": { "selector_matches": "[target=_blank]" } }
                ]
            },
            "eagerness": "moderate"
        }]
    }
    </script>
</head>

<body class="flex min-h-dvh flex-col">
    {{-- মোবাইলে সাইডবার লুকানো থাকে; এই লিংকটা কি-বোর্ড ব্যবহারকারীকে
         সরাসরি কাজের জায়গায় নিয়ে যায় (সেকশন ১৫.২০)। --}}
    <a href="#main"
       class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50
              focus:rounded-(--radius-field) focus:bg-(--color-brand-500) focus:px-4 focus:py-2
              focus:text-(--color-ink-inverse)">
        {{ __('core.a11y.skip_to_content') }}
    </a>

    @php
        /*
         * মেনুটা বাঁয়ে না উপরে — চেহারা ঠিক করে।
         *
         * ── কেন এই একটা সিদ্ধান্ত PHP-তে, CSS-এ নয় ────────────────
         * বাকি সবটা টোকেন দিয়ে হয়: রং, ধার, ঘনত্ব, সারির উচ্চতা।
         * কিন্তু "মেনু কোথায় বসবে" CSS-এর কথা নয় — বাঁয়ের রেল আর
         * উপরের পটি দুইটা আলাদা markup।
         *
         * ── কেন এটা "পর্দা তার থিম জানে" নিয়মটা ভাঙে না ─────────────
         * এটা পর্দা নয়, **শেল**। শেলের কাজই খোলসটা ঠিক করা, আর
         * ভেতরের ২৫২টা পাতার একটাও এই লাইনটার কথা জানে না — ওরা
         * আগের মতোই কেবল `$slot`-এ বসে।
         */
        /*
         * খোলসটা কোন কোড-রূপের — স্কিন হলে তার গোড়ার রূপটা।
         *
         * `Ui::nav()`-কে সরাসরি `ui` ঘরটা দিলে একটা `public_id` পেয়ে
         * সে ডিফল্টে নামত, আর Odoo-র উপর দাঁড়ানো কোম্পানির রূপ
         * Odoo-র রং নিয়ে Navy-র খোলসে বসত।
         */
        $shellLook = \App\Core\Support\LookRegistry::lookFor(
            \App\Core\Support\LookPreview::orChosen(auth()->user()?->ui)
        );

        $navPlacement = \App\Core\Support\Ui::nav($shellLook);

        /*
         * এই পর্দার শিরোনাম ও বোতামগুলো কোথায় বসে।
         *
         * `bar` — পুরো-চওড়া একটা পটিতে, পাতার কলামের বাইরে (D365,
         * Fiori, NetSuite)। `inline` — পাতার ভেতরে, লেখার সাথে এক
         * প্রান্তে (Odoo, Redwood, আর ABOS-এর নিজের তিনটা)।
         *
         * ১০৪টা পর্দা আগের মতোই `header` স্লটে পাঠায়; কেবল সেটা
         * কোথায় আঁকা হবে তা এখানে ঠিক হয়।
         */
        $commandPlacement = \App\Core\Support\Ui::commands($shellLook);
    @endphp

    {{-- প্রিভিউয়ের পটি সবার আগে — Redwood-এর ইটরঙা পটিরও উপরে, কারণ
         "আপনি যা দেখছেন সেটা আসল নয়" কথাটা রূপের সাজসজ্জার চেয়ে বড়। --}}
    <x-shell.preview-bar />

    {{-- সবকিছুর উপরে — Redwood-এর ৪px ইটরঙা পটি। --}}
    <x-shell.chrome region="above-all" :menu="$menu ?? []" />

    <div class="flex min-h-dvh flex-1">
        @if ($navPlacement === 'rail')
            <x-shell.sidebar :menu="$menu ?? []" />
        @endif

        <div class="flex min-w-0 flex-1 flex-col">
            <x-shell.topbar :menu="$menu ?? []" />

            @if ($navPlacement === 'top')
                <x-shell.topnav :menu="$menu ?? []" />
            @endif

            {{-- পথের বার — টপবার পুরো অ্যাপের, এটা এই পর্দার। কেন দুইটা
                 আলাদা, সেটা কম্পোনেন্টের ভেতরে লেখা। --}}
            <x-shell.crumbbar :menu="$menu ?? []" />

            {{-- নিচের প্যাডিং যা যা ঢেকে রাখে তার সমষ্টির চেয়ে বেশি:
                 মোবাইলে bottom nav, বড় স্ক্রিনে স্থির স্ট্যাটাস বার। ঠিক
                 সমান দিলে শেষ বোতামটা বারের গা ঘেঁষে থাকে আর আঙুল দিয়ে
                 টিপতে গেলে ভুলে nav-এ চাপ পড়ে। --}}
            {{-- min-w-0 — flex আইটেমের ডিফল্ট min-width: auto মানে সে
                 নিজের কনটেন্টের চেয়ে সরু হতে রাজি নয়। ভেতরে একটা চওড়া
                 টেবিল থাকলে main ওই টেবিলের প্রস্থ নিয়ে নিত, আর পুরো
                 পাতাটাই আড়াআড়ি স্ক্রল করত — টেবিলের নিজের overflow-auto
                 তখন কিছুই করতে পারত না, কারণ সমস্যাটা তার উপরে।

                 হিসাবের ছকে ৩৭৫px-এ ৯১px উপচে পড়ছিল ঠিক এই কারণে।
                 টুলবারের সার্চ ঘরে একই সমস্যা আগেও ধরা পড়েছিল। --}}
            {{-- ছাপার মাথা — শুধু কাগজে।

                 পর্দায় কোম্পানি ও শাখা টপবারে দেখা যায়, তাই সেখানে
                 এটা লাগে না। কিন্তু কাগজে ওই টপবারটা যায় না, আর তখন
                 একটা বকেয়ার তালিকা হাতে নিয়ে বলা যায় না ওটা কোন
                 প্রতিষ্ঠানের, কোন শাখার, কবেকার — দুইটা ডিপো এক অফিসে
                 হলে কাগজদুটো আলাদা করার কোনো উপায় থাকে না।

                 তারিখটাও দরকার: "এই বকেয়া কবেকার" প্রশ্নের উত্তর কাগজে
                 না থাকলে এক সপ্তাহ পরে কাগজটা আর কাজে লাগে না। --}}
            @php($printCompany = auth()->user()?->currentCompany)

            @if ($printCompany)
                <div class="hidden print:block print:mb-4 print:border-b print:border-black print:pb-2">
                    <p class="print:text-base print:font-bold">{{ $printCompany->name() }}</p>

                    <p class="print:text-xs">
                        @if ($printBranch = auth()->user()?->currentBranch)
                            {{ $printBranch->name() }} ·
                        @endif
                        {{ __('core.print.printed_at') }}: {{ \App\Core\Support\DateFormat::formatWithTime(now()) }}
                    </p>
                </div>
            @endif

            {{-- রূপের নিজের পাতা-মাথা — NetSuite-এর নেভি শিরোনাম।
                 যে রূপের কিছু নেই তার জন্য কিছুই আঁকে না। --}}
            <x-shell.chrome region="page-head" :menu="$menu ?? []" />

            {{--
                কাজের পটি — কেবল যে চেহারাগুলো ওটা চায়।

                ── কেন এটা `<main>`-এর বাইরে ────────────────────────────
                ভেতরে রাখলে ওটা `max-w-(--spacing-content-max)` কলামের
                ভেতরেই বসত, আর তখন "পুরো-চওড়া" কথাটার কোনো মানে থাকত না।
                D365-এর কমান্ড বার পর্দার এক ধার থেকে আরেক ধার পর্যন্ত
                যায় — সেটাই তাকে chrome বানায়, কনটেন্ট নয়।

                ── আঠালো নয়, ইচ্ছাকৃতভাবে ───────────────────────────────
                উপরে ইতিমধ্যেই দুইটা আঠালো পটি (টপবার ও ব্রেডক্রাম্ব)।
                তৃতীয়টা আটকালে ছোট ল্যাপটপে প্রায় ১৬০px স্থায়ীভাবে চলে
                যেত, আর যে তালিকা দেখতে মানুষ এসেছেন সেটার জন্য থাকত
                বাকিটুকু। ঘন চেহারাগুলোই এই পটিটা চায় — ওদের ব্যবহারকারী
                সারি গুনছেন, তাই জায়গাটা তাঁদেরই বেশি দরকার।
            --}}
            @if ($commandPlacement === 'bar')
                @isset($header)
                    <div data-command-strip
                         class="shrink-0 border-b border-(--color-border)
                                bg-(--color-toolbar) px-3 py-2 md:px-5 print-hide">
                        {{ $header }}
                    </div>
                @endisset
            @endif

            <main id="main"
                  class="min-w-0 flex-1 px-3 pt-4 pb-[calc(var(--spacing-bottom-nav)+1.5rem)]
                         md:px-5 md:pb-[calc(var(--spacing-status-bar)+1.5rem)] lg:px-6"
                  tabindex="-1">
                {{-- কনটেন্টের সর্বোচ্চ প্রস্থ — সেকশন ২০.১। বড় স্ক্রিনে
                     টেনে লম্বা নয়; জায়গা বাড়লে কলাম বাড়ে, লাইন নয়। --}}
                <div class="mx-auto w-full max-w-(--spacing-content-max)">
                    @if ($commandPlacement !== 'bar')
                        @isset($header)
                            <div class="mb-4">{{ $header }}</div>
                        @endisset
                    @endif

                    {{ $slot }}
                </div>
            </main>
        </div>
    </div>

    {{-- স্ট্যাটাস বার লেআউটের বাইরে, পর্দার একদম নিচে ও একদম বাঁ প্রান্ত
         থেকে। ভেতরের কলামে রাখলে সাইডবারের ডান পাশ থেকে শুরু হত, আর
         কনটেন্টের সাথে স্ক্রল করে চোখের বাইরে চলে যেত — অথচ কোন কোম্পানি
         ও কোন অর্থবছরে কাজ হচ্ছে সেটা সবসময় দেখা যাওয়ার কথা। --}}
    {{-- রূপের নিজের ভাসমান অংশ — Odoo-র পুরো-পর্দার লঞ্চার শিট।
         লেআউটের একদম বাইরে, কারণ ওটা গোটা পাতা ঢাকে। --}}
    <x-shell.chrome region="overlay" :menu="$menu ?? []" />

    <x-shell.statusbar />

    <x-shell.bottom-nav :menu="$menu ?? []" />

    {{-- স্ক্রিনের নিজের স্ক্রিপ্ট — Alpine লোড হওয়ার পরে।

         কোনো স্ক্রিন যদি একটা Alpine কম্পোনেন্ট ফাংশন সংজ্ঞায়িত করে
         (যেমন জাবেদার যোগফল), সেটা এখানে বসে। মাঝপথে <script> লিখলে
         Alpine তখনো আসেনি, আর ফাংশনটা পাওয়া যেত না।

         বেশিরভাগ স্ক্রিনে এটা খালি থাকবে, আর সেটাই কাম্য: আচরণ যত বেশি
         কম্পোনেন্টে থাকে, স্ক্রিনে তত কম জাভাস্ক্রিপ্ট লাগে। --}}
    @stack('scripts')
</body>
</html>
