{{--
    স্থিতিপত্রের এক পক্ষ — মাথা, তার সারি, উপমোট।

    ── কেন প্রতিটা সংখ্যা একটা লিংক ────────────────────────────────────
    নিয়ম ১: প্রতিটা সংখ্যা তার উৎসে নামে। "মজুদ ২৮,০৬,২৬৩" পড়ে কেউ
    জানতে চাইবে ওটা কোন এন্ট্রিগুলো — আর উত্তরটা এক ক্লিক দূরে না
    থাকলে সংখ্যাটা যাচাই করার কোনো উপায় থাকে না।

    ── কেন মাথাটা শূন্য হলেও থাকে ──────────────────────────────────────
    পুরনো পর্দাটার সবচেয়ে বড় দোষ ছিল **দায়ের একটা সারিও না থাকা** —
    কারণ ওটা কেবল যেসব খাতে এন্ট্রি আছে সেগুলো দেখাত। শূন্য দায় আর
    দায়ের ঘরটাই না থাকা এক জিনিস নয়: প্রথমটা একটা উত্তর, দ্বিতীয়টা
    একটা ফাঁক।

    সন্তানের স্তরে শূন্য সারি বাদ, কারণ ছকের ৬৪টা খাতের বেশিরভাগ একটা
    ডিপোতে কোনোদিন ছোঁয়া হয় না।
--}}
@php
    $money = fn ($v) => \App\Core\Support\Money::format($v);
    $extra = $extra ?? null;
@endphp

@foreach ($groups as $group)
    <div class="border-b border-(--color-border) last:border-b-0">
        <div class="flex items-baseline justify-between px-4 py-2">
            <span class="font-medium">{{ $group['head']->name() }}</span>
            <span class="tabular-nums font-medium">{{ $money($group['total']) }}</span>
        </div>

        @foreach ($group['lines'] as $line)
            <div class="flex items-baseline justify-between px-4 py-1.5 ps-8 text-sm">
                <a href="{{ route('accounts.coa.show', $line['account']) }}#transactions"
                   class="truncate text-(--color-link) hover:underline">
                    {{ $line['account']->name() }}
                </a>
                <span class="tabular-nums">{{ $money($line['amount']) }}</span>
            </div>
        @endforeach
    </div>
@endforeach

@if ($extra !== null)
    {{-- হিসাব করা সারি — খাত নয়, তাই লিংকও নেই। পর্দায় সেটা স্পষ্ট
         থাকা দরকার, নাহলে কেউ ছকে ওটা খুঁজতে গিয়ে পেত না। --}}
    <div class="flex items-baseline justify-between border-t border-(--color-border)
                px-4 py-2">
        <span class="font-medium">{{ $extra['label'] }}</span>
        <span @class(['tabular-nums font-medium',
            'text-(--color-badge-danger-ink)' => bccomp((string) $extra['amount'], '0', 4) < 0])>
            {{ $money($extra['amount']) }}
        </span>
    </div>
@endif
