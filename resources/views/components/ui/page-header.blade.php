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
    @isset($actions)
        <div class="print-hide flex flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>
