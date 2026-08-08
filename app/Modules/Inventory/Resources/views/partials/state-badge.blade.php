@php
    use App\Modules\Inventory\Models\Product;
    use App\Modules\Inventory\Models\Warehouse;

    // @include দিয়েও ডাকা হয়, তাই @props নয়
    $size = $size ?? 'md';

    /*
     * পণ্য আর গুদাম একই পার্শিয়াল ভাগ করে, কিন্তু রুট আলাদা।
     *
     * ── কেন একটাই পার্শিয়াল ─────────────────────────────────────────
     * দুইটার আচরণ হুবহু এক: সক্রিয় ↔ নিষ্ক্রিয়, মোছা নয়। দুইটা ফাইল
     * রাখলে একদিন একটায় নিয়ম বদলাত আর অন্যটা পুরনো আচরণে থেকে যেত,
     * আর ব্যবহারকারী দুই পর্দায় দুই রকম দেখতেন।
     *
     * ── কেন রুটটা এখানে ঠিক হয়, প্রপে নয় ────────────────────────────
     * প্রপ দিলে তিনটা ডাকার জায়গায় তিনবার একই কথা লিখতে হত, আর একটা
     * ভুলে গেলে সেই পর্দাটা নীরবে ভুল রুটে পোস্ট করত। মডেলটা নিজেই
     * বলে দিতে পারে সে কে — তাই সে-ই বলে।
     */
    $base = $record instanceof Warehouse ? 'inventory.warehouse' : 'inventory.product';
@endphp

{{--
    সক্রিয় / নিষ্ক্রিয় — সুইচের চেহারায় (মালিকের নমুনা, ২০২৬-০৮-০৭)।

    অনুমতি থাকলে বোতাম, না থাকলে একই চেহারার স্থির ব্যাজ: সুইচের চেহারা
    দিয়ে ক্লিকে কিছু না হওয়াটা পর্দার মিথ্যা কথা, মানুষ চাপবেই।

    destroy রুটটা মোছে না, নিষ্ক্রিয় করে — যে পণ্যের নামে লেনদেন আছে
    তাকে মুছলে ওই লেনদেনগুলোর পণ্যটাই হারিয়ে যেত।
--}}
@can('delete', $record)
    <x-ui.state-toggle
        :active="$record->is_active"
        :size="$size"
        :action="$record->is_active
            ? route($base.'.destroy', $record)
            : route($base.'.activate', $record)"
        :method="$record->is_active ? 'DELETE' : 'POST'"
        :aria-label="($record->is_active ? __('core.state.inactive') : __('core.state.active')).' — '.$record->name()" />
@else
    <x-ui.state-toggle :active="$record->is_active" :size="$size" />
@endcan
