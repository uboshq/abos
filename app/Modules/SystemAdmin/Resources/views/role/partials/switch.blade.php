{{--
    অনুমতির একটা সুইচ — নকশার টগলের চেহারা, ভেতরে সাধারণ চেকবক্স।

    ⓘ চেকবক্সটা `sr-only`, মোছা নয় — তাই ট্যাব দিয়ে পৌঁছানো যায়, স্পেসে
    বদলায়, আর ফর্ম জমা দিলে `permissions[]`-এ নামটা যায় ঠিক আগের মতো।
    ⚠️ লেখাটা (`$label`) পর্দা-পাঠকের জন্য: ঘরে কেবল সুইচ, আর "সম্পাদনা"
    শুনে বোঝা যায় না কীসের সম্পাদনা।
--}}
{{--
    ℹ `$cell` — কোন কলামের ঘর, যাতে কলামের মাথার "সব" টিকটা
    ওকে খুঁজে পায়। ⚠️ না দিলে ঘরটা কেবল মডিউলের টিকে পড়ে।
--}}
<label class="relative inline-flex cursor-pointer items-center gap-2 align-middle" title="{{ $name }}">
    <input type="checkbox" name="permissions[]" value="{{ $name }}" class="peer sr-only"
           @isset($cell) data-permission-cell="{{ $cell }}" @endisset
           @checked($checked)>
    <span class="h-5 w-9 rounded-full bg-(--color-border-strong) transition
                 peer-checked:bg-(--color-brand-500)
                 peer-focus-visible:ring-2 peer-focus-visible:ring-(--color-brand-500) peer-focus-visible:ring-offset-1"></span>
    <span class="pointer-events-none absolute left-0.5 top-0.5 size-4 rounded-full bg-white shadow-sm transition
                 peer-checked:translate-x-4"></span>
    <span class="sr-only">{{ $label }}</span>
    @isset($caption)
        <span class="text-xs text-(--color-ink-muted)" aria-hidden="true">{{ $caption }}</span>
    @endisset
</label>
