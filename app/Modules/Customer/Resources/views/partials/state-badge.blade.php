@php
    // @include দিয়ে ডাকা হয়, তাই @props নয় — ওটা কেবল কম্পোনেন্টে চলে
    $size = $size ?? 'md';
@endphp

{{--
    গ্রাহকের অবস্থা — সুইচের চেহারায় (মালিকের নমুনা, ২০২৬-০৮-০৭)।

    ── অনুমতি থাকলে এটা বোতাম, না থাকলে শুধু তথ্য ──────────────────────
    সুইচের চেহারা দিয়ে ক্লিকে কিছু না হওয়াটা পর্দার মিথ্যা কথা — মানুষ
    চাপবেই, আর কিছু না ঘটলে ভাববে সিস্টেম নষ্ট। তাই যিনি বদলাতে পারেন
    তাঁর জন্য এটা সত্যিকারের বোতাম, আর বাকিদের জন্য একই চেহারার একটা
    স্থির ব্যাজ।

    ── "মুছে ফেলা" নয়, "নিষ্ক্রিয়" ─────────────────────────────────────
    destroy রুটটা আসলে নিষ্ক্রিয় করে (CustomerController::destroy →
    deactivate), মোছে না। যে গ্রাহকের নামে বিল আছে তাঁকে মুছলে ওই
    বিলগুলোর মালিক হারিয়ে যেত — খতিয়ানে টাকা থাকত, কার কাছে পাওনা তা
    থাকত না।
--}}
@can('customer.update')
    <x-ui.state-toggle
        :active="$customer->is_active"
        :size="$size"
        :action="$customer->is_active
            ? route('customer.destroy', $customer)
            : route('customer.activate', $customer)"
        :method="$customer->is_active ? 'DELETE' : 'POST'"
        :confirm="$customer->is_active ? __('customer::message.confirm_deactivate') : null"
        :aria-label="($customer->is_active ? __('customer::action.deactivate') : __('customer::action.activate')).' — '.$customer->name()" />
@else
    <x-ui.state-toggle :active="$customer->is_active" :size="$size" />
@endcan
