@props([
    'name',
    'accept' => null,
    'multiple' => false,
    'required' => false,
    /**
     * ⭐ বাছার মুহূর্তেই বড় ফাইল ফেরানো (fe, ২১ অক্টোবর ২০২৬) — বাইটে; `null` মানে কোনো সীমা নেই (আগের মতো)।
     * ⓘ `svgMaxBytes` — `.svg` নামের ফাইলের আলাদা সীমা; `tooBig` — বার্তা, ভেতরে `:mb` (সংখ্যাটা JS বসায়)।
     * ⓘ সংখ্যাগুলো সার্ভারের [[UploadLimit]] থেকে — দরজার নিয়মের একই সংখ্যা; এটা কেবল আগাম, সার্ভার আবার দেখে।
     */
    'maxBytes' => null,
    'svgMaxBytes' => null,
    'tooBig' => null,
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

<span x-data="filePick({ none: @js($none), many: @js($many), max: @js((int) $maxBytes), svgMax: @js((int) $svgMaxBytes), tooBig: @js((string) $tooBig) })"
      data-file-input @if ($maxBytes) data-max-bytes="{{ (int) $maxBytes }}" @endif class="inline-flex flex-wrap items-center gap-2">
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
    @if ($maxBytes)
        {{-- ⓘ ফেরানোর কারণ — ঘরের ঠিক পাশে, স্ক্রিন রিডার শোনে (`role="alert"`) --}}
        <span x-show="refused" x-cloak x-text="refused" role="alert" data-file-refused
              class="w-full text-2xs text-(--color-badge-danger-ink)"></span>
    @endif
</span>
