{{--
    ⭐ অফারের মেইল বন্ধ — লগইন ছাড়া পাতা ([[MailUnsubscribeController]]; NEXUS-এর পর্যালোচনা R2, RFC 8058)।
    ⓘ খুললেই বন্ধ হয় না — বোতামে চাপলে তবে (মেইল প্রোগ্রাম আগে থেকে লিংক খুলে দেখে)।
--}}
<x-auth.plain :title="__('mail::field.unsubscribe.title')" :lead="$done ? null : __('mail::field.unsubscribe.lead', ['email' => $email])">
    @if ($done)
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2.5 text-sm text-(--color-badge-success-ink)">
            {{ __('mail::field.unsubscribe.done', ['email' => $email]) }}
        </div>
        <p class="text-sm text-(--color-ink-muted)">{{ __('mail::field.unsubscribe.still') }}</p>
    @else
        <form method="POST" action="{{ $action }}">
            @csrf
            <x-ui.button type="submit" tone="primary" class="w-full">{{ __('mail::field.unsubscribe.button') }}</x-ui.button>
        </form>
        <p class="mt-3 text-sm text-(--color-ink-muted)">{{ __('mail::field.unsubscribe.still') }}</p>
    @endif
</x-auth.plain>
