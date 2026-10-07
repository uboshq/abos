{{--
    "কোন প্রতিষ্ঠান" ঘর — তালিকা থেকে বাছা, অথবা এখনই যোগ করা।

    ⓘ [[person-picker]]-এর ধাঁচে, একই কারণে: মালিকের কথায় তালিকাটা কেবল
    অর্থ মডিউলে (*"eta sudu ekhanei bebohar hobe"*), তাই নতুন ব্যাংক বসাতে
    অন্য পর্দায় যেতে হলে আধা ভরা ফর্মটা হারাত। ফর্মেই "+" — এক জমায়।

    ⚠️ নিচের লেখার ঘরটা মুক্ত লেখা নয়, কেবল তৈরির পথ — আর তৈরিটা যায়
    [[InstitutionService::resolve]] দিয়ে: নামটা আগে থেকে থাকলে সেটাই
    নেওয়া হয়, দ্বিতীয় সারি বসে না।

    ব্যবহার:
        @include('finance::components.institution-picker', [
            'institutions' => $institutions,   // [id => নাম]
            'selected' => old('institution_id', $row->institution_id),
            'field' => 'institution_id',       // ঐচ্ছিক
            'onPick' => 'pickedBank($event.target.value)',  // ঐচ্ছিক
        ])
--}}
@php
    $field ??= 'institution_id';
    $label ??= __('finance::institution.which');
    $selected ??= old($field);

    /*
     * ⛔ বাছাই করলে কিছু ঘটবে কি না — আর সেটা **ঐচ্ছিক**।
     *
     * ── ⚠️ কেন ২১ সেপ্টেম্বর ২০২৬-এ এটা বসাতে হলো ───────────────────
     * ব্যাংক ঋণের ফর্মে `pickedBank()` লেখা ছিল, ঠিকঠাক কাজও করত, আর
     * তিনটা ইউনিট পরীক্ষা ওটাকে সবুজ বলত। ⛔ কিন্তু **কেউ ওটাকে ডাকত
     * না** — এই ঘরের `select`-এ কোনো `x-on:change` ছিল না। পরীক্ষাগুলো
     * ফাংশনটাকে সরাসরি ডাকত, তাই তারা প্রমাণ করত ফাংশনটা কাজ করে, এটা
     * নয় যে কেউ ওটাকে ডাকে।
     *
     * ⭐ মালিক বললেন *"শাখা auto bose na, bosar kotha cilo"* — আর তিনি
     * ঠিক ছিলেন: ব্যাংক বাছলে কিচ্ছু হত না।
     *
     * ⓘ ঐচ্ছিক, কারণ একই ঘর আমানত ও বীমার ফর্মেও বসে, যেখানে ঐ
     * ফাংশনটা নেই — আর না থাকলে Alpine নীরবে থেমে না গিয়ে কনসোলে
     * ভুল ছাপত।
     */
    $onPick ??= null;

    /*
     * ⚠️ শর্তওয়ালা অ্যাট্রিবিউটটা এখানে বানানো হয়, ট্যাগের ভিতরে নয় —
     * ওখানে `@if` বসালে গোটা ট্যাগটা কম্পাইল না হয়ে হুবহু ছাপা হয়ে যায়।
     */
    $extra = new \Illuminate\View\ComponentAttributeBag(
        $onPick ? ['x-on:change' => $onPick] : []
    );
@endphp

<div class="space-y-2">
    {{-- ⛔ `required` নেই — "দুইটার একটা" নিয়মটা HTML বলতে পারে না;
         বসালে নতুন নাম লেখার পথটাই অচল হত। [[person-picker]]-এ বিস্তার। --}}
    {{--
        ⛔ এখানে ট্যাগের **ভিতরে** একটা `@if … @endif` বসানো ছিল —
        ২১ সেপ্টেম্বর ২০২৬, মালিকের চোখে ধরা।

        ⚠️ Blade কম্পোনেন্টের ট্যাগ পার্স করার সময় ভিতরের নির্দেশ সামলাতে
        পারে না, তাই গোটা `<x-ui.select …>` **হুবহু লেখা হিসেবে** পাতায়
        চলে যেত — আর ব্রাউজার ওটাকে অচেনা একটা এলিমেন্ট ধরে চুপচাপ আঁকত।

        ⛔ ফল: **প্রতিষ্ঠানের ড্রপডাউনটা কোনোদিন তৈরিই হয়নি**। মালিকের
        প্রশ্ন ছিল *"প্রতিষ্ঠানের নাম নাই কেন"* — উত্তর: ঘরটা ছিলই না,
        কেবল নিচের "তালিকায় নেই?" অংশটা দেখা যেত। ⓘ আর পাতা ২০০ দিত,
        কোনো ত্রুটি ছাড়াই — তিনটা পর্দায় (জমা · ব্যাংক সুবিধা · বিমা)।

        ⭐ শর্তটা এখন অ্যাট্রিবিউট-ব্যাগে, ট্যাগের বাইরে বানানো।
    --}}
    <x-ui.select :name="$field"
                 :label="$label"
                 :options="$institutions"
                 :selected="$selected"
                 :attributes="$extra"
                 placeholder="—" />

    <details class="text-sm" @if ($errors->has('institution_new')) open @endif>
        <summary class="cursor-pointer text-(--color-brand-500) underline-offset-2 hover:underline">
            + {{ __('finance::institution.not_listed') }}
        </summary>

        <div class="mt-2">
            <x-ui.field name="institution_new"
                        :label="__('finance::institution.new_name')"
                        :value="old('institution_new')"
                        :hint="__('finance::institution.new_name_hint')" />
        </div>
    </details>
</div>
