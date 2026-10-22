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
--}}
@php
    $primary = collect($menu)->take(4);
    $hasMore = count($menu) > 4;
@endphp

<nav class="bottom-nav fixed inset-x-0 bottom-0 z-30 flex h-(--spacing-bottom-nav) items-stretch md:hidden"
     aria-label="{{ __('core.a11y.main_navigation') }}">

    @foreach ($primary as $module)
        @php
            $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null)
                ?? collect($module['groups'])->flatten(1)->first();
            $active = collect($module['groups'])->flatten(1)->contains('active', true);
        @endphp

        <a @if ($first && $first['url']) href="{{ $first['url'] }}" @endif
           @class(['bottom-nav-item', 'bottom-nav-item-on' => $active])
           @if ($active) aria-current="page" @endif>
            {{-- মোবাইলেও মডিউলের নিজের আকার — পাঁচটা আইটেম একরকম দেখালে
                 নিচের বারটা পড়া ছাড়া কাজে লাগে না।

                 ⓘ `drawn` মানে রেখা-আঁকা SVG, ইমোজি নয়। ⚠️ মডিউলের
                 আইকন ডিফল্টে ইমোজি (🗃️ 🏦 🏭), আর ইমোজির নিজের রং আছে —
                 নীল পটিতে ওগুলো সাদা হতে পারত না, আর পাঁচটা রঙিন ছবি
                 পাশাপাশি বসলে সক্রিয় কোনটা তা বোঝাই যেত না। `drawn`
                 সংস্করণ `currentColor` ধরে, তাই সাদা।

                 ⚠️ নামটা তালিকায় না থাকলে এই কম্পোনেন্ট **চুপ করে কিছুই
                 আঁকে না** — কোনো ভুলের বার্তা নেই, কেবল ফাঁকা ঘর। তাই
                 পনেরোটা মডিউলের কোডই আঁকার তালিকায় আছে কি না, সেটা আগে
                 মিলিয়ে নেওয়া হয়েছে। --}}
            <span class="bottom-nav-pill">
                <x-ui.icon :name="$module['icon']" :size="20" drawn />
            </span>
            <span class="w-full truncate text-center">{{ $module['label'] }}</span>
        </a>
    @endforeach

    @if ($hasMore)
        {{-- ⭐ "আরও" — বাকি মডিউলগুলো। বারে ⊞ থাকলে সেই সব-মডিউলের পাতা (ফোনে ⊞ বারে নয়, এখানে); নইলে আগের মতো খোঁজা।
             ⓘ খোঁজা ফোনেও বারের আইকনে থাকে। --}}
        <button type="button"
                x-data
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
