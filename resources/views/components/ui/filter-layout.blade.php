@props([
    /*
     * ⭐ ছাঁকনি — সব তালিকায় একটাই ছাঁচ (মালিক, ১৪ নভেম্বর ২০২৬: *"সেন্ট্রালি নিয়ন্ত্রিত … সবগুলো স্ক্রিনেই সেমভাবে"*, অনুমোদিত
     *   নমুনা মালিকের-ফাইল/তালিকার-নকশা-নমুনা-১৪-নভেম্বর.html: উপরে রঙিন কার্ড, ডান পাশ থেকে রঙিন প্যানেল)।
     *
     * ⓘ তথ্য আসে [[Panel::of()]] থেকে; পাতা কেবল `$filters` দেয়। ঘরগুলো তালিকার GET ফর্মের সাথে বাঁধা (`form` অ্যাট্রিবিউট) —
     *   খোঁজা, সাজানো, দৃশ্য, প্রতি পাতায় সব একসাথে যায়।
     * ⓘ প্যানেলে টিক দিলে তালিকা তখনই বদলায় না — নিচের "Nটা দেখুন" চাপলে বদলায়; টিক দিতে দিতে সংখ্যাগুলো সার্ভার থেকে আসে
     *   (`_filter_counts`), তাই চাপার আগেই জানা যায় কতটা আসবে। "বন্ধ" চাপলে টিক আগের মতো ফেরে।
     */
    'filters',
    /** তালিকার GET ফর্মের id — টুলবার যে ফর্মে বসে */
    'form' => 'list-form',
    /** "Nটা … দেখুন" বোতামের বিশেষ্য — "গ্রাহক", "বিল" … */
    'noun' => '',
])

@php
    $sections = $filters['sections'];
    $active = $filters['count'];
@endphp

