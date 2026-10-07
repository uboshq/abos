{{-- ⓘ A5 সংস্করণ — A4-এর distributor_compact থেকে মাপ ছোট করে (mm ×০.৭২, অক্ষর ×০.৮৫, সীমা ৬.৫pt), চেহারা একই। মালিক, ৩০ সেপ্টেম্বর ২০২৬: "A5 er jonno 21 desine koro" --}}
{{--
    ৬ · পরিবেশক ঘন তালিকা — ছোট অক্ষর, ঘন সারি: অনেক পণ্য এক পাতায়; মাথা এক সারিতে, তিন তথ্য এক ফালিতে।
    নমুনা: Design canvas, ৬। ⓘ সুইচ আর `data-*` চিহ্ন ভাগের partial-এ ([[InvoicePaperView]])।

    ⚠️ নমুনায় ব্র্যান্ড ধরে উপ-মোট আছে; `$facts`-এর সারিতে ব্র্যান্ড এখনো নেই — `rows[].group` এলে
    ভাগটা বসবে। ততদিন সারিগুলো বিলের ক্রমেই, আর কোড/লট নামের নিচে।
--}}
@php($v = new \App\Modules\Sales\Support\InvoicePaperView($doc, $facts, $company, $profile))

<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 6.8pt; color: #1a1d1c; }
    table { border-collapse: collapse; }
    table.head { width: 100%; border-bottom: 0.4mm solid #2f6f3e; }
    table.head td { vertical-align: middle; padding-bottom: 1.4mm; }
    .co-name { font-size: 11pt; font-weight: bold; }
    .co-meta { font-size: 6.5pt; color: #555c5a; }
    .facts-line { text-align: right; font-size: 6.8pt; }
    .dup { text-align: right; font-size: 6.5pt; font-weight: bold; }
    .notice { text-align: center; font-weight: bold; border: 0.3mm solid #b42318; color: #b42318; padding: 1.1mm; margin-top: 1.4mm; font-size: 8.5pt; }
    table.strip { width: 100%; margin-top: 1.4mm; background: #f3f6f4; }
    table.strip td { width: 33.3%; vertical-align: top; padding: 1.3mm 1.8mm; font-size: 6.5pt; line-height: 1.35; }
    .cap { font-size: 6.5pt; font-weight: bold; color: #2f6f3e; }
    .party { font-weight: bold; }
    .sub { font-size: 6.5pt; color: #6b7270; font-weight: normal; }
    table.items { width: 100%; margin-top: 2.2mm; }
    table.items th { background: #2f6f3e; color: #fff; font-size: 6.5pt; font-weight: bold; padding: 0.9mm 0.9mm; text-align: left; }
    table.items th.num { text-align: right; }
    table.items td { padding: 0.8mm 0.9mm; border-bottom: 0.1mm solid #e3e6e5; font-size: 6.8pt; vertical-align: top; }
    table.items tr.alt td { background: #fafbfa; }
    table.items tr.grand td { background: #2f6f3e; color: #fff; font-weight: bold; }
    .free { color: #2f6f3e; font-weight: bold; }
    .num { text-align: right; white-space: nowrap; }
    td.num { font-family: dejavusans; font-size: 6.8pt; }
    table.bottom { width: 100%; margin-top: 2.2mm; }
    table.bottom td.side { vertical-align: top; font-size: 6.5pt; }
    .pay-head { font-weight: bold; font-size: 6.5pt; }
    table.pay { width: 100%; margin-top: 0.6mm; }
    table.pay th { font-size: 6.5pt; color: #6b7270; text-align: left; padding: 0.4mm 0.7mm; }
    table.pay td { font-size: 6.5pt; padding: 0.4mm 0.7mm; border-top: 0.1mm solid #e3e6e5; }
    table.sums { width: 100%; }
    table.sums td { padding: 0.6mm 1.1mm; font-size: 6.8pt; }
    table.sums tr.net td { border-top: 0.2mm solid #1a1d1c; font-weight: bold; }
    table.sums tr.owed td { background: #fdf0e1; color: #8a4a0c; font-weight: bold; }
    .words { margin-top: 1.1mm; }
    .footnote { margin-top: 1.4mm; font-size: 6.8pt; font-weight: bold; color: #b42318; }
    table.signatures { width: 100%; margin-top: 7.9mm; }
    table.signatures td { text-align: center; padding: 0 2.2mm; font-size: 6.8pt; }
    .sig-line { border-top: 0.2mm solid #1a1d1c; padding-top: 0.6mm; }
    .printed { margin-top: 1.4mm; font-size: 6.5pt; color: #6b7270; }
</style>

<table class="head">
    <tr>
        <td>
{{-- ⭐ লোগো নামের বাঁয়ে — মালিক, ৩০ সেপ্টেম্বর ২০২৬ (নমুনা PDF দেখে) --}}
<table><tr>
    @if ($v->logo)<td style="padding-right: 2.5mm; vertical-align: middle"><img src="{{ $v->logo }}" style="height: 7.9mm;" alt=""></td>@endif
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

@include('sales::print.partials.invoice-items', ['v' => $v, 'facts' => $facts, 'paper' => $paper, 'narrow' => true, 'zebra' => true])

<table class="bottom">
    <tr>
        <td class="side" style="width: 60%; padding-right: 3.6mm">
            @if ($v->shows('amount_words'))<div class="words" data-words><strong>{{ $v->en('in_words') }}</strong> {{ $facts['words'] }}</div>@endif
            <div style="margin-top: 1.4mm">@include('sales::print.partials.invoice-payments', ['v' => $v, 'doc' => $doc])</div>
            <div class="footnote"><div style="text-align: left; font-size: 80%; line-height: 1.35">{!! nl2br(e($v->footnote)) !!}</div></div>
            <div style="margin-top: 1.4mm; text-align: left">@include('sales::print.partials.invoice-qr', ['v' => $v, 'width' => '13mm'])</div>
        </td>
        <td class="side">@include('sales::print.partials.invoice-sums', ['v' => $v, 'paper' => $paper])</td>
    </tr>
</table>

@include('sales::print.partials.invoice-signatures', ['v' => $v])
<div class="printed">{{ $v->printedAt() }}</div>
