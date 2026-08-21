{{--
    Tiles — SAP Fiori 3-এর নিজের খোলস।

    ── কী এখানে, আর কী নয় ──────────────────────────────────────────────
    ফিওরির লঞ্চপ্যাডের টালি এই ফাইলে **নেই**, আর সেটা ইচ্ছাকৃত। ওই
    টালিগুলো আর D365-এর শেভরন আর খতিয়ানের ধাপের ঘর — তিনটাই একই জিনিস
    দেখায়: কোন ধাপে কয়টা, কত টাকার। তিনটা আলাদা markup বানালে একই
    ডেটা তিনবার লেখা হত, আর একটা ধাপ যোগ করলে তিন জায়গায় বদলাতে হত।

    তাই ওটা একটাই কম্পোনেন্ট (`x-ui.stage-strip`), আর প্রতিটা রূপ কেবল
    তার চেহারা বদলায় — ফিওরিতে আলাদা টালি, D365-এ জোড়া শেভরন, খতিয়ানে
    বাক্সে ঘেরা ঘর। এখানে থাকে কেবল সেটুকু যা সত্যিই ফিওরির নিজের।
--}}

@if ($region === 'topbar-start')
    {{--
        শেল বার — বাঁয়ে হ্যামবার্গার, তারপর শিরোনাম ও উপশিরোনাম।

        ── কেন দুই লাইন এখানে ঠিক, অথচ কোম্পানির নামে ভুল ছিল ─────────
        কোম্পানি ও শাখা দুই লাইনে বসালে বারটা ৫৬px-এ আটকে যেত, তাই ওটা
        এক লাইনে বন্ধনীতে গেছে।

        কিন্তু ফিওরির শেল বারে শিরোনামের নিচে একটা ১০.৫px উপশিরোনাম
        থাকে — ওটাই তার চিহ্ন, আর ওই মাপে দুই লাইন ৪৪px-এর ভেতরেই ধরে।
        একই সিদ্ধান্ত নয়: একটা তথ্য দুই লাইন দখল করছিল, অন্যটা নকশার
        অংশ যেটা জায়গা চায় না।
    --}}
    @php
        $route = (string) (request()->route()?->getName() ?? '');
        $current = collect($menu)->first(
            fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
        ) ?? collect($menu)->first(
            fn ($m) => str_starts_with($route, $m['code'].'.'),
        ) ?? ($menu[0] ?? null);
    @endphp

    <a href="{{ route('dashboard') }}" data-shell-bar
       class="hidden shrink-0 items-center gap-2 rounded-[4px] px-2
              text-(--color-topbar-ink) transition-colors hover:bg-(--color-topbar-hover) sm:flex">
        <span class="grid size-7 shrink-0 place-items-center rounded-[4px] bg-white/12"
              aria-hidden="true">
            <svg viewBox="0 0 24 24" class="size-4 fill-current">
                <path d="M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z"/>
            </svg>
        </span>

        <span class="min-w-0 text-start leading-tight">
            <span class="block truncate text-sm font-semibold">
                {{ $current['label'] ?? __('core.menu.dashboard') }}
            </span>
            <span class="block truncate text-[10.5px] opacity-75">
                {{ __('core.brand.full_name') }}
            </span>
        </span>
    </a>
@endif
