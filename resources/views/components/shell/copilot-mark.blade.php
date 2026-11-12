{{--
    ⭐ কোপাইলটের জীবন্ত মগজ — মালিকের "ঘ" (১২ নভেম্বর ২০২৬; নমুনা Ve4fMPX3g6ksYwUSyDG99o): ঘোরা রঙের আংটি, ভেতরে রং-বদলানো মগজ
    যা আলতো শ্বাস নেয়, আর মগজের ভাঁজে আলোর বিন্দু ছোটে। রং আর নড়াচড়া [[copilot-quick.css]]-এ; রঙের ঢাল (`#cq-grad`) পাতায় একবার,
    [[x-shell.copilot-button]]-এ।
    @param $size  bare (উপরের বার — আংটি ছাড়া, মালিক ১২ নভেম্বর) | sm (উত্তরের পাশে) | lg (জিজ্ঞাসার মাথায়)
--}}
@props(['size' => 'sm'])
@php
    $left = 'M9 4.5A2.5 2.5 0 0 0 6.5 7v.2A2.8 2.8 0 0 0 4.5 10a2.8 2.8 0 0 0 .8 2 2.8 2.8 0 0 0-.8 2 2.8 2.8 0 0 0 2 2.7v.3a2.5 2.5 0 0 0 4.5 1.5V6';
    $right = 'M15 4.5A2.5 2.5 0 0 1 17.5 7v.2a2.8 2.8 0 0 1 2 2.8 2.8 2.8 0 0 1-.8 2 2.8 2.8 0 0 1 .8 2 2.8 2.8 0 0 1-2 2.7v.3a2.5 2.5 0 0 1-4.5 1.5V6';
@endphp
<span {{ $attributes->merge(['class' => 'cq-orb-mark'.(in_array($size, ['lg', 'bare'], true) ? ' '.$size : '')]) }} aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="url(#cq-grad)" stroke-width="{{ $size === 'lg' ? '1.8' : '2.1' }}" stroke-linecap="round" stroke-linejoin="round">
        <path d="{{ $left }}A2.5 2.5 0 0 0 9 4.5zM{{ substr($right, 1) }}A2.5 2.5 0 0 1 15 4.5zM9 9.5H7.5M16.5 9.5H15M9 14H8M16 14h-1"/>
        <circle class="cq-spark cq-spark-a" r="1.2" stroke="none"><animateMotion dur="2.4s" repeatCount="indefinite" path="{{ $left }}"/></circle>
        <circle class="cq-spark cq-spark-b" r="1.2" stroke="none"><animateMotion dur="2.4s" begin="1.2s" repeatCount="indefinite" path="{{ $right }}"/></circle>
    </svg>
</span>
