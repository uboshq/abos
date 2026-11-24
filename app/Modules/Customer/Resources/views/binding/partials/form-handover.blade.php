@php
    $field = 'h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2';
    $label = 'mb-1 block text-sm text-(--color-ink-muted)';
@endphp
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.from') }}<span class="ms-1 inline-block align-middle" title="{{ __('customer::binding.handover_hint') }}" aria-label="{{ __('customer::binding.handover_hint') }}" tabindex="0"><x-ui.icon name="info" :size="14" /></span></span>
                <select name="from_user_id" required class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}" @selected(old('from_user_id') == $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.to') }}</span>
                <select name="to_user_id" required class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}" @selected(old('to_user_id') == $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.on_date') }}</span>
                <x-ui.date name="on_date" :required="true" :value="old('on_date', $today)" />
            </label>
