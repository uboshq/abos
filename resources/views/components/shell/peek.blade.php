{{--
    পিক — তালিকার উপরেই ডকুমেন্টটা, তালিকা না ছেড়ে।

    ── ⭐ মালিকের নিয়ম, ২৮ সেপ্টেম্বর ২০২৬ ───────────────────────────────
    *"হাইপার লিংকে চাপলে পপআপ এলেই ভালো, নাহলে মডিউল/মেনু পরিবর্তন হয়ে
    যায়, সেটা অত্যন্ত বিরক্তিকর।"*

    ── ⓘ লিংকগুলো আসল `<a href>` থেকেই যাচ্ছে ───────────────────────────
    ⭐ কোনো পর্দার কোনো লিংক বদলানো হয়নি — শ্রোতাটা এখানে, একটাই, গোটা
    অ্যাপের জন্য ([[shell.js::peek]])। ⚠️ জরিপে দেখা গেছে `doc-link`
    মোট ১৭৫টা লিংক-জায়গার মাত্র ২২টা; বাকিগুলো কাঁচা `<a>` আর তিনটা
    ভাগাভাগি করা উপাদানে ছড়ানো। ⛔ উপাদান ধরে ধরে ছড়ালে বেশিরভাগ তালিকা
    বাদ পড়ত, আর কোনো পরীক্ষা লাল হত না।

    ⓘ Ctrl / ⌘ / Shift / মাঝের বোতাম আগের মতোই পুরো পাতা খোলে, আর
    JavaScript বন্ধ থাকলে প্রতিটা লিংক নিছক লিংক — কারণ কিছু সরানো হয়নি।

    ── ⛔ `<dialog>` নয় ────────────────────────────────────────────────
    `showModal()` ডাকতে CSP-Alpine-এ পদ্ধতি লাগত; বাকি জানালাগুলো
    ([[command-center]], [[inbox/partials/sheet]]) যেভাবে চলে, এটাও সেভাবে।

    ── ⓘ জ্যামিতিটা ইনলাইন `style`-এ, ইচ্ছাকৃত ─────────────────────────
    ⚠️ একটা Tailwind শ্রেণি বান্ডলে না থাকলে প্যানেলটা নীরবে ভেঙে যেত —
    [[command-center]]-এ ঠিক এই কারণেই একই সিদ্ধান্ত।
--}}
<div x-data="peek"
     x-show="open"
     x-cloak
     role="dialog"
     aria-modal="true"
     x-bind:aria-label="title"
     @keydown.escape.window="close()"
     @click.self="close()"
     style="position:fixed;inset:0;z-index:60;display:flex;justify-content:center;
            align-items:flex-start;padding:6vh 16px 16px;background:rgb(0 0 0 / 0.45)">

    <div x-ref="panel"
         tabindex="-1"
         class="w-full max-w-(--spacing-content-max) rounded-(--radius-field)
                bg-(--color-surface-app) shadow-lg"
         style="max-height:88vh;display:flex;flex-direction:column;outline:none">

        {{-- ⓘ ডকুমেন্টের নিজের শিরোনাম এখানে বসে না — ওটা ভিতরের পাতার
             কাজ, আর দুই জায়গায় লিখলে একদিন দুইটা আলাদা কথা বলত। --}}
        <div class="flex items-center justify-between gap-3 border-b border-(--color-border) px-4 py-2">
            <a x-bind:href="url" class="text-xs text-(--color-link) underline underline-offset-2">
                {{ __('core.peek.open_full') }}
            </a>

            <button type="button" @click="close()"
                    class="text-xs text-(--color-ink-muted) underline underline-offset-2">
                {{ __('core.peek.close') }}
            </button>
        </div>

        {{-- ⚠️ `x-show` নয়, কারণ ঘরটা খালি থাকলেও উচ্চতাটা ধরে রাখতে হয়,
             নইলে আনার সময় প্যানেলটা লাফায় --}}
        <div x-show="busy" class="px-4 py-6 text-center text-xs text-(--color-ink-muted)">
            {{ __('core.peek.loading') }}
        </div>

        {{-- ⛔ ব্যর্থ হলে চুপ করে থাকা নয়: পিক ভেঙে গেলে মানুষ যেন
             পুরো পাতাটা খুলে নিতে পারেন, সেটা বলে দেওয়া হয় --}}
        <div x-show="failed" class="px-4 py-6 text-center text-xs text-(--color-danger)">
            {{ __('core.peek.failed') }}
        </div>

        <div x-ref="body" data-peek-body
             class="min-w-0 overflow-y-auto px-4 py-3"
             style="flex:1 1 auto"></div>
    </div>
</div>
