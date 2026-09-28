{{--
    ক্লাসিক টেবিল ইনভয়েস — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬।

    ⓘ চলতি নকশার ([[print/document]]) পাশে দ্বিতীয় একটা ছাঁচ, বাছা হয়
    সেটিংসে (`sales.print.design.invoice`)। ⚠️ চলতিটা একটুও বদলায়নি, আর
    ডিফল্টও ওটাই।

    ── ⚠️ তথ্য একটাই, ছাঁচ দুইটা ─────────────────────────────────────────
    সারি, মোট, কথায় অঙ্ক, আদায়ের ছক — সব আসে সেই একই `$doc` থেকে যা চলতি
    নকশা আঁকে ([[SalesPrintController::invoice()]])। ⛔ এখানে নতুন করে কোনো
    অঙ্ক গোনা হয় না: দুই নকশায় দুই রকম বকেয়া ছাপা হওয়ার পথটাই বন্ধ।
    কেবল মাথার তিন কলাম (`$facts`) এই নকশার নিজের।

    ── ⚠️ টাকার ঘরের মাপ — ছাপার আকারে মাপা, অনুমান নয় ────────────────────
    ঘরের লেখা ৯pt, DejaVu-তে (অঙ্কগুলো সমান চওড়া — কারণ [[print/layout]]-এ)।
    mPDF-এর `GetStringWidth()` বলে ১২ কোটি (`12,31,87,500.00`) ৯pt-এ
    ২৬.২৫মিমি। ⓘ দু'পাশে ১.৫মিমি প্যাডিং বাদে: মোটের ঘর ৩২ → ২৯মিমি,
    দরের ঘর ২৯ → ২৬মিমি (দর ৬,১৫,৯৩,৭৫০.০০ = ২৪.২৩মিমি), ডানের টাকার
    সারি ৩৪ → ৩১মিমি (১২৩ কোটি পর্যন্ত)। [[AClassicTableInvoiceCanBeChosenTest]]
    এই মাপটাই পাতা থেকে পড়ে আবার মাপে।

    ⛔ রং কেবল দুই জায়গায়: লোগো আর নিচের লাল বাক্য — মালিকের নমুনায় ঠিক
    ঐটুকুই রঙিন, আর বাকিটা সাদাকালো প্রিন্টারে একই রকম পড়া যায়।
--}}
@php
    $cell = 9;
    $pad = 1.5;

    /*
     * ⓘ মোটের সারি — চলতি নকশার চাবি থেকে এই নকশার নামে।
     *
     * ⚠️ তালিকায় নেই এমন চাবি এলে সেটা **নিজের নামেই** শেষে বসে, হারায়
     * না। ⛔ নাহলে কাল কেউ মোটে নতুন একটা সারি (ধরুন "পরিবহন খরচ") যোগ
     * করলে চলতি কাগজে ওটা উঠত আর এখানে নীরবে বাদ পড়ত — আর যোগ-বিয়োগ
     * আর মিলত না।
     */
    $names = [
        'core.print.subtotal' => 'sales::print.classic.grand_total',
        'core.print.discount' => 'sales::print.classic.discount',
        'sales::print.bill_discount' => 'sales::print.bill_discount',
        'core.print.tax' => 'core.print.tax',
        'sales::field.rounding' => 'sales::print.classic.rounding',
        'core.print.total' => 'sales::print.classic.net_payable',
        'sales::print.paid' => 'sales::print.classic.paid',
        'sales::print.invoice_due' => 'sales::print.classic.invoice_due',
        'sales::print.previous_due' => 'sales::print.classic.previous_due',
        'sales::print.outstanding' => 'sales::print.classic.total_due',
    ];

    /* ⓘ নমুনায় এই দুইটা সবসময় থাকে — শূন্য হলেও, যাতে পাঠক খুঁজে না ফেরেন */
    $always = ['sales::print.paid', 'sales::print.previous_due'];

    $rows = [];

    foreach ($names as $key => $label) {
        if (isset($doc->totals[$key])) {
            $rows[] = [$label, $doc->totals[$key], $key];
        } elseif (in_array($key, $always, true) && isset($doc->totals['sales::print.invoice_due'])) {
            $rows[] = [$label, \App\Core\Support\Money::format('0'), $key];
        }
    }

    foreach ($doc->totals as $key => $value) {
        if (! isset($names[$key])) {
            $rows[] = [$key, $value, $key];
        }
    }

    $bold = ['core.print.total', 'sales::print.outstanding'];

    $footnote = $settings->get('sales.print.invoice_footnote');
    $footnote = filled($footnote) ? $footnote : __('sales::print.classic.footnote');

    $logo = $profile->shows('logo') ? $company->logoData() : null;
