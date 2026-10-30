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

    ⭐ টেনে আনার বাক্স (রসিদ-পরিশোধ মিল-তালিকা #৩৭, নকশা পাতা ২ আর ৪; ২৫ অক্টোবর ২০২৬): গোটা ঘরটাই ড্যাশ-রেখার বাক্স — ফাইল
    এনে ফেললে [[filePick]]-এর `drop()` **একই আসল ঘর** ভরে (একই নাম, তাই সার্ভারের সীমা আর ধরনের নিয়ম অবিকল), তারপর `change`
    ছোঁড়ে, তাই বড় ফাইল ফেরানো আর স্ক্যানার বোতামের মতোই চলে। ⓘ কীবোর্ডে আগের মতোই: Tab-এ আসল ঘর, Space/Enter-এ বাছাই, ফোকাসে
    বাক্সের রেখা জ্বলে; "সরান" আসল বোতাম। টেনে আনা কেবল মাউস/আঙুলের বাড়তি পথ — বাছার বোতাম থাকেই (WCAG 2.2 SC 2.5.7)।
--}}
@php
    $none = __('core.file.none');
    $many = __('core.file.many');
    $id = $attributes->get('id', $name.'-file');
@endphp

<span x-data="filePick({ none: @js($none), many: @js($many), max: @js((int) $maxBytes), svgMax: @js((int) $svgMaxBytes), tooBig: @js((string) $tooBig), wrongType: @js(__('core.file.wrong_type')), oneOnly: @js(__('core.file.one_only')) })"
      data-file-input data-file-drop @if ($maxBytes) data-max-bytes="{{ (int) $maxBytes }}" @endif
      @dragenter="dragOver($event)" @dragover="dragOver($event)" @dragleave="dragLeave($event)" @drop="drop($event)"
      :class="over ? 'bg-(--color-brand-50) outline-2 outline-(--color-brand-600)' : ''"
      class="flex w-full flex-wrap items-center justify-center gap-x-2 gap-y-1 rounded-(--radius-field) border border-dashed border-(--color-border)
             px-3 py-2 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--color-brand-500)">
    <label for="{{ $id }}"
           class="inline-flex min-h-(--spacing-touch) cursor-pointer items-center gap-1.5 rounded-(--radius-field) border
                  border-(--color-border) bg-(--color-surface-card) px-3 text-sm hover:bg-(--color-surface-hover)">
        <x-ui.icon name="attachment" :size="16" />
        {{ $multiple ? __('core.file.choose_many') : __('core.file.choose') }}
    </label>
    <input type="file" id="{{ $id }}" name="{{ $name }}{{ $multiple ? '[]' : '' }}" class="sr-only"
           @if ($accept) accept="{{ $accept }}" @endif @if ($multiple) multiple @endif @required($required)
           @change="pick($event)" {{ $attributes->except('id') }}>
    <span class="text-sm text-(--color-ink-muted)" x-text="label" aria-live="polite">{{ $none }}</span>
    <span x-show="! picked" class="text-xs text-(--color-ink-muted)" data-file-drop-hint>{{ __('core.file.drop') }}</span>

    {{-- ⓘ একটা ফাইল: নামের পাশেই "সরান"; কয়েকটা: প্রতিটার নাম আর নিজের "সরান" (পর্দা-পাঠক শোনে "সরান slip.pdf") --}}
    @if ($multiple)
        <ul x-show="names.length > 0" x-cloak class="w-full space-y-0.5 text-xs" data-file-names>
            <template x-for="(fileName, index) in names" :key="index">
                <li class="flex items-center justify-center gap-2">
                    <span class="min-w-0 truncate" x-text="fileName"></span>
                    <button type="button" @click="remove($event, index)" data-file-remove
                            class="shrink-0 rounded text-(--color-badge-danger-ink) underline focus-visible:outline-2 focus-visible:outline-(--color-brand-500)">{{ __('core.file.remove') }} <span class="sr-only" x-text="fileName"></span></button>
                </li>
            </template>
        </ul>
    @else
        <button type="button" x-show="picked" x-cloak @click="remove($event, 0)" data-file-remove
                class="rounded text-xs text-(--color-badge-danger-ink) underline focus-visible:outline-2 focus-visible:outline-(--color-brand-500)">{{ __('core.file.remove') }} <span class="sr-only" x-text="label"></span></button>
    @endif

    {{-- ⓘ ফেরানোর কারণ — বড় ফাইল, ভুল ধরন বা একটার ঘরে কয়েকটা; ঘরের ঠিক পাশে, স্ক্রিন রিডার শোনে (`role="alert"`) --}}
    <span x-show="refused" x-cloak x-text="refused" role="alert" data-file-refused
          class="w-full text-center text-2xs text-(--color-badge-danger-ink)"></span>
</span>
