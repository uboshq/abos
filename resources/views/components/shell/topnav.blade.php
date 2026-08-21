@props(['menu' => []])

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
            fn ($m) => str_starts_with($routeName, $m['code'].'.'),
        )
        ?? ($menu[0] ?? null);
@endphp

<nav class="topnav hidden shrink-0 items-center gap-0.5 overflow-x-auto border-b
            border-(--color-topnav-border) bg-(--color-topnav) px-3 md:flex md:px-5"
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
        <img src="{{ asset('brand/adi-icon-transparent.png') }}" alt=""
             aria-hidden="true" class="size-6 object-contain">
    </a>

    @foreach ($menu as $module)
        @php
            /*
             * মডিউলের নামে ক্লিক করলে তার প্রথম কাজের পাতায় যাওয়া।
             *
             * শুধু মেনু খোলা যথেষ্ট নয়: যিনি জানেন কোথায় যাচ্ছেন তাঁর
             * জন্য এক ক্লিক, আর যিনি জানেন না তাঁর জন্য নিচের তালিকা।
             */
            $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);
            $isActive = $activeModule && $module['code'] === $activeModule['code'];
        @endphp

        <div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false">
            <button type="button" data-nav-item
                    @click="open = ! open"
                    @keydown.escape.window="open = false"
                    :aria-expanded="open ? 'true' : 'false'"
                    @class([
                        'flex h-(--spacing-command) items-center gap-1.5 rounded-(--radius-field)',
                        'px-2.5 text-sm whitespace-nowrap transition-colors',
                        'bg-(--color-topnav-selected) font-semibold text-(--color-topnav-ink)' => $isActive,
                        'text-(--color-topnav-ink-muted) hover:bg-(--color-topnav-hover)' => ! $isActive,
                    ])>
                <x-ui.icon :name="$module['icon']" :size="16" />
                <span>{{ $module['label'] }}</span>
            </button>

            {{--
                তালিকাটা পাতার উপরে ভাসে, তাই পাতার রং।

                `pops-onto-page` না দিলে উপরের বারের গাঢ় কালি
                উত্তরাধিকারসূত্রে এখানেও নামত, আর সাদা পাতার উপর একটা
                গাঢ় বাক্স ভেসে থাকত — যেটা কোনো ERP করে না।
            --}}
            <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                 class="pops-onto-page absolute start-0 top-full z-50 mt-1 max-h-[70vh] w-64
                        overflow-y-auto rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) py-1.5 shadow-lg">

                {{-- মাথায় মডিউলের নিজের রং — পাশাপাশি দুইটা মডিউলের
                     তালিকা নাহলে একই রকম দেখাত। --}}
                <div class="flex items-center gap-2 px-3 pb-1.5">
                    <span class="grid size-6 shrink-0 place-items-center rounded-md text-white"
                          style="background: var(--color-module-{{ $module['code'] }}, var(--color-brand-600))"
                          aria-hidden="true">
                        <x-ui.icon :name="$module['icon']" :size="14" />
                    </span>
                    <span class="truncate text-sm font-semibold">{{ $module['label'] }}</span>
                </div>

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
    @endforeach
</nav>
