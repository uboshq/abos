@props([
    'menu' => [],

    /**
     * সরু পর্দার জন্য — `lg`-এর নিচে দেখা যায়, উপরে নয়।
     *
     * ── কেন এই সুইচটা লাগল ───────────────────────────────────────────
     * `quiet` কঙ্কালে (Linear) উপরে কোনো বার থাকে না — সেটাই ওর পরিচয়।
     * কিন্তু সাইডবারটা `lg:flex`, অর্থাৎ ১০২৪px-এর নিচে সে-ও নেই, আর
     * নিচের bottom-nav `md:hidden`, অর্থাৎ ৭৬৮px-এর উপরে সে-ও নেই।
     *
     * ফলে বারটা তুলে নেওয়ার পর **৭৬৮ থেকে ১০২৩px-এ কোনো নেভিগেশনই
     * থাকত না** — মেনুর একশোটা লিংক DOM-এ থাকত, সবগুলো লুকানো
     * সাইডবারের ভেতরে। ধরা পড়েছে চারটা প্রস্থে মেপে, ১৪৪০px-এ দেখে নয়।
     *
     * Linear নিজেও সরু পর্দায় একটা হেডার দেখায়, তাই এটা নকলের সাথে
     * বিরোধ নয় — বরং নকলটা এখন সব প্রস্থেই সত্যি।
     */
    'narrow' => false,

    /**
     * ⭐ বারটা রেলের মাথার উপর দিয়ে যায় — পুরো চওড়া বার (`rail` রূপ; মালিক, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ তখন "≡" ঠিক রেলের কলামে বসে (একই `--spacing-sidebar-icon` প্রস্থ, মাঝখানে), আর তার ডানে ⊞ সব মডিউল।
     * ⛔ মালিকের ছবিতে "≡" অর্ধেক রেলের উপর, অর্ধেক বারের উপর — বারের নিজের ২০px বাঁ-ফাঁক তাকে রেলের মাঝ থেকে সরিয়ে দিত।
     */
    'overRail' => false,
])

