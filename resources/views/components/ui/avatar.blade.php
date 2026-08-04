@props(['user' => null, 'size' => 'md'])

{{--
    ব্যবহারকারীর ছবি — ছবি থাকলে ছবি, না থাকলে আদ্যক্ষর।

    একটাই কম্পোনেন্ট, কারণ ছবি টপবারে, ড্রপডাউনে, প্রোফাইল পাতায় ও পরে
    অনুমোদনের ইতিহাসে দেখাতে হবে। আলাদা করে লিখলে একদিন একটায় ছবি আসত
    আর অন্যটায় আদ্যক্ষরই থেকে যেত।

    বৃত্তের রং accent থেকে আসে — ব্যবহারকারী রং বদলালে এটাও বদলায়।
--}}
@php
    $classes = [
        'sm' => 'size-8 text-xs',
        'md' => 'size-(--spacing-touch) text-sm',
        'lg' => 'size-24 text-3xl',
    ][$size] ?? 'size-(--spacing-touch) text-sm';

    $url = $user?->avatarUrl();
@endphp

@if ($url)
    {{-- alt="" — পাশেই নামটা লেখা থাকে, তাই স্ক্রিন রিডারে নামটা দুইবার
         পড়ার কোনো মানে নেই (সেকশন ১৭.৪)। --}}
    <img src="{{ $url }}" alt=""
         {{ $attributes->class([$classes, 'shrink-0 rounded-full object-cover']) }}>
@else
    <span aria-hidden="true"
          {{ $attributes->class([
              $classes,
              'flex shrink-0 items-center justify-center rounded-full',
              'bg-(--color-brand-700) font-semibold text-(--color-ink-inverse)',
          ]) }}>
        {{ $user?->initial() ?? '?' }}
    </span>
@endif
