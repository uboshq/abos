@php
    $field = 'h-(--spacing-field-compact) w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2';
    $label = 'mb-1 block text-sm text-(--color-ink-muted)';
@endphp
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.staff') }}</span>
                <select name="user_id" required class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}" @selected(old('user_id') == $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.supervisor_of') }}<span class="ms-1 inline-block align-middle" title="{{ __('customer::binding.supervisor_hint') }}" aria-label="{{ __('customer::binding.supervisor_hint') }}" tabindex="0"><x-ui.icon name="info" :size="14" /></span></span>
                <select name="supervisor_id" class="{{ $field }}">
                    <option value="">{{ __('customer::binding.no_supervisor') }}</option>
                    @foreach ($staff as $person)
                        <option value="{{ $person->id }}" @selected(old('supervisor_id') == $person->id)>{{ $person->name }}</option>
                    @endforeach
                </select>
            </label>
