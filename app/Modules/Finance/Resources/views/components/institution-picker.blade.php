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
        ])
--}}
@php
    $field ??= 'institution_id';
    $label ??= __('finance::institution.which');
    $selected ??= old($field);
@endphp

<div class="space-y-2">
    {{-- ⛔ `required` নেই — "দুইটার একটা" নিয়মটা HTML বলতে পারে না;
         বসালে নতুন নাম লেখার পথটাই অচল হত। [[person-picker]]-এ বিস্তার। --}}
    <x-ui.select :name="$field"
                 :label="$label"
                 :options="$institutions"
                 :selected="$selected"
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
