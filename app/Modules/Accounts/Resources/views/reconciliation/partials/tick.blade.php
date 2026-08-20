{{--
    টিকের ঘরটা।

    সারির ভেতরে একটা ইনপুট — তাই `render` ক্লোজার এই partial-টা ফেরত
    দেয়। কম্পোনেন্ট স্লট পড়ে না, আর ইনপুটটা টেবিলের বাইরে বসানো যায়
    না: `name="lines[]"` ফর্মের ভেতরে থাকতেই হবে।
--}}
<input type="checkbox" name="lines[]" value="{{ $line->id }}"
       @checked($line->reconciliation_id !== null)
       @disabled($locked)
       class="size-4 rounded border-(--color-border)">
