{{-- কী কী বদলেছে তার সারাংশ — তিনটা পর্যন্ত, তারপর গোনা।

     সবগুলো দেখালে একটা নতুন কর্মীর সারি চল্লিশ লাইন হত আর তালিকাটা
     পড়া যেত না। তিনটাতে বেশিরভাগ প্রশ্নের উত্তর মেলে; বাকিটা ভেতরে। --}}
@php $changes = $trail->changes @endphp

@if ($changes->isEmpty())
    <span class="text-2xs text-(--color-ink-muted)">—</span>
@else
    <span class="flex flex-wrap gap-1">
        @foreach ($changes->take(3) as $change)
            <span class="rounded-(--radius-field) bg-(--color-surface-hover) px-1.5 py-0.5 text-2xs">
                {{ $change->field }}:
                <span class="text-(--color-ink-muted)">{{ \Illuminate\Support\Str::limit($change->old_value ?? '—', 14) }}</span>
                →
                {{ \Illuminate\Support\Str::limit($change->new_value ?? '—', 14) }}
            </span>
        @endforeach

        @if ($changes->count() > 3)
            <span class="text-2xs text-(--color-ink-muted)">+{{ $changes->count() - 3 }}</span>
        @endif
    </span>
@endif
