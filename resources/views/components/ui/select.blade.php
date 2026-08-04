@props([
    'name',
    'label',
    'options' => [],
    'selected' => null,
    'placeholder' => null,
    'hint' => null,
    'required' => false,
])

{{--
    ফর্মের একটা ড্রপডাউন — x-ui.field-এর যমজ, শুধু ইনপুটের বদলে select।

    এতদিন প্রতিটা স্ক্রিন নিজে <select> লিখত: গ্রাহকের ফর্মে শাখা, মাস্টার
    তালিকার ফর্মে, রিপোর্টের ফিল্টারে। ফল হয়েছিল — কোথাও ভুলের বার্তা
    দেখাত না, কোথাও লেবেল sr-only, কোথাও উচ্চতা আলাদা। ঘরটা একই কাজ করে
    বলে দেখতেও একই হওয়া উচিত।

    field.blade.php-র মন্তব্যে লেখা নিয়মটাই খাটল: দ্বিতীয় ব্যবহারকারী
    আসার পরেই কম্পোনেন্ট, তার আগে নয় (সেকশন ১৯.৮)। এখানে ব্যবহারকারী
    চারজন, তাই অপেক্ষার আর কারণ নেই।

    options মানচিত্র — মান => যা দেখাবে। মডেল সরাসরি নেওয়া হয় না, কারণ
    তাহলে কম্পোনেন্টকে জানতে হত কোন মডেলের কোন পদ্ধতিতে নাম আসে, আর
    প্রতিটা নতুন মডেলের জন্য এখানে একটা শর্ত জুড়ত।
--}}
@php
    $hasError = $errors->has($name);
    $describedBy = collect([
        $hint ? "{$name}-hint" : null,
        $hasError ? "{$name}-error" : null,
    ])->filter()->implode(' ');

    // পুরনো মান স্ট্রিং হিসেবে আসে, id হিসেবে নয় — তাই তুলনাটা ঢিলা
    $current = old($name, $selected);
@endphp

<div>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">
        {{ $label }}
        @if ($required)
            <span class="text-(--color-danger)" aria-hidden="true">*</span>
            <span class="sr-only">({{ __('core.form.required') }})</span>
        @endif
    </label>

    <select id="{{ $name }}"
            name="{{ $name }}"
            @if ($required) required @endif
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class([
                'h-(--spacing-field) w-full rounded-(--radius-field) border px-3',
                'bg-(--color-surface-card)',
                'border-(--color-danger)' => $hasError,
                'border-(--color-border)' => ! $hasError,
            ]) }}>

        {{-- ঐচ্ছিক ঘরে "কিছু না" বাছার উপায় থাকতেই হবে; বাধ্যতামূলক ঘরে
             ফাঁকা সারিটা disabled, নাহলে required কিছুই আটকাত না --}}
        @if ($placeholder !== null)
            <option value="" @if ($required) disabled @endif @selected(blank($current))>
                {{ $placeholder }}
            </option>
        @endif

        @foreach ($options as $value => $text)
            <option value="{{ $value }}" @selected(! blank($current) && (string) $current === (string) $value)>
                {{ $text }}
            </option>
        @endforeach
    </select>

    @if ($hint)
        <p id="{{ $name }}-hint" class="mt-1 text-2xs text-(--color-ink-muted)">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-2xs text-(--color-danger)">{{ $message }}</p>
    @enderror
</div>
