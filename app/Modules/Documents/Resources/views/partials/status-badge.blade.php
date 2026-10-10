{{-- অবস্থা (§২২) — দশটা, প্রতিটার নিজের রং --}}
@php
    $tone = match ($status) {
        'approved', 'published' => 'success',
        'submitted', 'under_review' => 'pending',
        'changes_requested', 'rejected', 'expired', 'deleted' => 'danger',
        'archived', 'published_unapproved' => 'info',
        default => 'draft',
    };
@endphp
<x-ui.badge :tone="$tone">{{ __('documents::catalog.status.'.$status) }}</x-ui.badge>
