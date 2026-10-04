{{-- ছকের একটা ঘর শেষ করা — শেষ দিনটা বাছাই, মোছা নয়। আগেই শেষ হওয়া ঘরে কিছু নেই। --}}
@if ($visit->effective_to === null || $visit->effective_to->gte($today))
    <form method="POST" action="{{ route('sales.route.visit.end', $visit) }}" class="flex items-center gap-2">
        @csrf
        <input type="date" name="effective_to" required
               value="{{ $today->max($visit->effective_from)->toDateString() }}"
               min="{{ $visit->effective_from->toDateString() }}"
               aria-label="{{ __('sales::route.last_day') }}"
               class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border)
                      bg-(--color-surface-card) px-2">
        <x-ui.button type="submit" tone="secondary">{{ __('sales::route.end') }}</x-ui.button>
    </form>
@else
    <span class="text-(--color-ink-muted)">—</span>
@endif
