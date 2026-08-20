@php
    $badge = match ($cheque->status) {
        \App\Modules\Accounts\Models\Cheque::CLEARED => 'success',
        \App\Modules\Accounts\Models\Cheque::BOUNCED,
        \App\Modules\Accounts\Models\Cheque::CANCELLED => 'danger',
        \App\Modules\Accounts\Models\Cheque::DEPOSITED => 'info',
        default => 'pending',
    };
@endphp

<span class="rounded-(--radius-field) bg-(--color-badge-{{ $badge }}-bg)
             px-2 py-0.5 text-2xs text-(--color-badge-{{ $badge }}-ink)">
    {{ __('accounts::field.cheque_'.$cheque->status) }}
</span>

@if ($cheque->bounce_reason)
    <p class="mt-0.5 text-2xs text-(--color-ink-muted)">{{ $cheque->bounce_reason }}</p>
@endif
