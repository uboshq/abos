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
    $vendorCredit = $settings->get('print.show_vendor_credit', true);
@endphp

<style>
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

    table.lines { width: 100%; border-collapse: collapse; }
    table.lines th {
        border-bottom: 0.3mm solid #000;
        padding: {{ $thermal ? '1mm 0.5mm' : '1.5mm 2mm' }};
        text-align: left;
        font-size: {{ $thermal ? 7 : 9 }}pt;
    }
    table.lines td {
        border-bottom: {{ $thermal ? '0.2mm dashed #999' : '0.2mm solid #ddd' }};
        padding: {{ $thermal ? '1mm 0.5mm' : '1.5mm 2mm' }};
        vertical-align: top;
    }

    /* টাকার কলাম ডানে সারিবদ্ধ — দশমিক বিন্দু এক লাইনে না থাকলে চোখে
       যোগফল মেলানো যায় না। */
    .num { text-align: right; white-space: nowrap; }

    /* সংখ্যার ফন্ট শুধু ঘরে, হেডারে নয়।
       DejaVu-তে বাংলা অক্ষর নেই, তাই .num হেডারেও লাগালে "ডেবিট" ও
       "ক্রেডিট" ফাঁকা বাক্স হয়ে যায় — Phase 0-এর যে ফাঁদটা টাকার অঙ্কে
       ধরা পড়েছিল, সেটাই হেডারে ফিরে এসেছিল। ছেপে চোখে দেখে ধরা পড়েছে;
       PDF তৈরি হয়েছে দেখে বোঝা যেত না। */
    td.num { font-family: dejavusans; }
    th.num { font-family: hindsiliguri; }

    .totals { width: 100%; margin-top: {{ $thermal ? 1.5 : 3 }}mm; }
    .totals td { padding: 0.5mm 0; }
    .totals .grand { border-top: 0.3mm solid #000; font-weight: bold; font-size: {{ $thermal ? 9 : 11 }}pt; }

    .words { margin-top: {{ $thermal ? 1.5 : 3 }}mm; font-size: {{ $thermal ? 7 : 9 }}pt; }

    .signatures { width: 100%; margin-top: {{ $thermal ? 6 : 16 }}mm; }
    .signatures td { text-align: center; font-size: {{ $thermal ? 7 : 9 }}pt; padding-top: 1mm; }
    .sig-line { border-top: 0.25mm solid #000; padding-top: 1mm; }

    .foot { margin-top: {{ $thermal ? 3 : 8 }}mm; text-align: center; font-size: {{ $thermal ? 6.5 : 7.5 }}pt; color: #444; }
</style>

<div class="doc-head">
    {{-- ছবিটা নিজেই বসে, পথ নয় — কারণটা Company::logoData()-এ লেখা।
         থার্মাল কাগজে লোগো ছাপা হয় না: ৫৮ মিমি চওড়ায় ওটা একটা ধূসর
         দাগ, আর তাপীয় কালিতে ধূসর ভালো আসে না। --}}
    @php $logo = $thermal ? null : $company->logoData(); @endphp

    @if ($logo)
        <img src="{{ $logo }}" style="height: 14mm;" alt="">
    @endif

    <div class="company-name">{{ $company->name() }}</div>

    @if ($company->address())
        <div class="company-meta">{{ $company->address() }}</div>
    @endif

    @if ($company->phone || $company->bin)
        <div class="company-meta">
            @if ($company->phone){{ __('core.print.phone') }}: {{ $company->phone }}@endif
            @if ($company->phone && $company->bin) · @endif
            @if ($company->bin)BIN: {{ $company->bin }}@endif
        </div>
    @endif
</div>

<div class="doc-title">{{ $title }}</div>

@yield('body')

<div class="foot">
    {{ __('core.print.printed_at') }}: {{ \App\Core\Support\DateFormat::formatWithTime(now()) }}
    @if (auth()->check()) · {{ auth()->user()->name }} @endif

    @if ($vendorCredit)
        {{-- গ্রাহকের কাগজে ভেন্ডরের নাম ছোট ও নিচে, আর Control Panel থেকে
             বন্ধ করা যায় (সেকশন ১৭.২): কিছু প্রতিষ্ঠান কর-সংক্রান্ত কাগজে
             বাইরের কোনো নাম রাখতে চায় না। --}}
        <div>Powered by ABOS</div>
    @endif
</div>
