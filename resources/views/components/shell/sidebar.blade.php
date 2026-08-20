@props(['menu' => []])

{{--
    সাইডবার — সেকশন ১৫.২ ও ২০.২।

    দুই কলাম, DMS-এর প্রমাণিত নকশা অনুযায়ী:

      • বাঁয়ে সরু আইকন রেল — মডিউল বদলানোর জায়গা, গাঢ় রঙে
      • ডানে সাদা তালিকা — চলতি মডিউলের নিজের পাতাগুলো

    এক কলামে সব মডিউলের সব পাতা রাখলে তালিকাটা একশোর বেশি লিংক লম্বা হয়,
    আর গাঢ় পটভূমিতে অত লেখা সারাদিন পড়া ক্লান্তিকর। রেল হলো chrome,
    তালিকা হলো content — তাই রেল গাঢ়, তালিকা হালকা।

    মোবাইলে দুটোর কোনোটাই নেই; নিচে bottom nav (সেকশন ২০.২)।
--}}
@php
    /*
     * কোন মডিউলটা এখন খোলা।
     *
     * প্রথমে দেখা হয় কোনো মেনু সারি সক্রিয় কি না। কিন্তু মেনুতে থাকে
     * কেবল তালিকার পর্দাগুলো — তৈরি, সম্পাদনা আর একক ডকুমেন্টের পাতা
     * মেনুতে নেই। ফলে "নতুন আদেশ" চাপলেই কোনো সারি সক্রিয় থাকত না, আর
     * সাইডবার লাফ দিয়ে প্রথম মডিউলে (হিসাব) ফিরে যেত — ব্যবহারকারী
     * ফর্ম ভরছেন ক্রয়ের, অথচ পাশে হিসাবের মেনু।
     *
     * দ্বিতীয় চেষ্টা তাই রুটের নাম ধরে: প্রতিটা মডিউলের রুটে তার কোডের
     * উপসর্গ বসে (ModuleServiceProvider), তাই "purchase.order.create"
     * দেখে বোঝা যায় এটা কোন মডিউলের — কোনো মডিউলের নাম এখানে লিখতে
     * হয় না (সেকশন ১৯.৭)।
     */
    $routeName = (string) (request()->route()?->getName() ?? '');

    $activeModule = collect($menu)->first(
        fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
    )
        ?? collect($menu)->first(
            fn ($m) => str_starts_with($routeName, $m['code'].'.'),
        )
        ?? ($menu[0] ?? null);
@endphp

