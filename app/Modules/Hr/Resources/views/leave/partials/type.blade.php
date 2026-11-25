{{-- ⭐ ছুটির ধরন, আর মাতৃত্বকালীন ছুটিতে জীবিত সন্তানের "তথ্য নেই" হলে সইকারীর সতর্কতা (ধারা ৪৬(৩); [[LeaveApplication::signerWarning()]]; a6, ২১ অক্টোবর ২০২৬) --}}
<span>{{ $application->leaveType?->name() ?? '—' }}</span>
{{-- ⭐ গড় দৈনিক মজুরির নিয়ম — উৎসব বোনাস মজুরি নয় (ধারা ২(৪৫), ৪৮; fe, ২১ অক্টোবর ২০২৬; [[MaternityBenefit::averageDailyWage()]]) --}}
@if ($application->leaveType?->isMaternity())
    <span data-maternity-wage-rule class="mt-1 block text-2xs text-(--color-ink-muted)">{{ __('hr::message.maternity_wage_rule') }}</span>
@endif
@if ($warning = $application->signerWarning())
    <span data-leave-warning class="mt-1 block text-2xs text-(--color-badge-warning-ink)"><x-ui.icon name="alert-triangle" :size="14" class="inline-block align-text-bottom" /> {{ $warning }}</span>
@endif
