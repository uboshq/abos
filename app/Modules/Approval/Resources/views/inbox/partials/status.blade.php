@php
    $tone = match ($approval->status) {
        \App\Models\Approval::APPROVED => 'success',
        \App\Models\Approval::REJECTED => 'danger',
        \App\Models\Approval::CANCELLED => 'draft',
        default => 'pending',
    };
@endphp

<x-ui.badge :tone="$tone">{{ __('approval::status.'.$approval->status) }}</x-ui.badge>
