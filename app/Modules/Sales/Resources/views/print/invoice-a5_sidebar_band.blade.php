{{-- ⓘ A5 সংস্করণ — A4-এর sidebar_band থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ৪ · পাশের রঙিন পট্টি — বাঁ পাশে কমলা পট্টিতে কোম্পানি, বিলের নম্বর, তারিখ আর মোট বকেয়া; ডানে বাকি সব।
    নমুনা: Design canvas, ৪। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।

    ⚠️ পট্টিটা একটা টেবিল-ঘর — mPDF-এ পাতা-জোড়া উঁচু কলাম হয় না, তাই পট্টি কেবল বিষয়বস্তুর সমান লম্বা।
--}}
@php
    $v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile);
    $accent = '#b4531f';
@endphp

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 7.6pt; color: #1e1b18; }
    table { border-collapse: collapse; }
    table.frame { width: 100%; }
    td.band { width: 31.7mm; background: {{ $accent }}; color: #fff; padding: 5mm 3.6mm; vertical-align: top; }
    td.main { padding: 5mm 0 0 5mm; vertical-align: top; }
    .mark { width: 9.4mm; height: 9.4mm; background: #fff; color: {{ $accent }}; text-align: center; font-weight: bold; font-size: 11pt; }
    .co-name { font-size: 12.8pt; font-weight: bold; margin-top: 2.9mm; }
    .co-meta { font-size: 6.5pt; color: #f7dccb; }
    .b-cap { font-size: 6.5pt; color: #f7dccb; margin-top: 4.3mm; }
    .b-val { font-size: 8.5pt; font-weight: bold; }
    .b-big { font-size: 12.8pt; font-weight: bold; font-family: dejavusans; }
    .title { font-size: 20.4pt; font-weight: bold; letter-spacing: 0.7mm; }
    .dup { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #b42318; color: #b42318; padding: 1.4mm; margin-top: 2.2mm; font-size: 9.3pt; }
    table.two { width: 100%; margin-top: 3.6mm; }
    table.two td { width: 50%; vertical-align: top; font-size: 7.2pt; line-height: 1.45; padding-right: 2.2mm; }
    .cap { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; }
    .party { font-weight: bold; font-size: 8.5pt; }
    .sub { font-size: 6.5pt; color: #6b625b; font-weight: normal; }
    table.items { width: 100%; margin-top: 3.6mm; }
    table.items th { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; padding: 0 0.9mm 1.1mm 0.9mm; text-align: left; border-bottom: 0.4mm solid {{ $accent }}; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.4mm 0.9mm; border-bottom: 0.2mm solid #efe7e1; font-size: 7.2pt; vertical-align: top; }
    table.items tr.grand td { font-weight: bold; border-bottom: 0; }
    .free { color: {{ $accent }}; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; }
    table.sums { width: 51.8mm; margin-top: 2.9mm; margin-left: auto; }
    table.sums td { padding: 0.8mm 0; font-size: 7.6pt; }
    table.sums tr.net td { font-weight: bold; }
    table.sums tr.owed td { font-weight: bold; color: {{ $accent }}; border-top: 0.3mm solid {{ $accent }}; padding-top: 1.4mm; }
    .pay-head { font-size: 6.5pt; font-weight: bold; color: {{ $accent }}; margin-top: 2.9mm; }
    table.pay { width: 100%; margin-top: 0.7mm; }
    table.pay th { font-size: 6.5pt; color: #6b625b; text-align: left; padding: 0.6mm 0.7mm; }
    table.pay td { font-size: 6.8pt; padding: 0.6mm 0.7mm; border-top: 0.1mm solid #efe7e1; }
    .words { margin-top: 2.2mm; font-size: 6.8pt; color: #5c554f; }
    .footnote { margin-top: 2.2mm; font-size: 7.2pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 9.4mm; }
    table.signatures td { text-align: center; padding: 0 2.2mm; font-size: 7.2pt; }
    .sig-line { border-top: 0.2mm solid #1e1b18; padding-top: 0.7mm; }
    .printed { margin-top: 2.9mm; font-size: 6.5pt; color: #6b625b; }
</style>

<table class="frame">
    <tr>
        <td class="band">
            @if ($v->logo)
                <img src="{{ $v->logo }}" style="height: 9.4mm;" alt="">
            @else
                <div class="mark">{{ mb_strtoupper(mb_substr($v->head['name'], 0, 2)) }}</div>
            @endif
            <div class="co-name">{{ $v->head['name'] }}</div>
            @include('sales::print.partials.invoice-company', ['v' => $v])

            <div class="b-cap">{{ mb_strtoupper($v->label('bill_no')) }}</div>
            <div class="b-val">{{ $facts['bill']['bill_no'] }}</div>
            <div class="b-cap">{{ mb_strtoupper($v->label('bill_date')) }}</div>
            <div class="b-val">{{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('invoice_type'))
                <div class="b-cap">{{ mb_strtoupper($v->label('type')) }}</div>
                <div class="b-val" data-invoice-type>{{ $facts['bill']['type'] }}</div>
            @endif
            @if ($v->shows('previous_due'))
                <div class="b-cap">{{ mb_strtoupper($v->label('total_due')) }}</div>
                <div class="b-big">{{ $paper->money($v->sums['outstanding']) }}</div>
            @endif
            <div style="margin-top: 5.8mm">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '17.3mm'])</div>
        </td>
        <td class="main">
            <div class="title">{{ $v->en('heading') }}</div>
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
            @if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

            <table class="two">
                <tr>
                    <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
                    <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
                </tr>
            </table>

            @if ($v->shows('order_no'))<div style="margin-top: 1.4mm; font-size: 7.2pt" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }} · {{ $v->en('created_by') }} {{ $facts['bill']['created_by'] }}</div>@endif

            @include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true])
            @include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])
            @include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div class="footnote">{!! nl2br(e($v->footnote)) !!}</div>
            @include('sales::print.partials.invoice-signatures', ['v' => $v])
            <div class="printed">{{ $v->printedAt() }}</div>
        </td>
    </tr>
</table>
