<span data-two-step="{{ $step }}" @class([
    'rounded-full px-2 py-0.5 text-2xs font-semibold',
    'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $step === 'on',
    'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)' => $step !== 'on',
])>{{ __('system_admin::security.'.($step === 'required' ? 'required_not_set' : $step)) }}</span>
