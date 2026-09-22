{{--
    ছাপার লেআউট — সব ডকুমেন্টের ভিত্তি।

    কাগজ বদলালে এই একটা ফাইলের CSS বদলায়, মার্কআপ নয়। ছয়টা ডকুমেন্টে
    আলাদা করে "৫৮mm হলে এই মাপ" লিখলে একটা ফিল্ড যোগ করতে ছয় জায়গায়
    হাত দিতে হত।

    রং নেই — সাদাকালো (সেকশন ১৪.৯)। থার্মাল প্রিন্টারে রং আসেই না, আর
    A4-তে রঙিন প্রিন্ট গ্রাহকের খরচ বাড়ায়। শুধু প্রতিষ্ঠানের লোগো রঙিন।
--}}
@php
    $thermal = $paper->isThermal;
    $vendorCredit = $settings->get('print.show_vendor_credit', true) && $profile->shows('vendor_line');

    /*
     * ⭐ ঘনত্ব — মালিকের বসানো রূপ থেকে, ২২ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ সারির উঁচু-নিচু আর লেখার মাপ একসাথে বদলায়, কারণ কেবল প্যাডিং
     * কমালে বড় লেখা ঠেসে যেত আর পড়া কঠিন হত। ⚠️ ফন্টটা কমে অর্ধেক হারে
     * (`1 + (density − 1) / 2`) — পুরো হারে কমালে ০.৭৫-এ লেখাটা সাড়ে
     * সাত পয়েন্টে নামত, আর A4-তে ওটা আর বিল থাকত না।
     *
     * ⛔ কিন্তু সরু রোলে ঘনত্ব খাটে না — আর সেটা মাপা কথা, রুচি নয়।
     *
     * ⓘ [[NoPrintedFigureOverflowsItsColumnTest]] প্রতিটা থার্মাল ঘরের
     * প্রস্থ **ঐ কাগজের নিজের ফন্ট-মাপে** মেপে বসানো — ৮০মিমিতে ৮.৫pt,
     * ৫৮-তে ৭.৫pt। ⚠️ `roomy` রূপে লেখা ১.১২৫ গুণ বড় হত, আর তখন
     * প্রতিশ্রুত অঙ্কটাই ঘর ছাড়িয়ে যেত — মালিককে বলা "৮০মিমি এক লাখ
     * ধরে" কথাটা নীরবে মিথ্যা হয়ে যেত।
     *
     * ⓘ A4-তে ঝুঁকি নেই: ওখানে ঘরগুলো বড়, আর হিসাবটা মন্তব্যে লেখা।
     */
    $dense = $thermal ? 1.0 : $profile->format->density;
    $pad = fn (float $mm) => round($mm * $dense, 2);
    $rowFont = round($paper->fontSize * (1 + ($dense - 1) / 2), 2);
@endphp

