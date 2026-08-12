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
<aside x-data="{
           collapsed: localStorage.getItem('abos.sidebar') === 'collapsed',
           filter: '',
       }"
       x-effect="localStorage.setItem('abos.sidebar', collapsed ? 'collapsed' : 'open')"
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
       :class="collapsed
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
        <a href="{{ route('dashboard') }}"
           class="flex h-(--spacing-header) shrink-0 items-center gap-2 border-b border-(--color-border)
                  bg-(--color-surface-card) transition-colors hover:bg-(--color-surface-app)"
           :class="collapsed ? 'justify-center px-0' : 'px-2 lg:px-3'"
           title="{{ __('core.menu.dashboard') }}">

            {{-- সরু সাইডবারে (৪৪px) শুধু মার্ক — ওখানে ওয়ার্ডমার্ক ধরে না। --}}
            {{-- দুইটা ক্লাস একসাথে বসিয়ে (lg:hidden + lg:block) টগল করা
                 যায় না — দুইটাই থেকে যায়, আর স্টাইলশিটে যেটা পরে আছে
                 সেটাই জেতে। প্রথমবার তাতে গুটানো অবস্থায় লোগো একেবারে
                 উধাও হয়ে গিয়েছিল।

                 তাই একটাই ক্লাস শর্তসাপেক্ষে যোগ হয়: খোলা থাকলে বড়
                 পর্দায় মার্কটা লুকাও (ওখানে ওয়ার্ডমার্ক আছে), গুটানো
                 থাকলে লুকিও না। --}}
            <img src="{{ asset('brand/abos-icon-transparent.png') }}" alt="ABOS"
                 class="size-(--spacing-logo-sidebar) shrink-0"
                 :class="collapsed ? '' : 'lg:hidden'">

            {{-- খোলা সাইডবারে পূর্ণ ওয়ার্ডমার্ক — নাম ও পূর্ণরূপ দুটোই
                 ডিজাইনারের নিজের লেটারিংয়ে।

                 আগে এখানে মার্ক + টাইপ করা "ABOS" + টাইপ করা পূর্ণরূপ ছিল,
                 আর পূর্ণরূপটা ঘরে না ধরে কেটে যেত। ছবিটা একটাই জিনিস, তাই
                 কাটার প্রশ্নই নেই — সেটা প্রস্থ অনুযায়ী ছোট-বড় হয়, ভেঙে
                 যায় না। ৩২০×৯৬ অনুপাতে max-h-10 দিলে ১৩৩px চওড়া, যা
                 ২২০px সাইডবারেও অনেকটা জায়গা রেখে বসে। --}}
            {{-- গাঢ় জমিনের রূপ: সাইডবার #0F172A, আর সাদা-জমিনের
                 ওয়ার্ডমার্কের অক্ষর গাঢ় নীল — ওটা এখানে বসালে লোগোটা
                 প্রায় অদৃশ্য হয়ে যেত। --}}
            <img src="{{ asset('brand/abos-wordmark-dark.png') }}" alt="ABOS"
                 class="hidden max-h-10 w-auto object-contain object-left"
                 :class="collapsed ? '' : 'lg:block'">

            <span class="sr-only">{{ __('core.brand.full_name') }}</span>
        </a>

        {{-- গুটিয়ে ফেলার বোতাম।

             ছোট পর্দায় দেখানো হয় না: ওখানে তালিকাটা এমনিতেই থাকে না,
             তাই গোটানোর কিছু নেই — আর যে বোতাম কিছু বদলায় না সেটা মৃত
             বোতাম।

             পছন্দটা localStorage-এ। সেশনে রাখলে প্রতি লগইনে ফিরে আসত,
             আর সার্ভারে রাখলে প্রতিটা ক্লিকে একটা রিকোয়েস্ট যেত — অথচ
             এটা নিছক এই ব্রাউজারের দেখার পছন্দ, কোম্পানির সেটিং নয়। --}}
        <button type="button"
                @click="collapsed = ! collapsed"
                class="absolute end-1 top-4 z-10 hidden size-7 items-center justify-center
                       rounded-(--radius-field) text-(--color-ink-muted) transition-colors
                       hover:bg-(--color-surface-hover) hover:text-(--color-ink) lg:flex"
                :aria-label="collapsed
                    ? '{{ __('core.a11y.expand_sidebar') }}'
                    : '{{ __('core.a11y.collapse_sidebar') }}'"
                :aria-expanded="collapsed ? 'false' : 'true'">
            <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 fill-current"
                 :class="collapsed && 'rotate-180'">
                <path d="M11.7 6.3 6 12l5.7 5.7 1.4-1.4L8.8 12l4.3-4.3-1.4-1.4Zm6 0L12 12l5.7 5.7 1.4-1.4L14.8 12l4.3-4.3-1.4-1.4Z"/>
            </svg>
        </button>

        <div class="flex min-h-0 flex-1">

            {{-- আইকন রেল — মডিউল বদলানোর জায়গা।

                 ব্র্যান্ডের নিজের নীল, স্লেট-কালো নয়: পর্দার সবচেয়ে চওড়া
                 রঙিন পৃষ্ঠতলটা প্রোডাক্টের নিজের রং হওয়াই স্বাভাবিক। --}}
            <nav class="flex w-(--spacing-sidebar-icon) shrink-0 flex-col items-center
                        overflow-y-auto border-e border-black/10 bg-(--color-brand-700) py-1"
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
                       'relative mb-1 flex h-11 w-full items-center justify-center border-b border-white/15 transition-colors',
                       'bg-white/15' => $onDashboard,
                       'hover:bg-white/10' => ! $onDashboard,
                   ])
                   @if ($onDashboard) aria-current="page" @endif
                   title="{{ __('core.menu.dashboard') }}">

                    @if ($onDashboard)
                        <span class="absolute inset-y-1 start-0 w-[3px] rounded-e bg-white"
                              aria-hidden="true"></span>
                    @endif

                    <x-shell.module-icon group="dashboard" tone="white" size="rail" />
                    <span class="sr-only">{{ __('core.menu.dashboard') }}</span>
                </a>

                @foreach ($menu as $module)
                    @php
                        $isActive = $activeModule && $module['code'] === $activeModule['code'];
                        $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);
                    @endphp

                    {{-- সক্রিয় মডিউল চেনা যায় বাঁ পাশের সাদা দাগ দিয়ে, শুধু
                         হালকা পটভূমি দিয়ে নয়: সরু রেলে ৪৪px বর্গের ভেতরে
                         একটা ফিকে আয়তক্ষেত্র চোখে পড়ে না। --}}
                    <a @if ($first) href="{{ $first['url'] }}" @endif
                       @class([
                           'relative flex h-11 w-full items-center justify-center transition-colors',
                           'bg-white/15' => $isActive,
                           'hover:bg-white/10' => ! $isActive,
                       ])
                       @if ($isActive) aria-current="true" @endif
                       title="{{ $module['label'] }}">

                        @if ($isActive)
                            <span class="absolute inset-y-1 start-0 w-[3px] rounded-e bg-white"
                                  aria-hidden="true"></span>
                        @endif

                        <x-shell.module-icon :module="$module['code']" shape="module" tone="white" size="rail" />
                    </a>
                @endforeach
            </nav>

            {{-- তালিকা — শুধু চলতি মডিউলের পাতা, সাদা পটভূমিতে।

                 সব মডিউলের সব পাতা একসাথে দেখালে তালিকাটা যত লম্বা হয়,
                 তাতে খোঁজা আর স্ক্রল করা ছাড়া উপায় থাকে না। --}}
            <div class="hidden min-w-0 flex-1 flex-col border-e border-(--color-border)
                        bg-(--color-surface-card) lg:flex"
                 x-show="! collapsed">

                @if ($activeModule)
                    <p class="shrink-0 truncate px-3 pt-3 pb-1 text-2xs font-semibold uppercase
                              tracking-wide text-(--color-ink-muted)">
                        {{ $activeModule['label'] }}
                    </p>

                    {{-- মেনুতে খোঁজা।

                         টপবারের সার্চ পুরো সিস্টেম খোঁজে — গ্রাহক, বিল,
                         খাত। এটা শুধু এই তালিকাটা ছাঁকে। দুইটা আলাদা
                         কাজ: একটা "রহিমকে খুঁজছি", অন্যটা "পাতাটার নাম
                         মনে নেই, কিন্তু ওতে 'রোল' আছে"।

                         মডিউলে দশটার কম পাতা থাকলে ঘরটা দেখানো হয় না —
                         চোখেই সব দেখা যায়, আর তখন ঘরটা শুধু জায়গা নেয়। --}}
                    @php
                        $itemCount = collect($activeModule['groups'])->flatten(1)->count();
                    @endphp

                    @if ($itemCount >= 10)
                        <label class="relative shrink-0 px-2 pb-2">
                            <span class="sr-only">{{ __('core.a11y.filter_menu') }}</span>
                            <input type="search"
                                   x-model="filter"
                                   placeholder="{{ __('core.a11y.filter_menu') }}"
                                   class="h-8 w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 text-sm">
                        </label>
                    @endif

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

                    <nav class="flex-1 overflow-y-auto overflow-x-hidden pb-4"
                         aria-label="{{ __('core.a11y.main_navigation') }}">

                        @foreach ($activeModule['groups'] as $group => $items)
                            @foreach ($items as $item)
                                {{-- ছাঁকাটা ক্লায়েন্টেই — সারিগুলো ইতিমধ্যেই
                                     পাতায় আছে, তাই সার্ভারে যাওয়ার কোনো
                                     কারণ নেই। খালি ফিল্টারে সব দেখা যায়। --}}
                                <a @if ($item['url']) href="{{ $item['url'] }}" @endif
                                   x-show="filter === '' || {{ Js::from(mb_strtolower($item['label'])) }}.includes(filter.toLowerCase().trim())"
                                   @class([
                                       'flex min-h-(--spacing-touch) items-center gap-2 ps-3 pe-2 text-sm transition-colors',
                                       'border-s-[3px] border-(--color-brand-500) bg-(--color-surface-selected) ps-2 font-medium text-(--color-brand-700)' => $item['active'],
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
