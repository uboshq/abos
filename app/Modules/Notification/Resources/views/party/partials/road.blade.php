{{-- ⓘ পথ — নিজের চিহ্ন (WhatsApp-এর লোগো নয়, মালিকের নিয়ম) আর নাম --}}
<span class="inline-flex items-center gap-1.5 whitespace-nowrap" data-party-channel="{{ $message->channel }}">
    <x-ui.icon :name="$message->channel === 'sms' ? 'phone' : 'chat'" :size="15"
               :class="$message->channel === 'sms' ? 'text-(--color-brand-600)' : 'text-(--color-badge-success-ink)'" />
    {{ $name }}
</span>
