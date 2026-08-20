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

    $urgent = collect($notices)->firstWhere('tone', 'danger') !== null;
    $total = count($notices) + $mine->count();
@endphp

<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = !open"
            @click.outside="open = false"
            @keydown.escape.window="open = false"
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

        @if ($total > 0)
            {{-- সংখ্যাটা ব্যাজে, কারণ "কিছু একটা আছে" আর "সাতটা আছে" দুটো
                 আলাদা খবর, আর দ্বিতীয়টাই ঠিক করে দেয় এখনই দেখব না পরে। --}}
            <span @class([
                'absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full px-1',
                'text-[10px] font-semibold leading-4 text-white',
                'bg-(--color-danger)' => $urgent,
                'bg-(--color-brand-500)' => ! $urgent,
            ])>{{ $total }}</span>
        @endif
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

            @foreach ($mine as $note)
                {{--
                    খবরটা খোলা মানেই পড়া — তাই লিংকে ক্লিক করলে সারিটা
                    পড়া হিসেবে বসে। ক্লিক না করলে বসে না, কারণ ঘণ্টা
                    খোলা আর খবরটা পড়া এক জিনিস নয়: কেউ ঘণ্টা খুলে
                    দেখে বন্ধ করে দিতেই পারেন, আর তখন খবরটা হারিয়ে
                    গেলে সেটাই সবচেয়ে খারাপ।
                --}}
                <a href="{{ route('notifications.open', $note) }}"
                   class="flex items-start gap-2 border-b border-(--color-border) px-3 py-2 text-sm
                          hover:bg-(--color-surface-hover)">
                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-(--color-brand-500)"
                          aria-hidden="true"></span>
                    <span class="min-w-0">
                        <span class="block font-medium">{{ $note->title }}</span>
                        @if ($note->body)
                            <span class="block text-2xs text-(--color-ink-muted)">{{ $note->body }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
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
    </div>
</div>
