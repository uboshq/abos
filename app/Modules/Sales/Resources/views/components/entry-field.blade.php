@props(['label'])

{{-- এন্ট্রি স্ট্রিপের একটা ঘর — লেবেল ছোট বড়হাতের, নমুনার মতো।

     আলাদা কম্পোনেন্ট, কারণ ঘরগুলো দশটা আর প্রতিটায় একই তিন লাইনের মার্কআপ।
     হাতে লিখলে একটায় লেবেলের মাপ বদলে বাকিগুলোর সাথে আর মিলত না। --}}
<label class="block">
    <span class="mb-1 block truncate text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
        {{ __($label) }}
    </span>
    {{ $slot }}
</label>
