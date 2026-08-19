@props([
    'name',
    'value' => null,
    'required' => false,
    'readonly' => false,
    'hasError' => false,
    'describedBy' => null,
    'id' => null,

    /*
     * তারিখ বসলেই ফর্ম জমা — ছাঁকনির ঘরের জন্য।
     *
     * আগে কাঁচা ঘরে `onchange="this.form.submit()"` লেখা ছিল। ওটা এখানে
     * চলে না: Alpine-এর ভেতরে `this` মানে কম্পোনেন্ট, ইনপুট নয়। আর
     * লেখার ঘরে `change` ফোকাস ছাড়লে তবে ঘটে, তারিখ বসার সাথে নয় —
     * তাই জমাটা কম্পোনেন্ট থেকেই ডাকা হয়, মান পাকা হওয়ার পরে।
     */
    'submitOnChange' => false,
])

{{--
    তারিখের ঘর — দিন-মাস-বছর, সব কম্পিউটারে এক।

    ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
    ঘরটা ছিল সাধারণ `<input type="date">`। ব্রাউজার ওটার ভেতরের লেখাটা
    **নিজের লোকেল ধরে** আঁকে, আর CSS দিয়ে ওটা বদলানো যায় না। এই
    কম্পিউটারে ইংরেজি (US) থাকায় ১৯ আগস্ট দেখাত `08/19/2026` — মাস আগে,
    আমেরিকান ছাঁদে। অথচ অ্যাপের বাকি সব জায়গায় তারিখ `17-08-2026`।

    ছোট দেখতে, কিন্তু ফলটা ছোট নয়। `08/19` পড়া যায় একভাবে, কারণ ১৯
    কোনো মাস নয়। **`05/06` পড়া যায় দুইভাবে** — ৫ জুন না ৬ মে, বলার
    কোনো উপায় নেই। একজন হিসাবরক্ষক মাসের ভুল ঘরে এন্ট্রি বসিয়ে দিলে সেটা
    খাতা থেকে ধরা প্রায় অসম্ভব, কারণ দুইটা তারিখই বৈধ।

    ── কেন নিজের কম্পোনেন্ট, ব্রাউজারেরটা নয় ───────────────────────────
    ওটাই একমাত্র উপায়। `type="date"`-এর প্রদর্শিত ছাঁদ বদলানোর কোনো
    API নেই — না CSS, না অ্যাট্রিবিউট। D365, SAP, Oracle — তিনটাই তাই
    নিজের তারিখ-নিয়ন্ত্রণ লেখে।

    ── ভেতরের গড়ন ─────────────────────────────────────────────────────
    দেখা যায় একটা লেখার ঘর (`17-08-2026`), আর সার্ভারে যায় লুকানো
    ISO ঘরটা (`2026-08-17`)। ক্যালেন্ডার বোতাম টিপলে ব্রাউজারের নিজের
    পিকারই খোলে — ওটা বাদ দেওয়ার কারণ নেই, কেবল ওর **লেখাটা** দেখানোর
    দরকার নেই।

    টাইপ করার সময় নিজে থেকেই `-` বসে, তাই কেউ `17082026` লিখলেও চলে।
--}}
@php
    $id = $id ?? $name;
    // মান আসে ISO-তে (2026-08-17) — খালি বা ভুল হলে ঘরটাও খালি থাকে
    $iso = $value ? \Illuminate\Support\Carbon::parse($value)->toDateString() : '';
@endphp

<div class="relative" x-data="abosDate(@js($iso), @js((bool) $submitOnChange))">
    <input type="text"
           id="{{ $id }}"
           x-model="text"
           x-on:input="mask()"
           x-on:blur="commit()"
           inputmode="numeric"
           maxlength="10"
           autocomplete="off"
           placeholder="{{ __('core.form.date_hint') }}"
           @if ($required) required @endif
           @if ($readonly) readonly @endif
           @if ($hasError) aria-invalid="true" @endif
           @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
           {{ $attributes->class([
               'num h-(--spacing-field) w-full rounded-(--radius-field) border pe-9 ps-3',
               'bg-(--color-surface-card)',
               'bg-(--color-surface-app) text-(--color-ink-muted)' => $readonly,
               'border-(--color-danger)' => $hasError,
               'border-(--color-border)' => ! $hasError,
           ]) }}>

    {{-- সার্ভারে যায় এটাই — ISO, তাই ব্যাকএন্ডে কোনো বদল লাগেনি --}}
    <input type="hidden" name="{{ $name }}" x-bind:value="iso">

    {{-- ব্রাউজারের নিজের পিকার — দেখা যায় না, কিন্তু কাজ করে।
         showPicker() Chrome/Edge ৯৯+ এ আছে; না থাকলে ঘরটায় হাতে লেখা যায়,
         তাই কোথাও আটকে যায় না। --}}
    <input type="date" x-ref="native" x-bind:value="iso" tabindex="-1" aria-hidden="true"
           x-on:change="fromNative($event.target.value)"
           class="pointer-events-none absolute end-9 bottom-0 h-0 w-0 opacity-0">

    @unless ($readonly)
        <button type="button"
                x-on:click="pick()"
                class="absolute end-0 top-0 grid h-(--spacing-field) w-9 place-items-center
                       text-(--color-ink-muted) hover:text-(--color-ink)"
                aria-label="{{ __('core.form.pick_date') }}">
            <x-ui.icon name="calendar" class="size-4" />
        </button>
    @endunless
</div>
