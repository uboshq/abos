{{ $row['name'] }}
@if ($row['primary'])
    <span class="ms-1 text-2xs text-(--color-ink-muted)">{{ __('accounts::custody.primary') }}</span>
@endif
