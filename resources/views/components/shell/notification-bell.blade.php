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

<div x-data="notifyBell" class="relative" data-notify-bell-root
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
        <x-ui.icon name="bell" :size="18" />

        {{-- সংখ্যাটা ব্যাজে, কারণ "কিছু একটা আছে" আর "সাতটা আছে" দুটো
             আলাদা খবর, আর দ্বিতীয়টাই ঠিক করে দেয় এখনই দেখব না পরে।
             ⓘ শূন্যে লুকানো (`x-show`) — polling চালু থাকলে সংখ্যাটা নিজে বদলায়; বন্ধ থাকলে পাতার সংখ্যাই থাকে। --}}
        <span x-show="hasCount" x-text="count" @if ($total === 0) x-cloak @endif data-notify-count
              @class([
                  'absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full px-1',
                  'text-[10px] font-semibold leading-4 text-white',
                  'bg-(--color-danger)' => $urgent,
                  'bg-(--color-brand-500)' => ! $urgent,
              ])>{{ $total }}</span>
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="pops-onto-page absolute end-0 z-30 mt-1 w-80 overflow-hidden rounded-(--radius-card)
                border border-(--color-border) bg-(--color-surface-card) shadow-lg">

        @if ($mine->isNotEmpty())
            <div class="flex items-center justify-between border-b border-(--color-border) px-3 py-2">
                <p class="text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
                    {{ __('core.notify.title') }}
                </p>
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="text-2xs text-(--color-brand-500) hover:underline">
                        {{ __('core.notify.mark_all') }}
                    </button>
                </form>
            </div>

            {{-- ⭐ ছাঁকনি — সব / না-পড়া / অনুমোদন / কাজ / ব্যবস্থা (স্পেক §৯ক); কেবল দেখানো-লুকানো, সারি সার্ভারের --}}
            <div class="flex gap-1 overflow-x-auto border-b border-(--color-border) px-2 py-1 text-2xs" role="tablist">
                @foreach (['all', 'unread', 'approval', 'task', 'system'] as $tab)
                    <button type="button" role="tab" @click="pick('{{ $tab }}')"
                            :class="picked('{{ $tab }}') ? 'bg-(--color-surface-hover) font-semibold' : ''"
                            class="whitespace-nowrap rounded-(--radius-field) px-2 py-0.5">{{ __('core.notify.drawer.'.$tab) }}</button>
                @endforeach
            </div>

            <div class="max-h-96 overflow-y-auto">
                @foreach ($mine as $note)
                    {{--
                        খবরটা খোলা মানেই পড়া — তাই লিংকে ক্লিক করলে সারিটা
                        পড়া হিসেবে বসে। ক্লিক না করলে বসে না, কারণ ঘণ্টা
                        খোলা আর খবরটা পড়া এক জিনিস নয়: কেউ ঘণ্টা খুলে
                        দেখে বন্ধ করে দিতেই পারেন, আর তখন খবরটা হারিয়ে
                        গেলে সেটাই সবচেয়ে খারাপ।
                        ⓘ খোলার আগে দেখা হয় কাগজটা এখনো নাগালে কি না ([[NotificationController::open()]])।
                    --}}
                    <div x-show="shows('{{ $note->category }}', true)" data-bell-note="{{ $note->id }}"
                         class="flex items-start gap-2 border-b border-(--color-border) px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                        <span @class([
                            'mt-1.5 size-1.5 shrink-0 rounded-full',
                            'bg-(--color-danger)' => $note->isCritical(),
                            'bg-(--color-brand-500)' => ! $note->isCritical(),
                        ]) aria-hidden="true"></span>
                        <a href="{{ route('notifications.open', $note) }}" class="min-w-0 flex-1">
                            <span class="block font-medium">
                                @if ($note->isCritical())
                                    <span class="me-1 rounded-full bg-(--color-danger) px-1.5 text-[10px] text-white" data-critical>{{ __('core.notify.critical_mark') }}</span>
                                @endif
                                {{ $note->title }}
                            </span>
                            @if ($note->body)
                                <span class="block text-2xs text-(--color-ink-muted)">{{ $note->body }}</span>
                            @endif
                            <span class="block text-[10px] text-(--color-ink-muted)">
                                {{ \App\Core\Support\NotificationKinds::sourceLabel($note->module) }}
                                · {{ __('core.notify.priority.'.$note->priority) }}
                                · {{ $note->created_at?->diffForHumans() }}
                            </span>
                        </a>
                        <span class="flex shrink-0 flex-col items-end gap-1 text-[10px]">
                            <form method="POST" action="{{ route('notifications.read', $note) }}">
                                @csrf
                                <button type="submit" class="text-(--color-brand-500) hover:underline">{{ __('core.notify.action.read') }}</button>
                            </form>
                            <form method="POST" action="{{ route('notifications.archive', $note) }}">
                                @csrf
                                <button type="submit" class="text-(--color-ink-muted) hover:underline">{{ __('core.notify.action.archive') }}</button>
                            </form>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="border-b border-(--color-border) px-3 py-2 text-2xs font-semibold uppercase
                  tracking-wide text-(--color-ink-muted)">
            {{ __('core.notice.title') }}
        </p>

        @forelse ($notices as $notice)
            @php
                $tone = match ($notice['tone']) {
                    'danger' => 'text-(--color-danger)',
                    'pending' => 'text-(--color-badge-pending-ink)',
                    default => 'text-(--color-badge-info-ink)',
                };
            @endphp

            @if ($notice['url'])
                <a href="{{ $notice['url'] }}"
                   class="flex items-start gap-2 px-3 py-2 text-sm hover:bg-(--color-surface-hover)">
                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-current {{ $tone }}" aria-hidden="true"></span>
                    <span class="min-w-0">{{ $notice['text'] }}</span>
                </a>
            @else
                {{-- লিংক নেই যেগুলোর কোনো পর্দা নেই: ব্যাকআপ কমান্ড লাইনের
                     কাজ, প্রতিষ্ঠানের নোটিশ পড়ার জিনিস। যে লিংক কোথাও নিয়ে
                     যায় না, সেটা মৃত লিংক। --}}
                <p class="flex items-start gap-2 px-3 py-2 text-sm">
                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-current {{ $tone }}" aria-hidden="true"></span>
                    <span class="min-w-0">{{ $notice['text'] }}</span>
                </p>
            @endif
        @empty
            {{-- এই একটা জায়গায় "সব ঠিক আছে" লেখা যায়: ঘণ্টাটা খোলা হয়েছে
                 বলেই, নিজে থেকে জায়গা নিয়ে বসে নেই। --}}
            <p class="px-3 py-4 text-sm text-(--color-ink-muted)">
                {{ __('core.notice.none') }}
            </p>
        @endforelse

        {{-- ⭐ "কোন খবর পাব" — ২০ সেপ্টেম্বর ২০২৬, মালিকের *"বিজ্ঞপ্তির
             সেটিংস ta koro"*।

             ⓘ সেটিংসের দরজাটা ঘণ্টার ভিতরেই, কারণ প্রশ্নটা ঠিক তখনই মাথায়
             আসে যখন একটা অবাঞ্ছিত খবর চোখে পড়ে। প্রোফাইলের গভীরে রাখলে
             মানুষ খুঁজতেন না, খবরগুলো দেখাই ছেড়ে দিতেন। --}}
        {{-- ⭐ নিজের সব খবর — পড়া, আর্কাইভ, খোঁজা (স্পেক §৯খ) --}}
        <a href="{{ route('notifications.index') }}" data-notify-all
           class="flex items-center gap-2 border-t border-(--color-border) px-3 py-2 text-2xs
                  text-(--color-brand-500) hover:bg-(--color-surface-hover)">
            <x-ui.icon name="bell" class="size-3.5" />
            {{ __('core.notify.see_all') }}
        </a>

        <a href="{{ route('notifications.settings') }}"
           class="flex items-center gap-2 border-t border-(--color-border) px-3 py-2 text-2xs
                  text-(--color-ink-muted) hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
            <x-ui.icon name="settings" class="size-3.5" />
            {{ __('core.notify.settings_link') }}
        </a>
    </div>
</div>
