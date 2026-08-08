@php
    // @include দিয়ে ডাকা হয়, তাই @props নয় — ওটা কেবল কম্পোনেন্টে চলে
    $size = $size ?? 'md';
@endphp

{{--
    সরবরাহকারীর অবস্থা — সুইচের চেহারায় (মালিকের নমুনা, ২০২৬-০৮-০৭)।

    গ্রাহকের সাথে হুবহু একই আচরণ, আর সেটাই মূল কথা: এক জিনিস দুই পর্দায়
    দুই রকম দেখালে ব্যবহারকারীকে দুইবার শিখতে হয়।

    অনুমতি থাকলে এটা বোতাম, না থাকলে একই চেহারার স্থির ব্যাজ — সুইচের
    চেহারা দিয়ে ক্লিকে কিছু না হওয়াটা পর্দার মিথ্যা কথা।

    destroy রুটটা মোছে না, নিষ্ক্রিয় করে: যে সরবরাহকারীর নামে বিল আছে
    তাঁকে মুছলে ওই বিলগুলোর মালিক হারিয়ে যেত।
--}}
@can('delete', $supplier)
    <x-ui.state-toggle
        :active="$supplier->is_active"
        :size="$size"
        :action="$supplier->is_active
            ? route('supplier.destroy', $supplier)
            : route('supplier.activate', $supplier)"
        :method="$supplier->is_active ? 'DELETE' : 'POST'"
        :confirm="$supplier->is_active ? __('supplier::message.confirm_deactivate') : null"
        :aria-label="($supplier->is_active ? __('core.state.inactive') : __('core.state.active')).' — '.$supplier->name()" />
@else
    <x-ui.state-toggle :active="$supplier->is_active" :size="$size" />
@endcan
