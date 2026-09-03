@props(['menu' => [], 'shape' => 'modules'])

{{--
    উপরের মেনু — যে চেহারাগুলো নেভিগেশন উপরে রাখে তাদের জন্য।

    ── কেন দ্বিতীয় একটা বিন্যাস লাগল ───────────────────────────────────
    আটটা চেহারার পাঁচটা সত্যিকারের ERP-র নকল, আর তার চারটাই নেভিগেশন
    **উপরে** রাখে: Odoo-র অ্যাপ-মেনু, Fiori-র শেল বার, NetSuite-এর
    আড়াআড়ি মেনু, Fusion-এর উপরের পটি। ABOS-এর বাঁ দিকের আইকন রেল
    ওদের কারও নয়।

    রং আর ঘনত্ব টোকেন দিয়ে বদলানো যায়। **কোথায় বসবে** সেটা যায় না —
    ওটা markup-এর কথা। তাই এই ফাইলটা: একই মেনু, অন্য জায়গায়।

    ── কেন সাইডবারের কোড কপি করা হয়নি ─────────────────────────────────
    মেনুর আকার একটাই (`$menu` — মডিউল, তার groups, তার items), আর
    দুইটা বিন্যাসই সেটাই পড়ে। কপি করলে একদিন কেউ সাইডবারে একটা নতুন
    ব্যাজ যোগ করতেন, আর উপরের মেনুতে সেটা থাকত না — দুইজন ব্যবহারকারী
    দুই রকম ABOS দেখতেন, আর কেউ বলতে পারত না কেন।

    এখানে তাই কেবল **বসানোটা** আলাদা। কী বসবে সেটা এক জায়গা থেকেই আসে।

    ── মোবাইলে এটা নেই ────────────────────────────────────────────────
    ছোট পর্দায় দুইটা বিন্যাসেরই উত্তর এক: নিচের bottom nav। বারোটা
    মডিউল আড়াআড়ি বসানোর মতো জায়গা ৩৭৫px-এ নেই, আর জোর করে বসালে
    নামগুলো এক-দুই অক্ষরে কেটে যেত।
--}}
@php
    /*
     * কোন মডিউলটা এখন খোলা — সাইডবারের সাথে হুবহু একই নিয়ম।
     *
     * প্রথমে সক্রিয় সারি খোঁজা, তারপর রুটের নামের উপসর্গ। কারণটা
     * সাইডবারে লেখা: মেনুতে কেবল তালিকার পর্দা থাকে, তাই "নতুন আদেশ"
     * খুললে কোনো সারিই সক্রিয় থাকে না আর মেনু লাফ দিয়ে প্রথম মডিউলে
     * ফিরে যেত।
     */
    $routeName = (string) (request()->route()?->getName() ?? '');

    $activeModule = collect($menu)->first(
        fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
    )
        ?? collect($menu)->first(
            fn ($m) => collect($m['codes'])->contains(fn ($c) => str_starts_with($routeName, $c.'.')),
        )
        ?? ($menu[0] ?? null);

    /*
     * পটিতে কী কী বসবে — মডিউল, না চলতি মডিউলের ভাগ।
     *
     * ── কেন এই দুইটা আলাদা, ২৮ আগস্ট ২০২৬ ───────────────────────────
     * মালিক স্ক্রিনশট পাঠিয়ে বলেছেন *"ekhane menu asar kotha modiule
     * asteche"*। Odoo-তে উপরের বাঁয়ে অ্যাপের নাম, আর তার নিচের পটিতে
     * **সেই অ্যাপের নিজের মেনু**। আমাদের পর্দায় উপরে লেখা ছিল "হিসাব
     * ও অর্থ", আর নিচে বসে ছিল এগারোটা মডিউল — অর্থাৎ নামটা দুই
     * জায়গায়, আর মেনুটা কোথাও না।
     *
     * NetSuite ও Fiori-তে উল্টো: ওখানে উপরের পটিই মডিউলের তালিকা, আর
     * Fiori-তে মডিউল বদলের আর কোনো পথই নেই। তাই বাছাইটা রূপের ঘোষণা
     * থেকে আসে, এখানকার কোনো `if` থেকে নয় — কারণগুলো [[Ui::topnav()]]-এ।
     *
     * দুইটা ক্ষেত্রেই আকারটা এক (`label` · `items` · `active`), তাই
     * নিচের markup একটাই থাকে।
     */
    $entries = [];

    if ($shape === 'sections' && $activeModule) {
        foreach ($activeModule['groups'] as $group => $items) {
            $entries[] = [
                'label' => __('core.menu.'.$group),
                'icon' => null,
                'accent' => null,
                /*
                 * এই আকারে সারিগুলো **একটা মডিউলের ভেতরের** ছয়টা ভাগ,
                 * মডিউল নয় — তাই সাইডবারের দলগুলো এখানে খাটে না।
                 * `null` মানে কোনো ভাগরেখা আঁকা হবে না।
                 */
                'section' => null,
                'items' => array_values($items),
                'active' => collect($items)->contains('active', true),
            ];
        }
    } else {
        foreach ($menu as $module) {
            $entries[] = [
                'label' => $module['label'],
                'icon' => $module['icon'],
                'accent' => $module['code'],
                'section' => $module['section'],
                'items' => collect($module['groups'])->flatten(1)->values()->all(),
                'active' => $activeModule && $module['code'] === $activeModule['code'],
            ];
        }
    }

    /*
     * দলের ভাগরেখা — খাড়া, লেখা নয়।
     *
     * ── কেন এখানে শিরোনাম লেখা হয় না ─────────────────────────────
     * এই পটিটা আড়াআড়ি, আর তাতে ইতিমধ্যেই বারোটা মডিউল বসে —
     * `overflow-x-auto` আছে কারণ ল্যাপটপের পর্দাতেও ওগুলো ধরে না।
     * চারটা শিরোনাম যোগ করলে ("অর্থ ও হিসাব", "মানুষ ও নিয়ন্ত্রণ")
     * প্রতিটা মডিউল আরও ডানে সরত, আর শেষ কয়েকটা পর্দার বাইরে
     * চলে যেত — অর্থাৎ **ভাগ দেখাতে গিয়ে জিনিসটাই লুকিয়ে যেত**।
     *
     * সাইডবারে একই সিদ্ধান্ত, একই কারণে: রেখা দেখায়, `aria-label`
     * বলে। জায়গাটাই এখানে আসল সীমা, পছন্দ নয়।
     */
    $shownSection = null;