@endphp

<style @nonce>
    * { box-sizing: border-box; }

    body { font-family: hindsiliguri, sans-serif; font-size: 9.5pt; line-height: 1.3; color: #000; }

    table { border-collapse: collapse; }

    .head { width: 100%; margin-bottom: 3mm; }
    .head td { vertical-align: top; }
    .company-name { font-size: 14pt; font-weight: bold; }
    .company-meta { font-size: 8.5pt; }
    .big-title { text-align: right; font-size: 24pt; font-weight: bold; letter-spacing: 1mm; }

    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #000; padding: 2mm; margin-bottom: 3mm; font-size: 11pt; }

    .facts { width: 100%; margin-bottom: 3mm; }
    .facts td.box { width: 33.3%; border: 0.25mm solid #000; padding: 1.5mm 2mm; vertical-align: top; }
    .facts .box-head { font-weight: bold; border-bottom: 0.2mm solid #000; margin-bottom: 1mm; padding-bottom: 0.5mm; }
    .facts table.kv { width: 100%; font-size: 8.5pt; }
    .facts table.kv td { padding: 0.3mm 0; vertical-align: top; }
    .facts .k { width: 24mm; color: #333; }

    table.lines { width: 100%; }
    table.lines th { border: 0.25mm solid #000; background-color: #e8e8e8; padding: {{ $pad }}mm; font-size: 8.5pt; text-align: left; }
    table.lines td { border: 0.25mm solid #000; padding: 1mm {{ $pad }}mm; font-size: {{ $cell }}pt; vertical-align: top; }

    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; font-size: {{ $cell }}pt; }
    th.num { font-family: hindsiliguri; }

    .counts { width: 100%; margin-top: 1.5mm; font-size: 9pt; }
    .words { margin-top: 2mm; font-size: 9pt; }

    .bottom { width: 100%; margin-top: 3mm; }
    .bottom td.side { vertical-align: top; }

    table.payments { width: 100%; font-size: 7.5pt; }
    table.payments th, table.payments td { border: 0.2mm solid #000; padding: 0.8mm 1mm; text-align: left; }
    table.payments td.num { font-size: 8pt; }
    .payments-head { font-weight: bold; font-size: 8.5pt; margin-bottom: 1mm; }

    table.sums { width: 100%; }
    table.sums td { border: 0.25mm solid #000; padding: 1mm {{ $pad }}mm; font-size: 9pt; }
    table.sums tr.strong td { font-weight: bold; background-color: #f0f0f0; }

    .signatures { width: 100%; margin-top: 18mm; }
    .signatures td { width: 33.3%; text-align: center; font-size: 9pt; padding: 0 4mm; }
    .sig-line { border-top: 0.25mm solid #000; padding-top: 1mm; }

    .footnote { margin-top: 5mm; text-align: center; color: #c00000; font-weight: bold; font-size: 9pt; }
    .printed { margin-top: 2mm; text-align: center; font-size: 7.5pt; color: #444; }
</style>

<table class="head">
    <tr>
        <td style="width: 62%">
            @if ($logo)
                <img src="{{ $logo }}" style="height: 14mm;" alt="">
            @endif

            <div class="company-name">{{ $company->name() }}</div>

            @if ($company->address())
                <div class="company-meta">{{ $company->address() }}</div>
            @endif

            @if ($company->email)
                <div class="company-meta">{{ __('sales::print.classic.email') }}: {{ $company->email }}</div>
            @endif

            @if ($company->phone)
                <div class="company-meta">{{ __('sales::print.classic.phone') }}: {{ $company->phone }}</div>
            @endif
        </td>
        <td class="big-title">{{ __('sales::print.classic.heading') }}</td>
    </tr>
</table>

{{-- ⛔ "বাতিল" আর DUPLICATE এই নকশাতেও — কাগজটা যে-ই আঁকুক, সতর্কবার্তা হারায় না --}}
@if ($doc->notice)
    <div class="notice">{{ $doc->notice }}</div>
@endif

<table class="facts">
    <tr>
        @foreach (['bill_to', 'transport', 'bill'] as $group)
            <td class="box">
                <div class="box-head">{{ __('sales::print.classic.'.$group) }}</div>
                <table class="kv">
                    @foreach ($facts[$group] as $key => $value)
                        <tr>
                            <td class="k">{{ __('sales::print.classic.'.$key) }}</td>
                            <td>{{ $value !== '' ? $value : '—' }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        @endforeach
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th class="num" style="width: 9mm">{{ __('core.print.column.sl') }}</th>
            <th>{{ __('sales::print.classic.product') }}</th>
            <th style="width: 26mm">{{ __('sales::print.classic.qty') }}</th>
            <th style="width: 16mm">{{ __('sales::print.classic.free') }}</th>
            <th class="num" style="width: 29mm">{{ __('sales::print.classic.rate') }}</th>
            <th class="num" style="width: 32mm">{{ __('sales::print.classic.total') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($doc->lines as $index => $line)
            <tr>
                <td class="num">{{ $index + 1 }}</td>
                <td>
                    {{ ($line['code'] ?? '') !== '' ? $line['code'].' - '.$line['name'] : $line['name'] }}
                    @if (($line['note'] ?? '') !== '')
                        <div style="font-size: 7.5pt; color: #444;">{{ $line['note'] }}</div>
                    @endif
                </td>
                <td>{{ $line['qty'] }} {{ $line['unit'] }}</td>
                <td>{{ ($line['free'] ?? '') !== '' && $line['free'] !== '0' ? $line['free'] : '' }}</td>
                <td class="num">{{ $paper->money($line['rate']) }}</td>
                <td class="num">{{ $paper->money($line['amount']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="counts">
    <tr>
        <td>{{ __('sales::print.classic.total_items') }}: <strong>{{ $facts['total_items'] }}</strong></td>
        <td style="text-align: right">{{ __('sales::print.classic.total_delivery') }}: <strong>{{ $facts['total_delivery'] }}</strong></td>
    </tr>
</table>

@if ($doc->amountInWords)
    <div class="words"><strong>{{ __('sales::print.classic.in_words') }}:</strong> {{ $doc->amountInWords }}</div>
@endif

<table class="bottom">
    <tr>
        <td class="side" style="width: 104mm; padding-right: 4mm;">
            {{-- ⓘ খালি হলে ছকটাই নেই — যে বিলে একটাও জমা নেই সেখানে ফাঁকা ঘর আঁকা হয় না --}}
            @if ($doc->payments !== [])
                <div class="payments-head">{{ __('sales::print.payments_title') }}</div>
                <table class="payments">
                    <thead>
                        <tr>
                            <th class="num" style="width: 7mm">{{ __('core.print.column.sl') }}</th>
                            <th style="width: 22mm">{{ __('sales::print.txn_no') }}</th>
                            <th style="width: 18mm">{{ __('core.print.date') }}</th>
                            <th style="width: 17mm">{{ __('sales::print.method') }}</th>
                            <th>{{ __('core.print.narration') }}</th>
                            <th class="num" style="width: 26mm">{{ __('core.print.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($doc->payments as $row)
                            <tr>
                                <td class="num">{{ $row['no'] }}</td>
                                <td>{{ $row['ref'] }}</td>
                                <td>{{ $row['date'] }}</td>
                                <td>{{ $row['method'] }}</td>
                                <td>{{ $row['narration'] }}</td>
                                <td class="num">{{ $row['amount'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>
        <td class="side">
            <table class="sums">
                @foreach ($rows as [$label, $value, $key])
                    <tr @class(['strong' => in_array($key, $bold, true)])>
                        <td>{{ __($label) }}</td>
                        <td class="num" style="width: 34mm">{{ $paper->money($value) }}</td>
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

<table class="signatures">
    <tr>
        @foreach (['received_by', 'prepared_by', 'approved_by'] as $who)
            <td><div class="sig-line">{{ __('sales::print.classic.'.$who) }}</div></td>
        @endforeach
    </tr>
</table>

<div class="footnote">{{ $footnote }}</div>

<div class="printed">
    {{ __('sales::print.classic.printed_at') }}: {{ \App\Core\Support\DateFormat::formatWithTime(now()) }}
    @if (auth()->check()) · {{ auth()->user()->name }} @endif
</div>
