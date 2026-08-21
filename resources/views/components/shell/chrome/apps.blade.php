{{--
    Apps — Odoo-র নিজের খোলস।

    টোকেন দিয়ে যা হয় না, সেই টুকরোগুলো এখানে। প্রতিটার সাথে
    `data-*` চিহ্ন আছে, আর `tools/theme-parts.py` ওগুলো ধরেই প্রমাণ
    করে জিনিসটা সত্যিই পর্দায় আছে — নাহলে যাচাই লাল হয়।

    অঞ্চল অনুযায়ী কেবল চাওয়া টুকরোটাই আঁকা হয়।
--}}

@if ($region === 'topbar-start')
    {{--
        ৯-ফোঁটার লঞ্চার — ওডুর সবচেয়ে চেনা বোতাম।

        ── কেন এটা মেনু নয়, শিট ────────────────────────────────────
        ওডুতে এটা ড্রপডাউন নয়। চাপলে **গোটা পর্দা** ঢেকে অ্যাপের টালি
        আসে, উপরে একটা খোঁজার ঘর। কারণটা কাজের: একজন মানুষ দিনে
        দুই-তিনবার অ্যাপ বদলান, আর তখন তাঁর সামনে কেবল অ্যাপগুলোই
        থাকা উচিত — নিচের তালিকা নয়, যেটা তিনি এইমাত্র ছেড়ে যাচ্ছেন।

        ড্রপডাউন করলে দেখতে ওডুর মতো লাগত, কাজ করত অন্যরকম।
    --}}
    <button type="button" data-app-launcher
            x-data
            @click="$dispatch('open-launcher')"
            class="grid size-9 shrink-0 place-items-center rounded-(--radius-field)
                   text-(--color-topbar-ink) transition-colors hover:bg-(--color-topbar-hover)"
            aria-label="{{ __('core.ui.launcher') }}">
        <span class="grid grid-cols-3 gap-[3px]" aria-hidden="true">
            @for ($i = 0; $i < 9; $i++)
                <span class="block size-[3px] rounded-[1px] bg-current"></span>
            @endfor
        </span>
    </button>

    {{-- চলতি অ্যাপের নাম — বেগুনি বারে, লঞ্চারের ঠিক পাশে।
         ওডুতে এটাই বলে "আপনি কোথায়", আর ব্রেডক্রাম্ব বলে
         "এই অ্যাপের ভেতরে কোথায়"। দুইটা আলাদা প্রশ্ন। --}}
    @php
        $route = (string) (request()->route()?->getName() ?? '');
        $current = collect($menu)->first(
            fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
        ) ?? collect($menu)->first(
            fn ($m) => str_starts_with($route, $m['code'].'.'),
        ) ?? ($menu[0] ?? null);
    @endphp

    @if ($current)
        <span data-app-menu
              class="hidden shrink-0 text-sm font-semibold text-(--color-topbar-ink) sm:block">
            {{ $current['label'] }}
        </span>
    @endif
@endif


@if ($region === 'overlay')
    {{--
        লঞ্চারের শিট — পুরো পর্দা।

        ── কেন Alpine, `<details>` নয় ──────────────────────────────
        শেলের বাকি মেনুগুলো `<details>`, কারণ ওগুলো JavaScript ছাড়াই
        খোলে। এটা পারে না: শিটটা টপবারের **বাইরে** বসে (নাহলে সে
        টপবারের উচ্চতায় আটকা পড়ত), আর বোতামটা টপবারের ভেতরে। দুইটা
        আলাদা ডালে, তাই একটা ঘটনা লাগে।
    --}}
    <div data-launcher-sheet
         x-data="{ open: false, q: '' }"
         @open-launcher.window="open = true; $nextTick(() => $refs.q?.focus())"
         @keydown.escape.window="open = false"
         x-show="open" x-cloak
         x-transition.opacity.duration.120ms
         class="fixed inset-x-0 bottom-0 top-(--spacing-header) z-40 overflow-y-auto
                bg-(--color-surface-card) p-4 md:p-8"
         role="dialog" aria-modal="true">

        {{-- খোঁজার ঘরটা মাঝখানে ও চওড়ায় সীমিত — ওডুতেও তাই।
             বারোটা অ্যাপে খোঁজার দরকার হয় না; ষাটটায় হয়, আর তখন
             ঘরটা যেন আগে থেকেই সেখানে থাকে। --}}
        <div class="mx-auto max-w-lg">
            <label class="flex items-center gap-2 rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-muted) px-3 py-2">
                <svg viewBox="0 0 24 24" class="size-(--spacing-icon) shrink-0 fill-current
                                                text-(--color-ink-muted)" aria-hidden="true">
                    <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
                </svg>
                <input type="text" data-launcher-search x-ref="q" x-model="q"
                       placeholder="{{ __('core.ui.launcher_search') }}"
                       class="w-full bg-transparent text-sm outline-none">
            </label>
        </div>

        <div class="mx-auto mt-8 grid max-w-4xl grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
            @foreach ($menu as $module)
                @php
                    $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);
                @endphp
                <a @if ($first) href="{{ $first['url'] }}" @endif
                   x-show="!q || '{{ Str::lower($module['label']) }}'.includes(q.toLowerCase())"
                   class="flex flex-col items-center gap-2 rounded-(--radius-card) p-3
                          text-center transition-colors hover:bg-(--color-surface-hover)">
                    <span class="grid size-11 place-items-center rounded-(--radius-card) text-white"
                          style="background: var(--color-module-{{ $module['code'] }}, var(--color-brand-600))"
                          aria-hidden="true">
                        <x-ui.icon :name="$module['icon']" :size="22" />
                    </span>
                    <span class="text-2xs font-semibold">{{ $module['label'] }}</span>
                </a>
            @endforeach
        </div>

        <button type="button" @click="open = false"
                class="fixed end-4 top-[calc(var(--spacing-header)+1rem)] grid size-9
                       place-items-center rounded-(--radius-field) text-(--color-ink-muted)
                       hover:bg-(--color-surface-hover)"
                aria-label="{{ __('core.action.close') }}">
            <svg viewBox="0 0 24 24" class="size-5 fill-current" aria-hidden="true">
                <path d="m12 10.6 5.3-5.3 1.4 1.4-5.3 5.3 5.3 5.3-1.4 1.4-5.3-5.3-5.3 5.3-1.4-1.4 5.3-5.3-5.3-5.3 1.4-1.4z"/>
            </svg>
        </button>
    </div>
@endif
