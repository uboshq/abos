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

@if ($approval->requested_reason)
    <span class="block text-2xs text-(--color-ink-muted)">{{ $approval->requested_reason }}</span>
@endif
