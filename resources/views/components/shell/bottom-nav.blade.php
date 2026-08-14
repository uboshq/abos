@props(['menu' => []])

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

<nav class="fixed inset-x-0 bottom-0 z-30 flex h-(--spacing-bottom-nav) items-stretch
            border-t border-(--color-border) bg-(--color-surface-card) md:hidden"
     aria-label="{{ __('core.a11y.main_navigation') }}">

    @foreach ($primary as $module)
        @php
            $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null)
                ?? collect($module['groups'])->flatten(1)->first();
            $active = collect($module['groups'])->flatten(1)->contains('active', true);
        @endphp

        <a @if ($first && $first['url']) href="{{ $first['url'] }}" @endif
           @class([
               'flex flex-1 flex-col items-center justify-center gap-0.5 px-1 text-2xs transition-colors',
               'text-(--color-brand-500) font-medium' => $active,
               'text-(--color-ink-muted)' => ! $active,
           ])
           @if ($active) aria-current="page" @endif>
            {{-- মোবাইলেও মডিউলের নিজের আকার — পাঁচটা আইটেম একরকম দেখালে
                 নিচের বারটা পড়া ছাড়া কাজে লাগে না।

                 এখানে রঙিন টাইল নয়, currentColor: নিচের বারে লেখাটা
                 আইকনের ঠিক নিচেই থাকে, তাই চেনার কাজটা লেখাই করে। বারোটা
                 রঙিন টাইল পাঁচ ইঞ্চির পর্দায় পাশাপাশি বসালে ওটা একটা
                 রঙের সারি হয়ে যেত, আর সক্রিয় কোনটা তা বোঝা যেত না —
                 সক্রিয়তা এখানে রং দিয়েই বলা হয়। --}}
            <x-ui.icon :name="$module['icon']" :size="20" />
            <span class="w-full truncate text-center">{{ $module['label'] }}</span>
        </a>
    @endforeach

    @if ($hasMore)
        <button type="button"
                x-data
                @click="$dispatch('open-command-center')"
                class="flex flex-1 flex-col items-center justify-center gap-0.5 px-1 text-2xs
                       text-(--color-ink-muted)">
            <svg viewBox="0 0 24 24" class="size-(--spacing-icon) fill-current" aria-hidden="true">
                <path d="M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z"/>
            </svg>
            <span>{{ __('core.action.more') }}</span>
        </button>
    @endif
</nav>
