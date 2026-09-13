@props(['menu' => []])

{{--
    টপবারের নিচের বার — চলতি মডিউলের ভিতরের পর্দাগুলো, ট্যাব হিসেবে।

    ── কী বদলাল, ১৩ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
    আগে এখানে breadcrumb ছিল — "ড্যাশবোর্ড / অর্থ / ড্যাশবোর্ড"। সেটা
    বলত **আমি কোথায়**, কিন্তু পরের প্রশ্নটার উত্তর দিত না: *"এই
    মডিউলে আর কী কী আছে?"*

    ⓘ মালিকের চাওয়া (ছবি দিয়ে): বারটা phpMyAdmin-এর উপরের সারির মতো
    হবে — চলতি মডিউলের মেনু/সাব-মডিউলগুলো পাশাপাশি, চলতিটা চিহ্নিত।

    ⭐ আর পথটা এতে হারায় না, বরং স্পষ্ট হয়: মডিউলের নাম বাঁয়ে বসে
    থাকে, আর চলতি পর্দাটা নিচে দাগ দিয়ে জ্বলে। breadcrumb-এ যা তিনটা
    শব্দে বলা হত, এখানে তা **দেখা** যায় — সাথে কোথায় যাওয়া যায় তাও।

    ── কেন সব রূপে নয় ─────────────────────────────────────────────────
    `salesforce` ও `suite` রূপ নিজেরাই এমন একটা ট্যাব সারি আঁকে
    (`chrome/*`-এর `page-head`)। ⚠️ ওখানে এটাও বসালে **দুইটা ট্যাব বার**
    হত, একটার নিচে আরেকটা। তাই লেআউট ঐ দুইটায় পুরনো breadcrumb-ই রাখে।
    ⓘ তালিকাটা অনুমান নয়, মেপে বের করা — `grep "region === 'page-head'"`।
--}}
@php
    /*
     * চলতি মডিউল — তিন ধাপে, আর ক্রমটা গুরুত্বপূর্ণ।
     *
     * ⓵ কোনো সারি `active`? তাহলে ওটার মডিউল।
     * ⓶ না হলে রুটের নাম মডিউলের কোড দিয়ে শুরু কি না — ⓘ এটা লাগে
     *    কারণ রেকর্ডের ভিতরের পাতাগুলোয় (`…/42/edit`) কোনো মেনু সারি
     *    হুবহু মেলে না, অথচ মানুষ তখনো ঐ মডিউলেই আছেন।
     * ⓷ তাও না হলে কিছু নয় — ⛔ প্রথম মডিউলটা ধরে নেওয়া হয় না, কারণ
     *    ড্যাশবোর্ডে দাঁড়িয়ে "ক্রয়" জ্বলে থাকা মিথ্যা কথা বলত।
     */
    $route = (string) (request()->route()?->getName() ?? '');

    $current = collect($menu)->first(
        fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
    ) ?? collect($menu)->first(
        fn ($m) => collect($m['codes'] ?? [])->contains(fn ($c) => str_starts_with($route, $c.'.')),
    );

    /*
     * ⚠️ `url` নেই এমন সারি ট্যাব হতে পারে না।
     *
     * এখনো-তৈরি-হয়নি সারিগুলো (`planned`) মেনুতে "শীঘ্রই" লেখা নিয়ে
     * দেখা যায়, কিন্তু ট্যাব-বারে ওদের জায়গা নেই: `href=""` মানে পাতাটা
     * নিজেকেই আবার খোলে, আর ব্যবহারকারী ভাবেন কিছুই হলো না।
     */
    $tabs = $current
        ? collect($current['groups'])->flatten(1)
            ->filter(fn ($row) => ($row['url'] ?? null) !== null)
            ->values()
        : collect();

    $moduleUrl = $tabs->first()['url'] ?? null;

    /*
     * ── কখন গ্রুপে ভাগ, আর কখন সোজা সারি — মালিকের সিদ্ধান্ত ─────────
     *
     * ⚠️ হিসাব মডিউলে **২৯টা** পর্দা। সোজা সারিতে ওগুলো পর্দার বাইরে
     * চলে যায়, আর যে ট্যাব দেখাই যায় না সেটা ট্যাব নয়।
     *
     * ⭐ কিন্তু গ্রুপ করলে দাম আছে: পর্দায় পৌঁছাতে **একটা বাড়তি ক্লিক**।
     * ⓘ গ্রাহক বা এইচআর মডিউলে ছয়টা পর্দা — ওখানে ঐ ক্লিকটা কেবল বাধা,
     * কারণ ছয়টা এমনিতেই এক সারিতে ধরে।
     *
     * তাই সীমাটা: **আটের বেশি হলে গ্রুপ**। ⓘ সংখ্যাটা নিখুঁত নয়, কিন্তু
     * একটা সারিতে আটটার বেশি বাক্স চোখ আর "পড়ে" না, খুঁজতে হয়।
     *
     * আজকের ফল: Accounts (২৯) ও Finance (১১) গ্রুপ পায়; Approval (৮),
     * Backup (৭), Customer (৬), Hr (৬) সোজা সারিই থাকে।
     */
    $groupAfter = 8;

    $grouped = $current && $tabs->count() > $groupAfter;

    /*
     * গ্রুপগুলো — কেবল যেগুলোয় অন্তত একটা খোলা পর্দা আছে।
     *
     * ⓘ `planned` সারিগুলো বাদ পড়ায় কোনো গ্রুপ পুরো খালি হয়ে যেতে
     * পারে, আর খালি ড্রপডাউন খুলে মানুষ কিছুই পান না।
     */
    /*
     * ── গ্রুপের আইকন — অনুমান নয়, নামের মিল ───────────────────────────
     * ⭐ আইকনের তালিকায় `dashboard`, `transactions`, `reports`,
     * `settings` আগে থেকেই আছে, আর ওগুলো গ্রুপের চাবির হুবহু নাম। তাই
     * বেশিরভাগ গ্রুপ কোনো সিদ্ধান্ত ছাড়াই নিজের আইকন পায়।
     *
     * ⓘ কেবল `master`-এর কোনো আইকন নেই, তাই ওটার জন্য `book` — মাস্টার
     * তালিকাগুলো আসলে ব্যবসার খাতা।
     *
     * ⚠️ পর্দা-প্রতি আইকন এখনো নেই: ১৬২টা মেনু সারির একটাও নিজের আইকন
     * ঘোষণা করে না। তাই সোজা-ট্যাবের মডিউলগুলো (গ্রাহক, এইচআর) আইকন
     * ছাড়াই থাকে — ওটা স্টাইলের নয়, ডেটার কাজ।
     *
     * ⓘ অচেনা নাম দিলে `x-ui.icon` চুপ করে কিছুই আঁকে না, ভাঙে না।
     */
    $groupIcon = fn (string $name): string => ['master' => 'book'][$name] ?? $name;

    $groups = $grouped
        ? collect($current['groups'])
            ->map(fn ($items) => collect($items)->filter(fn ($r) => ($r['url'] ?? null) !== null)->values())
            ->filter(fn ($items) => $items->isNotEmpty())
        : collect();
