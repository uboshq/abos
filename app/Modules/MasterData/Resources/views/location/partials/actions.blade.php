{{--
    একটা এলাকার সারির কাজগুলো — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬:
    প্রতিটা স্তরের ট্যাবে সম্পাদনা · সক্রিয়/নিষ্ক্রিয় · মুছুন।

    ⓘ মাস্টার তালিকার সারির মতোই ([[list/partials/actions]]) — একই ক্রম,
    একই চাবি, যাতে এক পর্দা শিখলে অন্যটা আলাদা করে শিখতে না হয়।

    ⚠️ "মুছুন" সবসময় দেখায়, নিচে সন্তান থাকলেও। ⓘ লুকালে মানুষ ভাবতেন
    বোতামটাই নেই; চাপলে সার্ভার বলে দেয় কেন হল না — "নিচে ৩টা পয়েন্ট
    আছে" — আর সেটাই পরের ধাপ বলে দেয়।
--}}
@php
    // গাছের সারি থেকে এলে: নিচের স্তর যোগ — প্রথম কাজ ([[location/partials/row]])
    $items = array_values(array_filter([$addChild ?? null]));

    if (auth()->user()?->can('master_data.manage')) {
        $items[] = [
            'label' => __('master_data::action.edit'),
            'url' => route('master_data.location.edit', $location),
        ];

        // দুই অবস্থার দুই কাজ — একসাথে কখনো দরকার হয় না
        $items[] = $location->is_active
            ? ['label' => __('master_data::action.deactivate'),
                'url' => route('master_data.location.destroy', $location),
                'method' => 'delete', 'tone' => 'danger']
            : ['label' => __('master_data::action.activate'),
                'url' => route('master_data.location.activate', $location),
                'method' => 'post', 'tone' => 'success'];
    }

    // মোছা শেষে — এটাই একমাত্র কাজ যা ফেরানো যায় না
    if (auth()->user()?->can('master_data.delete')) {
        $items[] = [
            'label' => __('master_data::action.delete'),
            'url' => route('master_data.location.purge', $location),
            'method' => 'delete',
            'tone' => 'danger',
        ];
    }
@endphp

{{-- ⭐ তিন বিন্দুর বদলে "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"); কাজগুলো আগের মতোই --}}
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($location), \App\Core\Support\RowMenu::activeState($location), route('master_data.location.show', $location), $location->code ?? null, 'master_data')" />
