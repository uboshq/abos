@props([
    'name',
    'accept' => null,
    'multiple' => false,
    'required' => false,
])
{{--
    ⭐ ফাইল তোলার বাংলা বোতাম — পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬: *"ব্রাউজারের ইংরেজি 'Choose File' আর
    দেখাবে না"*।

    ⓘ ব্রাউজারের ঘর তার বোতাম আর "No file chosen" নিজের ভাষায় আঁকে — বদলানোর উপায় নেই। ⭐ তাই আসল ঘরটা `sr-only`
    (চোখে লুকানো, কিন্তু ফর্মে, কীবোর্ডে আর স্ক্রিন রিডারে আছে), বোতামটা তার `<label>`, আর পাশে বাছা ফাইলের নাম
    ([[filePick]], `components/forms.js`)। ⓘ বাকি বৈশিষ্ট্য (x-…, data-…, @change-এর মতো) আসল ঘরেই বসে।
--}}
@php
    $none = __('core.file.none');
    $many = __('core.file.many');
    $id = $attributes->get('id', $name.'-file');
@endphp

<span x-data="filePick({ none: @js($none), many: @js($many) })" data-file-input class="inline-flex flex-wrap items-center gap-2">
    <label for="{{ $id }}"
           class="inline-flex min-h-(--spacing-touch) cursor-pointer items-center gap-1.5 rounded-(--radius-field) border
                  border-(--color-border) bg-(--color-surface-card) px-3 text-sm hover:bg-(--color-surface-hover)">
        <x-ui.icon name="attachment" :size="16" />
        {{ $multiple ? __('core.file.choose_many') : __('core.file.choose') }}
    </label>
    <input type="file" id="{{ $id }}" name="{{ $name }}{{ $multiple ? '[]' : '' }}" class="sr-only"
           @if ($accept) accept="{{ $accept }}" @endif @if ($multiple) multiple @endif @required($required)
           @change="pick($event)" {{ $attributes->except('id') }}>
    <span class="text-sm text-(--color-ink-muted)" x-text="label">{{ $none }}</span>
</span>
