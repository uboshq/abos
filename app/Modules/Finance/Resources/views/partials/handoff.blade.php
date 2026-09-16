@props([
    /* কোন ভাউচারে যেতে হবে — 'receipt' (টাকা আসে) বা 'payment' (টাকা যায়) */
    'voucher' => 'receipt',

    /* বোতামটা কোথায় নিয়ে যাবে — না দিলে বোতাম দেখানো হয় না */
    'to' => null,

    /* বোতামের লেখা */
    'action' => null,
])

{{--
    খাতা থেকে খতিয়ান — তিন ধাপের ফিতা।

    ── ⭐ নমুনার কাঠামো, ১৫ সেপ্টেম্বর ২০২৬ ──────────────────────────────
    *"এই খাতা → রসিদ ভাউচার → খতিয়ান · টাকার ঘরগুলো ভাউচারের, খাতার নয়"*

    ⓘ পাঁচটা খাতার পাঁচটাতেই এই একই ফিতা, কেবল মাঝের ধাপটা বদলায়:
    টাকা এলে রসিদ, টাকা গেলে পরিশোধ।

    ── ⛔ কেন এটা কেবল সাজসজ্জা নয় ─────────────────────────────────────
    ⚠️ ব্যবহারকারীর সবচেয়ে সাধারণ ভুলটা হলো খাতায় সারি লিখে ধরে নেওয়া
    যে টাকাটা খাতায় বসে গেছে। ⛔ বসেনি — সারিটা খসড়া, আর খতিয়ান তখনো
    ফাঁকা। ⓘ ফিতাটা ঐ দূরত্বটা চোখে দেখায়: **খাতা ঘটনা লেখে · ভাউচার
    টাকা নাড়ে**।
--}}
<div data-boxed
     class="my-3 flex flex-wrap items-center gap-2 rounded-(--radius-card)
            border border-dashed border-(--color-brand-400)
            bg-(--color-surface-app) px-3 py-2 text-sm">

    <span class="rounded-(--radius-field) bg-(--color-brand-100) px-2 py-0.5 text-2xs font-medium
                 text-(--color-brand-700)">
        {{ __('finance::message.step_this_book') }}
    </span>

    <span aria-hidden="true" class="text-(--color-ink-muted)">→</span>

    <span class="rounded-(--radius-field) bg-(--color-brand-100) px-2 py-0.5 text-2xs font-medium
                 text-(--color-brand-700)">
        {{ $voucher === 'payment'
            ? __('finance::message.step_payment_voucher')
            : __('finance::message.step_receipt_voucher') }}
    </span>

    <span aria-hidden="true" class="text-(--color-ink-muted)">→</span>

    <span class="rounded-(--radius-field) bg-(--color-brand-100) px-2 py-0.5 text-2xs font-medium
                 text-(--color-brand-700)">
        {{ __('finance::message.step_ledger') }}
    </span>

    {{-- ⓘ বাক্যটা ফিতার অংশ, নিচে আলাদা নোট নয় — নমুনায় ওটা একই
         লাইনে, আর ওখানেই সে সবচেয়ে বেশি পড়া হয়। --}}
    <span class="text-2xs text-(--color-ink-muted)">
        {{ __('finance::message.money_fields_belong_to_the_voucher') }}
    </span>

    @if ($to)
        <x-ui.button tone="primary" class="ms-auto" :href="$to">
            {{ $action ?? __('finance::action.money_arrived') }}
        </x-ui.button>
    @endif
</div>
