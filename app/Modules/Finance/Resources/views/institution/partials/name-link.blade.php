<a href="{{ route('finance.institution.show', $institution) }}"
   class="font-medium text-(--color-brand-600) underline-offset-2 hover:underline">{{ $institution->name() }}</a>
@if (! $institution->is_active)
    <span class="ms-1 text-2xs text-(--color-ink-muted)">({{ __('finance::institution.inactive') }})</span>
@endif
