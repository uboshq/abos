{{--
    ঘণ্টা — এই মুহূর্তে যা নজরে আনার মতো।

    একই তথ্য নিচের স্ট্যাটাস বারেও চলে, আর সেটা ইচ্ছে করেই: বারটা চোখের
    কোণে থাকে, ঘণ্টাটা হাতের কাছে। বারে একটা লম্বা নোটিশ বাকিগুলোকে কেটে
    দেয়, আর মোবাইলে বারটা থাকেই না — তখন ঘণ্টাই একমাত্র পথ।

    <b>নতুন কোনো অনুসন্ধান নয়।</b> StatusNotices যা বারের জন্য একবার হিসাব
    করে রাখে (এক মিনিটের ক্যাশ), ঘণ্টাটা সেটাই পড়ে। প্রতিটা পাতায় আরও
    একটা ডেটাবেস প্রশ্ন যোগ করা এমন একটা দাম যা এই তথ্যটার নেই।

    <b>কিছু না থাকলে ঘণ্টা চুপ।</b> সংখ্যার লাল বিন্দুটা তখনই বসে যখন
    সত্যিই কিছু আছে — "০" লেখা ব্যাজ প্রতিদিন দেখে লোকে ব্যাজটাই দেখা বন্ধ
    করে দেয়, আর যেদিন সংখ্যাটা ৩ হয় সেদিনও দেখে না।
--}}
@php
    $notices = auth()->check()
        ? app(\App\Core\Services\StatusNotices::class)->all()
        : [];

    /*
     * ব্যক্তিগত খবর — ব্যবস্থার নোটিশ থেকে আলাদা, আর উপরে।
     *
     * StatusNotices বলে "ব্যবস্থার কী অবস্থা" (ব্যাকআপ বাকি, খসড়া
     * ঝুলে আছে)। এগুলো বলে "আপনার কী হলো" — আপনার দাবি অনুমোদিত হলো,
     * বা ফেরত এল। দ্বিতীয়টা প্রায় সবসময়ই আগে দেখার জিনিস, তাই আগে।
     *
     * সংখ্যাটাও দুইটার যোগফল: ঘণ্টায় ৩ দেখে খুলে ২টা পাওয়া গেলে
     * ব্যবহারকারী সংখ্যাটাকে আর বিশ্বাস করেন না।
     */
    $mine = auth()->check()
        ? app(\App\Core\Services\NotificationService::class)->unreadFor(auth()->id())
        : collect();

    /*
     * ⭐ বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১ (মালিকের স্পেক §৯ক): জরুরি খবরে ব্যাজ লাল, আর polling-এর সুইচ।
     * ⛔ polling ডিফল্টে বন্ধ (মালিক ২১ অক্টোবর ২০২৬ পর্যন্ত স্থগিত রেখেছেন) — বন্ধ থাকলে `data-poll-seconds` শূন্য।
     */
    $settings = app(\App\Core\Services\SettingsService::class);
    $pollSeconds = auth()->check() && $settings->get('notification.bell_polling', false)
        ? max(15, (int) $settings->get('notification.bell_poll_seconds', 60))
        : 0;

    $urgent = collect($notices)->firstWhere('tone', 'danger') !== null
        || $mine->contains(fn ($note) => $note->isCritical());
    $total = count($notices) + $mine->count();
@endphp

{{-- ⓘ data-notification-bell — যন্ত্রের সতর্কতা এখন কেবল এখানে আর উপরের ব্যানারে, নিচের বারে নয়; পরীক্ষা এই ঘরটা ধরে
     দেখে সতর্কতা হারায়নি (পাতা সাজানো ধাপ ১, ১০ অক্টোবর ২০২৬) --}}
