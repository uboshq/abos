{{-- গোপনীয়তার ধাপ — রং কেবল সাহায্য করে, লেখাটাই মূল (§১৪)। ⓘ গোপন থেকে উপরে লাল, যাতে
     তালিকায় এক নজরে চোখে পড়ে কোন কাগজ সাবধানে নাড়তে হবে। --}}
@php
    $tone = match ($level) {
        'public' => 'success',
        'internal' => 'draft',
        'confidential' => 'pending',
        default => 'danger',
    };
@endphp
<x-ui.badge :tone="$tone">{{ __('documents::catalog.level.'.$level) }}</x-ui.badge>
