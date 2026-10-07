{{-- ⭐ প্রস্তাবে এই বিল থেকে কত — খালি মানে বাছা নয় ([[PaymentScheduleController::propose()]]) --}}
@if ($proposing)
    <input type="number" step="0.01" min="0" max="{{ $bill->dueAmount() }}" name="picks[{{ $bill->id }}]"
           aria-label="{{ __('purchase::schedule.propose') }} {{ $bill->document_no }}" inputmode="decimal" data-proposal-pick
           class="num w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1 text-right">
@endif
