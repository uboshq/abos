@props(['stages' => []])

{{--
    Dynamics 365-এর প্রসেস ব্যান্ড — কাগজগুলো কোন ধাপে দাঁড়িয়ে।

    ── কেন এটা রূপের সাজ নয়, D365-এর পরিচয় ────────────────────────────
    ২৯ আগস্ট ২০২৬-এ মালিক বললেন `dynamic` রূপটা D365-এর ঠিক নকল হয়নি,
    আর Ava ও DMS দেখে মেলাতে বললেন।

    মিলিয়ে দেখা গেল রং ও কাঠামো বেশিরভাগই ঠিক ছিল — নেভি হেডার
    (`#0B2A4A`), `#F5F5F5` সাইট ম্যাপ (Fluent-এর নিজের রং, DMS-এর
    প্যালেটে ওই মন্তব্যটাই লেখা), নিচে পিন করা এরিয়া-সুইচার, ওয়াফল।
    **শুধু তীরগুলো ছিল না** — আর D365 খুললে চোখ প্রথমে ওখানেই পড়ে।

    ── কেন `clip-path`, বর্ডার নয় ─────────────────────────────────────
    বর্ডার দিয়ে তীর DMS-এ আগে করা হয়েছিল, আর যেকোনো zoom-এ জোড়াটা
    "একটা তীর" না হয়ে "কোণ করে মেলা দুইটা লাইন" হয়ে পড়ত। Ava-ও
    `clip-path`-ই ব্যবহার করে, আর জ্যামিতিটা সার্ভারে হিসাব হয় —
    প্রথমটার বাঁ কিনারা সমান, শেষটার ডান কিনারা সমান।

    ── কেন প্রতিটা তীর একটা লিংক ──────────────────────────────────────
    তীরের ভেতরের সংখ্যাটাও একটা সংখ্যা, আর নিয়ম ১ বলে প্রতিটা সংখ্যা
    তার উৎসে নিয়ে যায়। "খসড়া ৩১" দেখে ওই একত্রিশটা কোনগুলো তা এক
    ক্লিকে দেখা যায়।
--}}
@if ($stages !== [])
    <nav data-process-band
         class="no-print hidden shrink-0 gap-0.5 overflow-x-auto bg-(--color-surface-card)
                px-3 pt-2 md:flex md:px-5"
         aria-label="{{ __('core.band.process') }}">

        @foreach ($stages as $stage)
            <a href="{{ $stage['url'] }}"
               style="clip-path: polygon({{ $stage['points'] }})"
               @class([
                   'flex min-h-11 min-w-[8.25rem] flex-1 shrink-0 flex-col justify-center gap-px',
                   /* খাঁজের জন্য দুই পাশে জায়গা — নাহলে লেখাটা পাশের
                      তীরের ডগার নিচে চলে যেত। */
                   'px-[22px] py-1.5 text-sm transition-colors',
                   'bg-(--color-brand-600) text-(--color-ink-inverse)' => $stage['current'],
                   'bg-(--color-surface-sunken) text-(--color-ink-muted) hover:bg-(--color-surface-hover)'
                        => ! $stage['current'],
               ])
               @if ($stage['current']) aria-current="page" @endif>

                <span class="text-2xs tracking-wide uppercase opacity-85">{{ $stage['label'] }}</span>

                <span class="num text-[15px] font-semibold">
                    {{ number_format($stage['count']) }}
                    @if (! \App\Core\Support\Money::isZero($stage['total']))
                        <small class="text-2xs font-normal opacity-80">
                            · {{ \App\Core\Support\Money::format($stage['total']) }}
                        </small>
                    @endif
                </span>
            </a>
        @endforeach
    </nav>
@endif