@endphp

{{--
    টপবারের ঠিক নিচে আটকে থাকে, তার সাথেই — `sticky top-(--spacing-header)`।

    ⓘ স্ক্রল করার সাথে সাথে উঠে গেলে লম্বা তালিকার মাঝখানে "আমি কোথায়,
    আর পাশে কী আছে" — দুইটা প্রশ্নেরই উত্তর হারাত, অথচ প্রশ্ন দুইটা ঠিক
    তখনই ওঠে।

    `z` টপবারের এক ধাপ নিচে, যাতে টপবারের ড্রপডাউনগুলো এর উপর দিয়ে খোলে।
--}}
{{--
    ── পটিটার চেহারা: মালিকের দেওয়া phpMyAdmin-এর বার ─────────────────
    মালিকের কথা: *"১০০% এরকম চাই — color, font, সব।"*

    ⓘ ঐ বারের গড়নটা তিনটা জিনিসে: (১) উপর থেকে নিচে হালকা ধূসর
    গ্রেডিয়েন্ট, (২) প্রতিটা ট্যাব একটা পাতলা বর্ডারের ঘর, পাশাপাশি
    লাগানো — আলাদা ভাসমান বোতাম নয়, (৩) ছোট হরফ, স্বাভাবিক ওজন।

    ⚠️ রংগুলো কাঁচা হেক্সে বসানো হয়নি, টোকেন দিয়ে। ⓘ কারণ এই
    ব্যবস্থায় গাঢ় থিম আছে, আর হেক্স বসালে গাঢ় থিমে ধূসরের উপর ধূসর
    লেখা পড়ত — পড়াই যেত না। টোকেন ব্যবহার করায় গড়নটা এক থাকে, আর
    থিম বদলালে রং তার সাথে যায়।
--}}
<div class="sticky top-(--spacing-header) z-20 flex min-h-(--spacing-field-compact) shrink-0 items-center gap-2
            border-b border-(--color-border)
            bg-linear-to-b from-[var(--color-surface-card)] to-[var(--color-surface-muted)]
            py-1 ps-2 pe-3 md:pe-5 print-hide">

    {{-- মডিউলের নাম — ট্যাবগুলোর বাঁয়ে, আলাদা করে।

         ⓘ এটাই breadcrumb-এর মাঝের ধাপটার কাজ করে ("অর্থ"), আর লিংক
         থাকায় মডিউলের প্রথম পর্দায় ফেরাও যায়। --}}
    @if ($current)
        @if ($moduleUrl)
            <a href="{{ $moduleUrl }}"
               class="me-1 shrink-0 truncate text-xs font-semibold text-(--color-ink-body)
                      hover:text-(--color-brand-600) hover:underline">{{ $current['label'] }}</a>
        @else
            <span class="me-1 shrink-0 truncate text-xs font-semibold text-(--color-ink-body)">{{ $current['label'] }}</span>
        @endif

        <span class="h-4 w-px shrink-0 bg-(--color-border)" aria-hidden="true"></span>
    @else
        {{-- কোনো মডিউল মেলেনি (ড্যাশবোর্ড) — খালি বার রাখার চেয়ে
             বাড়ির নামটা থাকা ভালো, আর ডান পাশের কাজগুলো তখনো দরকার। --}}
        <a href="{{ route('dashboard') }}"
           class="me-1 shrink-0 text-xs font-semibold text-(--color-ink-body)
                  hover:text-(--color-brand-600) hover:underline">{{ __('core.menu.dashboard') }}</a>
    @endif

    {{--
        ট্যাবগুলো — একটাই সারিতে, আর বেশি হলে পাশে স্ক্রল।

        ⚠️ মোড়ানো (`flex-wrap`) হয় না ইচ্ছাকৃতভাবে: বারটার উচ্চতা তখন
        পর্দাভেদে বদলাত, আর নিচের পুরো পাতাটা লাফাত। ⓘ একটা মডিউলে
        বিশটা পর্দা থাকতে পারে (Accounts-এ আছে), তাই স্ক্রলই একমাত্র
        স্থিতিশীল উত্তর।
    --}}
    {{--
        ⚠️ `overflow-x-auto` **কেবল সোজা ট্যাবের বেলায়**।

        ⛔ এটা একটা ক্লিপিং বাক্স তৈরি করে, তাই ভিতরের `absolute`
        ড্রপডাউনগুলো কেটে যেত — খুলত, কিন্তু দেখা যেত না। প্রথম খসড়ায়
        ঠিক তাই হয়েছিল: গ্রুপে চাপলে কিছুই হচ্ছে না মনে হত।

        ⭐ আর গ্রুপ-মোডে স্ক্রলের দরকারও নেই: ২৯টা পর্দা তখন পাঁচটা ঘরে
        নেমে আসে, যা এমনিতেই এক সারিতে ধরে। অর্থাৎ যেখানে স্ক্রল লাগে
        সেখানে ড্রপডাউন নেই, আর যেখানে ড্রপডাউন আছে সেখানে স্ক্রল লাগে না।
    --}}
    <nav @class(['flex min-w-0 flex-1 items-center', 'overflow-x-auto' => ! $grouped])
         aria-label="{{ __('core.a11y.breadcrumb') }}">

        @if (! $grouped)
            {{-- ⓘ `active` মেনু থেকেই আসে — এখানে আবার `routeIs()` লিখলে
                 দুই জায়গায় দুই নিয়ম হত, আর রেকর্ডের ভিতরে ঢুকলে একটা
                 জ্বলত আর অন্যটা নিভত (TheTrailWentBlankInsideARecordTest)। --}}
            @foreach ($tabs as $tab)
                {{-- ⓘ ঘরগুলো পাশাপাশি লাগানো — `-ms-px` দিয়ে বাঁ বর্ডারটা
                     আগেরটার ডান বর্ডারের উপর বসে, তাই মাঝখানে দুই পিক্সেল
                     মোটা দাগ পড়ে না। ছবির বারেও দাগটা এক পিক্সেল। --}}
                <a href="{{ $tab['url'] }}"
                   @class([
                       'shrink-0 whitespace-nowrap border border-(--color-border) -ms-px px-3 py-1 text-xs transition-colors first:ms-0 first:rounded-s-(--radius-field) last:rounded-e-(--radius-field)',
                       'relative z-10 bg-(--color-surface-card) font-semibold text-(--color-brand-600)' => $tab['active'] ?? false,
                       'bg-linear-to-b from-[var(--color-surface-card)] to-[var(--color-surface-muted)] text-(--color-ink-body) hover:bg-(--color-surface-card) hover:bg-none' => ! ($tab['active'] ?? false),
                   ])
                   @if ($tab['active'] ?? false) aria-current="page" @endif>
                    {{ $tab['label'] }}
                </a>
            @endforeach
        @else
            @foreach ($groups as $name => $items)
                @php
                    $activeItem = $items->firstWhere('active', true);
                @endphp

                @if ($items->count() === 1)
                    {{-- ⭐ একটাই পর্দা হলে ড্রপডাউন নয়, সরাসরি লিংক।

                         ⓘ এক আইটেমের ড্রপডাউন মানে একটা ক্লিক নষ্ট — খুলে
                         দেখা যায় ভিতরে একটাই জিনিস, যেটা বাইরেই লেখা ছিল। --}}
                    <a href="{{ $items->first()['url'] }}"
                       @class([
                           'shrink-0 whitespace-nowrap border border-(--color-border) -ms-px px-3 py-1 text-xs transition-colors first:ms-0 first:rounded-s-(--radius-field)',
                           'relative z-10 bg-(--color-surface-card) font-semibold text-(--color-brand-600)' => $activeItem !== null,
                           'bg-linear-to-b from-[var(--color-surface-card)] to-[var(--color-surface-muted)] text-(--color-ink-body) hover:bg-(--color-surface-card) hover:bg-none' => $activeItem === null,
                       ])
                       @if ($activeItem !== null) aria-current="page" @endif>
                        <span class="flex items-center gap-1.5">
                            <x-ui.icon :name="$groupIcon($name)" :size="14" />
                            {{ $items->first()['label'] }}
                        </span>
                    </a>
                @else
                    <div x-data="{ open: false }" class="relative shrink-0">
                        <button type="button"
                                @click="open = ! open" @click.outside="open = false"
                                @keydown.escape.window="open = false"
                                :aria-expanded="open.toString()"
                                @class([
                                    'flex items-center gap-1 whitespace-nowrap border border-(--color-border) -ms-px px-3 py-1 text-xs transition-colors first:ms-0',
                                    'relative z-10 bg-(--color-surface-card) font-semibold text-(--color-brand-600)' => $activeItem !== null,
                                    'bg-linear-to-b from-[var(--color-surface-card)] to-[var(--color-surface-muted)] text-(--color-ink-body) hover:bg-(--color-surface-card) hover:bg-none' => $activeItem === null,
                                ])>
                            <x-ui.icon :name="$groupIcon($name)" :size="14" />

                            {{ __('core.menu.'.$name) }}

                            {{-- ⭐ চলতি পর্দার নামটা গ্রুপের গায়েই — তাই
                                 কিছু না খুলেই "আমি কোথায়" পড়া যায়।
                                 ⓘ breadcrumb যা বলত, এটাও সেটাই বলে। --}}
                            @if ($activeItem !== null)
                                <span class="opacity-70" aria-hidden="true">›</span>
                                <span class="max-w-32 truncate">{{ $activeItem['label'] }}</span>
                            @endif
                        </button>

                        <div x-show="open" x-cloak x-transition.opacity
                             class="absolute start-0 top-full z-30 mt-1 max-h-80 min-w-48 overflow-y-auto
                                    rounded-(--radius-card) border border-(--color-border)
                                    bg-(--color-surface-card) py-1 shadow-lg">
                            @foreach ($items as $item)
                                <a href="{{ $item['url'] }}"
                                   @class([
                                       'block px-3 py-1.5 text-xs transition-colors hover:bg-(--color-surface-muted)',
                                       'font-semibold text-(--color-brand-600)' => $item['active'] ?? false,
                                       'text-(--color-ink-body)' => ! ($item['active'] ?? false),
                                   ])
                                   @if ($item['active'] ?? false) aria-current="page" @endif>
                                    {{ $item['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        @endif
    </nav>

    {{-- এই পর্দার নিজের কাজ — আগের বারের মতোই।

         ⚠️ স্ট্যাকটা রাখা **বাধ্যতামূলক**: দুইশোর বেশি পর্দা নিজের
         বোতাম এখানে পাঠায় (`crumb_actions`), আর বারটা বদলাতে গিয়ে
         ওগুলো ফেলে দিলে প্রতিটা পর্দার প্রধান কাজটাই উধাও হত। --}}
    @stack('crumb_actions')
</div>