<style @nonce>
    * { box-sizing: border-box; }

    body {
        font-family: hindsiliguri, sans-serif;
        font-size: {{ $paper->fontSize }}pt;
        line-height: 1.35;
        color: #000;
    }

    .doc-head { text-align: center; margin-bottom: {{ $thermal ? 2 : 5 }}mm; }
    .company-name { font-size: {{ $thermal ? 11 : 15 }}pt; font-weight: bold; }
    .company-meta { font-size: {{ $thermal ? 7 : 9 }}pt; }

    .doc-title {
        margin: {{ $thermal ? 2 : 4 }}mm 0 {{ $thermal ? 1 : 2 }}mm;
        font-size: {{ $thermal ? 9.5 : 12 }}pt;
        font-weight: bold;
        text-align: center;
        {{ $thermal ? 'border-top: 0.3mm dashed #000; border-bottom: 0.3mm dashed #000; padding: 1mm 0;' : '' }}
    }

    .meta { width: 100%; font-size: {{ $thermal ? 7.5 : 9 }}pt; margin-bottom: {{ $thermal ? 2 : 4 }}mm; }
    .meta td { padding: 0.3mm 0; vertical-align: top; }
    .meta .label { color: #333; }

    {{-- ⭐ ছকের রেখা — বসানো রূপ ঠিক করে, ২২ সেপ্টেম্বর ২০২৬।

         ⓘ তিনটা চেহারা, আর তিনটাই সত্যিকারের আলাদা কাগজ:
           `grid`  — প্রতিটা ঘর ঘেরা; কর-পরিদর্শকের চেনা চেহারা
           `rows`  — কেবল সারির নিচে রেখা; আজ পর্যন্ত যা ছাপা হয়েছে
           `clean` — কোনো রেখা নেই, কেবল শিরোনামের নিচে একটা

         ⚠️ mPDF-এ `border-collapse: collapse` ছাড়া ঘেরা ছকে দুইটা পাশের
         ঘরের রেখা পাশাপাশি বসে দ্বিগুণ মোটা দেখাত। --}}
    table.lines { width: 100%; border-collapse: collapse; }
    table.lines th {
        border-bottom: 0.3mm solid #000;
        padding: {{ $thermal ? $pad(1).'mm '.$pad(0.5).'mm' : $pad(1.5).'mm '.$pad(2).'mm' }};
        text-align: left;
        font-size: {{ round(($thermal ? 7 : 9) * (1 + ($dense - 1) / 2), 2) }}pt;
    }
    table.lines td {
        padding: {{ $thermal ? $pad(1).'mm '.$pad(0.5).'mm' : $pad(1.5).'mm '.$pad(2).'mm' }};
        vertical-align: top;
        font-size: {{ $rowFont }}pt;
    }

    @if ($profile->format->rule === \App\Core\Engines\Print\PrintFormat::RULE_GRID)
        table.lines th, table.lines td { border: 0.2mm solid #000; }
    @elseif ($profile->format->rule === \App\Core\Engines\Print\PrintFormat::RULE_ROWS)
        table.lines td { border-bottom: {{ $thermal ? '0.2mm dashed #999' : '0.2mm solid #ddd' }}; }
    @endif

    {{-- ⚠️ ডোরাকাটা সারি ব্লেডেই বসে, `:nth-child` দিয়ে নয় — mPDF ওটা
         চেনে না, আর না চিনলে কোনো ভুল দেখাত না: প্রতিটা সারি সাদা থেকে
         যেত, আর সুইচটা চালু করে কেউ বুঝতেই পারতেন না কেন কিছু বদলায়নি। --}}
    table.lines tr.alt td { background-color: #f0f0f0; }

    /* পণ্যের নামের নিচের ছোট লেখা — ব্যাচ ও মেয়াদ।

       ছোট আর ধূসর, কারণ এটা পরিচয় নয়, সহায়ক তথ্য: চোখ আগে নামটা পড়ে,
       তারপর দরকার হলে নিচে নামে। একই মাপে দিলে প্রতিটা সারি দুই লাইনের
       নাম বলে মনে হত। */
    .note { font-size: {{ $thermal ? 6 : 7.5 }}pt; color: #444; }

    /* টাকার কলাম ডানে সারিবদ্ধ — দশমিক বিন্দু এক লাইনে না থাকলে চোখে
       যোগফল মেলানো যায় না। */
    .num { text-align: right; white-space: nowrap; }

    /* ⭐ টাকার অঙ্কের মাপ — স্পষ্ট করে লেখা, ২১ সেপ্টেম্বর ২০২৬।

       ⛔ এতদিন `table.lines td`-তে কোনো `font-size` ছিল না। মাপটা আসত
       `body` থেকে (আর mPDF-এর `default_font_size` থেকেও) — দুইটাই
       `$paper->fontSize`, তাই কাগজে কিছুই বদলাত না।

       ⚠️ কিন্তু কলামের চওড়া ঠিক করতে গেলে ঐ কথাটা **জানতে হত**। পাশেই
       `table.lines th` ৯পয়েন্ট, আর সেটা পড়ে ৯পয়েন্টে মেপে কলাম বসানো
       হয়েছিল — অথচ ঘরটা ১০পয়েন্টে ছাপে। ১০.৫% তফাত, আর ঠিক ততটুকুই
       উপচে পড়ত।

       ⓘ এই লাইনটা একটা পিক্সেলও বদলায় না। কেবল প্রশ্নটার উত্তর
       ফাইলেই থাকে, অনুমান করতে হয় না।

       ── ⭐ ঘনত্ব এল, আর মাপটা আবার মিলিয়ে দেখা হলো (২২ সেপ্টেম্বর ২০২৬) ──
       ⚠️ ঘন রূপে লেখা ছোট হয়, তাই ওদিকে ঝুঁকি নেই। ⓘ ঝুঁকিটা উল্টো দিকে:
       `roomy`-তে ফন্ট বাড়ে ১.১২৫ গুণ, আর A4-র সবচেয়ে লম্বা অঙ্কটা
       ২৯.১৭ → ৩২.৮১মিমি হয় — ৩৬মিমি ঘরে এখনো ধরে, ৩.২মিমি হাতে থাকে।
       ⛔ ঘনত্ব এর বেশি বাড়ালে ওটা উপচাবে, আর তখন ঘরের মাপও বাড়াতে হবে। */
    td.num { font-size: {{ $rowFont }}pt; }

    /* সংখ্যার ফন্ট শুধু ঘরে, হেডারে নয়।
       DejaVu-তে বাংলা অক্ষর নেই, তাই .num হেডারেও লাগালে "ডেবিট" ও
       "ক্রেডিট" ফাঁকা বাক্স হয়ে যায় — Phase 0-এর যে ফাঁদটা টাকার অঙ্কে
       ধরা পড়েছিল, সেটাই হেডারে ফিরে এসেছিল। ছেপে চোখে দেখে ধরা পড়েছে;
       PDF তৈরি হয়েছে দেখে বোঝা যেত না। */
    /* ⚠️ DejaVu এখানে থাক — সরু ফন্ট দিয়ে জায়গা বাঁচানোর চেষ্টা করবেন না।

       ২১ সেপ্টেম্বর ২০২৬-এ ঠিক সেই চেষ্টাটাই মাপা হয়েছে: HindSiliguri
       একই অঙ্ক **২৬% সরু** আঁকে (A4-তে ২১.৫৯ বনাম ২৯.১৭মিমি), আর লাখ-
       কোটির লম্বা সংখ্যার সমস্যাটা তাতে এমনিই মিটে যেত।

       ⛔ কিন্তু HindSiliguri-র অঙ্কগুলো সমান চওড়া নয় — `১` ১.১৪মিমি,
       `০` ১.৯২মিমি, ৬৮% তফাত। তাতে দশমিক বিন্দুগুলো এক খাড়া রেখায়
       থাকত না, আর ঠিক উপরের কারণটাই (চোখে যোগফল মেলানো) নষ্ট হত।
       DejaVu-তে প্রতিটা অঙ্ক ২.২৪৩৭মিমি — সমান। */
    td.num { font-family: dejavusans; }
    th.num { font-family: hindsiliguri; }

    .totals { width: 100%; margin-top: {{ $thermal ? 1.5 : 3 }}mm; }
    .totals td { padding: 0.5mm 0; }
    .totals .grand { border-top: 0.3mm solid #000; font-weight: bold; font-size: {{ $thermal ? 9 : 11 }}pt; }

    {{-- ⭐ আদায়ের ছক — মালিকের নমুনার "Paid · Received Into Accounts"।

         ⓘ বিলের বাঁ-নিচে, অর্ধেক প্রস্থে: ডান পাশে টাকার সারিগুলো বসে,
         আর নমুনাতেও দুইটা পাশাপাশি। ⚠️ পুরো প্রস্থ দিলে ছকটা মোটের
         সারিগুলোকে নিচে ঠেলে দিত, আর চোখ আগে জমার ছক পড়ত — অথচ
         প্রথম প্রশ্নটা সবসময় "মোট কত"।

         ⓘ লেখা ছোট (৭.৫pt): এটা সহায়ক তথ্য, কাগজের মূল কথা নয়। --}}
    .payments-block { margin-top: {{ $thermal ? 1.5 : 3 }}mm; width: {{ $thermal ? 100 : 55 }}%; }
    .payments-head { font-weight: bold; font-size: {{ $thermal ? 7 : 8.5 }}pt; margin-bottom: 1mm; }
    table.payments { width: 100%; border-collapse: collapse; font-size: {{ $thermal ? 6.5 : 7.5 }}pt; }
    table.payments th, table.payments td { border: 0.2mm solid #000; padding: 0.8mm 1mm; text-align: left; }

    .words { margin-top: {{ $thermal ? 1.5 : 3 }}mm; font-size: {{ $thermal ? 7 : 9 }}pt; }

    .signatures { width: 100%; margin-top: {{ $thermal ? 6 : 16 }}mm; }
    .signatures td { text-align: center; font-size: {{ $thermal ? 7 : 9 }}pt; padding-top: 1mm; }
    .sig-line { border-top: 0.25mm solid #000; padding-top: 1mm; }

    .foot { margin-top: {{ $thermal ? 3 : 8 }}mm; text-align: center; font-size: {{ $thermal ? 6.5 : 7.5 }}pt; color: #444; }
</style>

<div class="doc-head">
    {{-- ছবিটা নিজেই বসে, পথ নয় — কারণটা Company::logoData()-এ লেখা।

         ── ⭐ থার্মালে লোগো এখন সুইচের, নিয়মের নয় (২২ সেপ্টেম্বর ২০২৬) ──
         ⓘ আগে এখানে হাতে লেখা ছিল "থার্মাল হলে লোগো নয়" — কারণটা সত্যি:
         ৫৮মিমি চওড়ায় ওটা একটা ধূসর দাগ, আর তাপীয় কালিতে ধূসর ভালো আসে
         না। ⚠️ কিন্তু মালিকের কথা: *"ইনভয়েজে কি লোগো দেবে পস প্রিন্টারে
         কি লোগো দেবে"* — অর্থাৎ সিদ্ধান্তটা তাঁর, যন্ত্রের নয়।

         ⭐ তাই নিয়মটা ডিফল্ট হয়েছে: পস রসিদের রূপে লোগো বন্ধ থাকে, আর
         কেউ চাইলে চালু করতে পারেন। ⛔ চলতি কাগজ একটুও বদলায়নি। --}}
    @if ($profile->shows('logo'))
        @php $logo = $company->logoData(); @endphp

        @if ($logo)
            <img src="{{ $logo }}" style="height: {{ $thermal ? 8 : 14 }}mm;" alt="">
        @endif
    @endif

    @if ($profile->shows('company_name'))
        <div class="company-name">{{ $company->name() }}</div>
    @endif

    @if ($profile->shows('address') && $company->address())
        <div class="company-meta">{{ $company->address() }}</div>
    @endif

    @php
        $showPhone = $profile->shows('phone') && $company->phone;
        $showBin = $profile->shows('bin') && $company->bin;
    @endphp

    @if ($showPhone || $showBin)
        <div class="company-meta">
            @if ($showPhone){{ __('core.print.phone') }}: {{ $company->phone }}@endif
            @if ($showPhone && $showBin) · @endif
            @if ($showBin){{ __('core.print.bin') }}: {{ $company->bin }}@endif
        </div>
    @endif
</div>

@if ($profile->shows('title'))
    <div class="doc-title">{{ $title }}</div>
@endif

@yield('body')

<div class="foot">
    @if ($profile->shows('printed_at'))
        {{ __('core.print.printed_at') }}: {{ \App\Core\Support\DateFormat::formatWithTime(now()) }}
        @if (auth()->check()) · {{ auth()->user()->name }} @endif
    @endif

    @if ($vendorCredit)
        {{-- গ্রাহকের কাগজে ভেন্ডরের নাম ছোট ও নিচে, আর Control Panel থেকে
             বন্ধ করা যায় (সেকশন ১৭.২): কিছু প্রতিষ্ঠান কর-সংক্রান্ত কাগজে
             বাইরের কোনো নাম রাখতে চায় না।

             ── কী লেখা থাকে, আর কী থাকে না (২ সেপ্টেম্বর ২০২৬) ──────────
             মালিকের নির্দেশে এখানে **প্রস্তুতকারীর নাম নেই** — কেবল
             UNIVER-এর নাম আর হটলাইন। কারণটা কাগজটার কাজেই আছে: এই
             কাগজ যাঁর হাতে যায় তিনি নাম পড়তে চান না, **সমস্যা হলে কোথায়
             ফোন করবেন সেটা খোঁজেন**। নম্বরটা না থাকলে ওই খোঁজাটা
             গ্রাহকের কাছে ফিরে যেত, আর সেখান থেকে আমাদের কাছে —
             একটা ধাপ বেশি, আর প্রতিবার। --}}
        <div>{{ __('core.print.vendor_line') }} · {{ __('core.print.hotline') }}</div>
    @endif
</div>
