@props([
    'value' => 0,
    'href' => null,
    'decimals' => 2,
    'blankOnZero' => false,
    'tone' => null,
])

{{--
    টাকার একটা অঙ্ক — আর যেখানে সম্ভব, তার উৎসে যাওয়ার লিংক।

    নিয়ম ১ বলে প্রতিটা সংখ্যা থেকে তার উৎসে যাওয়া যাবে। এতদিন সেটা
    অর্ধেক মানা হত: কোড ও ডকুমেন্ট নম্বর ক্লিকযোগ্য ছিল, কিন্তু **টাকার
    অঙ্কগুলো নয়**। অথচ ব্যবহারকারী কোডে ক্লিক করেন না — তিনি সংখ্যাটা
    দেখেন, অবাক হন, আর জানতে চান "এই ১,২৫,০০০ কোথা থেকে এল"। ঠিক ওই
    জায়গাটাই ক্লিকযোগ্য ছিল না।

    href না দিলে সাধারণ লেখা — সব সংখ্যার পেছনে যাওয়ার মতো কিছু থাকে
    না (যেমন গণনার নোটের হিসাব, বা তালিকার যোগফল যা চোখের সামনের
    সারিগুলোরই যোগ)।

    num ক্লাসটা ট্যাবুলার অঙ্ক দেয় — একই প্রস্থে প্রতিটা সংখ্যা, তাই এক
    কলামে দশমিক বিন্দু এক লাইনে থাকে।
--}}
@php
    $zero = bccomp((string) ($value === '' || $value === null ? 0 : $value), '0', 4) === 0;

    $text = $blankOnZero && $zero
        ? ''
        : number_format((float) $value, $decimals);

    $classes = trim('num '.($tone !== null ? "text-(--color-{$tone})" : ''));
@endphp

@if ($href !== null && $text !== '')
    <a href="{{ $href }}"
       {{ $attributes->class([
           $classes,
           'text-(--color-brand-500) underline-offset-2 hover:underline' => $tone === null,
           'underline-offset-2 hover:underline' => $tone !== null,
       ]) }}>{{ $text }}</a>
@else
    <span {{ $attributes->class([$classes]) }}>{{ $text }}</span>
@endif
