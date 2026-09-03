{{--
    Dynamic — Microsoft Dynamics 365-এর নিজের খোলস।

    D365-এর চেনা অংশগুলো: নেভি বারে ওয়াফল ও অ্যাপের নাম, বাঁয়ে সাইট
    ম্যাপ যার **একদম নিচে** এলাকা বদলের বোতাম, উপরে সরু কমান্ড বার,
    তালিকার নামটাই ড্রপডাউন, ডানে Quick find, আর শেভরনের প্রসেস বার।
--}}

@if ($region === 'topbar-start')
    {{--
        ওয়াফল — ৯ ফোঁটা, কিন্তু Odoo-র লঞ্চার নয়।

        ── দুইটা দেখতে এক, কাজ আলাদা ───────────────────────────────
        ওডুর লঞ্চার পুরো পর্দা ঢেকে **অ্যাপ বদলায়**। মাইক্রোসফটের
        ওয়াফল একটা ছোট প্যানেল খুলে **অন্য পণ্যে** নিয়ে যায় — Word,
        Teams, তারপর নিচে "সব অ্যাপ"।

        আমাদের একটাই পণ্য, তাই এখানে ওয়াফল মডিউলের তালিকা দেখায় —
        কিন্তু প্যানেলে, শিটে নয়। আকৃতি এক, আচরণ ওদেরটাই।
    --}}
    <div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false">
        <button type="button" data-waffle
                @click="open = ! open"
                @keydown.escape.window="open = false"
                :aria-expanded="open ? 'true' : 'false'"
                class="grid size-9 place-items-center rounded-[2px] text-(--color-topbar-ink)
                       transition-colors hover:bg-(--color-topbar-hover)"
                aria-label="{{ __('core.ui.launcher') }}">
            <span class="grid grid-cols-3 gap-[3px]" aria-hidden="true">
                @for ($i = 0; $i < 9; $i++)
                    <span class="block size-[3px] rounded-[1px] bg-current"></span>
                @endfor
            </span>
        </button>

        <div x-show="open" x-cloak x-transition.opacity.duration.100ms
             class="pops-onto-page absolute start-0 top-full z-50 mt-1 w-72 rounded-[2px]
                    border border-(--color-border) bg-(--color-surface-card) p-2 shadow-lg">
            <p class="px-2 pb-2 text-2xs font-semibold uppercase tracking-wide
                      text-(--color-ink-muted)">{{ __('core.ui.launcher') }}</p>
            <div class="grid max-h-[60vh] grid-cols-2 gap-1 overflow-y-auto">
                @foreach ($menu as $module)
                    @php $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null); @endphp
                    <a @if ($first) href="{{ $first['url'] }}" @endif
                       class="flex items-center gap-2 rounded-[2px] px-2 py-2 text-sm
                              hover:bg-(--color-surface-hover)">
                        <span class="grid size-6 shrink-0 place-items-center rounded-[2px] text-white"
                              style="background: var(--color-module-{{ $module['code'] }}, var(--color-brand-600))"
                              aria-hidden="true">
                            <x-ui.icon :name="$module['icon']" :size="14" />
                        </span>
                        <span class="truncate">{{ $module['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- বিভাজক ও অ্যাপের নাম — D365-এ ওয়াফলের ঠিক পাশে বসে, আর ওটাই
         বলে "কোন অ্যাপে আছেন"। --}}
    @php
        $route = (string) (request()->route()?->getName() ?? '');
        $current = collect($menu)->first(
            fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
        ) ?? collect($menu)->first(
            fn ($m) => collect($m['codes'])->contains(fn ($c) => str_starts_with($route, $c.'.')),
        ) ?? ($menu[0] ?? null);
    @endphp

    @if ($current)
        <span class="hidden h-4 w-px shrink-0 bg-(--color-topbar-ink-muted) opacity-50 sm:block"
              aria-hidden="true"></span>
        <span data-d365-app
              class="hidden shrink-0 text-sm font-semibold text-(--color-topbar-ink) sm:block">
            {{ $current['label'] }}
        </span>
    @endif
@endif


@if ($region === 'rail-foot')
    {{--
        এলাকা বদলের বোতাম — প্যানেলের **একদম নিচে**।

        ── কেন নিচে, আর কেন সেটা খেয়াল করার মতো ────────────────────
        প্রায় সব ERP নেভিগেশনের বদল উপরে রাখে। D365 রাখে নিচে, আর
        সেটাই তার সাইট ম্যাপের সবচেয়ে চেনা অভ্যাস: উপরের অংশটা এই
        এলাকার কাজ, নিচের বোতামটা অন্য এলাকায় যাওয়ার দরজা।

        `mt-auto` — প্যানেল যত লম্বাই হোক, বোতামটা নিচেই থাকে।
    --}}
    <a href="{{ route('dashboard') }}" data-area-switch
       class="mt-auto flex shrink-0 items-center gap-2 border-t border-(--color-border)
              bg-(--color-sidebar-panel) px-3 py-2.5 text-sm font-semibold
              text-(--color-ink) transition-colors hover:bg-(--color-surface-hover)">
        <span class="truncate">{{ __('core.ui.area_switch') }}</span>
        <span class="ms-auto" aria-hidden="true">
            <svg viewBox="0 0 24 24" class="size-3.5 fill-current">
                <path d="M12 15.5 5.5 9h13z"/>
            </svg>
        </span>
    </a>
@endif
