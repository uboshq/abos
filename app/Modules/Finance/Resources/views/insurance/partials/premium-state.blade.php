{{-- ⭐ প্রিমিয়ামের সূচির অবস্থা বাছা — দেওয়া / দিন পার / আজ / সামনে (অর্থ-মডিউলের পরিকল্পনা ৬.২, [[InsuranceReports]]) --}}
<label>
    <span class="sr-only">{{ __('finance::insurance.premium_state') }}</span>
    <select name="state" data-premium-state
            class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
        <option value="">{{ __('finance::insurance.premium_all_states') }}</option>
        @foreach (['overdue', 'today', 'upcoming', 'paid'] as $state)
            <option value="{{ $state }}" @selected(($filters['state'] ?? '') === $state)>{{ __('finance::insurance.premium_state_'.$state) }}</option>
        @endforeach
    </select>
</label>
