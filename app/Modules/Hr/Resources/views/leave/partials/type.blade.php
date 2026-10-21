{{-- ⭐ ছুটির ধরন, আর মাতৃত্বকালীন ছুটিতে জীবিত সন্তানের "তথ্য নেই" হলে সইকারীর সতর্কতা (ধারা ৪৬(৩); [[LeaveApplication::signerWarning()]]; a6, ২১ অক্টোবর ২০২৬) --}}
<span>{{ $application->leaveType?->name() ?? '—' }}</span>
@if ($warning = $application->signerWarning())
    <span data-leave-warning class="mt-1 block text-2xs text-(--color-badge-warning-ink)">⚠ {{ $warning }}</span>
@endif
