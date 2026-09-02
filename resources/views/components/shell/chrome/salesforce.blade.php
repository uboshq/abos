{{--
    Salesforce — Lightning Experience-এর নিজের খোলস।

    ── এর আসল চিহ্নটা রঙে নয় ───────────────────────────────────────────
    গাঢ় নেভি মাথা আর নীল বোতাম দেখে অনেক ERP-ই চেনা যায়। যেটা কেবল
    Salesforce-এরই আছে, সেটা হলো পর্দার **একদম নিচে সাঁটা একটা বার** —
    ইউটিলিটি বার। এখানকার আর কোনো রূপে ওটা নেই, আর ওটা তুলে নিলে
    বাকিটা কেবল "একটা নেভি ERP"।

    সাথে অ্যাপ লঞ্চার (২×২ গোল চারকোনা, ড্রপডাউন — পুরো পর্দা নয়,
    কারণ Salesforce-এরটা ড্রপডাউনই)।
--}}

@if ($region === 'topbar-start')
    {{--
        অ্যাপ লঞ্চার — ২×২, ৯ ফোঁটা নয়।

        ── কেন Odoo-র সাথে গুলিয়ে যায় না ───────────────────────────
        Odoo-র লঞ্চারও উপরে-বাঁয়ে বসে, কিন্তু ওটা ৯টা ছোট ফোঁটা আর
        খুললে **পুরো পর্দা** ভরে যায়। Salesforce-এরটা ৪টা গোল চারকোনা,
        আর খুললে একটা ছোট ড্রপডাউন প্যানেল — পাতাটা পেছনে দেখা যায়।

        দুইটা আলাদা markup, ইচ্ছাকৃতভাবে: এক CSS পৃষ্ঠতলে বসালে একটা
        ঠিক করতে গিয়ে অন্যটা ভাঙত।
    --}}
    <details data-sf-launcher class="relative shrink-0">
        <summary class="flex cursor-pointer list-none items-center rounded-[4px] px-2 py-1
                        text-(--color-topbar-ink) transition-colors
                        hover:bg-(--color-topbar-hover)"
                 title="{{ __('core.ui.launcher_search') }}">
            <svg viewBox="0 0 24 24" class="size-(--spacing-icon) fill-current" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7" rx="1.5"/>
                <rect x="14" y="3" width="7" height="7" rx="1.5"/>
                <rect x="3" y="14" width="7" height="7" rx="1.5"/>
                <rect x="14" y="14" width="7" height="7" rx="1.5"/>
            </svg>
        </summary>

        <div class="pops-onto-page absolute start-0 top-full z-50 mt-1 w-80 rounded-[4px]
                    border border-(--color-border) bg-(--color-surface-card) p-2 shadow-lg">
            <div class="grid grid-cols-3 gap-1">
                @foreach ($menu as $module)
                    @php
                        // মডিউলের নিজের কোনো ঠিকানা নেই — তার প্রথম
                        // পর্দাটাই তার দরজা (Odoo-র লঞ্চারও তাই করে)
                        $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);
                    @endphp
                    <a @if ($first) href="{{ $first['url'] }}" @endif
                       class="flex flex-col items-center gap-1 rounded-[4px] px-2 py-3 text-center
                              text-2xs text-(--color-ink) transition-colors
                              hover:bg-(--color-surface-hover)">
                        <x-ui.icon :name="$module['icon']" :size="20" />
                        <span class="line-clamp-2">{{ $module['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </details>

    <a href="{{ route('dashboard') }}" data-sf-app
       class="hidden shrink-0 items-center gap-2 pe-2 text-(--color-topbar-ink) sm:flex"
       title="{{ __('core.brand.full_name') }}">
        <span class="grid size-6 shrink-0 place-items-center overflow-hidden rounded-[2px] bg-white">
            <img src="{{ asset('brand/abos-icon-transparent.png') }}" alt=""
                 aria-hidden="true" class="size-5 object-contain">
        </span>
        <span class="text-sm font-bold">{{ __('core.brand.name') }}</span>
    </a>
@endif


@if ($region === 'page-head')
    {{--
        চলতি অ্যাপের ট্যাব সারি।

        ── কেন উপরের মেনুটাই যথেষ্ট নয় ─────────────────────────────
        উপরের সারিটা মডিউল বদলায় — Accounts থেকে Sales। Salesforce-এ
        তার **নিচে** আরেকটা সারি থাকে: চলতি অ্যাপের ভেতরের পর্দাগুলো,
        চলতিটার নিচে একটা নীল দাগ।

        ওই দুই-স্তরের গড়নটাই Lightning চেনার সবচেয়ে বড় সূত্র, আর
        সেটা কেবল রং দিয়ে আনা যায় না।
    --}}
    @php
        $route = (string) (request()->route()?->getName() ?? '');
        $current = collect($menu)->first(
            fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
        ) ?? collect($menu)->first(
            fn ($m) => str_starts_with($route, $m['code'].'.'),
        ) ?? ($menu[0] ?? null);

        $tabs = $current
            ? collect($current['groups'])->flatten(1)->take(8)
            : collect();
    @endphp

    @if ($tabs->count() > 1)
        <nav data-sf-apptabs aria-label="{{ $current['label'] }}"
             class="flex gap-1 overflow-x-auto border-b border-(--color-topnav-border)
                    bg-(--color-topnav) px-3 md:px-5">
            @foreach ($tabs as $tab)
                <a href="{{ $tab['url'] }}"
                   @class([
                       'shrink-0 border-b-2 px-3 py-2 text-xs font-medium transition-colors',
                       'border-(--color-brand-500) text-(--color-brand-500)' => $tab['active'] ?? false,
                       'border-transparent text-(--color-topnav-ink) hover:bg-(--color-topnav-hover)'
                            => ! ($tab['active'] ?? false),
                   ])>
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>
    @endif
@endif


@if ($region === 'overlay')
    {{--
        ইউটিলিটি বার — পর্দার একদম নিচে সাঁটা।

        ── কেন এটাই Salesforce-এর আসল চিহ্ন ────────────────────────
        আর কোনো রূপে নিচে সাঁটা বার নেই। Lightning-এ ওটা সবসময় থাকে,
        প্রতিটা পর্দায়, আর ওখান থেকেই মানুষ বিজ্ঞপ্তি ও অনুমোদনে যান
        — পাতা না ছেড়ে।

        নতুন কোনো ফিচার এখানে বানানো হয়নি: তিনটাই আগে থেকে থাকা
        পর্দা, কেবল Salesforce-এর নিজের জায়গা থেকে পৌঁছানো।
    --}}
    @php
        /*
         * তিনটাই আগে থেকে থাকা পর্দা, আর তিনটাই অনুমতিতে বাঁধা।
         *
         * নতুন কোনো ফিচার এখানে বানানো হয়নি — Salesforce-এর নিজের
         * জায়গা থেকে পৌঁছানোর ব্যবস্থা, ব্যস। অনুমতি না মিললে
         * লিংকটাই বসে না; বসালে ক্লিক করে ৪০৩ পেতেন, আর মনে হত
         * ব্যবস্থাটা ভাঙা।
         */
        $tools = collect([
            ['route' => 'approval.inbox.mine', 'can' => 'approval.view',
             'icon' => 'inbox', 'label' => __('approval::menu.mine')],
            ['route' => 'approval.inbox.index', 'can' => 'approval.decide',
             'icon' => 'check-circle', 'label' => __('approval::menu.inbox')],
            ['route' => 'governance.audit.index', 'can' => 'governance.audit.view',
             'icon' => 'clock', 'label' => __('governance::menu.audit_trail')],
        ])->filter(fn ($t) => \Illuminate\Support\Facades\Route::has($t['route'])
            && auth()->user()?->can($t['can']));
    @endphp

    @if ($tools->isNotEmpty())
        <nav data-sf-utilitybar aria-label="{{ __('core.brand.name') }}"
             class="no-print fixed inset-x-0 bottom-0 z-30 hidden items-center gap-4
                    border-t border-(--color-footer-border) bg-(--color-footer)
                    px-4 py-1.5 md:flex">
            @foreach ($tools as $item)
                <a href="{{ route($item['route']) }}"
                   class="inline-flex items-center gap-1.5 text-2xs font-semibold
                          text-(--color-footer-ink) transition-colors hover:text-white">
                    <x-ui.icon :name="$item['icon']" class="size-4" />
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif
@endif
