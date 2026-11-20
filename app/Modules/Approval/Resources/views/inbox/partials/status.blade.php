@php
    $tone = match ($approval->status) {
        \App\Models\Approval::APPROVED => 'success',
        \App\Models\Approval::REJECTED => 'danger',
        \App\Models\Approval::CANCELLED => 'draft',
        \App\Models\Approval::RETURNED => 'info',
        default => 'pending',
    };
@endphp

<x-ui.badge :tone="$tone">{{ __('approval::status.'.$approval->status) }}</x-ui.badge>
{{-- ⭐ মালিক নিজের অনুরোধে নিজে অনুমোদন দিলেন — স্থায়ী দাগ, ইতিহাসের তালিকাতেও (মালিক, ২০ নভেম্বর ২০২৬; [[ui.owner-self-mark]]) --}}
@if ($approval->status === \App\Models\Approval::APPROVED)
    <x-ui.owner-self-mark :approval="$approval" class="mt-1 inline-block" />
@endif
