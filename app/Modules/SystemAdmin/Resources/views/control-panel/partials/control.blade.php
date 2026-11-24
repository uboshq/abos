{{-- ⭐ এক সেটিংয়ের ঘর — পুরনো ট্যাবের হুবহু একই নাম, মান আর নিয়ম (বন্ধ ঘর `scope[]` বহন করে না, সাদা তালিকায় মান যায় নম্বর নয়) --}}
@php
    $locked = $setting['type'] === 'boolean' && ! $settings->mayChange($setting['key'], auth()->user());
    $choices = empty($setting['options']) ? null : (is_callable($setting['options']) ? call_user_func($setting['options']) : $setting['options']);
    $label = $setting['text'];
@endphp
@unless ($locked)
    <input type="hidden" form="board-form" name="scope[]" value="{{ $setting['key'] }}">
@endunless
@if ($setting['type'] === 'boolean')
    @include('system_admin::partials.switch', ['name' => 'settings['.$setting['key'].']', 'form' => 'board-form', 'label' => $label, 'checked' => (bool) $setting['value'], 'disabled' => $locked])
    @if ($locked)
        <span class="block text-2xs text-(--color-ink-muted)">{{ __('system_admin::settings.super_admin_only') }}</span>
    @endif
@elseif ($choices !== null)
    <select form="board-form" name="settings[{{ $setting['key'] }}]" aria-label="{{ $label }}"
            class="h-(--spacing-field-compact) w-full max-w-56 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
        @foreach ($choices as $key => $sample)
            <option value="{{ array_is_list($choices) ? $sample : $key }}" @selected((string) $setting['value'] === (string) (array_is_list($choices) ? $sample : $key))>{{ is_string($sample) ? __($sample) : $sample }}</option>
        @endforeach
    </select>
@else
    <input type="{{ $setting['type'] === 'integer' ? 'number' : 'text' }}" form="board-form" name="settings[{{ $setting['key'] }}]" aria-label="{{ $label }}"
           value="{{ is_scalar($setting['value']) ? $setting['value'] : '' }}" @if ($setting['type'] === 'integer') min="0" inputmode="numeric" @endif
           class="h-(--spacing-field-compact) w-full max-w-56 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
@endif
