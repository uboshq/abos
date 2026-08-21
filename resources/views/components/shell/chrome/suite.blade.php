{{--
    Suite — Oracle NetSuite-এর নিজের খোলস।

    নেটস্যুটের চিহ্ন: ৩৬px নেভি হেডারে লোগো ও খোঁজার ঘর, তার নিচে
    ক্যারেটওয়ালা মেনু, পাতার মাথায় নেভি শিরোনাম, আর ২২px উঁচু
    গ্রেডিয়েন্ট বোতামের সারি।
--}}

@if ($region === 'topbar-start')
    {{--
        নেভি হেডারের লোগো-ব্লক।

        ── কেন এখানে আলাদা করে লোগো ────────────────────────────────
        বাকি রূপে ব্র্যান্ডটা সাইডবারের মাথায় বা উপরের মেনুর শুরুতে
        বসে। নেটস্যুটে সাইডবার নেই আর মেনুটা হেডারের **নিচে** — তাই
        লোগোটা হেডারেই থাকতে হয়, নাহলে পর্দায় সফটওয়্যারের নাম বলে
        কিছু থাকে না।
    --}}
    <a href="{{ route('dashboard') }}" data-suite-header
       class="hidden shrink-0 items-center gap-2 pe-2 text-(--color-topbar-ink) sm:flex"
       title="{{ __('core.brand.full_name') }}">
        <span class="grid size-6 shrink-0 place-items-center overflow-hidden rounded-[2px] bg-white">
            <img src="{{ asset('brand/adi-icon-transparent.png') }}" alt=""
                 aria-hidden="true" class="size-5 object-contain">
        </span>
        <span class="text-sm font-bold tracking-[.01em]">{{ __('core.brand.name') }}</span>
    </a>
@endif


@if ($region === 'page-head')
    {{--
        পাতার শিরোনাম — ১৭px, নেভি, বোল্ড।

        ── কেন টুলবারের শিরোনামটাই যথেষ্ট নয় ───────────────────────
        টুলবারের শিরোনাম তালিকার ভেতরে বসে, কার্ডের অংশ হয়ে। নেটস্যুটে
        শিরোনামটা কার্ডের **বাইরে**, পাতার নিজের মাথা হিসেবে — আর তার
        নিচে বোতামের সারি, তারও নিচে কার্ড।

        ওই ক্রমটাই নেটস্যুট চেনার সবচেয়ে বড় সূত্র, আর সেটা কেবল রং
        দিয়ে আনা যায় না।
    --}}
    @php
        $route = (string) (request()->route()?->getName() ?? '');
        $current = collect($menu)->first(
            fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
        ) ?? collect($menu)->first(
            fn ($m) => str_starts_with($route, $m['code'].'.'),
        ) ?? ($menu[0] ?? null);

        $page = collect($menu)
            ->flatMap(fn ($m) => collect($m['groups'])->flatten(1))
            ->firstWhere('active', true);
    @endphp

    <div class="px-3 pt-2 md:px-5">
        <h2 data-suite-title
            class="flex flex-wrap items-baseline gap-2 text-[17px] font-bold
                   text-(--color-topbar)">
            {{ $page['label'] ?? $current['label'] ?? __('core.menu.dashboard') }}
            @if ($current)
                <span class="text-2xs font-normal text-(--color-ink-muted)">
                    {{ $current['label'] }}
                </span>
            @endif
        </h2>
    </div>
@endif