{{--
    গুটানো অবস্থাটা localStorage-এ, তাই পাতা বদলালেও থেকে যায়।

    x-cloak-এর দরকার নেই: ডিফল্ট "খোলা", আর Alpine বুট হওয়ার আগে
    সাইডবারটা খোলাই দেখায় — অর্থাৎ ভুল অবস্থাটা দেখা যায় না, শুধু
    গুটানো ব্যবহারকারীর ক্ষেত্রে এক পলকে খোলা থেকে গুটিয়ে যায়।
--}}
<aside x-data="{ filter: '' }"
       {{--
           চওড়া মাপটা স্থির ক্লাসেই, Alpine-এর অপেক্ষায় নয়।

           ── কেন ─────────────────────────────────────────────────────
           আগে প্রস্থটা কেবল :class দিয়ে বসত, অর্থাৎ Alpine বুট হওয়ার
           আগ পর্যন্ত aside-এর প্রস্থ ছিল ৪৪px — আইকন রেলের মাপ। ভেতরের
           মেনু প্যানেলটা তখন শূন্য প্রস্থে চাপা পড়ত, আর তার প্রথম দুইটা
           অক্ষর ("গ্রা", "CU") পাতার শিরোনামের উপর উঁকি দিত।

           ধরা পড়েছে ছবি তুলে — কোড পড়ে নয়। ছবিটা Alpine বুট হওয়ার
           আগেই উঠেছিল, আর তাতেই দেখা গেল আসল ব্রাউজারেও ওই ভগ্নাংশ
           সেকেন্ডে ঠিক এটাই ঘটে।

           এখন স্থির ক্লাসই খোলা অবস্থাটা ধরে রাখে (যেটা ডিফল্ট), আর
           Alpine কেবল গুটানো অবস্থাটা সামলায় — অর্থাৎ সে দেরি করলে
           পাতার চেহারা ভাঙে না, শুধু গুটানো থাকলে খুলতে এক পলক লাগে।
       --}}
       class="relative sticky top-0 hidden h-dvh shrink-0 md:flex
              md:w-(--spacing-sidebar-icon)
              lg:w-(--spacing-sidebar) xl:w-(--spacing-sidebar-wide)"
       :class="$store.sidebar.collapsed
           ? 'lg:w-(--spacing-sidebar-icon)! xl:w-(--spacing-sidebar-icon)!'
           : 'lg:w-(--spacing-sidebar) xl:w-(--spacing-sidebar-wide)'">

    <div class="flex min-w-0 flex-1 flex-col">

        {{-- মাথা — টপবারের মতোই সাদা ও ঠিক একই উচ্চতা।

             গাঢ় রাখলে লোগোর নিচের রেখা আর টপবারের নিচের রেখা একই উচ্চতায়
             থাকলেও দুই রঙের হত, আর পর্দার দুই অর্ধেক দুইটা আলাদা স্ক্রিনের
             মতো দেখাত। --}}
        {{-- গুটানো অবস্থায় px-3 থাকলে ৪৪px ঘরে মাত্র ২০px জায়গা থাকত,
             আর ৩২px-এর মার্কটা ওখানে চেপে গিয়ে লম্বাটে দেখাত। তাই তখন
             পাশের ফাঁক শূন্য আর লোগো মাঝখানে। --}}
        {{--
            মাথাটা দুই ভাগ, রেল ও প্যানেলের সাথে খাড়াভাবে মিলিয়ে।

            ── কেন এক টুকরো নয় ─────────────────────────────────────────
            আগে মাথাটা পুরো সাইডবারের উপর দিয়ে একটানা যেত, তাই রেলের
            গাঢ় কলামটা শুরু হত তার নিচ থেকে — উপরের-বাঁ কোণে সাদার
            একটা চওড়া পট্টি, আর তার নিচে হঠাৎ নেভি। দুই কলাম দুই
            জায়গা থেকে শুরু হলে ওটা একটা শেল মনে হয় না।

            এখন রেলের অংশটা নেভিই থাকে আর তাতে পণ্যের মার্ক বসে সাদা
            টাইলে; পাশে সাদা পটে ওয়ার্ডমার্ক। দুইটা মিলে একটাই কোণ।
        --}}
        <div class="flex h-(--spacing-brand-plate) shrink-0 border-b border-(--color-border)">
            {{-- রেলের ঘর — মার্কটা সাদা টাইলে, কারণ ওটা সাদা জমিনের
                 জন্য আঁকা; নেভির উপর সরাসরি বসালে নিজের নীল মিলিয়ে যেত। --}}
            <a href="{{ route('dashboard') }}"
               class="grid w-(--spacing-sidebar-icon) shrink-0 place-items-center bg-(--color-brand-900)"
               title="{{ __('core.brand.full_name') }}">
                <span class="grid size-10 place-items-center overflow-hidden rounded-[11px] bg-white">
                    <img src="{{ asset('brand/adi-icon-transparent.png') }}" alt=""
                         aria-hidden="true" class="size-7 object-contain">
                </span>
            </a>

        <a href="{{ route('dashboard') }}"
           class="flex min-w-0 flex-1 items-center gap-2
                  bg-(--color-surface-card) transition-colors hover:bg-(--color-surface-app)"
           :class="$store.sidebar.collapsed ? 'justify-center px-0' : 'px-2 lg:px-3'"
           title="{{ __('core.menu.dashboard') }}">

            {{-- মার্কটা এখানে আর নেই — পাশের রেলের ঘরেই সেটা সবসময়
                 বসে, দুই অবস্থাতেই। এখানেও রাখলে গুটানো অবস্থায় একই
                 ছবি পাশাপাশি দুইবার দেখা যেত। --}}

            {{-- খোলা সাইডবারে পূর্ণ ওয়ার্ডমার্ক — নাম ও পূর্ণরূপ দুটোই
                 ডিজাইনারের নিজের লেটারিংয়ে।

                 আগে এখানে মার্ক + টাইপ করা "ABOS" + টাইপ করা পূর্ণরূপ ছিল,
                 আর পূর্ণরূপটা ঘরে না ধরে কেটে যেত। ছবিটা একটাই জিনিস, তাই
                 কাটার প্রশ্নই নেই — সেটা প্রস্থ অনুযায়ী ছোট-বড় হয়, ভেঙে
                 যায় না। ৩২০×৯৬ অনুপাতে max-h-10 দিলে ১৩৩px চওড়া, যা
                 ২২০px সাইডবারেও অনেকটা জায়গা রেখে বসে। --}}
            {{--
                সাদা মাথায় সাদা-জমিনের ওয়ার্ডমার্ক।

                এখানে আগে `-dark` ভার্সনটা বসানো ছিল, নামটা দেখে —
                কিন্তু ওই নামের মানে "গাঢ় জমিনের জন্য", আর ওটার ক্রোম-
                রুপালি প্রান্ত সাদার উপর পড়ে সস্তা WordArt-এর মতো
                দেখাত। মাথাটা সাদা (উপরের মন্তব্য), তাই গাঢ় নীল
                অক্ষরের ভার্সনটাই এখানকার।
            --}}
            {{-- ওয়ার্ডমার্কটা ঘরের পুরো চওড়া নেয়, একটা ছোট চিহ্ন হয়ে
                 কোণে বসে থাকে না — ব্র্যান্ডের ঘরে ব্র্যান্ডটাই প্রধান। --}}
            <span class="hidden w-full min-w-0 flex-col items-stretch gap-1.5"
                  :class="$store.sidebar.collapsed ? '' : 'lg:flex'">
                <img src="{{ asset('brand/adi-abos-lockup.png') }}" alt="ADI | ABOS"
                     class="h-auto w-full object-contain object-left">

                {{--
                    স্লোগানটা লেখা, ছবি নয়।

                    সরবরাহ করা লকআপে স্লোগানসহ ছবিটা ৭২০×২৬২। ৫৬px উঁচু
                    মাথায় ওটা সর্বোচ্চ ১৫৪px চওড়া হতে পারে, আর তাতে
                    স্লোগানের লেখা দাঁড়ায় ~৪px — একটা সোনালি দাগ, পড়া
                    যায় না।

                    অক্ষরগুলো ছবিই থাকে, কারণ ওগুলো কাস্টম আঁকা; স্লোগানটা
                    মূল আর্টেও letter-spaced small-caps, কাস্টম কিছু নয়।

                    ফিতার উপর নেভি লেখা, সাদার উপর সোনালি লেখা নয়: উজ্জ্বল
                    সোনা সাদায় ২.৪:১, পড়াই যেত না। ফিতায় সোনাটা জমিন আর
                    লেখাটা নেভি — কনট্রাস্ট ৮:১-এর উপরে, আর সোনা তখনো সাজ,
                    তথ্য নয়।
                --}}
                {{-- ফিতাটাও পুরো চওড়ায়, লেখা মাঝখানে — লকআপে স্লোগানটা
                     অক্ষরগুলোর নিচে ঠিক ততটাই চওড়া। --}}
                {{-- এক লাইনেই — `whitespace-nowrap` ছাড়া লেখাটা "BUILT
                     AROUND YOUR / BUSINESS" হয়ে দুই লাইনে ভেঙে যেত, আর
                     ফিতাটা তখন একটা বাক্স হয়ে দাঁড়াত। মাপ ও ফাঁক এমন
                     যাতে ২২০px ঘরে ধরে; ইংরেজি স্লোগান লম্বা হলে
                     `text-clip` নয়, ফিতাটাই সরু হয়। --}}
                <span class="w-full whitespace-nowrap rounded-full px-2 py-0.5 text-center
                             text-[7px] font-bold uppercase tracking-[0.1em]
                             text-(--color-brand-900) shadow-sm"
                      style="background: var(--color-brand-gold-gradient)">
                    {{-- দাঁড়ি ছাড়া — লকআপে ওটা নেই, আর ফিতার ভেতরে একটা
                         বিন্দু শেষে ঝুলে থাকে --}}
                    {{ rtrim(__('core.brand.tagline'), '.') }}
                </span>
            </span>

            <span class="sr-only">{{ __('core.brand.full_name') }}</span>
        </a>
        </div>

        <div class="flex min-h-0 flex-1">

            {{-- আইকন রেল — মডিউল বদলানোর জায়গা।

                 ব্র্যান্ডের নিজের নীল, স্লেট-কালো নয়: পর্দার সবচেয়ে চওড়া
                 রঙিন পৃষ্ঠতলটা প্রোডাক্টের নিজের রং হওয়াই স্বাভাবিক। --}}
            {{-- রেলটা গাঢ় নেভি, ব্র্যান্ড নীল নয়: প্রতিটা টাইল এখন নিজের
                 রঙে, আর ব্র্যান্ড নীলের উপর ইন্ডিগো টাইলটা প্রায় মিলিয়ে
                 যেত। নেভি সব রঙের জন্যই যথেষ্ট গাঢ় জমিন।

                 `gap-1.5` — টাইলগুলো আলাদা জিনিস, একটা লম্বা রঙিন ফিতা
                 নয়। ফাঁক না থাকলে বারোটা রং জোড়া লেগে একটা রংধনু হত। --}}
            {{-- `overflow-visible` — নাহলে hover-এর তালিকাটা রেলের
                 প্রান্তেই কেটে যেত, আর গোটা জিনিসটার কোনো মানে থাকত না।
                 বারোটা মডিউলে রেলের উচ্চতা ~৫৯০px, তাই সাধারণ পর্দায়
                 স্ক্রলের দরকার পড়ে না; খুব ছোট উচ্চতায় বাইরের `aside`
                 নিজেই স্ক্রল করে। --}}
            <nav class="flex w-(--spacing-sidebar-icon) shrink-0 flex-col items-center gap-1.5
                        overflow-visible border-e border-black/10 bg-(--color-brand-900) py-2"
                 aria-label="{{ __('core.a11y.module_navigation') }}">

                {{-- ড্যাশবোর্ড — রেলের সবার উপরে, মডিউলগুলোর আগে।

                     আগে ওখানে যাওয়ার একমাত্র পথ ছিল লোগোতে ক্লিক করা।
                     লোগো যে একটা লিংক সেটা কেউ আন্দাজ করে না — ফলে
                     ড্যাশবোর্ড কার্যত লুকানো ছিল, অথচ সেটাই দিনের শুরুর
                     পাতা।

                     মডিউলগুলো থেকে একটা রেখা দিয়ে আলাদা: এটা কোনো মডিউল
                     নয়, এটা সবগুলোর উপরের পাতা। --}}
                @php
                    $onDashboard = request()->routeIs('dashboard');
                @endphp

                <a href="{{ route('dashboard') }}"
                   @class([
                       'relative mb-1 flex h-11 w-full items-center justify-center border-b border-white/15',
                       'transition-transform hover:-translate-y-px' => ! $onDashboard,
                   ])
                   @if ($onDashboard) aria-current="page" @endif
                   title="{{ __('core.menu.dashboard') }}">

                    <span @class([
                              'grid size-9 place-items-center rounded-[11px] text-white transition-shadow',
                              'ring-2 ring-(--color-brand-gold) shadow-lg' => $onDashboard,
                          ])
                          style="background: var(--color-module-dashboard)"
                          aria-hidden="true">
                        <x-ui.icon name="dashboard" :size="19" />
                    </span>

                    <span class="sr-only">{{ __('core.menu.dashboard') }}</span>
                </a>

                @foreach ($menu as $module)
                    @php
                        $isActive = $activeModule && $module['code'] === $activeModule['code'];
                        $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);
                    @endphp

                    {{--
                        প্রতিটা মডিউল নিজের রঙের টাইলে।

                        ── কেন রং, শুধু আকার নয় ────────────────────────
                        রেলে বারোটা চিহ্ন উপর-নিচে বসে, আর ২০px-এ একরঙা
                        আউটলাইনে গুদাম আর বাক্স প্রায় একই ধূসর আকার। তখন
                        চেনার বদলে **পড়তে** হয়, অথচ রেলে কোনো লেখাই নেই।
                        আকার ও রং — দুইটা সংকেত একসাথে দিলে এক ঝলকেই ধরা
                        যায়।

                        ── রংটা কোথা থেকে ─────────────────────────────
                        `--color-module-{code}` — টোকেনের নামই মডিউলের
                        কোড, তাই এখানে কারও নাম লেখা নেই (§১৯.৭)। টোকেন
                        না থাকলে ব্র্যান্ড নীল বসে, পর্দা ভাঙে না।

                        ── সক্রিয়টা চেনা যায় সোনার রিং দিয়ে ────────────
                        টাইলগুলো এমনিতেই রঙিন, তাই "একটু গাঢ় পটভূমি"
                        দিয়ে সক্রিয়টা আলাদা করা যায় না — সোনা এখানে
                        কোনো তথ্য নয়, শুধু "আপনি এখানে" বলার সাজ।
                    --}}
                    {{--
                        মাউস রাখলেই ওই মডিউলের পর্দাগুলো পাশে খোলে।

                        ── কেন ─────────────────────────────────────────
                        আগে যেকোনো পর্দায় পৌঁছাতে তিন চাল লাগত: রেলে
                        ক্লিক → মডিউল খোলে → প্যানেল পড়া → আবার ক্লিক।
                        যিনি জানেন কোথায় যাচ্ছেন তাঁর জন্য ওই মাঝের
                        ধাপটা নিছক অপেক্ষা।

                        এখন দুইটা পথ, আর কোনোটাই বাধ্যতামূলক নয়:
                          • ক্লিক → মডিউল খোলে, প্যানেলে পর্দাগুলো পড়া যায়
                          • hover → পর্দাগুলো পাশেই, সরাসরি এক ক্লিকে

                        ── কি-বোর্ডেও ─────────────────────────────────
                        `focus-within` — Tab দিয়ে টাইলে এলেই তালিকাটা
                        খোলে, নাহলে যিনি মাউস ব্যবহার করেন না তাঁর জন্য
                        পথটা থাকত না।

                        ── ব্রাউজারের নিজের tooltip-এর বদলে ────────────
                        `title` প্রায় এক সেকেন্ড অপেক্ষা করে। লেখাহীন
                        বারোটা টাইলের সারিতে ওই দেরিটা এত লম্বা যে মানুষ
                        অপেক্ষা না করে ক্লিক করে দেখে নেয় — আর সেটাই
                        সমস্যাটা ফিরিয়ে আনে।
                    --}}
                    <div class="group/rail relative w-full">
                        <a @if ($first) href="{{ $first['url'] }}" @endif
                           @class([
                               'relative flex h-11 w-full items-center justify-center transition-transform',
                               'hover:-translate-y-px' => ! $isActive,
                           ])
                           @if ($isActive) aria-current="true" @endif
                           title="{{ $module['label'] }}">

                            <span @class([
                                      'grid size-9 place-items-center rounded-[11px] text-white transition-shadow',
                                      'ring-2 ring-(--color-brand-gold) shadow-lg' => $isActive,
                                  ])
                                  style="background: var(--color-module-{{ $module['code'] }}, var(--color-brand-600))"
                                  aria-hidden="true">
                                <x-ui.icon :name="$module['icon']" :size="19" />
                            </span>

                            <span class="sr-only">{{ $module['label'] }}</span>
                        </a>

                        <div class="rail-flyout invisible absolute start-full top-0 z-50 ms-1 w-60
                                    rounded-(--radius-card) border border-(--color-border)
                                    bg-(--color-surface-card) py-1.5 opacity-0 shadow-lg
                                    group-hover/rail:visible group-hover/rail:opacity-100
                                    group-focus-within/rail:visible group-focus-within/rail:opacity-100"
                             role="presentation">

                            {{-- মাথায় মডিউলের নাম ও তার নিজের রং — কোন
                                 তালিকাটা খুলল সেটা না বললে পাশাপাশি
                                 দুইটা মডিউলের তালিকা একই রকম দেখাত। --}}
                            <div class="flex items-center gap-2 px-3 pb-1.5">
                                <span class="grid size-6 shrink-0 place-items-center rounded-md text-white"
                                      style="background: var(--color-module-{{ $module['code'] }}, var(--color-brand-600))"
                                      aria-hidden="true">
                                    <x-ui.icon :name="$module['icon']" :size="14" />
                                </span>
                                <span class="truncate text-sm font-semibold">{{ $module['label'] }}</span>
                            </div>

                            <div class="max-h-[70vh] overflow-y-auto">
                                @foreach (collect($module['groups'])->flatten(1) as $item)
                                    <a @if ($item['url']) href="{{ $item['url'] }}" @endif
                                       @class([
                                           'flex items-center px-3 py-1.5 text-sm',
                                           'font-semibold text-(--color-brand-700)' => $item['active'],
                                           'text-(--color-ink-body) hover:bg-(--color-surface-hover)' => ! $item['active'] && $item['url'],
                                           'cursor-not-allowed text-(--color-ink-disabled)' => ! $item['url'],
                                       ])>
                                        <span class="truncate">{{ $item['label'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </nav>

            {{-- তালিকা — শুধু চলতি মডিউলের পাতা।

                 সব মডিউলের সব পাতা একসাথে দেখালে তালিকাটা যত লম্বা হয়,
                 তাতে খোঁজা আর স্ক্রল করা ছাড়া উপায় থাকে না।

                 জমিনটা খাঁটি সাদা নয়, এক ধাপ হালকা টিন্ট (surface-app):
                 ডানের কনটেন্ট এলাকাও সাদা কার্ডে ভরা, আর প্যানেলও সাদা
                 হলে দুইটার মাঝের সীমানাটা কেবল ১px রেখা হয়ে দাঁড়াত। --}}
            <div class="menu-panel hidden min-w-0 flex-1 flex-col border-e border-(--color-border)
                        bg-(--color-surface-app) lg:flex"
                 x-show="! $store.sidebar.collapsed">

                @if ($activeModule)
                    {{-- এই তালিকাটা ছাঁকা — গোটা সিস্টেম খোঁজা নয়।

                         ── কেন ঘরটা মডিউলের নামের উপরে ─────────────────
                         নিচে ছিল, আর তাতে মালিক জিজ্ঞেস করেছেন "এটা তো
                         গ্লোবাল সার্চ, মডিউলের নিচে কেন?" — প্রশ্নটাই
                         প্রমাণ। দুইটা ঘর দেখতে একরকম, আর নামের নিচে
                         বসলে মনে হয় ওটা মডিউলের ভেতরের কোনো বড় খোঁজ।

                         উপরে বসলে ক্রমটা পড়া যায়: "এই প্যানেলে খুঁজুন"
                         → "আপনি আছেন মাস্টার ডাটায়" → পাতাগুলো।

                         ── লেখাটাও মডিউলের নাম বলে ─────────────────────
                         "মেনুতে খুঁজুন" কোন মেনু তা বলত না। "মাস্টার
                         ডাটায় খুঁজুন" পড়েই বোঝা যায় পরিধিটা কতটুকু।

                         টপবারের সার্চ পুরো সিস্টেম খোঁজে — গ্রাহক, বিল,
                         খাত। দুইটা আলাদা কাজ: একটা "রহিমকে খুঁজছি",
                         অন্যটা "পাতাটার নাম মনে নেই, কিন্তু ওতে 'রোল'
                         আছে"। এটা সার্ভারেই যায় না।

                         মডিউলে দশটার কম পাতা থাকলে ঘরটা দেখানো হয় না —
                         চোখেই সব দেখা যায়, আর তখন ঘরটা শুধু জায়গা নেয়। --}}
                    @php
                        $itemCount = collect($activeModule['groups'])->flatten(1)->count();
                    @endphp

                    @if ($itemCount >= 10)
                        <label class="relative shrink-0 px-2 pt-2.5 pb-1">
                            <span class="sr-only">
                                {{ __('core.a11y.filter_this_menu', ['module' => $activeModule['label']]) }}
                            </span>

                            <span class="pointer-events-none absolute inset-y-0 start-4 flex items-center
                                         text-(--color-ink-placeholder)" aria-hidden="true">
                                <x-ui.icon name="search" :size="15" />
                            </span>

                            <input type="search"
                                   x-model="filter"
                                   placeholder="{{ __('core.a11y.filter_this_menu', ['module' => $activeModule['label']]) }}"
                                   class="h-(--spacing-field-dense) w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) ps-8 pe-2 text-sm">
                        </label>
                    @endif

                    <p class="shrink-0 truncate px-3 pt-2 pb-1 text-2xs font-semibold uppercase
                              tracking-wide text-(--color-ink-muted)">
                        {{ $activeModule['label'] }}
                    </p>

                    {{-- কিছু না মিললে বলা হয়।

                         না বললে ব্যবহারকারী একটা সম্পূর্ণ ফাঁকা প্যানেল
                         দেখতেন আর ভাবতেন মেনুটা ভেঙে গেছে — অথচ শুধু
                         তার লেখা শব্দটার সাথে কিছু মেলেনি। --}}
                    @php
                        $labels = collect($activeModule['groups'])->flatten(1)
                            ->pluck('label')->map(fn ($l) => mb_strtolower($l))->values();
                    @endphp

                    <p x-show="filter !== '' && ! {{ Js::from($labels) }}
                               .some(l => l.includes(filter.toLowerCase().trim()))"
                       x-cloak
                       class="px-3 py-2 text-2xs text-(--color-ink-muted)">
                        {{ __('core.empty.no_results') }}
                    </p>

                    <nav class="flex-1 space-y-px overflow-y-auto overflow-x-hidden px-2 pb-4"
                         aria-label="{{ __('core.a11y.main_navigation') }}">

                        @foreach ($activeModule['groups'] as $group => $items)
                            @foreach ($items as $item)
                                {{-- ছাঁকাটা ক্লায়েন্টেই — সারিগুলো ইতিমধ্যেই
                                     পাতায় আছে, তাই সার্ভারে যাওয়ার কোনো
                                     কারণ নেই। খালি ফিল্টারে সব দেখা যায়।

                                     সক্রিয় সারিটা ভরাট নীল পিল, বাঁ পাশের
                                     দাগ নয়: দাগটা সরু আর ফিকে টিন্টের সাথে
                                     মিলে যেত, আর তালিকাটা লম্বা হলে "আমি
                                     কোথায়" প্রশ্নের উত্তর খুঁজতে হত। --}}
                                <a @if ($item['url']) href="{{ $item['url'] }}" @endif
                                   x-show="filter === '' || {{ Js::from(mb_strtolower($item['label'])) }}.includes(filter.toLowerCase().trim())"
                                   @class([
                                       'flex min-h-(--spacing-field-compact) items-center gap-2 rounded-(--radius-field) px-2.5 text-sm transition-colors',
                                       'bg-(--color-brand-500) font-semibold text-(--color-ink-inverse) shadow-sm' => $item['active'],
                                       'text-(--color-ink-body) hover:bg-(--color-surface-hover)' => ! $item['active'] && $item['url'],
                                       'cursor-not-allowed text-(--color-ink-disabled)' => ! $item['url'],
                                   ])
                                   @if (! $item['url']) aria-disabled="true" @endif
                                   @if ($item['active']) aria-current="page" @endif>
                                    <span class="min-w-0 truncate">{{ $item['label'] }}</span>
                                </a>
                            @endforeach
                        @endforeach
                    </nav>
                @endif
            </div>
        </div>
    </div>
</aside>
