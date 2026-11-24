@if ($failedToday > 0)
    <p class="mb-4 flex items-center gap-2 rounded-(--radius-field) bg-(--color-badge-danger-bg)
              px-3 py-2 text-sm text-(--color-badge-danger-ink)">
        <x-ui.icon name="alert-triangle" :size="16" />
        {{ __('governance::message.failed_today', ['count' => $failedToday]) }}
    </p>
@endif
