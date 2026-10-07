{{ $head->name() }}
@unless ($head->isEarning())
    <span class="text-2xs text-(--color-ink-muted)">({{ __('hr::kind.deduction') }})</span>
@endunless