{{--
    টপ নেভিগেশন — সেকশন ১৫.১।

    কোম্পানি+শাখা │ Search │ ⛶ │ ভাষা │ ব্যবহারকারী+রোল

    কোম্পানির ব্লকটা বাঁয়ে, ডানে নয়।

    আগে ডানে ছিল, আর তাতে দুইটা সমস্যা হত। এক, পড়ার ক্রম উল্টো: বাঁ
    থেকে ডানে পড়া চোখ প্রথমেই "আমি কোন প্রতিষ্ঠানের হয়ে কাজ করছি" জানতে
    চায়, আর সেটা পাওয়া যেত সবার শেষে। দুই, ডান কোণে কোম্পানি ও
    ব্যবহারকারী পাশাপাশি বসত — দুইটা আলাদা প্রশ্নের উত্তর একই জায়গায়
    জট পাকিয়ে থাকত।

    এখন বাঁয়ে "কোথায় কাজ করছি", ডানে "আমি কে"।
--}}
@php
    $user = auth()->user();
    $company = $user?->currentCompany;
    $branch = $user?->currentBranch;

    /*
     * ⭐ বাঁ কোণের চিহ্ন — চলতি কোম্পানির নিজের লোগো, ABOS-এর নয় (মালিক, ২১ অক্টোবর ২০২৬)।
     *
     * ⓘ অনুরোধপ্রতি পড়া হয়, তাই কোম্পানি বদলালে পরের পাতাতেই নতুন লোগো। লোগো না থাকলে নামের দুই শব্দের প্রথম অক্ষর
     * ("আল-আমিন কর্পোরেশন" → "আক") কোম্পানির নিজের রঙের গোল ব্যাজে — রংটা id ধরে ছয়টার একটা, তাই একই কোম্পানি
     * সবসময় একই রঙে। ⓘ অক্ষর মানে একটা গোটা গ্রাফিম (`\X`), বাইট নয়; হসন্তে শেষ হলে পরেরটাও, নইলে "ক্" একা ঝুলত।
     * ⓘ ABOS-এর চিহ্ন রেলের মাথায়, হোমের বোতাম হয়ে ([[shell.sidebar]])।
     */
    $brandName = $company?->name() ?? 'ABOS';
    $brandLogo = $company?->logoUrl();
    $brandInitials = '';
    foreach (array_slice(preg_split('/\s+/u', trim($brandName), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2) as $word) {
        preg_match_all('/\X/u', $word, $graphemes);
        $letter = $graphemes[0][0] ?? '';
        if (str_ends_with($letter, "\u{09CD}") && isset($graphemes[0][1])) {
            $letter .= $graphemes[0][1];
        }
        $brandInitials .= mb_strtoupper($letter);
    }
    $brandTone = $company ? ((int) $company->id - 1) % 6 + 1 : 1;
@endphp

{{-- `data-topbar` — পাহারার জন্য, নকশার জন্য নয়।

     [[AClonePromisedAShapeAndDrewAnotherTest]] প্রতিটা রূপে মিলিয়ে দেখে
     ঘোষিত কঙ্কালটাই আঁকা হচ্ছে কি না। বারটা আছে কি নেই সেটা জানতে
     পর্দায় ধরার মতো একটা চিহ্ন লাগে, আর ক্লাস দিয়ে সেটা করা যায় না:
     ক্লাস নকশার সাথে বদলায়, আর তখন পাহারাটাও নিঃশব্দে অন্ধ হয়ে যেত।

     মানটাও দরকার — `full` না `narrow` — কারণ `quiet` রূপে বারটা
     থাকে, কিন্তু কেবল সরু পর্দায়। শুধু "আছে কি নেই" দেখলে পাহারাটা
     ওই দুইটার তফাত করতে পারত না। --}}
<header data-topbar="{{ $narrow ? 'narrow' : 'full' }}"
        @class([
            'topbar sticky top-0 z-30 flex h-(--spacing-header) shrink-0 items-center gap-2',
            'border-b border-(--color-topbar-border) bg-(--color-topbar) px-3 md:px-5',
            'md:ps-0' => $overRail,
            'lg:hidden' => $narrow,
        ])>

    {{-- রূপের নিজের অংশ — Odoo-র লঞ্চার, D365-এর ওয়াফল, ইত্যাদি।
         যে রূপের কিছু নেই তার জন্য এটা কিছুই আঁকে না। --}}
    <x-shell.chrome region="topbar-start" :menu="$menu ?? []" />

    {{--
        ⭐ SAP Fiori shell bar — ওয়েবের মান ধাপ ২ (মালিকের আদেশ "100% int. standard", ২০ অক্টোবর ২০২৬; নকশা:
        E:\ABOS\মালিকের-ফাইল\ওয়েবের-চেহারা-ভুল-ও-পরিকল্পনা-২০-অক্টোবর.pdf, পাতা ৩)।

        বাঁ থেকে ডানে, আর কিছু নয়:  [≡] ⊞ লোগো · কোম্পানি · শাখা ▾ │ একটাই খোঁজা │ ＋ তৈরি · 🔔 · ছবি

        ⛔ আগে বারে দশটার বেশি জিনিস ছিল (⊞ লঞ্চার, তৈরি, সবুজ টিক, কাগজ, 🔔, পূর্ণপর্দা, EN, 🌙, কোম্পানি, ⇄, ছবি) — Fiori-র
        বারে থাকে কেবল পরিচয়, খোঁজা আর কয়েকটা কাজ। ⓘ কিছুই হারায়নি, জায়গা বদলেছে, অ্যাপের বারের এক নিয়মে:
          · আমার সইয়ের অপেক্ষা বারেই থাকে, ＋ তৈরি আর 🔔-এর মাঝে, কেবল সইদাতার (মালিক, ২০ অক্টোবর); "সই হয়েছে, পোস্ট হয়নি" → ঘণ্টার প্যানেলের মাথায় "আপনার কাজ";
          · ভাষা, রং (ফোনের মতো · আলো · অন্ধকার), পূর্ণপর্দা, কোম্পানি বদল → ছবির মেনুতে;
          · মডিউলের লঞ্চার → ⊞ আবার বারে, "≡"-এর ডানে (মালিক, ২১ অক্টোবর ২০২৬: Odoo-র হোমের মতো পুরো পর্দা); রেলেও সব মডিউল;
          · চলতি মডিউলের নাম → মডিউল-প্যানেলের মাথায় আর পাতার পথে।
        ⓘ কোম্পানির সাথে শাখা সবসময় ([[shell.company-switcher]], মালিকের স্থায়ী নিয়ম)।
    --}}
    {{-- মেনু গুটানো-খোলার একমাত্র সুইচ — দুই অবস্থাতেই একই জায়গায় ($store.sidebar; [[AListenerNobodyEverBoundTest]]) --}}
    @if ($overRail)
        {{--
            ⭐ "≡" রেলের কলামেই — মালিক, ২১ অক্টোবর ২০২৬ (ছবিতে বোতামটা অর্ধেক রেলে, অর্ধেক বারে)।

            ঘরটা রেলের সমান: একই `w-(--spacing-sidebar-icon)`, একই `border-e` (স্বচ্ছ — দেখা যায় না, কেবল মাপ মেলায়), আর
            ভেতরে মাঝখানে; রেলের টাইলগুলোও ঠিক তাই ([[shell.sidebar]]-এর `nav`)। ⓘ ফলে "≡"-এর মাঝ আর রেলের আইকনের মাঝ
            একই x-এ, যে রূপ রেলের প্রস্থ যা-ই বসাক। বোতাম ৪০×৪০ (টাচ), আইকন ২২px, ঘষা পড়লে গোল হালকা জমিন।
            ⓘ বারের রং পুরো চওড়ায়, রেলের রং বারের নিচ থেকে — কোণে দুই রং কাটাকাটি করে না।
            ⓘ ফোনে রেল নেই (নিচের নেভিগেশন), তাই "≡"-ও নেই — কাজহীন বোতাম রাখা হয় না; ⊞ আর লোগো একই সারিতে থাকে।
        --}}
        <div data-menu-cell
             class="hidden w-(--spacing-sidebar-icon) shrink-0 items-center justify-center border-e border-transparent md:flex">
            <button type="button"
                    x-data
                    data-menu-toggle
                    @click="$store.sidebar.toggle()"
                    class="flex size-[40px] shrink-0 items-center justify-center rounded-full text-(--color-topbar-ink-muted)
                           transition-colors hover:bg-(--color-topbar-hover) hover:text-(--color-topbar-ink)
                           focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-brand-500)"
                    style="--topbar-control-h: 40px"
                    aria-label="{{ __('core.a11y.menu') }}"
                    title="{{ __('core.a11y.menu') }}"
                    :aria-expanded="$store.sidebar.collapsed ? 'false' : 'true'">
                <x-ui.icon name="list" :size="22" />
            </button>
        </div>
    @else
        <button type="button"
                x-data
                @click="$store.sidebar.toggle()"
                class="hidden size-9 shrink-0 items-center justify-center rounded-(--radius-field)
                       border border-(--color-topbar-border) text-(--color-topbar-ink-muted) transition-colors
                       hover:bg-(--color-topbar-hover) hover:text-(--color-topbar-ink) md:flex"
                aria-label="{{ __('core.a11y.collapse_sidebar') }}"
                :aria-expanded="$store.sidebar.collapsed ? 'false' : 'true'"
                :aria-label="$store.sidebar.collapsed
                    ? '{{ __('core.a11y.expand_sidebar') }}'
                    : '{{ __('core.a11y.collapse_sidebar') }}'">
            <x-ui.icon name="list" :size="18" />
        </button>
    @endif

    {{-- ⭐ ⊞ · লোগো · কোম্পানি · শাখা ▾ — এক সারিতে, ৩৭৫ থেকে ১৯২০px (মালিক, ২১ অক্টোবর ২০২৬)।
         ⓘ "≡"-এর কিনারা থেকে ঠিক ১৪px: ঘরের ভেতরে "≡"-এর ডানে (রেল − ৪০px) ÷ ২ ফাঁকা, তার পরে বারের `gap-2` — দুইটা বাদ দিয়ে বাকিটা। --}}
    <div data-shell-start @class(['flex min-w-0 shrink items-center gap-[12px] max-sm:gap-0',
                                  'md:ms-[calc(14px_-_0.5rem_-_(var(--spacing-sidebar-icon)_-_40px)_/_2)]' => $overRail])>
        {{-- ⭐ ⊞ সব মডিউল — Odoo-র হোমের মতো পুরো পর্দা (মালিক, ২১ অক্টোবর ২০২৬; Google · M365 · Fiori সবারই আছে)।
             ⓘ টাইল রেলের একই তালিকা থেকে ($menu: অনুমতি আর মডিউলের সুইচ দুইটাই দেখা), তাই রেলে যা নেই লঞ্চারেও নেই। --}}
        @if ($overRail)
            <x-shell.launcher :menu="$menu ?? []" />
        @endif

        {{-- ⭐ গ্রাহকের ব্র্যান্ড — চলতি কোম্পানির লোগো, চাপলে হোম; লোগো না থাকলে আদ্যক্ষরের ব্যাজ (উপরের `@php`)।
             মাপ: উচ্চতা ২৮px (৩২px পাতের ভেতরে), চওড়া বড়জোর ১৪০px, `object-contain` — চওড়া লোগো ছোট হয়, ভাঙে না।
             ⓘ ফোনে (sm-এর নিচে) বড়জোর ৫৬px: ৩৭৫px-এ ডানের কাজগুলোর পরে লোগো আর খোঁজার আইকনের জন্য ~১০৭px থাকে; ৬:১ লোগো
             ১৪০px নিলে খোঁজার আইকনটা লোগোর উপর চেপে বসত (ছবিতে দেখা, ২১ অক্টোবর ২০২৬)।
             লোগোর পেছনে হালকা পাত (`--color-company-logo-plate`) — অন্ধকারে, আর আলোতেও, কারণ এই রূপের বার আলোতেও গাঢ়।
             ⛔ আগে এখানে ABOS-এর চিহ্ন, আর কোম্পানির লোগো সুইচারের ভেতরে — এখন লোগো একবারই, এখানে; সুইচারে কেবল নাম। --}}
        <a href="{{ route('dashboard') }}" data-shell-logo data-shell-brand="{{ $company?->id }}"
           class="flex shrink-0 items-center rounded-(--radius-field)
                  focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-brand-500)"
           title="{{ $brandName }}"
           @unless ($brandLogo) aria-label="{{ $brandName }}" @endunless>
            @if ($brandLogo)
                <span class="flex h-[32px] max-w-[140px] items-center rounded-[6px] bg-(--color-company-logo-plate) px-[4px] max-sm:max-w-[56px]">
                    <img src="{{ $brandLogo }}" alt="{{ $brandName }}" data-company-logo
                         class="h-[28px] w-auto max-w-[132px] object-contain max-sm:max-w-[48px]">
                </span>
            @else
                <span data-company-initials aria-hidden="true"
                      class="grid size-[32px] shrink-0 place-items-center rounded-full text-[13px] font-bold leading-none"
                      style="background: var(--color-company-mark-{{ $brandTone }}); color: var(--color-company-mark-ink)">{{ $brandInitials }}</span>
            @endif
        </a>

        {{-- কোম্পানি · শাখা ▾ — লোগোর ঠিক পাশে, সব মাপে (ফোনে নাম ছোট হয়ে কাটে, বার নয়); লোগো বাঁয়ের চিহ্নেই, তাই এখানে নয় --}}
        @if ($company)
            <div class="min-w-0 shrink" data-shell-company>
                <x-shell.company-switcher :company="$company" :branch="$branch" beside-mark />
            </div>
        @endif
    </div>

    {{-- একটাই খোঁজা — মাঝে, চওড়া; পাতা, মেনু, গ্রাহক, পণ্য আর কাগজের নম্বর সবই এখানে ([[SearchController::pagesMatching()]])।
         "/" বা Ctrl+K চাপলেও খোলে ([[commandCenter]])। --}}
    {{-- ⭐ খোঁজার ঘর নিজেই লেখার ঘর, ফল তার ঠিক নিচে (Fiori shell bar search; লাইভ-হটফিক্স, ২১ অক্টোবর ২০২৬)।
         ⛔ আগে এটা একটা বোতাম ছিল যা পুরো পাতার উপর আলাদা প্যালেট খুলত — নিজের দ্বিতীয় "যেকোনো কিছু খুঁজুন" ঘরসহ, আর চওড়া
         পর্দায় সেটা বাঁয়ে রেল-সাইডবারের উপর বসত (মালিকের স্ক্রিনশট, ১৬৮৭px)। এখন একটাই ঘর; Ctrl+K আর "/" এই ঘরেই আনে।
         ⓘ যুক্তি shell.js-এর commandCenter-এ (CSP-Alpine অ্যাট্রিবিউটে পদ্ধতি পড়ে না)। ⓘ ফোনে কেবল আইকন — চাপলে পুরো-প্রস্থে নামে। --}}
    <div x-data="commandCenter({ url: '{{ route('search') }}' })"
         data-shell-search :data-expanded="expandedFlag"
         @open-command-center.window="show()"
         @keydown.window="hotkey($event)"
         @click.outside="close()"
         class="relative mx-auto flex min-w-0 flex-1 justify-end sm:max-w-xl sm:justify-start">

        <button type="button" data-search-icon @click="show()"
                aria-label="{{ __('core.action.search_everything') }}"
                class="flex size-(--spacing-touch) shrink-0 items-center justify-center rounded-(--radius-field)
                       text-(--color-topbar-ink) hover:bg-(--color-topbar-hover) sm:hidden">
            <svg viewBox="0 0 24 24" class="size-(--spacing-icon) fill-current" aria-hidden="true">
                <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
            </svg>
        </button>

        <label data-search-field
               class="flex min-h-(--spacing-touch) w-full min-w-0 items-center gap-2 rounded-(--radius-field)
                      border border-(--color-topbar-border) bg-(--color-topbar-field) px-3
                      text-(--color-topbar-ink-muted) transition-colors focus-within:border-(--color-topbar-ink-muted)
                      hover:border-(--color-topbar-ink-muted)">
            <svg viewBox="0 0 24 24" class="size-(--spacing-icon) shrink-0 fill-current" aria-hidden="true">
                <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
            </svg>
            <input x-ref="box" x-model="q" type="search" autocomplete="off" enterkeyhint="search"
                   @focus="show()" @input="ask()"
                   @keydown.down.prevent="next()"
                   @keydown.up.prevent="prev()"
                   @keydown.enter.prevent="choose($event)"
                   @keydown.escape.stop="dismiss()"
                   role="combobox" aria-autocomplete="list" aria-haspopup="listbox"
                   aria-label="{{ __('core.action.search_everything') }}"
                   aria-controls="command-hits" :aria-expanded="open"
                   :aria-activedescendant="activeId"
                   aria-keyshortcuts="/ Control+K"
                   placeholder="{{ __('core.action.search_everything') }}"
                   class="min-w-0 flex-1 border-0 bg-transparent text-sm text-(--color-topbar-ink) outline-none
                          placeholder:text-(--color-topbar-ink-muted)">
            <span x-show="busy" x-cloak class="text-xs" aria-hidden="true">…</span>
            <kbd class="ms-auto hidden rounded border border-(--color-topbar-border) px-1.5 text-xs md:inline" aria-hidden="true">/</kbd>
        </label>

        {{-- ⓘ ফল — ঘরের ঠিক নিচে, বাঁ কিনারা মিলিয়ে, প্রস্থ ঘরের সমান (অন্তত ৪৮০px); ডানে ছাড়ালে place() ভেতরে আনে।
             `pops-onto-page` — বারের ৩৬px বোতাম-উচ্চতার নিয়ম এখানে নয়। ⓘ জ্যামিতি inline: ক্লাস নীরবে না এলে গড়ন ভাঙত। --}}
        <div x-ref="panel" x-show="open" x-cloak data-search-panel
             class="pops-onto-page"
             style="position:absolute;top:calc(100% + 4px);left:0;z-index:60;width:max(100%, 480px);max-width:calc(100vw - 16px);
                    border-radius:12px;overflow:hidden;border:1px solid var(--color-border);background:var(--color-surface-card);
                    box-shadow:0 20px 60px color-mix(in srgb, black 25%, transparent)">
            <div style="max-height:60vh;overflow-y:auto">
                <template x-if="hint">
                    <p style="padding:16px;font-size:13px;color:var(--color-ink-muted)">{{ __('core.search.type_to_find') }}</p>
                </template>
                <template x-if="nothingFound">
                    <p style="padding:16px;font-size:13px;color:var(--color-ink-muted)">{{ __('core.search.nothing_found') }}</p>
                </template>

                <div id="command-hits" role="listbox" aria-label="{{ __('core.action.search_everything') }}">
                    <div x-show="recentList.length > 0" role="group" aria-labelledby="command-recent-label">
                        <p id="command-recent-label"
                           style="margin:0;padding:8px 14px 4px;font-size:11px;font-weight:600;color:var(--color-ink-muted)">{{ __('core.search.recent') }}</p>
                        <template x-for="(hit, i) in recentList" :key="hit.url">
                            <a :href="hit.url" :id="hitId(i)" role="option" :aria-selected="isActive(i) ? 'true' : 'false'"
                               @mousemove="point(i)"
                               class="hover:bg-(--color-surface-muted) aria-selected:bg-(--color-surface-muted)"
                               style="display:flex;align-items:baseline;gap:10px;padding:10px 14px;border-bottom:1px solid var(--color-border);text-decoration:none">
                                <span style="flex:none;font-size:11px;color:var(--color-ink-muted)" x-text="hit.type"></span>
                                <span style="flex:none;font-size:12px;color:var(--color-ink-muted)" x-text="hit.no"></span>
                                <span style="flex:1;font-size:13px;color:var(--color-ink-body)" x-text="hit.label"></span>
                            </a>
                        </template>
                    </div>

                    <div x-show="actionList.length > 0" role="group" aria-labelledby="command-actions-label">
                        <p id="command-actions-label"
                           style="margin:0;padding:8px 14px 4px;font-size:11px;font-weight:600;color:var(--color-ink-muted)">{{ __('core.search.actions') }}</p>
                        <template x-for="(act, j) in actionList" :key="act.url">
                            <a :href="act.url" :id="actionId(j)" role="option" :aria-selected="isActionActive(j) ? 'true' : 'false'"
                               @mousemove="pointAction(j)"
                               class="hover:bg-(--color-surface-muted) aria-selected:bg-(--color-surface-muted)"
                               style="display:flex;align-items:baseline;gap:10px;padding:10px 14px;border-bottom:1px solid var(--color-border);text-decoration:none">
                                <span style="flex:none;font-size:11px;color:var(--color-ink-muted)" x-text="act.type"></span>
                                <span style="flex:1;font-size:13px;color:var(--color-ink-body)" x-text="act.label"></span>
                            </a>
                        </template>
                    </div>

                    <template x-for="(hit, i) in hitList" :key="hit.url">
                        <a :href="hit.url" :id="hitId(i)" role="option" :aria-selected="isActive(i) ? 'true' : 'false'"
                           @mousemove="point(i)"
                           class="hover:bg-(--color-surface-muted) aria-selected:bg-(--color-surface-muted)"
                           style="display:flex;align-items:baseline;gap:10px;padding:10px 14px;border-bottom:1px solid var(--color-border);text-decoration:none">
                            <span style="flex:none;font-size:11px;color:var(--color-ink-muted)" x-text="hit.type"></span>
                            <span style="flex:none;font-size:12px;color:var(--color-ink-muted)" x-text="hit.no"></span>
                            <span style="flex:1;font-size:13px;color:var(--color-ink-body)" x-text="hit.label"></span>
                        </a>
                    </template>
                </div>
            </div>
        </div>
    </div>

    {{-- ডানে কেবল এগুলো: কাজ শুরু (＋ তৈরি), আমার সইয়ের অপেক্ষা (সইদাতা হলে), সই হয়েছে-পোস্ট হয়নি, কী ঘটেছে (🔔), আর আমি কে (ছবি) --}}
    <div class="flex shrink-0 items-center gap-1" data-shell-actions>
        <x-shell.create-menu />
        {{-- ⭐ অনুমোদন — ＋ তৈরি আর 🔔-এর মাঝে, কেবল সইদাতার বারে (মালিক, ২০ অক্টোবর ২০২৬) --}}
        <x-shell.approval-quick-access />
        {{-- ⭐ সই হয়েছে, পোস্ট হয়নি — অনুমোদনের ঠিক পাশে, সংখ্যার ব্যাজসহ (মালিকের নিজের আইকন, ১১ অক্টোবর; লাইভ-হটফিক্স ২১ অক্টোবর
             ২০২৬: ঘণ্টার ভেতরে লুকিয়ে ছিল, মালিক বারে খুঁজে পাননি)। শূন্যে ব্যাজ নেই, আইকন থাকে। ঘণ্টার প্যানেলেরটাও থাকে। --}}
        <x-shell.signed-unposted bar />
        <x-shell.notification-bell />
        <x-shell.profile-menu :user="$user" :company="$company" :branch="$branch" />
    </div>
</header>
