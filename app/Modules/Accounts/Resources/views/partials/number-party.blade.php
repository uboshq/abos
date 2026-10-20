{{--
    ⭐ কাগজের নম্বর, পাশে পক্ষ — মালিক, ১৯ অক্টোবর ২০২৬: *"নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।"*

    ⓘ টেবিলের `render` ক্লোজার কম্পোনেন্ট সরাসরি ফেরত দিতে পারে না, তাই এই ছোট partial কোরের `<x-ui.party-cell>` বসায়।
    `$href` থাকলে নম্বরটা লিংক। ⚠️ `$party` আনে ডাকনেওয়ালা, গোটা পাতার একবারে — এখানে কোনো প্রশ্ন নেই।
--}}
<x-ui.party-cell :party="$party ?? null">
    @if (! empty($href))
        <a href="{{ $href }}" class="num text-(--color-brand-500) underline-offset-2 hover:underline">{{ $no }}</a>
    @else
        <span class="num">{{ $no ?? '—' }}</span>
    @endif
</x-ui.party-cell>
