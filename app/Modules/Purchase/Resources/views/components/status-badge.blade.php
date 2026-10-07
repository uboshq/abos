{{-- ডকুমেন্টের অবস্থা — এক নজরে। --}}
@php
    $tone = match ($document->status) {
        \App\Core\Support\DocumentStatus::CONFIRMED => 'success',
        \App\Core\Support\DocumentStatus::CANCELLED => 'danger',
        default => 'muted',
    };
@endphp

<span class="inline-flex items-center rounded-full px-2 py-0.5 text-2xs
             bg-(--color-badge-{{ $tone }}-bg) text-(--color-badge-{{ $tone }}-ink)">
    {{ \App\Core\Support\DocumentStatus::label($document->status) }}
</span>
