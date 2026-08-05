@props([
    'search' => true,
    'searchPlaceholder' => null,
    'filter' => true,
    'sort' => [],
    'view' => false,
    'density' => true,
    /**
     * The screen's columns, as the table declares them — [['key' => …, 'label' => …], …].
     *
     * Given, the Columns button appears and hides the ones unticked. Omitted,
     * it does not: a Columns menu that lists nothing is the dead button this
     * toolbar had six of.
     */
    'columns' => [],
    'export' => true,
    'share' => true,
    'print' => true,
    'refresh' => true,
])

{{--
    One Toolbar Standard — সেকশন ১৫.২৪।

    সব গ্রিড ও রিপোর্টে হুবহু একই টুলবার, একই ক্রমে। প্রতিটা স্ক্রিনে আলাদা
    করে বানালে একটায় Sort বাঁয়ে আর অন্যটায় ডানে চলে যেত, আর ব্যবহারকারীকে
    প্রতিটা স্ক্রিন আলাদা করে শিখতে হত।

    যে বোতাম এই স্ক্রিনে অর্থহীন সেটা বাদ দেওয়া যায় (:print="false"), কিন্তু
    নতুন বোতাম এখানেই যোগ করতে হবে — স্ক্রিনে নয়।

    ক্রমটা ব্যবহারকারীর দেওয়া নমুনা অনুযায়ী: বাঁয়ে Filter By, তারপর খোঁজার
    ঘর, তারপর Sort by, আর ডান প্রান্তে View।

    ── আগে এখানে ছয়টা মৃত বোতাম ছিল ────────────────────────────────────
    Filter · Columns · Density · Export · Print · Refresh — ছয়টাই ছিল খালি
    <button>, কোনো আচরণ ছাড়া। দেখে মনে হত কাজ করে, ক্লিক করলে কিছুই হত না।
    মেনুর মৃত সারিগুলোর মতোই এটা সবচেয়ে খারাপ ধরনের স্টাব: কাজটা আছে বলে
    দেখায়।

    এখন যা আছে তার প্রতিটাই সত্যিই কিছু করে। Columns ও Export সরানো হয়েছে —
    ওগুলো এখনো তৈরি হয়নি, আর তৈরি না হওয়া জিনিস দেখানোর চেয়ে না দেখানোই
    সৎ। যেদিন তৈরি হবে সেদিন এখানেই ফিরবে।
--}}
@php
    // টুলবার সবসময় একটা GET ফর্মের ভেতরে বসে, তাই প্রতিটা নিয়ন্ত্রণ
    // ফর্মটা জমা দিলেই কাজ করে — আলাদা JavaScript লাগে না।
    $currentSort = (string) request('sort');
    $currentView = request('view') === 'grid' ? 'grid' : 'list';
    $isCompact = request()->boolean('compact');
    $hasFilters = $filter && trim($slot->toHtml()) !== '';

    /*
     * Which columns are showing.
     *
     * In the query string, not in a cookie or a table of preferences: it
     * travels with the link. Somebody who hides four columns and sends the
     * page to a colleague sends what they are looking at, which is the whole
     * reason they sent it. A saved preference would have shown the colleague
     * their own columns instead, and the two would have argued about a
     * screenshot.
     *
     * Nothing chosen means everything, so a fresh link is the full table.
     */
    $columnKeys = collect($columns)->pluck('key')->filter()->values();
    $hiddenColumns = collect(explode(',', (string) request('hide')))
        ->map(fn ($k) => trim($k))->filter()->values();

    /* The current query, minus the page number — every menu below builds a
       link from it, and carrying page 3 into a CSV of the whole list is how
       an export quietly comes back short. */
    $params = collect(request()->query())->except('page')->all();
    $shareUrl = url()->current().'?'.http_build_query($params);
@endphp

