@props(['status'])

{{--
    ডকুমেন্টের অবস্থা — খসড়া, নিশ্চিত, বাতিল, সম্পন্ন।

    প্রতিটা স্ক্রিনে একই রং ও একই লেখা। আলাদা করে লিখলে এক স্ক্রিনে
    "বাতিল" লাল আর অন্যটায় ধূসর হয়ে যেত, আর ব্যবহারকারী কোনটার কী মানে
    সেটা শিখতেই পারত না।
--}}
@php
    $tones = [
        \App\Core\Support\DocumentStatus::DRAFT => 'draft',
        \App\Core\Support\DocumentStatus::CONFIRMED => 'success',
        \App\Core\Support\DocumentStatus::CANCELLED => 'danger',
        \App\Core\Support\DocumentStatus::CLOSED => 'info',
    ];
@endphp

<x-ui.badge :tone="$tones[$status] ?? 'draft'" {{ $attributes }}>
    {{ __('core.status.' . $status) }}
</x-ui.badge>
