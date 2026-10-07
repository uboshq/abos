{{--
    বিমাকারীর নাম → প্রতিষ্ঠানের পাতা।

    ⓘ [[name-link]] থেকে আলাদা, কারণ এখানে হাতে থাকে কেবল আইডি
    (`$policy->institution_id`) আর নামটা, গোটা মডেল নয় — আর প্রতিষ্ঠান
    মুছে গেলে বা না থাকলে লিংক দেওয়ার কিছু নেই।

    @param $id     প্রতিষ্ঠানের আইডি, নয়তো null
    @param $label  যা লেখা থাকবে
--}}
@if ($id)
    <a href="{{ route('finance.institution.show', $id) }}"
       class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $label }}</a>
@else
    <span class="text-(--color-ink-muted)">—</span>
@endif
