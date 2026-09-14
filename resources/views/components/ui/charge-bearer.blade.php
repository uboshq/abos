@props(['direction' => 'in'])

{{--
    চার্জটা কে দিয়েছে — আমরা, না অন্য পক্ষ।

    ── ⭐ মালিকের প্রশ্ন, ১৪ সেপ্টেম্বর ২০২৬ ────────────────────────────
    *"চার্জ কাটা হয়েছে — এটা কে দেবে তার অপশন রাখবে।"*

    ⓘ দেখতে একটা ছোট বোতাম, কিন্তু খাতায় **দুইটা সম্পূর্ণ আলাদা ফল**।
    ধরা যাক রসিদ ১০,০০০ টাকা, বিকাশের চার্জ ৯২.৫০:

        আমরা দিলে    খাতে ঢোকে ৯,৯০৭.৫০, চার্জ ৯২.৫০ যায় 5211-এ,
                     আর গ্রাহকের খতিয়ানে **পুরো ১০,০০০** জমা
        ওরা দিলে     ওরা ১০,০৯২.৫০ পাঠিয়েছেন, আমরা পেয়েছি ১০,০০০,
                     আর **আমাদের কোনো খরচই নেই** — 5211-এ কিছু বসে না

    ⛔ ঘরটা না থাকলে ব্যবস্থাকে একটা ধরে নিতে হত, আর ভুল ধরলে হয়
    গ্রাহকের খতিয়ানে ৯২.৫০ কম জমা হত (তিনি বারবার ফোন করতেন), নয়
    আমাদের খরচের খাতায় এমন একটা টাকা বসত যা আমরা দিইনি।

    ── ⚠️ ডিফল্ট `us` কেন ──────────────────────────────────────────────
    আজ পর্যন্ত ব্যবস্থাটা ওটাই ধরে নিত, আর বেশিরভাগ সময় সেটাই সত্য।
    ⓘ ডিফল্ট বদলালে পুরনো প্রতিটা সারির অর্থ চুপচাপ বদলে যেত।
--}}

@php
    $inward = $direction === 'in';
@endphp

<fieldset class="mt-2 flex flex-col gap-1.5">
    <legend class="text-2xs font-medium tracking-wide text-(--color-ink-muted) uppercase">
        {{ __('accounts::field.charge_borne_by') }}
    </legend>

    <div class="flex flex-wrap gap-2">
        @foreach (['us', 'them'] as $who)
            <label class="cursor-pointer">
                <input type="radio" name="charge_borne_by" value="{{ $who }}" class="peer sr-only"
                       x-model="chargeBy" @checked($who === 'us')>
                <span class="inline-flex items-center rounded-(--radius-field) border
                             border-(--color-border) bg-(--color-surface-card) px-2.5 py-1 text-xs
                             text-(--color-ink-muted) transition-colors
                             peer-checked:border-(--color-brand-500) peer-checked:font-medium
                             peer-checked:text-(--color-ink)
                             peer-focus-visible:outline-2 peer-focus-visible:outline-(--color-brand-500)">
                    @if ($who === 'us')
                        {{ __('accounts::charge.we_paid') }}
                    @else
                        {{ $inward ? __('accounts::charge.sender_paid') : __('accounts::charge.receiver_paid') }}
                    @endif
                </span>
            </label>
        @endforeach
    </div>

    {{-- ⭐ ফলটা লেখা থাকে, কারণ বোতামটা দেখে কেউ বুঝবে না খাতায় কী
         বদলাচ্ছে — আর ওটা না বুঝলে বাছাইটা আন্দাজে হবে। --}}
    <p class="text-xs text-(--color-ink-faint)"
       x-text="chargeBy === 'us'
           ? @js(__('accounts::message.charge_ours'))
           : @js($inward ? __('accounts::message.charge_theirs_in') : __('accounts::message.charge_theirs_out'))"></p>
</fieldset>
