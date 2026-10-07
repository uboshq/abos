{{-- ⭐ দাবির খাতার অবস্থা বাছা — জমা / অনুমোদিত / আংশিক / নিষ্পন্ন / নাকচ (অর্থ-মডিউলের পরিকল্পনা ৬.৪, [[InsuranceReports]]) --}}
<label>
    <span class="sr-only">{{ __('finance::insurance_claim.state') }}</span>
    <select name="state" data-claim-state
            class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
        <option value="">{{ __('finance::insurance_claim.all_states') }}</option>
        @foreach (\App\Modules\Finance\Models\InsuranceClaim::STATES as $state)
            <option value="{{ $state }}" @selected(($filters['state'] ?? '') === $state)>{{ __('finance::insurance_claim.state_'.$state) }}</option>
        @endforeach
    </select>
</label>
