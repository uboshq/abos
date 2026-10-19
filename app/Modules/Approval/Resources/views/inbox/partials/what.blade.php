{{--
    কী নিয়ে অনুরোধ।

    নামটা মডিউলের নিজের ঘোষণা থেকে আসে (module.php → approvals), তাই
    "sales · discount" নয়, "বিক্রয়ে ছাড়" লেখা থাকে। ঘোষণাটা না পেলে
    কাঁচা নামটাই দেখানো হয় — লুকিয়ে ফেলার চেয়ে সেটা ভালো, কারণ তখন
    অন্তত বোঝা যায় কোন ছকটা ঘোষণা করতে ভুলে যাওয়া হয়েছে।
--}}
<span class="font-medium">
    {{ $labels[$approval->module.'.'.$approval->action] ?? $approval->module.' · '.$approval->action }}
</span>

{{-- ⓘ কাগজের নম্বর — "সই হয়েছে, পোস্ট হয়নি" তালিকায় কাগজটা চেনার জন্য (১১ অক্টোবর ২০২৬)
     ⭐ আর নম্বরের পাশে পক্ষ, এক লাইনে — মালিক, ১৯ অক্টোবর ২০২৬: *"নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়"*
     ([[ApprovalFacts]], গোটা পাতার জন্য একবারে)। ⓘ ইনবক্সের মূল তালিকায় নম্বর আর পক্ষ নিজের কলামে, তাই সেখানে কিছু আসে না। --}}
@if (! empty($paperNo) || ! empty($party))
    <span class="block text-(--color-ink-muted)">
        <x-ui.party-cell :party="$party ?? null"><span class="num">{{ $paperNo ?: '—' }}</span></x-ui.party-cell>
    </span>
@endif

@if ($approval->requested_reason)
    <span class="block text-2xs text-(--color-ink-muted)">{{ $approval->requested_reason }}</span>
@endif
