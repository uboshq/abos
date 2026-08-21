@props(['title', 'subtitle' => null])

{{--
    পাতার শিরোনাম ও Productivity Bar — সেকশন ১৫.১৬।

    Create · Save · Approve · Print · Export · Share · Duplicate · History · Help
    — যেগুলো এই স্ক্রিনে প্রযোজ্য, সেগুলো actions slot-এ। সবসময় একই জায়গায়,
    ডান দিকে, একই ক্রমে; নাহলে ব্যবহারকারীকে প্রতিটা স্ক্রিনে বোতাম খুঁজতে হয়।
--}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-start justify-between gap-3']) }}>
    <div class="min-w-0">
        <h1 class="truncate text-xl font-semibold">{{ $title }}</h1>

        @if ($subtitle)
            <p class="mt-0.5 text-sm text-(--color-ink-muted)">{{ $subtitle }}</p>
        @endif
    </div>

    {{-- print-hide: "নতুন সরবরাহকারী" কাগজে ক্লিক করা যায় না। বোতামগুলো
         <a> হলে print CSS-এর button নিয়মে ধরা পড়ে না, তাই এখানে স্পষ্ট
         করে বলা। --}}
    {{--
        সোনার সরু রেখা — কেবল Rose-এ দেখা যায়।

        ── কেন এটা এখানে, রূপের নিজের ফাইলে নয় ─────────────────────
        রেখাটা শিরোনামের **ঠিক নিচে** বসে, আর শিরোনাম এই কম্পোনেন্টের
        ভেতরে। শেল থেকে ওখানে পৌঁছানো যায় না।

        উচ্চতা টোকেন থেকে, আর ডিফল্ট শূন্য — তাই বাকি সাত রূপে ওটা
        থাকলেও দেখা যায় না, একটা পিক্সেলও নেয় না।

        ── কেন সোনা কেবল এখানেই ────────────────────────────────────
        প্রকল্পের লেখা নিয়ম: সোনা অলঙ্কার, তথ্য নয়। টেবিলে, সংখ্যায়
        বা অবস্থায় কখনো নয় — কেবল সীমানা ও শিরোনামের রেখায়।
    --}}
    <div data-gold-hairline aria-hidden="true"
         class="gold-hairline mt-3 rounded-full"></div>

    @isset($actions)
        <div class="print-hide flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
