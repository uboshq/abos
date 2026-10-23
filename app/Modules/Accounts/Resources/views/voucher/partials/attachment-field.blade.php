{{--
    সংযুক্তি — বিলের ছবি বা স্ক্যান।

    ── ⛔ কেন ঘরটা ফর্মে লাগে, ১৫ সেপ্টেম্বর ২০২৬ ───────────────────────
    সংযুক্তির ব্যবস্থাটা আগে থেকেই আছে ([[AttachmentEngine]]), আর
    ডকুমেন্টের **দেখার পাতায়** কার্ডটাও বসানো — কিন্তু **তৈরির পর্দায়
    কোনো ঘর ছিল না**।

    ⚠️ ফলে ব্যবহারকারীকে আগে ভাউচারটা সংরক্ষণ করতে হত, তারপর খুলে ছবি
    যোগ করতে হত — দুই ধাপ, আর দ্বিতীয় ধাপটা মানুষ ভুলে যান। ⓘ নমুনার
    খরচ আর জাবেদা দুইটাতেই ঘরটা আছে, কারণ ঐ দুইটাতেই কাগজ থাকে।

    ── ⓘ স্ক্যানের পর্দাটা এখানেই খোলে ─────────────────────────────────
    `x-on:change` দিয়ে [[components/shell/scanner]] ডাকা হয় — ছবি হলে
    চার কোণ টেনে সোজা করার পর্দা খোলে, PDF হলে কিছুই হয় না।
--}}
<div>
    <label for="attachment" class="mb-1 block text-sm font-medium">
        {{ __('accounts::field.attachment') }}
    </label>

    {{-- ⭐ বাংলা বোতাম ([[ui.file-input]]) — ব্রাউজারের ইংরেজি "Choose File / No file chosen" নয় (fe, ২৩ অক্টোবর ২০২৬); স্ক্যানার আসল ঘরেই --}}
    <x-ui.file-input id="attachment" name="attachment"
                     accept="image/png,image/jpeg,image/webp,application/pdf"
                     x-on:change="$store.scanner.begin($el, 'paper')" />

    <p class="mt-1 text-xs text-(--color-ink-muted)">
        {{ __('accounts::message.attachment_hint') }}
    </p>
</div>
