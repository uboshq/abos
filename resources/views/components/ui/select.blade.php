@props([
    'name',
    'label',
    'options' => [],
    'selected' => null,
    'placeholder' => null,
    'hint' => null,
    'required' => false,

    /*
     * ⭐ কখন বাধ্যতামূলক — একটা Alpine শর্ত, ১৫ সেপ্টেম্বর ২০২৬।
     *
     * ── ⛔ কেন `required` একাই যথেষ্ট ছিল না ──────────────────────────
     * কিছু ঘর **অন্য একটা ঘরের উত্তর দেখে** বাধ্যতামূলক হয়। গ্রাহকের
     * ফর্মে পয়েন্টটা ঠিক তাই: ধরন "পরিবেশক" হলে লাগবেই, নাহলে ঐচ্ছিক।
     *
     * ⚠️ আগে ওটা কেবল `x-bind:required` দিয়ে করা হচ্ছিল, আর তাতে
     * **দুইটা জিনিস পিছিয়ে থাকত**:
     *   ১. লেবেলের তারাটা সার্ভার থেকে স্থির আঁকা — ধরন বদলালেও থাকত
     *   ২. ফাঁকা সারিটা (`placeholder`) `disabled` হয়ে বসে থাকত, ফলে
     *      ধরন বদলানোর পরেও ব্যবহারকারী পয়েন্টটা **খালি করতে পারতেন না**
     *
     * ⛔ ২ নম্বরটাই আসল ক্ষতি: পর্দা বলত "ঐচ্ছিক", আর ঘরটা ছাড়ত না।
     *
     * ⓘ তাই শর্তটা এখন এক জায়গায় লেখা হয়, আর তিনটাই (তারা, `required`,
     * ফাঁকা সারি) ওটাই দেখে — তিনজন কোনোদিন আলাদা কথা বলতে পারে না।
     */
    'requiredWhen' => null,

    // ভুলের বার্তা কোন নামে খুঁজবে — x-ui.field-এর মতোই, একই কারণে
    'errorKey' => null,
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
    $key = $errorKey ?? $name;
    $hasError = $errors->has($key);
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
        @elseif ($requiredWhen)
            {{-- x-cloak — পাতা আঁকার প্রথম মুহূর্তে তারাটা যেন ঝিলিক না দেয় --}}
            <span class="text-(--color-danger)" aria-hidden="true"
                  x-cloak x-show="{{ $requiredWhen }}">*</span>
            <span class="sr-only" x-cloak x-show="{{ $requiredWhen }}">
                ({{ __('core.form.required') }})
            </span>
        @endif
    </label>

    <select id="{{ $name }}"
            name="{{ $name }}"
            @if ($required) required @endif
            @if (! $required && $requiredWhen) x-bind:required="{{ $requiredWhen }}" @endif
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
            <option value=""
                    @if ($required) disabled @endif
                    @if (! $required && $requiredWhen) x-bind:disabled="{{ $requiredWhen }}" @endif
                    @selected(blank($current))>
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

    @error($key)
        <p id="{{ $name }}-error" class="mt-1 text-2xs text-(--color-danger)">{{ $message }}</p>
    @enderror
</div>
