@props(['title', 'subtitle' => null, 'more' => [], 'info' => null])
{{-- ⭐ `info` — কেবল ⓘ-এর ভিতরের বাড়তি লেখা (২০ নভেম্বর ২০২৬): পাতার নিজের ব্যাখ্যার বাক্স `data-page-subtitle` পেয়ে "ABOS"/"Dynamic"-এ
     লুকায়, আর লেখাটা এখানে আসে — বাকি রূপে বাক্সটা যেমন ছিল তেমন ([[ui/page-info]]) --}}
{{--
    ⭐ পাতার মাথার এক ছাঁচ — পাতা সাজানোর পরিকল্পনা ধাপ ১, ১০ অক্টোবর ২০২৬: "কোথায় আছি" (লেআউট নিজেই উপরে বসায়,
    [[shell/page-path]]) → শিরোনাম → এক লাইনের ব্যাখ্যা (`subtitle`) → ডান কোণে **একটাই** প্রধান বোতাম (`actions`
    স্লট) → বাকি কাজ "⋯"-এ (`more`, [[ui/row-actions]]-এর একই তালিকা: label, url, method, tone)।
    ⓘ পুরনো ডাক (কেবল `actions`) হুবহু চলে — পাতা ধরে ধরে বাকি বোতামগুলো `more`-এ যাবে (ধাপ ২)।
--}}

{{--
    পাতার শিরোনাম ও Productivity Bar — সেকশন ১৫.১৬।

    Create · Save · Approve · Print · Export · Share · Duplicate · History · Help
    — যেগুলো এই স্ক্রিনে প্রযোজ্য, সেগুলো actions slot-এ। সবসময় একই জায়গায়,
    ডান দিকে, একই ক্রমে; নাহলে ব্যবহারকারীকে প্রতিটা স্ক্রিনে বোতাম খুঁজতে হয়।
--}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-start justify-between gap-3']) }}>
    <div class="min-w-0">
        {{-- ⓘ পাতায় প্রথম শিরোনাম h1, পরেরটা h2 — [[PageHeading]] --}}
        {{-- ⛔ এক লাইনের php-নির্দেশ আর নিচে php-ব্লক একই ফাইলে থাকলে Blade প্রথমটা থেকে ব্লকের শেষ পর্যন্ত গিলে ফেলে —
             `$tag` বসতই না, প্রতিটা পাতা ৫০০ (fe, ২০ নভেম্বর ২০২৬, লোকাল সার্ভারে `/appearance`)। তাই এক ব্লকে। --}}
        @php
            $tag = \App\Core\Support\PageHeading::tag();

            /*
             * ⭐ কাগজের পাতা (`*.show`, রুটে বাঁধা কাগজ) — মালিক নিজে লিখে নিজে অনুমোদন বা পোস্ট করলে নামের পাশে ছোট দাগ
             * (মালিক, ২০ নভেম্বর ২০২৬; [[ui.owner-self-mark]])। ⓘ এক জায়গায়, পনেরোটা পাতায় নয়; দাগ না থাকলে কিছুই আঁকা হয় না।
             */
            $headRoute = request()->route();
            $headPaper = null;

            if ($headRoute instanceof \Illuminate\Routing\Route && str_ends_with((string) $headRoute->getName(), '.show')
                && ! str_starts_with((string) $headRoute->getName(), 'approval.')) {
                foreach ($headRoute->parameters() as $headValue) {
                    if ($headValue instanceof \Illuminate\Database\Eloquent\Model) {
                        $headPaper = $headValue;

                        break;
                    }
                }
            }

            /*
             * ⭐ "বার্তায় পাঠান" — কাগজের নিজের পাতার ⋯-তেও, তালিকার সারির মতো (বার্তার ওয়েব নমুনা v7, ট্যাব ঘ)।
             * ⓘ কেবল তিন ABOS রূপে, `messaging.use` চাবিতে, আর ফোনের ছাপার দরজা যে কাগজ চেনে ([[MessengerChrome::shareItem()]]);
             *   ⛔ SAP ও Dynamic-এ উত্তর null — মাথা হুবহু আগের মতো।
             */
            $headShare = $headPaper !== null && class_exists(\App\Modules\Messaging\Support\MessengerChrome::class)
                && \App\Modules\Messaging\Support\MessengerChrome::shareItem($headPaper) !== null;
        @endphp
        <{{ $tag }} data-page-title class="truncate text-xl font-semibold">{{ $title }}@if ($subtitle || filled($info))<span data-page-info class="hidden" role="img" title="{{ trim($subtitle.' '.$info) }}" aria-label="{{ trim($subtitle.' '.$info) }}"><x-ui.icon name="info" :size="15" /></span>@endif<x-ui.owner-self-mark :paper="$headPaper" class="ms-2 inline-block align-middle text-base font-normal" /></{{ $tag }}>

        {{-- ⭐ "ABOS"-এ ব্যাখ্যার লাইন নয়, নামের পাশে ⓘ — মালিক, ১৯ নভেম্বর ২০২৬ রাত: "এটা কোনো ইন্টারন্যাশনাল স্ট্যান্ডার্ডে পড়ে না …
             সবগুলো পর্দা একসাথে সরাই ফেলবে"। ⓘ চিহ্নটা বাকি রূপে `hidden`, লাইনটা `data-page-subtitle` ধরে কেবল "ABOS"-এ লুকায় ([[abos-look.css]]) --}}
        @if ($subtitle)
            <p data-page-subtitle class="mt-0.5 text-sm text-(--color-ink-muted)">{{ $subtitle }}</p>
        @endif
    </div>

    {{-- print-hide: "নতুন সরবরাহকারী" কাগজে ক্লিক করা যায় না। বোতামগুলো
         <a> হলে print CSS-এর button নিয়মে ধরা পড়ে না, তাই এখানে স্পষ্ট
         করে বলা। --}}
    {{--
        সোনার সরু রেখা — কেবল Rose-এ দেখা যায়।

        ── কেন এটা এখানে, রূপের নিজের ফাইলে নয় ─────────────────────
        রেখাটা শিরোনামের **ঠিক নিচে** বসে, আর শিরোনাম এই কম্পোনেন্টের
        ভেতরে। শেল থেকে ওখানে পৌঁছানো যায় না।

        উচ্চতা টোকেন থেকে, আর ডিফল্ট শূন্য — তাই বাকি সাত রূপে ওটা
        থাকলেও দেখা যায় না, একটা পিক্সেলও নেয় না।

        ── কেন সোনা কেবল এখানেই ────────────────────────────────────
        প্রকল্পের লেখা নিয়ম: সোনা অলঙ্কার, তথ্য নয়। টেবিলে, সংখ্যায়
        বা অবস্থায় কখনো নয় — কেবল সীমানা ও শিরোনামের রেখায়।
    --}}
    <div data-gold-hairline aria-hidden="true"
         class="gold-hairline mt-3 rounded-full"></div>

    @if (isset($actions) || $more !== [] || $headShare)
        <div class="print-hide flex flex-wrap items-center gap-2" data-page-actions>
            {{ $actions ?? '' }}

            @if ($more !== [] || $headShare)
                <x-ui.row-actions :items="$more" :paper="$headShare ? $headPaper : null" />
            @endif
        </div>
    @endif
</div>
