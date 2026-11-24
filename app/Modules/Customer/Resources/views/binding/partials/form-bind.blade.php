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
                <span class="{{ $label }}">{{ __('customer::binding.dealers') }}</span>
                <select name="customer_ids[]" multiple size="8" class="w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 py-1">
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" @selected(in_array((string) $customer->id, array_map('strval', (array) old('customer_ids')), true))>{{ $customerName($customer) }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.or_area') }}<span class="ms-1 inline-block align-middle" title="{{ __('customer::binding.area_hint') }}" aria-label="{{ __('customer::binding.area_hint') }}" tabindex="0"><x-ui.icon name="info" :size="14" /></span></span>
                <select name="location_id" class="{{ $field }}">
                    <option value="">—</option>
                    @foreach ($areas as $area)
                        <option value="{{ $area->id }}" @selected(old('location_id') == $area->id)>{{ $areaName($area) }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="{{ $label }}">{{ __('customer::binding.starts_on') }}</span>
                <x-ui.date name="starts_on" :required="true" :value="old('starts_on', $today)" />
            </label>
