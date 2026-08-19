@props([
    'name',
    'label',
    'value' => null,
    'type' => 'text',
    'hint' => null,
    'required' => false,
    'numeric' => false,
    'readonly' => false,

    /*
     * ভুলের বার্তা কোন নামে খুঁজবে — না দিলে ঘরের নামেই।
     *
     * ── কেন এটা লাগল ────────────────────────────────────────────────
     * নিজস্ব ঘরের ইনপুটের নাম হয় custom[route_no], কারণ অ্যারে হিসেবে
     * যেতে হয়। কিন্তু ভ্যালিডেশন ভুল লেখে custom.route_no নামে। ফলে
     * $errors->has('custom[route_no]') কখনো সত্যি হত না, আর ভুলের
     * বার্তাটা কোনোদিন দেখা যেত না — ব্যবহারকারী শুধু দেখতেন ফর্মটা
     * ফিরে এসেছে, কেন তা নয়।
     */
    'errorKey' => null,
])

{{--
    ফর্মের একটা ঘর — লেবেল, ইনপুট, সাহায্যের লাইন ও ভুলের বার্তা একসাথে।

    প্রথমে এটা Customer মডিউলের ভেতরে লেখা হয়েছিল, ইচ্ছাকৃতভাবে: এক
    ব্যবহারকারী দেখে শেয়ার্ড কম্পোনেন্ট বানালে পরেরজনের দরকারে সেটায় শর্ত
    জুড়তে হয় (সেকশন ১৯.৮)। Accounts মডিউল হুবহু একই জিনিস চেয়েছে —
    একটাও পার্থক্য ছাড়া — তাই এখন এটা core-এ।

    দ্বিতীয় ব্যবহারকারীই যথেষ্ট প্রমাণ। তৃতীয়টার জন্য অপেক্ষা করলে
    ততক্ষণে দুইটা কপি আলাদা হয়ে যেত, আর তখন একত্র করাটা মার্জ হয়ে
    দাঁড়াত, সরানো নয়।
--}}
@php
    $key = $errorKey ?? $name;
    $hasError = $errors->has($key);
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

    @if ($type === 'date')
        {{--
            তারিখ ব্রাউজারের নিজের ঘরে যায় না।

            `<input type="date">` তার লেখাটা **লোকেল ধরে** আঁকে, আর ওটা
            বদলানোর কোনো API নেই — না CSS, না অ্যাট্রিবিউট। এই কম্পিউটারে
            en-US থাকায় ১৯ আগস্ট দেখাত `08/19/2026`, অথচ বাকি সব জায়গায়
            `17-08-2026`। `05/06` পড়া যায় দুইভাবে, আর দুইটাই বৈধ তারিখ —
            তাই ভুলটা খাতা থেকে ধরাই যায় না।

            এখানে ধরায় বলে ৪২টা ফর্ম একসাথে ঠিক হলো, একটাও না ছুঁয়ে।
        --}}
        <x-ui.date :name="$name" :id="$name" :value="$value"
                   :required="$required" :readonly="$readonly"
                   :hasError="$hasError" :describedBy="$describedBy ?: null"
                   {{ $attributes }} />
    @else
    <input id="{{ $name }}"
           name="{{ $name }}"
           type="{{ $type }}"
           value="{{ $value }}"
           @if ($required) required @endif
           @if ($readonly) readonly @endif
           @if ($hasError) aria-invalid="true" @endif
           @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
           {{ $attributes->class([
               'h-(--spacing-field) w-full rounded-(--radius-field) border px-3',
               'bg-(--color-surface-card)',
               // readonly ঘর দেখতেই আলাদা হতে হবে — নাহলে ব্যবহারকারী
               // টাইপ করতে গিয়ে বুঝতে পারে না কেন কিছু হচ্ছে না
               'bg-(--color-surface-app) text-(--color-ink-muted)' => $readonly,
               // টাকা ও সংখ্যা ডানে — এক কলামে দশমিক বিন্দু এক লাইনে না
               // থাকলে চোখ প্রতিটা সারিতে নতুন করে জায়গা খোঁজে।
               'num text-end' => $numeric,
               'border-(--color-danger)' => $hasError,
               'border-(--color-border)' => ! $hasError,
           ]) }}>
    @endif

    @if ($hint)
        <p id="{{ $name }}-hint" class="mt-1 text-2xs text-(--color-ink-muted)">{{ $hint }}</p>
    @endif

    @error($key)
        {{-- ভুলটা ঘরের পাশেই — উপরের তালিকায় থাকলে লম্বা ফর্মে কোন ঘরটা
             ভুল সেটা খুঁজতে হয় (সেকশন ১৫.২৩)। --}}
        <p id="{{ $name }}-error" class="mt-1 text-2xs text-(--color-danger)">{{ $message }}</p>
    @enderror
</div>
