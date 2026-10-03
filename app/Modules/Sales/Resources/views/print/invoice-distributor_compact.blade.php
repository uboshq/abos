{{--
    ৬ · পরিবেশক ঘন তালিকা — ছোট অক্ষর, ঘন সারি: অনেক পণ্য এক পাতায়; মাথা এক সারিতে, তিন তথ্য এক ফালিতে।
    নমুনা: Design canvas, ৬। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।

    ⚠️ নমুনায় ব্র্যান্ড ধরে উপ-মোট আছে; `$facts`-এর সারিতে ব্র্যান্ড এখনো নেই — `rows[].group` এলে
    ভাগটা বসবে। ততদিন সারিগুলো বিলের ক্রমেই, আর কোড/লট নামের নিচে।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 8pt; color: #1a1d1c; }
    table { border-collapse: collapse; }
    table.head { width: 100%; border-bottom: 0.6mm solid #2f6f3e; }
    table.head td { vertical-align: middle; padding-bottom: 2mm; }
    .co-name { font-size: 13pt; font-weight: bold; }
    .co-meta { font-size: 7pt; color: #555c5a; }
    .facts-line { text-align: right; font-size: 8pt; }
    .dup { text-align: right; font-size: 7pt; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.4mm solid #b42318; color: #b42318; padding: 1.5mm; margin-top: 2mm; font-size: 10pt; }
    table.strip { width: 100%; margin-top: 2mm; background: #f3f6f4; }
    table.strip td { width: 33.3%; vertical-align: top; padding: 1.8mm 2.5mm; font-size: 7.5pt; line-height: 1.35; }
    .cap { font-size: 6.5pt; font-weight: bold; color: #2f6f3e; }
    .party { font-weight: bold; }
    .sub { font-size: 6.8pt; color: #6b7270; font-weight: normal; }
    table.items { width: 100%; margin-top: 3mm; }
    table.items th { background: #2f6f3e; color: #fff; font-size: 6.8pt; font-weight: bold; padding: 1.3mm 1.2mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 1.1mm 1.2mm; border-bottom: 0.2mm solid #e3e6e5; font-size: 8pt; vertical-align: top; }
    table.items tr.alt td { background: #fafbfa; }
    table.items tr.grand td { background: #2f6f3e; color: #fff; font-weight: bold; }
    .free { color: #2f6f3e; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; font-size: 8pt; }
    table.bottom { width: 100%; margin-top: 3mm; }
    table.bottom td.side { vertical-align: top; font-size: 7.5pt; }
    .pay-head { font-weight: bold; font-size: 7pt; }
    table.pay { width: 100%; margin-top: 0.8mm; }
    table.pay th { font-size: 6.5pt; color: #6b7270; text-align: left; padding: 0.6mm 1mm; }
    table.pay td { font-size: 7.5pt; padding: 0.6mm 1mm; border-top: 0.2mm solid #e3e6e5; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.8mm 1.5mm; font-size: 8pt; }
    table.sums tr.net td { border-top: 0.3mm solid #1a1d1c; font-weight: bold; }
    table.sums tr.owed td { background: #fdf0e1; color: #8a4a0c; font-weight: bold; }
    .words { margin-top: 1.5mm; }
    .footnote { margin-top: 2mm; font-size: 8pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 11mm; }
    table.signatures td { text-align: center; padding: 0 3mm; font-size: 8pt; }
    .sig-line { border-top: 0.25mm solid #1a1d1c; padding-top: 0.8mm; }
    .printed { margin-top: 2mm; font-size: 6.5pt; color: #6b7270; }
</style>

<table class="head">
    <tr>
        <td>
{{-- ⭐ লোগো নামের বাঁয়ে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (নমুনা PDF দেখে) --}}
<table><tr>
    @if ($v->logo)<td style="padding-right: 3.5mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 11mm;" alt=""></td>@endif
    <td style="vertical-align: middle">
                <div class="co-name">{{ $v->head['name'] }}</div>
                @include('sales::print.partials.invoice-company', ['v' => $v])
    </td>
</tr></table>
        </td>
        <td>
            <div class="facts-line"><strong>{{ mb_strtoupper($v->en('heading')) }} {{ $facts['bill']['bill_no'] }}</strong> · {{ $facts['bill']['bill_date'] }}</div>
            @if ($v->shows('order_no'))<div class="facts-line" data-order-no>{{ $v->en('order_no') }} {{ $facts['bill']['order_no'] }}</div>@endif
            @if ($v->shows('invoice_type'))<div class="facts-line" data-invoice-type><strong>{{ $facts['bill']['type'] }}</strong></div>@endif
            @if ($v->duplicate)<div class="dup" data-duplicate>{{ $v->en('duplicate') }}</div>@endif
        </td>
    </tr>
</table>

@if ($v->notices !== [])<div class="notice">{{ implode(' · ', $v->notices) }}</div>@endif

<table class="strip">
    <tr>
        <td>@include('sales::print.partials.invoice-bill-to', ['v' => $v, 'facts' => $facts])</td>
        <td>@include('sales::print.partials.invoice-transport', ['v' => $v, 'facts' => $facts])</td>
        <td><div class="cap">{{ mb_strtoupper(__('sales::invoice_design.details', [], 'en')) }}</div>{{ $v->en('created_by') }} {{ $facts['bill']['created_by'] }}<br>{{ $v->en('total_items') }} {{ $facts['total_items'] }} · {{ $v->en('total_delivery') }} {{ $facts['total_delivery'] }}</td>
    </tr>
</table>

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'zebra' => true])

<table class="bottom">
    <tr>
        <td class="side" style="width: 60%; padding-right: 5mm">
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div style="margin-top: 2mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            <div class="footnote"><div style="text-align: left; font-size: 50%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
            <div style="margin-top: 2mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '18mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
