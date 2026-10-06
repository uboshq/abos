{{--
    ⭐ "এটা আলাদা প্রতিষ্ঠান" — নামে মিল পেলে টিক দিয়ে এগোনোর ঘর (মালিক, ৬ অক্টোবর ২০২৬: *"ঘরটা সেটাই তো নাই"*)।

    ⛔ সার্ভার `allow_duplicate` বুঝত আর বার্তায় বলত "ঘরটা টিক দিয়ে আবার সংরক্ষণ করুন", কিন্তু গ্রাহক বা
    সরবরাহকারীর ফর্মে ঘরটা আঁকাই ছিল না — তাই নামে মিল পেলে মানুষ আটকেই থাকতেন।
    ⓘ দেখা যায় কেবল নামের মিলের ভুল এলে, বা আগে টিক দেওয়া থাকলে; সব সময় দেখালে না পড়েই টিক পড়ত।
--}}
@php
    $nameError = $errors->first('name_en');
    $show = old('allow_duplicate') || ($nameError !== '' && str_starts_with($nameError, __('core.duplicate.name_matches')));
@endphp

@if ($show)
    <label class="mt-2 flex items-center gap-2 text-sm" data-allow-duplicate>
        <input type="checkbox" name="allow_duplicate" value="1" class="size-4" @checked(old('allow_duplicate'))>
        {{ __('core.duplicate.allow') }}
    </label>
@endif
