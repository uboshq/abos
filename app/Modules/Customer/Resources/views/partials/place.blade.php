{{--
    ⭐ গ্রাহকের জায়গা — "পয়েন্ট · শাখা", তালিকার আলাদা কলামে। মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম): *"গ্রাহকের নামের পাশে
    পয়েন্ট দিতে হবে অবশ্যই, সব জায়গায়, আর শাখার নামও দিতে হবে।"*

    ⓘ এক লাইনে (১০৮০p-এ সারি ভাঙে না): লম্বা হলে "…", পুরো লেখা `title`-এ। জায়গা না থাকলে "—"।
    ⚠️ এই ঘর কখনো নিজে প্রশ্ন করে না — ডাকনেওয়ালা গোটা পাতার `customer.location.parent` আর `customer.branch` একবারে আনে
    ([[Customer::PLACE]])। ⓘ সারিতে লেখা আগেই তৈরি থাকলে (`place`, যেমন ট্র্যাকিংয়ের সারি) সেটাই বসে।
--}}
@php $place = isset($place) ? (trim((string) $place) ?: null) : ($customer ?? null)?->placeName(); @endphp
@if ($place === null)—@else<span class="block max-w-48 truncate" title="{{ $place }}" data-place>{{ $place }}</span>@endif
