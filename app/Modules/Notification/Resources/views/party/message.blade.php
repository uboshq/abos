{{--
    ⭐ খাতার সারির "লেখাটা দেখুন" — ঠিক যে লেখা গেছে, কাকে, কোন নম্বরে, কোন ভাষায় (ধাপ ৩, নমুনা HUXqpfhxng8823eEFrvH54 ট্যাব গ)।
    ⓘ তালিকার উপর পপআপে খোলে (সাধারণ লিংক, [[peek]]); শাখার দেয়ালের ভিতরে (রুটের বাঁধন)।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::party_log.message_title') }}</x-slot:title>

    <div class="space-y-4" data-party-message="{{ $message->id }}">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-lg font-semibold">{{ __('notification::party_log.message_title') }}</h1>
            <a href="{{ route('notification.party_messages.index') }}" class="text-sm text-(--color-brand-500) hover:underline">{{ __('notification::party_log.back') }}</a>
        </div>

        <dl class="grid gap-x-6 gap-y-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm sm:grid-cols-[auto_1fr]">
            <dt class="text-(--color-ink-muted)">{{ __('notification::party_log.when') }}</dt>
            <dd class="num">{{ \App\Core\Support\DateFormat::formatWithTime($message->created_at) }}</dd>
            <dt class="text-(--color-ink-muted)">{{ __('notification::party_log.party') }}</dt>
            <dd>{{ $message->party_name ?? '—' }}</dd>
            <dt class="text-(--color-ink-muted)">{{ __('notification::party_log.phone') }}</dt>
            <dd class="num">+{{ $message->phone }}</dd>
            <dt class="text-(--color-ink-muted)">{{ __('notification::party_log.paper') }}</dt>
            <dd class="num">{{ $message->document_no ?? ($message->template?->name ?? '—') }}</dd>
            <dt class="text-(--color-ink-muted)">{{ __('notification::party_log.road') }}</dt>
            <dd>{{ __('notification::party.channels.'.($message->channel === 'sms' ? 'sms' : 'whatsapp')) }}</dd>
            <dt class="text-(--color-ink-muted)">{{ __('notification::party_log.language') }}</dt>
            <dd>{{ __('notification::party.locales.'.($message->locale === 'en' ? 'en' : 'bn')) }}</dd>
            @if ($message->template)
                <dt class="text-(--color-ink-muted)">{{ __('notification::party_log.template') }}</dt>
                <dd>{{ $message->template->name }}</dd>
            @endif
        </dl>

        <p class="whitespace-pre-line rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-sunken) p-4 text-sm" data-party-body>{{ $message->body }}</p>
    </div>
</x-layouts.app>
