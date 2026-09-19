{{--
    সক্রিয় না নিষ্ক্রিয় — পিলটাই বোতাম, কোম্পানির তালিকার মতো।

    ⛔ ২০ সেপ্টেম্বর ২০২৬, মালিক: *"Deactive korlei List theke Hariye zacche
    keno? ekhane Status dewar dorkar"*। ⓘ নিষ্ক্রিয় করলে সারিটা তালিকা থেকেই
    উধাও হত — "নিষ্ক্রিয়গুলোও দেখাও" টিক ছাঁকনির প্যানেলে লুকানো ছিল, তাই
    মনে হত মুছে গেছে। এখন সারি থাকে, অবস্থা এই কলামে।

    ⚠️ চাপার অধিকার Action মেনুর মতোই (`master_data.manage`) আর একই দরজা:
    নিষ্ক্রিয় করা `destroy` (DELETE), সক্রিয় করা `activate` (POST)। অধিকার না
    থাকলে পিলটা কেবল দেখায়, চাপা যায় না।
--}}
@php
    $canToggle = auth()->user()?->can('master_data.manage') ?? false;
@endphp
<x-ui.state-toggle
    :active="(bool) $location->is_active"
    :action="$canToggle
        ? ($location->is_active
            ? route('master_data.location.destroy', $location)
            : route('master_data.location.activate', $location))
        : null"
    :method="$location->is_active ? 'DELETE' : 'POST'"
    size="sm" />
