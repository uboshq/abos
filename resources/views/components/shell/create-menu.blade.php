{{--
    ＋ তৈরি করুন — যা যা এখান থেকে শুরু করা যায়।

    ডান দিকের ঝাঁকের প্রথম জিনিস, কারণ এটাই একমাত্র যেটা কাজ *শুরু* করে;
    বাকিগুলো জানায় বা পর্দা বদলায়।

    <b>তালিকাটা এখানে, স্ক্রিনে নয়।</b> নতুন কিছু তৈরি করার পথ যোগ করতে হলে
    নিচের সারিতে এক লাইন — মডিউল যেদিন আসে সেদিন তার এন্ট্রিও আসে। এতে দুটো
    জিনিস ঠিক থাকে: ক্রমটা সব পর্দায় এক, আর কোনো মডিউল "আমারটা এখানে
    বসাতে ভুলে গেছি" অবস্থায় থাকে না।

    <b>যে রুট নেই, তার সারিও নেই।</b> Route::has() দিয়ে যাচাই করা হয়, তাই
    আধা-তৈরি মডিউলের এন্ট্রি মেনুতে এসে ৪০৪-এ নিয়ে যায় না। মেনুর মৃত সারি
    এই প্রকল্পে দুবার সরানো হয়েছে; তৃতীয়বার যেন না লাগে।

    ABOS-এ এখন কোনো লেনদেনের মডিউল নেই, তাই তালিকাটা খালি — আর মেনুটা সেটাই
    বলে, চুপ করে থাকে না। "কিছু নেই" জানা আর "কাজ করল না" ভাবা এক নয়।
--}}
@php
    /**
     * ['route' => রুটের নাম, 'label' => অনুবাদের চাবি, 'can' => অনুমতি|null]
     *
     * মডিউল এলে এখানেই এক সারি — Sales এলে বিক্রয় চালান, Purchase এলে ক্রয়
     * আদেশ, Accounts এলে ভাউচার।
     */
    $entries = collect([
        // ['route' => 'sales.invoice.create', 'label' => 'core.create.invoice', 'can' => 'sales.create'],
    ])->filter(fn ($e) => \Illuminate\Support\Facades\Route::has($e['route']))
      ->filter(fn ($e) => ! ($e['can'] ?? null) || auth()->user()?->can($e['can']))
      ->values();
@endphp

<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = ! open" @click.outside="open = false"
            @keydown.escape.window="open = false"
            :aria-expanded="open.toString()"
            class="flex min-h-(--spacing-touch) items-center gap-1.5 rounded-(--radius-field)
                   bg-(--color-brand-500) px-3 text-sm font-medium text-white
                   transition-colors hover:brightness-110">
        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
            <path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/>
        </svg>
        <span class="hidden sm:inline">{{ __('core.action.create') }}</span>
        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-3 shrink-0 fill-current opacity-70">
            <path d="M7 10l5 5 5-5H7Z"/>
        </svg>
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="pops-onto-page absolute end-0 z-30 mt-1 w-56 overflow-hidden rounded-(--radius-card)
                border border-(--color-border) bg-(--color-surface-card) shadow-lg">
        @forelse ($entries as $entry)
            <a href="{{ route($entry['route']) }}"
               class="block px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                {{ __($entry['label']) }}
            </a>
        @empty
            <p class="px-3 py-3 text-sm text-(--color-ink-muted)">
                {{ __('core.create.nothing_yet') }}
            </p>
        @endforelse
    </div>
</div>
