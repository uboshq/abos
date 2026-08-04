@props([
    'name',
    'label',
    'value' => null,
    'type' => 'text',
    'hint' => null,
    'required' => false,
    'numeric' => false,
])

{{--
    ফর্মের একটা ঘর — লেবেল, ইনপুট, সাহায্যের লাইন ও ভুলের বার্তা একসাথে।

    কেন মডিউলের ভেতরে, শেয়ার্ড কম্পোনেন্টে নয়: এটা Phase 2-এর পরীক্ষার
    অংশ। আরও দুই-তিনটা মডিউল লেখার পর যদি সবাই হুবহু এটাই চায়, তখন এটা
    core-এ উঠবে। এক ব্যবহারকারী দেখে শেয়ার্ড কম্পোনেন্ট বানালে পরেরজনের
    দরকারে সেটায় শর্ত জুড়তে হয়, আর তিন মডিউল পরে ওটা আর পড়ার মতো
    থাকে না (সেকশন ১৯.৮)।
--}}
@php
    $hasError = $errors->has($name);
    $describedBy = collect([
        $hint ? "{$name}-hint" : null,
        $hasError ? "{$name}-error" : null,
    ])->filter()->implode(' ');
@endphp

<div>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">
        {{ $label }}
        @if ($required)
            {{-- তারকাটা সাজসজ্জা নয়, তাই স্ক্রিন রিডারেও বোঝা যায় এমন
                 লেখা সাথে থাকে — aria-required-এর উপর ছেড়ে দিলে চোখে
                 দেখা আর শোনা দুই রকম তথ্য দিত। --}}
            <span class="text-(--color-danger)" aria-hidden="true">*</span>
            <span class="sr-only">({{ __('core.form.required') }})</span>
        @endif
    </label>

    <input id="{{ $name }}"
           name="{{ $name }}"
           type="{{ $type }}"
           value="{{ $value }}"
           @if ($required) required @endif
           @if ($hasError) aria-invalid="true" @endif
           @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
           {{ $attributes->class([
               'h-(--spacing-field) w-full rounded-(--radius-field) border px-3',
               'bg-(--color-surface-card)',
               // টাকা ও সংখ্যা ডানে — এক কলামে দশমিক বিন্দু এক লাইনে না
               // থাকলে চোখ প্রতিটা সারিতে নতুন করে জায়গা খোঁজে।
               'num text-end' => $numeric,
               'border-(--color-danger)' => $hasError,
               'border-(--color-border)' => ! $hasError,
           ]) }}>

    @if ($hint)
        <p id="{{ $name }}-hint" class="mt-1 text-2xs text-(--color-ink-muted)">{{ $hint }}</p>
    @endif

    @error($name)
        {{-- ভুলটা ঘরের পাশেই — উপরের তালিকায় থাকলে লম্বা ফর্মে কোন ঘরটা
             ভুল সেটা খুঁজতে হয় (সেকশন ১৫.২৩)। --}}
        <p id="{{ $name }}-error" class="mt-1 text-2xs text-(--color-danger)">{{ $message }}</p>
    @enderror
</div>
