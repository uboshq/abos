{{-- ⭐ ঘণ্টার ভিতরের তালিকা — ঘণ্টায় সরাসরি, বা তিন ABOS রূপে প্রথমবার খুললে তবে আনা (মালিকের নমুনা, ঘণ্টা ও রেল দ্রুত, ২৬ নভেম্বর ২০২৬)।
     চাই: $mine (নিজের না-পড়া খবর), $notices (ব্যবস্থার নোটিশ) --}}
{{-- ⭐ আপনার কাজ — "সই হয়েছে, খাতায় ওঠেনি" বার থেকে এখানে এল (ওয়েবের মান ধাপ ২, ২০ অক্টোবর ২০২৬; Fiori: কাজ নোটিশের
     ভেতরে)। ⓘ চিহ্ন আর লিংক যেমন ছিল ([[shell.signed-unposted]]), পাশে নাম লেখা। আমার সইয়ের অপেক্ষা বারেই থাকে। --}}
<div class="flex items-center gap-2 border-b border-(--color-border) px-3 py-2 text-sm" data-bell-tasks>
    <x-shell.signed-unposted />
    <span>{{ __('core.notify.signed_unposted') }}</span>
</div>

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
                            <span class="me-1 rounded-full bg-(--color-count-urgent) px-1.5 text-[10px] text-(--color-count-ink)" data-critical>{{ __('core.notify.critical_mark') }}</span>
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
