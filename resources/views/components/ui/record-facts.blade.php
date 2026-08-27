@props([
    /** @var list<\App\Core\Panels\Fact> */
    'facts' => [],

    /**
     * পাতার কোন জায়গাটা আঁকা হচ্ছে।
     *
     * ── কেন একটা অঞ্চল লাগে ──────────────────────────────────────────
     * দুইটা চেহারা পাতার **দুই জায়গায়** বসে: ওডুর smart buttons রেকর্ডের
     * একদম মাথায়, আর তথ্যের সারিগুলো নিচের `<dl>`-এর ভেতরে।
     *
     * এক জায়গা থেকে দুইটাই আঁকা যায় না — `<dl>`-এর ভেতরে একটা flex
     * সারি বসালে HTML-ই ভুল হত (`<dl>`-এর সরাসরি সন্তান কেবল `<dt>`,
     * `<dd>` আর `<div>` হতে পারে, আর অর্থটাও থাকত না)।
     *
     * তাই পাতা দুই জায়গায় ডাকে, আর যে জায়গাটা এই রূপের নয় সেখানে
     * কম্পোনেন্ট কিছুই আঁকে না — ঠিক যেভাবে `x-shell.chrome` অঞ্চল ধরে
     * কাজ করে।
     */
    'region' => 'body',
])

{{--
    এই রেকর্ড সম্পর্কে বাকি মডিউলরা যা জানে।

    ── কেন এটা একটা কম্পোনেন্ট হলো ──────────────────────────────────────
    আগে প্রতিটা রেকর্ড-পাতা নিজে একটা `@foreach` লিখে `<dl>`-এ সারি
    বসাত। এক পাতায় ছিল, তাই চলছিল; কিন্তু ত্রিশটা পাতায় ছড়ালে চেহারা
    বদলানোর দিন ত্রিশ জায়গায় হাত দিতে হত — আর একটা ভুলে গেলে সেটা কেবল
    ওই পাতাতেই ধরা পড়ত।

    ── কেন দুই রকম চেহারা ───────────────────────────────────────────────
    ওডুতে রেকর্ডের মাথায় **smart buttons** বসে: একটা ঘরে বড় করে সংখ্যা,
    নিচে ছোট করে নাম, আর ক্লিক করলে ওই তালিকায় নিয়ে যায়। ওটাই ওডুর
    রেকর্ড-পাতার সবচেয়ে চেনা জিনিস — "৩টা চালান" দেখে সরাসরি চালানের
    তালিকায় যাওয়া।

    বাকি রূপে ওগুলো তথ্যের সারি হিসেবেই বসে, পাতার বাকি ঘরগুলোর সাথে।

    **কথাটা এক, চেহারাটা রূপের** — ঠিক যেভাবে `stage-strip` D365-এ শেভরন
    আর Fiori-তে টালি হয়।

    ── কেন ক্লিক করা না গেলেও ঘরটা থাকে ─────────────────────────────────
    সব fact-এ ঠিকানা থাকে না — "শেষ কেনা: ১২ আগস্ট" কোথাও নিয়ে যায় না।
    ওগুলো তখন বোতাম নয়, ঘর: একই মাপ, একই জায়গা, কেবল হাত দিলে কিছু হয়
    না আর দেখতেও বোতামের মতো নয়।

    যেটা ক্লিক করা যায় সেটাই কেবল বোতামের মতো দেখাবে — নাহলে পর্দায়
    আবার একটা মৃত বোতাম তৈরি হত।
--}}
@php
    $facts = collect($facts);

    $shape = \App\Core\Support\Ui::record(
        \App\Core\Support\LookRegistry::lookFor(
            \App\Core\Support\LookPreview::orChosen(auth()->user()?->ui)
        )
    );
@endphp

@if ($facts->isNotEmpty())
    @if ($shape === 'smartbuttons' && $region === 'head')
        <div data-smart-buttons class="mb-3 flex flex-wrap gap-2">
            @foreach ($facts as $fact)
                {{-- দুইটা আলাদা শাখা, একটা গতিশীল ট্যাগ নয়।

                     `<{{ $to ? 'a' : 'div' }}>` লেখা যেত আর কম লাইনে হত,
                     কিন্তু ওতে খোলা আর বন্ধ ট্যাগ দুইটা আলাদা expression
                     থেকে আসত — একটা বদলে অন্যটা ভুলে গেলে HTML নীরবে
                     ভাঙত, আর ব্লেডের কোনো ভুলও দেখাত না। --}}
                @if ($fact->url)
                    <a href="{{ $fact->url }}"
                       class="flex min-w-[7rem] flex-col items-start rounded-(--radius-card)
                              border border-(--color-border) bg-(--color-surface-card) px-3 py-2
                              transition-colors hover:border-(--color-brand-500)
                              hover:bg-(--color-surface-hover)">
                        <span class="num text-lg leading-tight font-bold text-(--color-ink)">
                            {{ $fact->value }}
                        </span>
                        <span class="text-2xs text-(--color-ink-muted)">{{ __($fact->label) }}</span>
                    </a>
                @else
                    <div class="flex min-w-[7rem] flex-col items-start rounded-(--radius-card)
                                border border-(--color-border) bg-(--color-surface-app) px-3 py-2">
                        <span class="num text-lg leading-tight font-bold text-(--color-ink)">
                            {{ $fact->value }}
                        </span>
                        <span class="text-2xs text-(--color-ink-muted)">{{ __($fact->label) }}</span>
                    </div>
                @endif
            @endforeach
        </div>
    @elseif ($shape !== 'smartbuttons' && $region === 'body')
        @foreach ($facts as $fact)
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __($fact->label) }}</dt>
                <dd class="text-sm">
                    @if ($fact->url)
                        <a href="{{ $fact->url }}" class="underline">{{ $fact->value }}</a>
                    @else
                        {{ $fact->value }}
                    @endif
                </dd>
            </div>
        @endforeach
    @endif
@endif
