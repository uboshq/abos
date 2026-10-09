{{-- অবস্থা (§২২) — আজ খসড়া, অনুমোদিত, আর্কাইভে --}}
@php
    $tone = match ($status) {
        'approved' => 'success',
        'archived' => 'info',
        default => 'draft',
    };
@endphp
<x-ui.badge :tone="$tone">{{ __('documents::catalog.status.'.$status) }}</x-ui.badge>
