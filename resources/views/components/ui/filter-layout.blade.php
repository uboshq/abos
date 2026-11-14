@props([
    /*
     * ⭐ ছাঁকনি প্যানেল আর তালিকা পাশাপাশি — সব তালিকায় একটাই ছাঁচ (মালিক, ১৪ নভেম্বর ২০২৬: *"সেন্ট্রালি নিয়ন্ত্রিত …
     *   সবগুলো স্ক্রিনেই সেমভাবে"*; নমুনা claude.ai/artifact/7UoyiGpYFqJCeuL3dMFveR, v2: ভাঁজ করে রাখা)।
     *
     * ⓘ তথ্য আসে [[FilterSet::sections()]] থেকে; পাতা কেবল `$filters` দেয় ([[ListFilters::panel()]])।
     * ⓘ ঘরগুলো তালিকার GET ফর্মের সাথে বাঁধা (`form` অ্যাট্রিবিউট) — খোঁজা, সাজানো, দৃশ্য, প্রতি পাতায় সব একসাথে যায়।
     */
    'filters',
    /** তালিকার GET ফর্মের id — টুলবার যে ফর্মে বসে */
    'form' => 'list-form',
])

@php
    $sections = $filters['sections'];
    $active = $filters['count'];
    $folded = (bool) $filters['folded'];
@endphp

