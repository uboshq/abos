<a href="{{ route('system_admin.user.edit', $row['user']) }}" class="font-medium text-(--color-link) hover:underline">{{ $row['user']->name }}</a>
@unless ($row['user']->is_active)<span class="ms-1 text-2xs text-(--color-ink-muted)">({{ __('core.state.inactive') }})</span>@endunless