<div x-data="{ filtersOpen: {{ $hasFilters && request()->hasAny(['from', 'to', 'branch_id', 'inactive', 'account_id', 'status']) ? 'true' : 'false' }} }"
     {{ $attributes->merge(['class' => 'border-b border-(--color-border) bg-(--color-surface-card)']) }}>

    <div class="flex flex-wrap items-center gap-2 px-3 py-2">

        {{-- Filter By — লেখা সহ, বাঁ প্রান্তে।

             স্ক্রিনের নিজস্ব ফিল্টার না থাকলে বোতামটাও থাকে না: যে বোতাম
             খালি প্যানেল খোলে সেটাও একটা মৃত বোতাম। --}}
        @if ($hasFilters)
            <button type="button"
                    @click="filtersOpen = ! filtersOpen"
                    :aria-expanded="filtersOpen ? 'true' : 'false'"
                    aria-controls="toolbar-filters"
                    class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field)
                           border border-(--color-border) px-3 text-sm transition-colors
                           hover:bg-(--color-surface-hover)">
                <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                    <path d="M3 5h18v2l-7 7v5l-4 2v-7L3 7V5Z"/>
                </svg>
                {{ __('core.toolbar.filter') }}
            </button>
        @endif

        @if ($search)
            {{-- min-w-48 না দিলে ছোট পর্দায় ঘরটা শূন্যে মিলিয়ে যায়।

                 flex-1 জায়গা ছেড়ে দিতে রাজি, আর Sort by-র লেবেল ও ড্রপডাউন
                 মিলে প্রায় পুরো সারিটা নিয়ে নেয় — ফলে ৩৭৫ পিক্সেলে খোঁজার
                 ঘরটা এক আঙুলের চেয়েও সরু হয়ে যেত, শুধু ম্যাগনিফায়ারটা
                 দেখা যেত। একটা সর্বনিম্ন চওড়া ধরে রাখলে ওটা মিলিয়ে যাওয়ার
                 বদলে Sort by পরের লাইনে নেমে যায়। --}}
            <label class="relative min-w-48 flex-1 sm:max-w-sm">
                <span class="sr-only">{{ __('core.action.search') }}</span>
                {{-- placeholder-এ কী কী দিয়ে খোঁজা যায় তা লেখা থাকে।
                     শুধু "খুঁজুন" লিখলে ব্যবহারকারী নাম দিয়েই খোঁজে, আর
                     মোবাইল নম্বর দিয়েও যে খোঁজা যায় তা কখনো জানে না। --}}
                <input type="search" name="q" value="{{ request('q') }}"
                       placeholder="{{ $searchPlaceholder ?? __('core.action.search') }}"
                       class="h-9 w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) ps-8 pe-3 text-sm">
                <svg viewBox="0 0 24 24" aria-hidden="true"
                     class="pointer-events-none absolute start-2 top-1/2 size-4 -translate-y-1/2
                            fill-(--color-ink-muted)">
                    <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
                </svg>
            </label>
        @endif

        {{-- Sort by — ডিফল্টটা ব্যবসায়িক অর্থবহ হতে হবে ("সবচেয়ে বেশি
             বকেয়া আগে"), নাহলে ব্যবহারকারীকে প্রতিবার নিজে সাজাতে হয়,
             আর তালিকা খুলেই কাজের সারিগুলো চোখে পড়ে না। --}}
        @if ($sort !== [])
            <label class="flex items-center gap-2 text-sm">
                <span class="whitespace-nowrap text-(--color-ink-muted)">{{ __('core.toolbar.sort_by') }}</span>
                <select name="sort" onchange="this.form.submit()"
                        class="h-9 rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 text-sm">
                    @foreach ($sort as $value => $label)
                        <option value="{{ $value }}" @selected($currentSort === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <div class="print-hide ms-auto flex items-center gap-1">

            {{-- View — তালিকা নাকি কার্ড।

                 ছোট পর্দায় CSS এমনিতেই কার্ড দেখায়, তাই টগলটা সেখানে
                 কিছু বদলায় না — এজন্যই ওখানে দেখানোও হয় না। --}}
            @if ($view)
                <span class="me-1 hidden text-sm text-(--color-ink-muted) sm:inline">
                    {{ __('core.toolbar.view') }}
                </span>

                <span class="hidden overflow-hidden rounded-(--radius-field) border
                             border-(--color-border) sm:inline-flex">
                    @foreach ([
                        'list' => 'M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z',
                        'grid' => 'M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z',
                    ] as $mode => $path)
                        <button type="submit" name="view" value="{{ $mode }}"
                                aria-pressed="{{ $currentView === $mode ? 'true' : 'false' }}"
                                aria-label="{{ __('core.toolbar.view_'.$mode) }}"
                                @class([
                                    'flex min-h-(--spacing-touch) items-center px-3 transition-colors',
                                    'bg-(--color-brand-500) text-white' => $currentView === $mode,
                                    'text-(--color-ink-muted) hover:bg-(--color-surface-hover)' => $currentView !== $mode,
                                ])>
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 fill-current">
                                <path d="{{ $path }}"/>
                            </svg>
                        </button>
                    @endforeach
                </span>
            @endif

            {{-- ঘন সারি — একই পর্দায় বেশি সারি। তালিকা কম্পোনেন্ট
                 compact প্রপটা এখান থেকেই পায়। --}}
            @if ($density)
                <button type="submit" name="compact" value="{{ $isCompact ? '0' : '1' }}"
                        aria-pressed="{{ $isCompact ? 'true' : 'false' }}"
                        aria-label="{{ __('core.toolbar.density') }}"
                        @class([
                            'flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) px-2
                             text-sm transition-colors hover:bg-(--color-surface-hover)',
                            'text-(--color-brand-500)' => $isCompact,
                            'text-(--color-ink-muted) hover:text-(--color-ink)' => ! $isCompact,
                        ])>
                    {{-- ঘন সারির নিজের ছবি।

                         এটা আর তালিকা-দৃশ্যের বোতামটা হুবহু একই তিন-দাগের
                         পথ আঁকত — পাশাপাশি দুটো বোতাম, একই ছবি, আলাদা কাজ।
                         যে দুটো জিনিস দেখতে এক, ব্যবহারকারী ধরে নেয় সে দুটো
                         একই জিনিস, আর একটাতে চেপে অন্যটা আশা করে।

                         এখানে দাগগুলো ঘন, আর উপরে-নিচে দুটো তীর ভেতরের দিকে
                         — "সারিগুলো কাছে আনো"। --}}
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                        <path d="M12 2 8.5 5.5 10 7l2-2 2 2 1.5-1.5L12 2Zm0 20 3.5-3.5L14 17l-2 2-2-2-1.5 1.5L12 22ZM4 9h16v1.6H4V9Zm0 3.2h16v1.6H4v-1.6Z"/>
                    </svg>
                    <span class="hidden xl:inline">{{ __('core.toolbar.density') }}</span>
                </button>
            @endif

            {{-- Columns — কোন কলামগুলো দেখা যাবে।

                 টিকগুলো ফর্মের ভেতরেই, তাই "প্রয়োগ" চাপলে বাকি সব
                 (খোঁজা, সাজানো, ফিল্টার) অক্ষত রেখে পাতা ফিরে আসে। আলাদা
                 JavaScript নেই — যে টুলবার ফর্ম জমা দিয়ে চলে, তার কলাম
                 বাছাইও ফর্মেই থাকা উচিত। --}}
            @if ($columnKeys->isNotEmpty())
                <div x-data="{ open: false }" class="relative">
                    <button type="button" @click="open = ! open" @click.outside="open = false"
                            @keydown.escape.window="open = false"
                            :aria-expanded="open.toString()"
                            aria-label="{{ __('core.toolbar.columns') }}"
                            class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) px-2
                                   text-sm text-(--color-ink-muted) transition-colors
                                   hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                            <path d="M3 4h4v16H3V4Zm6 0h6v16H9V4Zm8 0h4v16h-4V4Z"/>
                        </svg>
                        <span class="hidden xl:inline">{{ __('core.toolbar.columns') }}</span>
                        @if ($hiddenColumns->isNotEmpty())
                            <span class="rounded-full bg-(--color-brand-500) px-1.5 text-[10px] font-semibold text-white">
                                {{ $columnKeys->count() - $hiddenColumns->count() }}/{{ $columnKeys->count() }}
                            </span>
                        @endif
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity
                         class="absolute end-0 z-30 mt-1 w-60 rounded-(--radius-card) border
                                border-(--color-border) bg-(--color-surface-card) p-2 shadow-lg">
                        @foreach ($columns as $column)
                            @php $key = $column['key']; @endphp
                            <label class="flex min-h-(--spacing-touch) cursor-pointer items-center gap-2
                                          rounded-(--radius-field) px-2 text-sm hover:bg-(--color-surface-hover)">
                                <input type="checkbox" name="show[]" value="{{ $key }}"
                                       @checked(! $hiddenColumns->contains($key))
                                       class="size-4 shrink-0">
                                <span class="truncate">{{ $column['label'] }}</span>
                            </label>
                        @endforeach

                        {{-- একটাও না রাখলে খালি টেবিল — সেটা কেউ চায় না, আর
                             সার্ভার তখন সবগুলোই দেখায়। এখানে বলে দেওয়া হয়
                             যাতে "কাজ করেনি" মনে না হয়। --}}
                        <p class="px-2 pt-1 text-2xs text-(--color-ink-muted)">
                            {{ __('core.toolbar.columns_note') }}
                        </p>

                        <x-ui.button type="submit" tone="secondary" class="mt-2 w-full">
                            {{ __('core.action.apply') }}
                        </x-ui.button>
                    </div>
                </div>
            @endif

            {{-- Export — যা সত্যিই বেরোয়।

                 CSV আর ছাপা, দুটোই। Word দেওয়া হয়নি: একটা টেবিলকে .doc
                 বলে HTML পাঠানো যায়, Word সেটা খোলেও — কিন্তু ওটা Word
                 ফাইল নয়, আর যেদিন কেউ ওটা সম্পাদনা করে ফেরত পাঠাবে সেদিন
                 জানা যাবে। xlsx-ও নয়: সত্যিকারের xlsx লিখতে আলাদা লাইব্রেরি
                 লাগে, আর CSV প্রতিটা Excel-এ খোলে।

                 PDF ব্রাউজারের ছাপা-থেকে-PDF দিয়ে — একই টেবিল দ্বিতীয়বার
                 তৈরি না করে, যেটা করলে দুটোর একটা পরে ঠিক করতে ভুলে যাওয়া
                 হত। --}}
            @if ($export)
                <div x-data="{ open: false }" class="relative">
                    <button type="button" @click="open = ! open" @click.outside="open = false"
                            @keydown.escape.window="open = false"
                            :aria-expanded="open.toString()"
                            aria-label="{{ __('core.toolbar.export') }}"
                            class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) px-2
                                   text-sm text-(--color-ink-muted) transition-colors
                                   hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                            <path d="M12 3v10l3.5-3.5L17 11l-5 5-5-5 1.5-1.5L12 13V3ZM5 18h14v2H5v-2Z"/>
                        </svg>
                        <span class="hidden xl:inline">{{ __('core.toolbar.export') }}</span>
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity
                         class="absolute end-0 z-30 mt-1 w-52 overflow-hidden rounded-(--radius-card)
                                border border-(--color-border) bg-(--color-surface-card) shadow-lg">
                        <a href="{{ url()->current().'?'.http_build_query($params + ['export' => 'csv']) }}"
                           class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                            {{ __('core.toolbar.export_csv') }}
                        </a>
                        {{-- লেখা থাকা সত্ত্বেও aria-label — ComponentTest
                             ট্যাগের ভেতরটাই কেবল পড়ে, ভেতরের লেখা দেখে না,
                             তাই পাহারাটা টিকিয়ে রাখতে হলে দুটোই দরকার।
                             লেবেল আর লেখা হুবহু এক রাখা হয়েছে: আলাদা হলে
                             স্ক্রিন রিডার একটা শুনত আর চোখে দেখা যেত অন্যটা। --}}
                        <button type="button" onclick="window.print()"
                                aria-label="{{ __('core.toolbar.export_pdf') }}"
                                class="block w-full px-3 py-2 text-start text-sm hover:bg-(--color-surface-hover)">
                            {{ __('core.toolbar.export_pdf') }}
                        </button>
                    </div>
                </div>
            @endif

            {{-- Share — এই পাতার লিংক, ফাইল নয়।

                 লিংকটাই সঠিক জিনিস: খোঁজা, সাজানো, ফিল্টার আর কোন কলামগুলো
                 দেখা যাচ্ছে — সব ঠিকানার ভেতরে, তাই যে খুলবে সে হুবহু এই
                 পর্দাটাই দেখবে। ফাইল পাঠালে সে একটা মুহূর্তের ছবি পেত, আর
                 কাল সেটা ভুল হয়ে যেত।

                 <b>যাকে পাঠানো হচ্ছে তার লগইন লাগবে</b> — লিংকটা এই
                 প্রতিষ্ঠানের ভেতরের। বাইরের কাউকে পাঠাতে হলে CSV। --}}
            @if ($share)
                <div x-data="{ open: false, copied: false }" class="relative">
                    <button type="button" @click="open = ! open" @click.outside="open = false"
                            @keydown.escape.window="open = false"
                            :aria-expanded="open.toString()"
                            aria-label="{{ __('core.toolbar.share') }}"
                            class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) px-2
                                   text-sm text-(--color-ink-muted) transition-colors
                                   hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
                        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                            <path d="M18 16a3 3 0 0 0-2.2 1l-6-3.5a3 3 0 0 0 0-1l6-3.5a3 3 0 1 0-1-1.7l-6 3.5a3 3 0 1 0 0 4.4l6 3.5A3 3 0 1 0 18 16Z"/>
                        </svg>
                        <span class="hidden xl:inline">{{ __('core.toolbar.share') }}</span>
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity
                         class="absolute end-0 z-30 mt-1 w-56 overflow-hidden rounded-(--radius-card)
                                border border-(--color-border) bg-(--color-surface-card) shadow-lg">
                        <a href="https://wa.me/?text={{ urlencode($shareUrl) }}"
                           target="_blank" rel="noopener"
                           class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                            WhatsApp
                        </a>
                        <a href="mailto:?body={{ urlencode($shareUrl) }}"
                           class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                            {{ __('core.toolbar.share_email') }}
                        </a>
                        <button type="button"
                                @click="navigator.clipboard.writeText('{{ $shareUrl }}'); copied = true; setTimeout(() => copied = false, 2000)"
                                aria-label="{{ __('core.toolbar.share_copy') }}"
                                class="block w-full px-3 py-2 text-start text-sm hover:bg-(--color-surface-hover)">
                            <span x-show="! copied">{{ __('core.toolbar.share_copy') }}</span>
                            <span x-show="copied" x-cloak class="text-(--color-badge-success-ink)">
                                {{ __('core.toolbar.share_copied') }}
                            </span>
                        </button>
                    </div>
                </div>
            @endif

            @if ($print)
                {{-- ছাপা ব্রাউজারেরই কাজ; আলাদা রুট বানানো মানে একই টেবিল
                     দ্বিতীয়বার তৈরি করা, আর দুইটার একটা পরে ঠিক করতে
                     ভুলে যাওয়া। ছাপার নিজস্ব CSS আছে। --}}
                <button type="button" onclick="window.print()"
                        aria-label="{{ __('core.action.print') }}"
                        class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) px-2
                               text-sm text-(--color-ink-muted) transition-colors
                               hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                        <path d="M7 3h10v4H7V3ZM5 9h14a2 2 0 0 1 2 2v6h-4v4H7v-4H3v-6a2 2 0 0 1 2-2Zm4 8h6v4H9v-4Z"/>
                    </svg>
                    <span class="hidden xl:inline">{{ __('core.action.print') }}</span>
                </button>
            @endif

            @if ($refresh)
                {{-- ফর্মটা আবার জমা দেয়, তাই খোঁজা-সাজানো-ফিল্টার সব অক্ষত
                     থেকে শুধু ডেটা নতুন করে আসে। পাতা রিলোড করলে ওগুলো
                     থাকত, কিন্তু ব্রাউজার ফর্ম-জমা আবার পাঠাতে চায় কি না
                     জিজ্ঞেস করত। --}}
                <button type="submit"
                        aria-label="{{ __('core.toolbar.refresh') }}"
                        class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field) px-2
                               text-sm text-(--color-ink-muted) transition-colors
                               hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                        <path d="M12 5V2L8 6l4 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7Z"/>
                    </svg>
                    <span class="hidden xl:inline">{{ __('core.toolbar.refresh') }}</span>
                </button>
            @endif
        </div>
    </div>

    {{-- স্ক্রিনের নিজস্ব ফিল্টার — একটাই সারিতে, টেবিলের উপরে
         (সেকশন ১৫.৮), Filter By বোতামের নিচে। --}}
    @if ($hasFilters)
        <div id="toolbar-filters"
             x-show="filtersOpen"
             x-cloak
             class="flex flex-wrap items-center gap-2 border-t border-(--color-border)
                    bg-(--color-surface-app) px-3 py-2">
            {{ $slot }}

            <x-ui.button type="submit" tone="secondary">{{ __('core.action.search') }}</x-ui.button>
        </div>
    @endif
</div>
