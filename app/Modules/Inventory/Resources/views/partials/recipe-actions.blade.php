{{-- সারির কাজ — সম্পাদনা, আর চালু/বন্ধ।

     "মোছা" নেই: রেসিপি মুছে ফেললে পুরনো বিক্রির ইতিহাস অনাথ হত
     ("ওই দিন কী দিয়ে বানানো হয়েছিল")। নিয়ম ৫। --}}
{{--
    ── ⚠️ আগে এই বোতামটা পর্দায় ছিলই না ────────────────────────────────
    এখানে কাজগুলো `<x-ui.row-actions>`-এর **স্লটে** লেখা ছিল। কিন্তু
    কম্পোনেন্টটা কেবল `items` prop আঁকে, `$slot` কোথাও রেন্ডার করে না —
    আর ভেতরে `@if ($items !== [])` থাকায় সে **কোনো ত্রুটি ছাড়াই কিছুই
    আঁকত না**।

    অর্থাৎ রেসিপির তালিকায় Action বোতামটা অদৃশ্য ছিল — হুবহু সেই
    অভিযোগ যেটা মালিক পণ্যের তালিকা নিয়ে করেছেন (৩ সেপ্টেম্বর ২০২৬)।
    ধরা পড়েছে পণ্যের তালিকা বানাতে গিয়ে, নজির খুঁজতে গিয়ে।

    কম্পোনেন্টটা এখন স্লট পেলে **লাল হয়**, নীরবে গিলে ফেলে না।
--}}
@php
    $items = [];

    if (auth()->user()?->can('update', $recipe)) {
        $items[] = [
            'label' => __('core.action.edit'),
            'url' => route('inventory.recipe.edit', $recipe),
        ];
    }

    /*
     * চালু/বন্ধ — অবস্থা অনুযায়ী একটাই বোতাম।
     *
     * ⚠️ নিষ্ক্রিয় করার লেখাটা আগে `action.show_inactive` ছিল
     * ("নিষ্ক্রিয় দেখান") — তালিকার ছাঁকনির লেখা, কাজের নয়। বোতামটা
     * কোনোদিন পর্দায় আসেনি বলে ভুলটাও কেউ দেখেনি।
     */
    if (auth()->user()?->can('delete', $recipe)) {
        $items[] = $recipe->is_active
            ? [
                'label' => __('inventory::action.deactivate'),
                'url' => route('inventory.recipe.destroy', $recipe),
                'method' => 'delete',
                'tone' => 'danger',
            ]
            : [
                'label' => __('inventory::action.activate'),
                'url' => route('inventory.recipe.activate', $recipe),
                'method' => 'post',
                'tone' => 'success',
            ];
    }
@endphp

<x-ui.row-actions :items="$items" />