<div x-data="notifyBell" class="relative" data-notify-bell-root data-notification-bell
     data-count="{{ $total }}" data-base="{{ count($notices) }}"
     data-poll-seconds="{{ $pollSeconds }}" data-poll-url="{{ auth()->check() ? route('notifications.unread-count') : '' }}">
    <button type="button"
            @click="toggle"
            @click.outside="close"
            @keydown.escape.window="close"
            :aria-expanded="open.toString()"
            class="relative flex size-(--spacing-touch) items-center justify-center rounded-(--radius-field)
                   text-(--color-ink-muted) transition-colors hover:bg-(--color-surface-hover)"
            title="{{ __('core.notice.title') }}"
            aria-label="{{ __('core.notice.title') }}">
        {{-- ঘণ্টাটা আঁকা, ইমোজি নয়।

             আগে এখানে ইমোজি ছিল, যুক্তি ছিল "ubos-dms-এও তাই, তাই দুই
             পণ্যে টপবার একরকম দেখায়"। কিন্তু ইমোজির আঁকাটা ফন্টের, আর
             ফন্ট মেশিনভেদে আলাদা — ফলে টপবারটা এক পণ্যের দুই মেশিনেই
             দুই রকম দেখাত, দুই পণ্যে এক তো দূরের কথা। পাশের অনুমোদনের
             সিলটা এই কারণেই আগে থেকেই আঁকা। --}}
        {{-- ⭐ রঙিন জমিনে সাদা চিহ্ন — মালিক, ৬ নভেম্বর ২০২৬: "top barer icon golo rongin colore korbe" --}}
        <span class="grid size-8 place-items-center" data-topbar-icon="bell" style="color: var(--color-topbar-glyph-bell)">
            <x-ui.icon name="bell" :size="26" />
        </span>

        {{-- সংখ্যাটা ব্যাজে, কারণ "কিছু একটা আছে" আর "সাতটা আছে" দুটো
             আলাদা খবর, আর দ্বিতীয়টাই ঠিক করে দেয় এখনই দেখব না পরে।
             ⓘ শূন্যে লুকানো (`x-show`) — polling চালু থাকলে সংখ্যাটা নিজে বদলায়; বন্ধ থাকলে পাতার সংখ্যাই থাকে।
             ⓘ রং থিমের টোকেনে (অনুমোদন ভাগ ৩, দফা ১৯) — গাঢ় থিমেও সংখ্যা ৪.৫:১-এর উপরে। --}}
        <span x-show="hasCount()" x-text="count" @if ($total === 0) x-cloak @endif data-notify-count
              @class([
                  'absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full px-1',
                  'text-[10px] font-semibold leading-4',
                  'bg-(--color-count-urgent) text-(--color-count-ink)' => $urgent,
                  'bg-(--color-count-info) text-(--color-count-info-ink)' => ! $urgent,
              ])>{{ $total }}</span>
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="pops-onto-page absolute end-0 z-30 mt-1 w-80 overflow-hidden rounded-(--radius-card)
                border border-(--color-border) bg-(--color-surface-card) shadow-lg">
        {{-- ⭐ তিন ABOS রূপে তালিকাটা পাতার সাথে আসে না — প্রথমবার খুললে আনা হয়, তারপর মনে থাকে ([[notifyBell]]; প্রতিটা পাতা প্রায় ৪৭ KB হালকা)।
             ⓘ গোনা আর লাল দাগ আগের মতোই সঙ্গে সঙ্গে; বাকি রূপে তালিকা আগের মতোই সরাসরি। --}}
        @if (\App\Core\Support\AbosHead::on() && auth()->check())
            <div data-bell-list data-bell-url="{{ route('notifications.index', ['part' => 'bell']) }}">
                <div class="space-y-2 p-3" data-bell-wait aria-busy="true">
                    <div class="h-3 animate-pulse rounded bg-(--color-surface-hover)"></div>
                    <div class="h-3 w-4/5 animate-pulse rounded bg-(--color-surface-hover)"></div>
                    <div class="h-3 w-3/5 animate-pulse rounded bg-(--color-surface-hover)"></div>
                </div>
                <p class="hidden px-3 py-3 text-sm" data-bell-failed>
                    {{ __('core.notify.load_failed') }}
                    <a href="{{ route('notifications.index') }}" class="text-(--color-link) hover:underline">{{ __('core.notify.see_all') }}</a>
                </p>
            </div>
        @else
            @include('components.shell.partials.bell-list')
        @endif
    </div>
</div>
