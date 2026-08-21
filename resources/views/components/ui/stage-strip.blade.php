@props(['stages' => []])

{{--
    ধাপের স্ট্রিপ — কাগজটা কোন ধাপে, কয়টা, আর কত টাকার।

    ── কেন এটা একটা কম্পোনেন্ট, কোনো রূপের নিজস্ব অংশ নয় ────────────────
    D365 এটাকে শেভরনে আঁকে, খতিয়ান রূপ চৌকো ঘরে, Fiori টালিতে। **আঁকাটা**
    রূপের, কিন্তু **কথাটা** নয়: "৪৮টা অনুমোদিত, ২৬টা চালান হয়েছে, ১৭টা
    কোনো সারিতেই নেই" — এটা ব্যবসার তথ্য, সাজসজ্জা নয়।

    তাই কম্পোনেন্টটা এক, আর প্রতিটা রূপ কেবল তার চেহারা বদলায়
    (`--stage-clip`, `--stage-gap`, `--radius-*`)।

    ── কেন গোনার পাশে টাকা ─────────────────────────────────────────────
    "১৪টা" কাউকে নাড়ায় না। "১৪টা · ৩,৮১,২০০ টাকা" নাড়ায়। গোনা একটা
    তথ্য; টাকা হলো **কারণ**। নমুনার নিজের কথা, আর এখানেও তাই।

    ── অবস্থার তিনটা রূপ ───────────────────────────────────────────────
    `done` — পেরিয়ে এসেছে · `now` — এখন এখানে · `bad` — আটকে আছে।
    রং একা অর্থ বহন করে না: প্রতিটার সাথে লেখাও থাকে (নিয়ম ১৪.৯)।
--}}
@if ($stages !== [])
    <div data-stage-strip
         {{ $attributes->merge(['class' => 'stage-strip flex overflow-x-auto']) }}>
        @foreach ($stages as $stage)
            <div @class([
                    'stage min-w-[104px] flex-1 px-3 py-1.5',
                    'is-done' => ($stage['state'] ?? null) === 'done',
                    'is-now' => ($stage['state'] ?? null) === 'now',
                    'is-bad' => ($stage['state'] ?? null) === 'bad',
                 ])>
                <div class="text-2xs text-(--color-ink-muted)">{{ $stage['label'] }}</div>
                <div class="num text-base leading-tight font-bold">{{ $stage['count'] }}</div>
                @if (isset($stage['amount']))
                    <div class="num text-2xs text-(--color-ink-muted)">{{ $stage['amount'] }}</div>
                @endif
            </div>
        @endforeach
    </div>
@endif