<div x-data="filterPanel"
     data-form="{{ $form }}"
     data-show="{{ __('core.list_filter.show_n', ['noun' => $noun]) }}"
     data-show-plain="{{ __('core.list_filter.show') }}"
     @filter-panel-toggle.window="openPanel"
     @keydown.escape.window="closePanel"
     @keydown.alt.f.window.prevent="openPanel"
     data-filter-layout>

    {{-- ⭐ রঙিন কার্ড — চাপলেই সেই ছাঁকনি; বসানো কার্ডের চারপাশে উজ্জ্বল দাগ --}}
    @if (($filters['cards'] ?? []) !== [])
        <div class="list-cards" data-list-cards>
            @foreach ($filters['cards'] as $card)
                <a href="{{ $card['url'] }}" @class(['list-card', 'is-on' => $card['on']])
                   style="--a: {{ $card['gradient'][0] }}; --b: {{ $card['gradient'][1] }}"
                   data-list-card @if ($card['on']) aria-current="true" @endif>
                    <span class="list-card-icon" aria-hidden="true">{{ $card['icon'] }}</span>
                    <span class="min-w-0">
                        <span class="list-card-label">{{ $card['label'] }}</span>
                        <span class="list-card-value num">{{ $card['value'] }}</span>
                        <span class="list-card-sub">{{ $card['sub'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    {{ $slot }}

    <div class="list-filter-scrim" :class="open ? 'is-open' : ''" x-on:click="closePanel" aria-hidden="true"></div>

    <aside id="list-filter-panel" class="list-filter-drawer" :class="open ? 'is-open' : ''" data-filter-panel
           aria-label="{{ __('core.list_filter.title') }}">
        <div class="list-filter-head">
            <div class="flex items-center justify-between gap-2">
                <strong class="flex items-center gap-2 text-base">
                    <x-ui.icon name="filter" class="size-4" /> {{ __('core.list_filter.title') }}
                    <span class="list-filter-picked" data-picked="{{ __('core.list_filter.picked', ['n' => '#']) }}" x-text="pickedText">@if ($active > 0){{ __('core.list_filter.picked', ['n' => $active]) }}@endif</span>
                </strong>
                <span class="flex items-center gap-2">
                    <button type="button" class="list-filter-headlink" x-on:click="clearAll" data-filter-clear>{{ __('core.list_filter.clear') }}</button>
                    <button type="button" class="list-filter-close" x-on:click="closePanel" aria-label="{{ __('core.list_filter.close') }}">✕</button>
                </span>
            </div>
        </div>

        <div class="list-filter-sections" x-on:change="changed">
            @foreach ($sections as $section)
                <section @class(['list-filter-group', 'tone-'.$section['tone'], 'has' => $section['active'] > 0, 'is-shut' => ! $section['open']])
                         data-filter-section="{{ $section['key'] }}">
                    <button type="button" class="list-filter-group-head" x-on:click="toggleGroup($event)">
                        <span class="list-filter-tile" aria-hidden="true">{{ $section['icon'] }}</span>
                        <span>{{ $section['label'] }}</span>
                        @if ($section['active'] > 0)<span class="list-filter-n">{{ $section['active'] }}</span>@endif
                        <span class="list-filter-caret" aria-hidden="true">▼</span>
                    </button>
                    <div class="list-filter-body">
                        @switch($section['kind'])
                            @case('choice')
                                @if ($section['multi'])
                                    <div class="list-filter-tools">
                                        <button type="button" x-on:click="pickAll($event)">{{ __('core.list_filter.pick_all') }}</button>
                                        <button type="button" x-on:click="pickNone($event)">{{ __('core.list_filter.pick_none') }}</button>
                                    </div>
                                @endif
                                @if ($section['findable'])
                                    <input type="search" class="list-filter-find" x-on:input="find"
                                           placeholder="{{ __('core.list_filter.find', ['name' => $section['label']]) }}"
                                           aria-label="{{ __('core.list_filter.find', ['name' => $section['label']]) }}">
                                @endif
                                @foreach ($section['options'] as $i => $option)
                                    <label @class(['list-filter-option', 'is-zero' => $option['count'] === 0 && ! $option['checked'], 'is-more' => $section['findable'] && $i >= 6 && ! $option['checked']])
                                           data-option="{{ $option['label'] }}" data-value="{{ $option['value'] }}">
                                        <input form="{{ $form }}"
                                               type="{{ $section['multi'] ? 'checkbox' : 'radio' }}"
                                               name="{{ $section['key'] }}{{ $section['multi'] ? '[]' : '' }}"
                                               value="{{ $option['value'] }}" @checked($option['checked'])
                                               @if ($option['value'] === $section['default']) data-default @endif>
                                        @if ($option['tone'])<span class="list-filter-dot tone-{{ $option['tone'] }}" aria-hidden="true"></span>@endif
                                        <span class="min-w-0 flex-1">{{ $option['label'] }}</span>
                                        @if ($option['count'] !== null)
                                            <span class="list-filter-count num" data-count>{{ number_format($option['count']) }}</span>
                                        @endif
                                    </label>
                                @endforeach
                                @if ($section['findable'] && count($section['options']) > 6)
                                    <button type="button" class="list-filter-more" x-on:click="showMore($event)" data-more>{{ __('core.list_filter.more', ['n' => count($section['options']) - 6]) }}</button>
                                @endif
                                @break
                            @case('range')
                                <div class="list-filter-range">
                                    <input form="{{ $form }}" type="text" inputmode="decimal" name="{{ $section['key'] }}_min"
                                           value="{{ $section['min'] !== null ? rtrim(rtrim($section['min'], '0'), '.') : '' }}"
                                           placeholder="{{ __('core.list_filter.min') }} ৳" aria-label="{{ $section['label'] }} — {{ __('core.list_filter.min') }}" class="list-filter-input num">
                                    <span class="text-(--color-ink-muted)">–</span>
                                    <input form="{{ $form }}" type="text" inputmode="decimal" name="{{ $section['key'] }}_max"
                                           value="{{ $section['max'] !== null ? rtrim(rtrim($section['max'], '0'), '.') : '' }}"
                                           placeholder="{{ __('core.list_filter.max') }} ৳" aria-label="{{ $section['label'] }} — {{ __('core.list_filter.max') }}" class="list-filter-input num">
                                </div>
                                <div class="list-filter-quick">
                                    @foreach ([10000, 50000, 100000] as $q)
                                        <button type="button" x-on:click="quickMin($event)" data-min="{{ $q }}">৳{{ number_format($q) }}+</button>
                                    @endforeach
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
                </section>
            @endforeach
        </div>

        <div class="list-filter-foot">
            <button type="button" class="list-filter-button" x-on:click="closePanel">{{ __('core.list_filter.close') }}</button>
            <button type="submit" form="{{ $form }}" class="list-filter-button is-primary" data-filter-apply x-text="showText">{{ __('core.list_filter.show') }}</button>
        </div>
    </aside>
</div>