<div x-data="filterPanel"
     data-form="{{ $form }}"
     data-fold-url="{{ route('list_filters.fold') }}"
     data-token="{{ csrf_token() }}"
     data-folded="{{ $folded ? '1' : '0' }}"
     @filter-panel-toggle.window="toggle"
     @keydown.alt.f.window.prevent="toggle"
     data-filter-layout
     @class(['list-filter-layout', 'is-folded' => $folded])
     :class="folded ? 'is-folded' : ''">

    {{-- ভাঁজ করা অবস্থায় সরু ফিতা — নীল গোলে চালু ছাঁকনির সংখ্যা, যাতে ভুলে না যান --}}
    <button type="button" class="list-filter-rail" data-filter-rail x-on:click="unfold"
            title="{{ __('core.list_filter.open') }} (Alt+F)" aria-label="{{ __('core.list_filter.open') }}">
        <span aria-hidden="true">»</span>
        <x-ui.icon name="filter" class="size-4" />
        <span class="list-filter-rail-label">{{ __('core.list_filter.title') }}</span>
        @if ($active > 0)
            <span class="list-filter-badge" data-filter-rail-count>{{ $active }}</span>
        @endif
    </button>

    <aside id="list-filter-panel" class="list-filter-panel" data-filter-panel aria-label="{{ __('core.list_filter.title') }}"
           :class="sheet ? 'is-sheet' : ''">
        <div class="list-filter-head">
            <strong>{{ __('core.list_filter.title') }}</strong>
            <span class="flex items-center gap-2">
                @if ($active > 0)
                    <a href="{{ $filters['clear'] }}" class="text-xs text-(--color-accent-600) hover:underline" data-filter-clear>{{ __('core.list_filter.clear') }}</a>
                @endif
                <button type="button" class="list-filter-fold" data-filter-fold x-on:click="fold"
                        title="{{ __('core.list_filter.fold') }} (Alt+F)" aria-label="{{ __('core.list_filter.fold') }}">«</button>
            </span>
        </div>

        <div class="list-filter-sections" x-on:change="submit">
            @foreach ($sections as $section)
                <details @if ($section['open']) open @endif data-filter-section="{{ $section['key'] }}">
                    <summary>
                        <span>{{ $section['label'] }}</span>
                        @if ($section['active'] > 0)
                            <span class="list-filter-badge">{{ $section['active'] }}</span>
                        @endif
                    </summary>
                    <div class="list-filter-body">
                        @switch($section['kind'])
                            @case('choice')
                                @if ($section['findable'])
                                    <input type="search" class="list-filter-find" x-on:input="find"
                                           placeholder="{{ __('core.list_filter.find', ['name' => $section['label']]) }}"
                                           aria-label="{{ __('core.list_filter.find', ['name' => $section['label']]) }}">
                                @endif
                                @foreach ($section['options'] as $option)
                                    <label @class(['list-filter-option', 'is-zero' => $option['count'] === 0 && ! $option['checked']])
                                           data-option="{{ $option['label'] }}">
                                        <input form="{{ $form }}"
                                               type="{{ $section['multi'] ? 'checkbox' : 'radio' }}"
                                               name="{{ $section['key'] }}{{ $section['multi'] ? '[]' : '' }}"
                                               value="{{ $option['value'] }}" @checked($option['checked'])>
                                        <span class="min-w-0 flex-1">{{ $option['label'] }}</span>
                                        @if ($option['count'] !== null)
                                            <span class="num text-xs text-(--color-ink-muted)" data-count>{{ number_format($option['count']) }}</span>
                                        @endif
                                    </label>
                                @endforeach
                                @break
                            @case('range')
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="grid gap-0.5 text-xs text-(--color-ink-muted)">{{ __('core.list_filter.min') }}
                                        <input form="{{ $form }}" type="text" inputmode="decimal" name="{{ $section['key'] }}_min"
                                               value="{{ $section['min'] !== null ? rtrim(rtrim($section['min'], '0'), '.') : '' }}" class="list-filter-input num"></label>
                                    <label class="grid gap-0.5 text-xs text-(--color-ink-muted)">{{ __('core.list_filter.max') }}
                                        <input form="{{ $form }}" type="text" inputmode="decimal" name="{{ $section['key'] }}_max"
                                               value="{{ $section['max'] !== null ? rtrim(rtrim($section['max'], '0'), '.') : '' }}" class="list-filter-input num"></label>
                                </div>
                                @break
                            @case('date')
                                <div class="grid gap-2">
                                    <label class="grid gap-0.5 text-xs text-(--color-ink-muted)">{{ __('core.list_filter.from') }}
                                        <x-ui.date dense :name="$section['from_key']" :value="$section['from']" :form="$form" /></label>
                                    <label class="grid gap-0.5 text-xs text-(--color-ink-muted)">{{ __('core.list_filter.to') }}
                                        <x-ui.date dense :name="$section['to_key']" :value="$section['to']" :form="$form" /></label>
                                </div>
                                @break
                            @case('fields')
                                <div class="grid gap-2">
                                    @foreach ($section['fields'] as $field)
                                        <label class="grid gap-0.5 text-xs text-(--color-ink-muted)">{{ $field['label'] }}
                                            @if ($field['type'] === 'select')
                                                <select form="{{ $form }}" name="{{ $field['key'] }}" class="list-filter-input">
                                                    @foreach ($field['options'] as $value => $label)
                                                        <option value="{{ $value }}" @selected((string) $field['value'] === (string) $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif ($field['type'] === 'date')
                                                <x-ui.date dense :name="$field['key']" :value="$field['value']" :form="$form" />
                                            @else
                                                <input form="{{ $form }}" type="number" name="{{ $field['key'] }}" value="{{ $field['value'] }}"
                                                       @isset($field['min']) min="{{ $field['min'] }}" @endisset
                                                       @isset($field['max']) max="{{ $field['max'] }}" @endisset
                                                       placeholder="{{ $field['placeholder'] ?? '' }}" class="list-filter-input num">
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                                @break
                        @endswitch
                    </div>
                </details>
            @endforeach
        </div>

        {{-- ফোনে প্যানেল নিচ থেকে ওঠে; এখানে বন্ধ আর "ফল দেখুন" --}}
        <div class="list-filter-foot">
            <button type="button" class="list-filter-button" x-on:click="closeSheet">{{ __('core.list_filter.close') }}</button>
            <button type="submit" form="{{ $form }}" class="list-filter-button is-primary">{{ __('core.list_filter.show') }}</button>
        </div>
    </aside>

    <div class="list-filter-main min-w-0">
        {{ $slot }}
    </div>
</div>