@endphp

{{-- একটাও সারি না থাকলে পটিটাই আঁকা হয় না — খালি একটা বর্ডার
     পাতার মাথায় বসে থাকলে ওটা "কিছু লোড হয়নি" বলে পড়ে। --}}
@if ($entries !== [])
<nav class="topnav hidden shrink-0 items-center gap-0.5 overflow-x-auto border-b
            border-(--color-topnav-border) bg-(--color-topnav) px-3 md:flex md:px-5"
     x-data
     {{-- পটিটা আড়াআড়ি সরলে খোলা তালিকা বন্ধ — ওটা `fixed`, তাই
          বোতামের সাথে সরে না, আর না সরালে ভুল জায়গায় ঝুলে থাকত। --}}
     @scroll="$dispatch('topnav-scrolled')"
     aria-label="{{ __('core.a11y.module_navigation') }}">

    {{--
        পণ্যের চিহ্ন — সবার আগে।

        ── কেন এটা এখানে, টপবারে নয় ────────────────────────────────
        ব্র্যান্ড প্লেটটা সাইডবারের মাথায় বসে। এই বিন্যাসে সাইডবার
        নেই, তাই ওটাও নেই — আর প্রথম ছবিতে সেটা ধরা পড়ল: পর্দায়
        কোম্পানির লোগো ছিল, সফটওয়্যারের নাম কোথাও ছিল না।

        ডিপোতে একই কম্পিউটারে কয়েকটা জিনিস খোলা থাকে। "এটা কোন
        সফটওয়্যার" প্রশ্নটার উত্তর পর্দায় না থাকলে ফোনে সাপোর্ট
        চাইতে গিয়ে মানুষ বলতে পারেন না কোনটার কথা বলছেন।
    --}}
    <a href="{{ route('dashboard') }}"
       class="me-2 grid size-8 shrink-0 place-items-center overflow-hidden rounded-[9px] bg-white"
       title="{{ __('core.brand.full_name') }}">
        <img src="{{ asset('brand/abos-icon-transparent.png') }}" alt=""
             aria-hidden="true" class="size-6 object-contain">
    </a>

    {{--
        তালিকাগুলো **fixed**, `absolute` নয় — আর এটাই আসল সারাই।

        ── কী ভাঙা ছিল, ২৮ আগস্ট ২০২৬ ──────────────────────────────
        এই `<nav>`-এ `overflow-x-auto` আছে, কারণ এগারোটা মডিউল সরু
        পর্দায় ধরে না। কিন্তু CSS-এ `overflow-x: auto` দিলে
        `overflow-y` নিজে থেকেই `auto` হয়ে যায় — দুইটা আলাদা করে
        দেওয়ার কোনো উপায় নেই।

        ফলে ৭০vh লম্বা তালিকাটা **৪১px উঁচু পটির ভেতরেই কাটা পড়ত**।
        লাইভে মেপে দেখা গেছে: তালিকাটা DOM-এ ৬২৮px, Alpine ঠিকই
        `open = true` করত, অথচ ওই জায়গায় `elementFromPoint` ফেরত দিত
        পাতার sticky হেডার — অর্থাৎ পর্দায় ওটা **একেবারেই ছিল না**।

        ব্যবহারকারীর কাছে সেটা "ক্লিক করলে কিছু হয় না", আর বারবার
        ক্লিকে পটিটা খাড়াখাড়ি স্ক্রল করত — মালিকের ভাষায় "skin hang
        kore"।

        `fixed` স্ক্রল-কনটেইনারের বাইরে আঁকে, তাই কাটা পড়ে না।
        বিনিময়ে জায়গাটা নিজে হিসাব করতে হয় (`place()`), আর পটি সরলে
        বা জানালা বদলালে তালিকা বন্ধ — নাহলে ওটা বাতাসে ঝুলে থাকত।
    --}}
    @foreach ($entries as $entry)
        @php
            $opensSection = $entry['section'] !== null && $entry['section'] !== $shownSection;
            $shownSection = $entry['section'];

            // `top` দলটার কোনো নাম নেই, তাই তার আগে কোনো রেখাও নয়।
            $sectionLabel = ($opensSection && $entry['section'] !== 'top')
                ? __('core.nav_section.'.$entry['section'])
                : null;
        @endphp

        @if ($sectionLabel)
            <div role="separator" aria-label="{{ $sectionLabel }}"
                 class="mx-1.5 h-5 w-px shrink-0 bg-(--color-topnav-border)"></div>
        @endif

        @php
            /*
             * এক সারির ভাগে তালিকা লাগে না — সরাসরি লিংক।
             *
             * Odoo-ও তাই করে: যে মেনুতে একটাই পাতা, সেটা ক্লিকেই খোলে।
             * তালিকা খুলে একটা মাত্র সারি দেখানো একটা বাড়তি ক্লিক, আর
             * ওই ক্লিকটা কিছুই জানায় না।
             */
            $only = count($entry['items']) === 1 ? $entry['items'][0] : null;
        @endphp

        @if ($only && $only['url'])
            <a href="{{ $only['url'] }}" data-nav-item
               @class([
                   'flex h-(--spacing-command) shrink-0 items-center gap-1.5',
                   'rounded-(--radius-field) px-2.5 text-sm whitespace-nowrap transition-colors',
                   'bg-(--color-topnav-selected) font-semibold text-(--color-topnav-ink)' => $entry['active'],
                   'text-(--color-topnav-ink-muted) hover:bg-(--color-topnav-hover)' => ! $entry['active'],
               ])
               @if ($entry['active']) aria-current="page" @endif>
                @if ($entry['icon'])
                    <x-ui.icon :name="$entry['icon']" :size="16" />
                @endif
                <span>{{ $entry['label'] }}</span>
            </a>
        @else
            <div class="shrink-0"
                 x-data="{
                     open: false, x: 0, y: 0,
                     place() {
                         const r = $refs.btn.getBoundingClientRect();
                         /* ২৬৪ = তালিকার চওড়া (w-64) + ৮px ফাঁক — ডান
                            প্রান্তের মডিউলটা নাহলে পর্দার বাইরে খুলত। */
                         this.x = Math.max(8, Math.min(r.left, window.innerWidth - 264));
                         this.y = r.bottom + 4;
                     },
                     toggle() { this.open = ! this.open; if (this.open) this.place(); },
                 }"
                 @click.outside="open = false"
                 @keydown.escape.window="open = false"
                 @resize.window="open = false"
                 @scroll.window="open = false"
                 @topnav-scrolled.window="open = false">

                <button type="button" data-nav-item x-ref="btn"
                        @click="toggle()"
                        :aria-expanded="open ? 'true' : 'false'"
                        @class([
                            'flex h-(--spacing-command) items-center gap-1.5 rounded-(--radius-field)',
                            'px-2.5 text-sm whitespace-nowrap transition-colors',
                            'bg-(--color-topnav-selected) font-semibold text-(--color-topnav-ink)' => $entry['active'],
                            'text-(--color-topnav-ink-muted) hover:bg-(--color-topnav-hover)' => ! $entry['active'],
                        ])>
                    @if ($entry['icon'])
                        <x-ui.icon :name="$entry['icon']" :size="16" />
                    @endif
                    <span>{{ $entry['label'] }}</span>
                </button>

                {{--
                    তালিকাটা পাতার উপরে ভাসে, তাই পাতার রং।

                    `pops-onto-page` না দিলে উপরের বারের গাঢ় কালি
                    উত্তরাধিকারসূত্রে এখানেও নামত, আর সাদা পাতার উপর
                    একটা গাঢ় বাক্স ভেসে থাকত — যেটা কোনো ERP করে না।
                --}}
                <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                     :style="`left: ${x}px; top: ${y}px`"
                     class="pops-onto-page fixed z-50 max-h-[70vh] w-64 overflow-y-auto
                            rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) py-1.5 shadow-lg">

                    {{-- মাথায় নিজের রং — পাশাপাশি দুইটা তালিকা নাহলে
                         একই রকম দেখাত। ভাগের তালিকায় রং নেই, তাই
                         কেবল নামটাই বসে। --}}
                    <div class="flex items-center gap-2 px-3 pb-1.5">
                        @if ($entry['accent'])
                            <span class="grid size-6 shrink-0 place-items-center rounded-md text-white"
                                  style="background: var(--color-module-{{ $entry['accent'] }}, var(--color-brand-600))"
                                  aria-hidden="true">
                                <x-ui.icon :name="$entry['icon']" :size="14" />
                            </span>
                        @endif
                        <span class="truncate text-sm font-semibold">{{ $entry['label'] }}</span>
                    </div>

                    @foreach ($entry['items'] as $item)
                        <a @if ($item['url']) href="{{ $item['url'] }}" @endif
                           @class([
                               'flex items-center px-3 py-1.5 text-sm',
                               'font-semibold text-(--color-brand-700)' => $item['active'],
                               'text-(--color-ink-body) hover:bg-(--color-surface-hover)' => ! $item['active'] && $item['url'],
                               'cursor-not-allowed text-(--color-ink-disabled)' => ! $item['url'],
                           ])
                           @if (! $item['url']) aria-disabled="true" @endif
                           @if ($item['active']) aria-current="page" @endif>
                            <span class="truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
</nav>
@endif
