{{-- কার নামে — আর এটাই বলে দেয় সংখ্যাটা স্থিতিপত্রে আছে কি না।

     ── কেন রং দুইটা আলাদা ────────────────────────────────────────────
     ব্যবসার জমা সম্পদ; মালিকের সঞ্চয়পত্র উত্তোলন হয়ে বেরিয়ে গেছে।
     এক তালিকায় পাশাপাশি বসে বলে চোখে আলাদা না হলে কেউ দুইটা যোগ করে
     ফেলতেন — আর ওই যোগফল কোনো রিপোর্টের সাথেই মিলত না। --}}
<span class="inline-flex flex-col">
    <x-ui.badge :tone="$deposit->isBusinessAsset() ? 'info' : 'draft'">
        {{ __('finance::who.'.$deposit->held_by) }}
    </x-ui.badge>

    @if ($deposit->holder_name)
        <span class="mt-0.5 text-2xs text-(--color-ink-muted)">{{ $deposit->holder_name }}</span>
    @endif
</span>
