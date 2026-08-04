@props([
    'search' => true,
    'searchPlaceholder' => null,
    'filter' => true,
    'sort' => [],
    'view' => false,
    'density' => true,
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
                    <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                        <path d="M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z"/>
                    </svg>
                    <span class="hidden xl:inline">{{ __('core.toolbar.density') }}</span>
                </button>
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
