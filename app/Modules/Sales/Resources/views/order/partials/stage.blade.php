{{-- আদেশের ধাপ — একটা ব্যাজ, আর রঙটাই বলে কাজ বাকি কি না।

     ⓘ ধাপ ঠিক করে [[OrderTracking::stageOf()]], পর্দা নয় — নাহলে
     ট্যাবের সংখ্যা আর সারির লেখা একদিন আলাদা কথা বলত। --}}
@php
    $tone = match ($stage) {
        \App\Modules\Sales\Services\OrderTracking::BILLED => 'success',
        \App\Modules\Sales\Services\OrderTracking::DELIVERED => 'pending',
        \App\Modules\Sales\Services\OrderTracking::PARTIAL => 'warning',
        default => 'muted',
    };
@endphp

<span @class([
    'inline-flex rounded-full px-2 py-0.5 text-2xs font-medium',
    'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $tone === 'success',
    'bg-(--color-badge-pending-bg) text-(--color-badge-pending-ink)' => $tone === 'pending',
    'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => $tone === 'warning',
    'bg-(--color-surface-sunken) text-(--color-ink-muted)' => $tone === 'muted',
])>
    {{ __('sales::field.stage_'.$stage) }}
</span>
