{{--
    প্রিন্সিপালের কমিশন — কোন মাসে শেষ হওয়া চক্র (মালিক, ৫ অক্টোবর ২০২৬; [[PrincipalCommission::cycleClosingIn()]])।
    ⓘ খালি রাখলে প্রতিটা প্রিন্সিপালের চলতি চক্র — আজ যেটায় পড়ে। মানটা ইঞ্জিন যা মেনেছে (`$filters`), ঠিকানা নয়।
--}}
@php
    $pickedMonth = is_string($filters['month'] ?? null) ? $filters['month'] : '';
@endphp

<label class="flex items-center gap-2 text-sm">
    <span class="text-(--color-ink-muted)">{{ __('supplier::principal.month') }}</span>
    <input type="month" name="month" value="{{ $pickedMonth }}" data-principal-month
           class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
</label>

@if ($pickedMonth !== '')
    <a href="{{ request()->fullUrlWithQuery(['month' => null, 'page' => null]) }}"
       class="inline-flex h-(--spacing-field-compact) items-center text-sm text-(--color-ink-muted) underline">
        {{ __('supplier::principal.current_cycle') }}
    </a>
@endif
