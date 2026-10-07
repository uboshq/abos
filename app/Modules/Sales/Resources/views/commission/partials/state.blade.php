@php
    $badge = match ($claim->status) {
        \App\Modules\Sales\Models\CommissionClaim::SETTLED => 'success',
        \App\Modules\Sales\Models\CommissionClaim::REJECTED => 'danger',
        default => 'pending',
    };
@endphp

<span class="rounded-(--radius-field) bg-(--color-badge-{{ $badge }}-bg)
             px-2 py-0.5 text-2xs text-(--color-badge-{{ $badge }}-ink)">
    {{ __('sales::field.commission_'.$claim->status) }}
</span>

@if ($claim->decision_reason)
    <p class="mt-0.5 text-2xs text-(--color-ink-muted)">{{ $claim->decision_reason }}</p>
@endif
