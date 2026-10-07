@if ($month['lock'])
    <span class="rounded-(--radius-field) bg-(--color-badge-danger-bg)
                 px-2 py-0.5 text-2xs text-(--color-badge-danger-ink)">
        {{ __('accounts::field.closed') }}
    </span>
@else
    <span class="rounded-(--radius-field) bg-(--color-badge-success-bg)
                 px-2 py-0.5 text-2xs text-(--color-badge-success-ink)">
        {{ __('accounts::field.open') }}
    </span>
@endif
