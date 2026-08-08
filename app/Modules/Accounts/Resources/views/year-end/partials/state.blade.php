{{-- বছরের অবস্থা — রং একা কিছু বলে না, লেখাটাই বলে। --}}
@if ($year->is_closed)
    <x-ui.badge tone="draft">{{ __('accounts::state.year_closed') }}</x-ui.badge>
    <span class="ms-2 text-2xs text-(--color-ink-muted)">
        {{ \App\Core\Support\DateFormat::format($year->closed_at) }}
    </span>
@elseif ($year->is_current)
    <x-ui.badge tone="success">{{ __('accounts::state.year_current') }}</x-ui.badge>
@else
    <x-ui.badge tone="info">{{ __('accounts::state.year_open') }}</x-ui.badge>
@endif
