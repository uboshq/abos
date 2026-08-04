@props(['menu' => []])

{{--
    সাইডবার — সেকশন ১৫.২ ও ২০.২।

    দুই কলাম, DMS-এর প্রমাণিত নকশা অনুযায়ী:

      • বাঁয়ে সরু আইকন রেল — মডিউল বদলানোর জায়গা, গাঢ় রঙে
      • ডানে সাদা তালিকা — চলতি মডিউলের নিজের পাতাগুলো

    এক কলামে সব মডিউলের সব পাতা রাখলে তালিকাটা একশোর বেশি লিংক লম্বা হয়,
    আর গাঢ় পটভূমিতে অত লেখা সারাদিন পড়া ক্লান্তিকর। রেল হলো chrome,
    তালিকা হলো content — তাই রেল গাঢ়, তালিকা হালকা।

    মোবাইলে দুটোর কোনোটাই নেই; নিচে bottom nav (সেকশন ২০.২)।
--}}
@php
    // কোন মডিউলটা এখন খোলা — কোনোটা সক্রিয় না থাকলে প্রথমটা।
    $activeModule = collect($menu)->first(
        fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
    ) ?? ($menu[0] ?? null);
@endphp

<aside class="sticky top-0 hidden h-dvh shrink-0 md:flex
              md:w-(--spacing-sidebar-icon)
              lg:w-(--spacing-sidebar)
              xl:w-(--spacing-sidebar-wide)">

    <div class="flex min-w-0 flex-1 flex-col">

        {{-- মাথা — টপবারের মতোই সাদা ও ঠিক একই উচ্চতা।

             গাঢ় রাখলে লোগোর নিচের রেখা আর টপবারের নিচের রেখা একই উচ্চতায়
             থাকলেও দুই রঙের হত, আর পর্দার দুই অর্ধেক দুইটা আলাদা স্ক্রিনের
             মতো দেখাত। --}}
        <a href="{{ route('dashboard') }}"
           class="flex h-(--spacing-header) shrink-0 items-center gap-2 border-b border-(--color-border)
                  bg-(--color-surface-card) px-3 transition-colors hover:bg-(--color-surface-app)"
           title="{{ __('core.menu.dashboard') }}">

            {{-- সরু সাইডবারে (৪৪px) শুধু মার্ক — ওখানে ওয়ার্ডমার্ক ধরে না। --}}
            <img src="{{ asset('brand/abos-icon-transparent.png') }}" alt="ABOS"
                 class="size-(--spacing-logo-sidebar) shrink-0 lg:hidden">

            {{-- খোলা সাইডবারে পূর্ণ ওয়ার্ডমার্ক — নাম ও পূর্ণরূপ দুটোই
                 ডিজাইনারের নিজের লেটারিংয়ে।

                 আগে এখানে মার্ক + টাইপ করা "ABOS" + টাইপ করা পূর্ণরূপ ছিল,
                 আর পূর্ণরূপটা ঘরে না ধরে কেটে যেত। ছবিটা একটাই জিনিস, তাই
                 কাটার প্রশ্নই নেই — সেটা প্রস্থ অনুযায়ী ছোট-বড় হয়, ভেঙে
                 যায় না। ৩২০×৯৬ অনুপাতে max-h-10 দিলে ১৩৩px চওড়া, যা
                 ২২০px সাইডবারেও অনেকটা জায়গা রেখে বসে। --}}
            <img src="{{ asset('brand/abos-wordmark-transparent.png') }}" alt="ABOS"
                 class="hidden max-h-10 w-auto object-contain object-left lg:block">

            <span class="sr-only">{{ __('core.brand.full_name') }}</span>
        </a>

        <div class="flex min-h-0 flex-1">

            {{-- আইকন রেল — মডিউল বদলানোর জায়গা।

                 ব্র্যান্ডের নিজের নীল, স্লেট-কালো নয়: পর্দার সবচেয়ে চওড়া
                 রঙিন পৃষ্ঠতলটা প্রোডাক্টের নিজের রং হওয়াই স্বাভাবিক। --}}
            <nav class="flex w-(--spacing-sidebar-icon) shrink-0 flex-col items-center
                        overflow-y-auto border-e border-black/10 bg-(--color-brand-700) py-1"
                 aria-label="{{ __('core.a11y.module_navigation') }}">

                @foreach ($menu as $module)
                    @php
                        $isActive = $activeModule && $module['code'] === $activeModule['code'];
                        $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);
                    @endphp

                    {{-- সক্রিয় মডিউল চেনা যায় বাঁ পাশের সাদা দাগ দিয়ে, শুধু
                         হালকা পটভূমি দিয়ে নয়: সরু রেলে ৪৪px বর্গের ভেতরে
                         একটা ফিকে আয়তক্ষেত্র চোখে পড়ে না। --}}
                    <a @if ($first) href="{{ $first['url'] }}" @endif
                       @class([
                           'relative flex h-11 w-full items-center justify-center transition-colors',
                           'bg-white/15' => $isActive,
                           'hover:bg-white/10' => ! $isActive,
                       ])
                       @if ($isActive) aria-current="true" @endif
                       title="{{ $module['label'] }}">

                        @if ($isActive)
                            <span class="absolute inset-y-1 start-0 w-[3px] rounded-e bg-white"
                                  aria-hidden="true"></span>
                        @endif

                        <x-shell.module-icon :module="$module['code']" shape="module" tone="white" />
                    </a>
                @endforeach
            </nav>

            {{-- তালিকা — শুধু চলতি মডিউলের পাতা, সাদা পটভূমিতে।

                 সব মডিউলের সব পাতা একসাথে দেখালে তালিকাটা যত লম্বা হয়,
                 তাতে খোঁজা আর স্ক্রল করা ছাড়া উপায় থাকে না। --}}
            <div class="hidden min-w-0 flex-1 flex-col border-e border-(--color-border)
                        bg-(--color-surface-card) lg:flex">

                @if ($activeModule)
                    <p class="shrink-0 truncate px-3 pt-3 pb-1 text-2xs font-semibold uppercase
                              tracking-wide text-(--color-ink-muted)">
                        {{ $activeModule['label'] }}
                    </p>

                    <nav class="flex-1 overflow-y-auto overflow-x-hidden pb-4"
                         aria-label="{{ __('core.a11y.main_navigation') }}">

                        @foreach ($activeModule['groups'] as $group => $items)
                            @foreach ($items as $item)
                                <a @if ($item['url']) href="{{ $item['url'] }}" @endif
                                   @class([
                                       'flex min-h-(--spacing-touch) items-center gap-2 ps-3 pe-2 text-sm transition-colors',
                                       'border-s-[3px] border-(--color-brand-500) bg-(--color-surface-selected) ps-2 font-medium text-(--color-brand-700)' => $item['active'],
                                       'text-(--color-ink-body) hover:bg-(--color-surface-hover)' => ! $item['active'] && $item['url'],
                                       'cursor-not-allowed text-(--color-ink-disabled)' => ! $item['url'],
                                   ])
                                   @if (! $item['url']) aria-disabled="true" @endif
                                   @if ($item['active']) aria-current="page" @endif>
                                    <span class="min-w-0 truncate">{{ $item['label'] }}</span>
                                </a>
                            @endforeach
                        @endforeach
                    </nav>
                @endif
            </div>
        </div>
    </div>
</aside>
