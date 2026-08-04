@props([
    'search' => true,
    'filter' => true,
    'columns' => true,
    'density' => true,
    'export' => true,
    'print' => true,
    'refresh' => true,
])

{{--
    One Toolbar Standard — সেকশন ১৫.২৪।

    সব গ্রিড ও রিপোর্টে হুবহু একই টুলবার, একই ক্রমে। প্রতিটা স্ক্রিনে আলাদা
    করে বানালে একটায় Export বাঁয়ে আর অন্যটায় ডানে চলে যেত, আর ব্যবহারকারীকে
    প্রতিটা স্ক্রিন আলাদা করে শিখতে হত।

    যে বোতাম এই স্ক্রিনে অর্থহীন সেটা বাদ দেওয়া যায় (:print="false"), কিন্তু
    নতুন বোতাম এখানেই যোগ করতে হবে — স্ক্রিনে নয়।
--}}
<div {{ $attributes->merge([
        'class' => 'flex flex-wrap items-center gap-2 border-b border-(--color-border) bg-(--color-surface-card) px-3 py-2',
    ]) }}>

    @if ($search)
        <label class="relative min-w-0 flex-1 sm:max-w-xs">
            <span class="sr-only">{{ __('core.action.search') }}</span>
            <input type="search" name="q" value="{{ request('q') }}"
                   placeholder="{{ __('core.action.search') }}"
                   class="h-9 w-full rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) ps-8 pe-3 text-sm">
            <svg viewBox="0 0 24 24" aria-hidden="true"
                 class="pointer-events-none absolute start-2 top-1/2 size-4 -translate-y-1/2
                        fill-(--color-ink-muted)">
                <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
            </svg>
        </label>
    @endif

    {{-- স্ক্রিনের নিজস্ব ফিল্টার — একটাই সারিতে, চার্টের উপরে (সেকশন ১৫.৮) --}}
    {{ $slot }}

    <div class="ms-auto flex items-center gap-1">
        @if ($filter)
            <x-ui.toolbar-button icon="filter" :label="__('core.toolbar.filter')" />
        @endif

        @if ($columns)
            <x-ui.toolbar-button icon="columns" :label="__('core.toolbar.columns')" />
        @endif

        @if ($density)
            <x-ui.toolbar-button icon="density" :label="__('core.toolbar.density')" />
        @endif

        @if ($export)
            <x-ui.toolbar-button icon="export" :label="__('core.action.export')" />
        @endif

        @if ($print)
            <x-ui.toolbar-button icon="print" :label="__('core.action.print')" />
        @endif

        @if ($refresh)
            <x-ui.toolbar-button icon="refresh" :label="__('core.toolbar.refresh')" />
        @endif
    </div>
</div>
