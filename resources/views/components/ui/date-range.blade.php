@props(['dates' => ['from' => null, 'to' => null]])

{{--
    তারিখের পরিসর — টুলবারের ফিল্টার প্যানেলের ভেতরে বসে।

    সাতটা ডকুমেন্ট তালিকায় হুবহু একই দুইটা ঘর লাগে। প্রতিটাতে হাতে লিখলে
    একটায় aria-label বাদ পড়ত, আরেকটায় ঘরের নাম from-এর বদলে start হত,
    আর তখন হোম পর্দার লিংক ওই এক তালিকায় কাজ করত না — কেউ ধরতেও পারত না
    কেন।

    onchange-এ ফর্ম জমা: তারিখ বাছাইয়ের পর আলাদা করে "খুঁজুন" চাপতে হয় না।
--}}
<x-ui.date name="from"
           value="{{ $dates['from'] ?? '' }}"
           aria-label="{{ __('core.table.from_date') }}"
           :submit-on-change="true"
           class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />

<x-ui.date name="to"
            value="{{ $dates['to'] ?? '' }}"
            aria-label="{{ __('core.table.to_date') }}"
            :submit-on-change="true"
            class="h-(--spacing-field-compact) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 text-sm" />
