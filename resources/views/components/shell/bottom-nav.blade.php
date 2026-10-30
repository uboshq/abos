@props([
    'menu' => [],
    /** ⭐ বারে ⊞ সব মডিউল আছে (রেল-রূপ) — তখন "আরও" সেই পাতাটাই খোলে (মালিক, ২১ অক্টোবর ২০২৬; [[shell.launcher]]) */
    'launcher' => false,
])

{{--
    Bottom nav — শুধু মোবাইলে (সেকশন ২০.২)।

    পাঁচটার বেশি নয়: ৩২০px চওড়া স্ক্রিনে ছয়টা আইটেম মানে প্রতিটার ভাগে
    ৫৩px, আর ন্যূনতম টাচ টার্গেট ৪৪px — লেখার জায়গা থাকে না, আর দুইটা
    বোতাম পাশাপাশি ভুল করে চাপা পড়ে।

    বাকি মডিউলগুলো "আরও"-র নিচে; হারিয়ে যায় না।

    ⭐ ৩১ অক্টোবর ২০২৬ (মালিক: "smart kajer kichu daw int standard onuzayi"; Material 3 navigation bar, iOS tab bar, SAP Fiori):
      · চারটা মডিউল এই মানুষের — রেলের পিন, নইলে ভূমিকার চারটা ([[MenuBuilder::phoneBar()]]); আগে সবার বারে মেনুর প্রথম চারটা;
      · আইকনের কোণে সংখ্যা — মডিউলের সারিগুলোর ব্যাজের যোগ (অপেক্ষার অনুমোদন, খসড়া …);
      · মাঝখানে ＋ — বুড়ো আঙুলের নাগালে তৈরির তালিকা ([[shell.create-menu]], `open-create-menu`);
      · চালু মডিউলে আবার চাপ — পাতার মাথায়, মাথায় থাকলে মডিউলের প্রথম পাতায় (iOS);
      · লম্বা চাপ (বা ডান-চাপ) — মডিউলের ছোট মেনু: কাজ আর রিপোর্টের প্রথম কয়েকটা;
      · নিচে স্ক্রল করলে বার সরে যায়, উপরে করলে ফেরে (Material 3);
      · iPhone-এর নিচের দাগের জায়গা ছেড়ে ([[app.css]] `.bottom-nav`, safe-area)।
--}}
@php
    $bar = app(\App\Core\Services\MenuBuilder::class)->phoneBar($menu, auth()->user());
    $primary = $bar['four'];
    $hasMore = $bar['more'];
@endphp

<nav class="bottom-nav fixed inset-x-0 bottom-0 z-30 flex h-(--spacing-bottom-nav) items-stretch md:hidden"
     x-data="bottomNav"
     :class="hidden && 'bottom-nav-hidden'"
     @scroll.window.passive="scrolled()"
     data-bottom-nav
     aria-label="{{ __('core.a11y.main_navigation') }}">

    @foreach ($primary as $i => $module)
        @php
            $items = collect($module['groups'])->flatten(1);
            $first = $items->firstWhere('url', '!==', null) ?? $items->first();
            $active = $items->contains('active', true);
            // ⓘ লম্বা চাপের মেনু — কাজের প্রথম তিনটা আর রিপোর্টের প্রথমটা, যেগুলোর ঠিকানা আছে
            $shortcuts = collect([...array_slice($module['groups']['transactions'] ?? [], 0, 3), ...array_slice($module['groups']['reports'] ?? [], 0, 1)])
                ->filter(fn ($item) => ($item['url'] ?? null) !== null)
                ->values();
        @endphp

        {{-- ⭐ মাঝখানে ＋ — দুই মডিউলের পরে, যাতে বুড়ো আঙুলের ঠিক নিচে --}}
        @if ($i === 2)
            <button type="button"
                    data-bottom-create
                    @click="$dispatch('open-create-menu')"
                    class="bottom-nav-item"
                    aria-label="{{ __('core.action.create') }}">
                <span class="bottom-nav-create" aria-hidden="true">
                    <svg viewBox="0 0 24 24" class="size-6 fill-current"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg>
                </span>
            </button>
        @endif

        <a @if ($first && $first['url']) href="{{ $first['url'] }}" @endif
           data-bottom-module="{{ $module['code'] }}"
           @class(['bottom-nav-item relative', 'bottom-nav-item-on' => $active])
           @if ($active) aria-current="page" @click="again($event)" @endif
           @if ($shortcuts->isNotEmpty())
               @pointerdown="press('{{ $module['code'] }}')" @pointerup="release()" @pointerleave="release()"
               @contextmenu.prevent="sheet = '{{ $module['code'] }}'"
           @endif>
            {{-- মোবাইলেও মডিউলের নিজের আকার — `drawn` মানে রেখা-আঁকা SVG, `currentColor` ধরে, তাই সাদা।
                 ⚠️ নামটা তালিকায় না থাকলে কম্পোনেন্ট চুপ করে কিছুই আঁকে না — পনেরোটা মডিউলের কোডই আঁকার তালিকায় আছে। --}}
            <span class="bottom-nav-pill">
                <x-ui.icon :name="$module['icon']" :size="20" drawn />
            </span>
            @if ($module['count'])
                <span data-bottom-count class="bottom-nav-count" aria-label="{{ $module['count'] }}">{{ $module['count'] > 99 ? '99+' : $module['count'] }}</span>
            @endif
            <span class="w-full truncate text-center">{{ $module['label'] }}</span>
        </a>

        @if ($shortcuts->isNotEmpty())
            {{-- ⭐ লম্বা চাপের ছোট মেনু — নিচ থেকে ওঠা পাত (Material 3 bottom sheet); বাইরে চাপ বা Esc-এ বন্ধ --}}
            <div x-cloak x-show="sheet === '{{ $module['code'] }}'" x-transition.opacity
                 data-bottom-sheet="{{ $module['code'] }}"
                 class="fixed inset-0 z-40 flex items-end bg-black/40"
                 @click.self="sheet = null" @keydown.escape.window="sheet = null">
                <div class="w-full rounded-t-2xl bg-(--color-surface) p-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] text-(--color-ink) shadow-2xl">
                    <p class="px-2 pb-2 text-sm font-semibold">{{ $module['label'] }}</p>
                    @foreach ($shortcuts as $item)
                        <a href="{{ $item['url'] }}" class="flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2 text-base hover:bg-(--color-surface-muted)">
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach

    @if ($hasMore)
        {{-- ⭐ "আরও" — বাকি মডিউলগুলো। বারে ⊞ থাকলে সেই সব-মডিউলের পাতা (ফোনে ⊞ বারে নয়, এখানে); নইলে আগের মতো খোঁজা।
             ⓘ খোঁজা ফোনেও বারের আইকনে থাকে। --}}
        <button type="button"
                @if ($launcher)
                    data-bottom-launcher
                    @click="$dispatch('open-module-launcher')"
                    aria-haspopup="dialog"
                @else
                    @click="$dispatch('open-command-center')"
                @endif
                class="bottom-nav-item">
            <span class="bottom-nav-pill">
                <svg viewBox="0 0 24 24" class="size-(--spacing-icon) fill-current" aria-hidden="true">
                    <path d="M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z"/>
                </svg>
            </span>
            <span>{{ __('core.action.more') }}</span>
        </button>
    @endif
</nav>
