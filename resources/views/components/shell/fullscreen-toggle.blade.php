{{--
    ফুল-স্ক্রিন — টপবারের বোতাম।

    লম্বা চালান বা চওড়া স্টক টেবিলের পাতায় ব্রাউজারের নিজের chrome সরে
    গেলে কাজে লাগার মতো উচ্চতা পাওয়া যায়।

    অবস্থাটা document থেকেই পড়া হয়, নিজে মনে রাখা হয় না: Esc বা F11
    দিয়েও ফুল-স্ক্রিন ছাড়া যায়, আর তখন নিজের রাখা boolean বাস্তবের সাথে
    অমিল হয়ে যেত — বোতামটা ভুল আইকন দেখাত।
--}}
<button type="button"
        x-data="{
            full: false,
            sync() { this.full = Boolean(document.fullscreenElement) },
            toggle() {
                if (document.fullscreenElement) {
                    document.exitFullscreen()
                } else {
                    // ব্যবহারকারীর ক্লিক ছাড়া, বা iframe/policy আটকালে
                    // প্রত্যাখ্যাত হয়। ফেরানোর কিছু নেই — পাতা যেমন আছে
                    // তেমনই থাকে, আর আইকনটা ঠিকই থাকে কারণ সেটা document
                    // অনুসরণ করে।
                    document.documentElement.requestFullscreen().catch(() => {})
                }
            },
        }"
        x-init="sync()"
        @fullscreenchange.document="sync()"
        @click="toggle()"
        :aria-pressed="full"
        :title="full ? '{{ __('core.action.exit_fullscreen') }}' : '{{ __('core.action.fullscreen') }}'"
        :aria-label="full ? '{{ __('core.action.exit_fullscreen') }}' : '{{ __('core.action.fullscreen') }}'"
        class="hidden size-(--spacing-touch) shrink-0 items-center justify-center rounded-(--radius-field)
               text-(--color-ink-muted) transition-colors hover:bg-(--color-surface-hover)
               hover:text-(--color-ink) md:flex">

    <svg viewBox="0 0 24 24" class="size-(--spacing-icon) fill-current" aria-hidden="true">
        {{-- ভেতরের দিকে তীর — বেরোনোর জন্য --}}
        <path x-show="full" x-cloak d="M8 8H4v2h6V4H8v4Zm6 0V4h-2v6h6V8h-4ZM8 16v4h2v-6H4v2h4Zm4 0h4v4h2v-6h-6v2Z"/>
        {{-- বাইরের দিকে তীর — ঢোকার জন্য --}}
        <path x-show="!full" d="M4 4h6v2H6v4H4V4Zm10 0h6v6h-2V6h-4V4ZM4 14h2v4h4v2H4v-6Zm14 0h2v6h-6v-2h4v-4Z"/>
    </svg>
</button>
