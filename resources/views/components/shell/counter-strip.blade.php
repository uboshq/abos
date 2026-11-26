@props(['title' => ''])
{{-- ⭐ কাউন্টার মোডের পাতলা সারি — কোম্পানি · শাখা · কে বিল করছেন, ডানে ঘণ্টা আর "✕ বন্ধ করুন" (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা) --}}
@php
    $user = auth()->user();
@endphp
<div data-counter-strip
     class="sticky top-0 z-40 flex h-10 items-center gap-3 px-4 text-sm text-(--color-ink-inverse) print-hide"
     style="background: var(--color-brand-700, #0f2d6b)">
    {{-- ⓘ ABOS-এর জীবন্ত লোগো, ছোট — সাদা ঘরে (মালিক, ২৬ নভেম্বর ২০২৬) --}}
    <span class="flex h-7 items-center rounded-(--radius-field) bg-(--color-surface-card) px-2">
        <x-brand.live-logo style="height: 20px; width: auto;" />
    </span>
    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" style="background: var(--color-warning)">
        {{ __('core.counter.mode') }} · {{ $title }}
    </span>
    <span class="truncate">{{ $user?->currentCompany?->name() }}@if ($user?->currentBranch) · {{ $user->currentBranch->name() }}@endif</span>
    <span class="hidden truncate opacity-80 md:inline">{{ __('core.counter.billing_by', ['name' => $user?->name]) }}</span>
    <span class="ms-auto flex items-center gap-2">
        <x-shell.notification-bell />
        <button type="button" data-counter-close
                class="rounded-(--radius-field) bg-(--color-surface-card) px-3 py-1 text-xs font-bold text-(--color-danger)">✕ {{ __('core.counter.close') }}</button>
    </span>
</div>
