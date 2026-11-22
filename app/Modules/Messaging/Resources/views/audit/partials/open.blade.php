{{-- ⛔ The only way into a thread: a written reason (≥ 10 characters), recorded in the audit trail ([[MessengerAuditController::open()]]). --}}
<form method="POST" action="{{ route('messaging.audit.open', ['thread' => $thread->public_id]) }}" class="flex gap-2">
    @csrf
    <input type="text" name="reason" required minlength="10" maxlength="500"
        aria-label="{{ __('messaging::audit.reason') }}"
        placeholder="{{ __('messaging::audit.reason_placeholder') }}"
        class="w-72 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-sm">
    <x-ui.button type="submit">{{ __('messaging::audit.open') }}</x-ui.button>
</form>
