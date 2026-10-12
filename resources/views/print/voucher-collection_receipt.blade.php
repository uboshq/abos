{{--
    ভাউচার · নকশা 20 — আদায় রসিদ — ডিলারের নাম-পয়েন্ট, মাধ্যম, বিল সমন্বয়; খাতের সারি আর চার ঘরের সারাংশ।
    মালিক, ১২ অক্টোবর ২০২৬: *"ei gulo control panel e bosaw sobgulo"*; ভাগের কাজ [[VoucherPaper]]-এ।
--}}
@php
    $p = \App\Modules\Accounts\Support\VoucherPaper::from(get_defined_vars());
    $v = $p->v;
    $h = $p->head();
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #111; }
    table { border-collapse: collapse; }
    .num { text-align: right; white-space: nowrap; font-family: {{ $p->numFont() }}; }
</style>
<table style="width: 100%; border-bottom: 0.6mm solid #1f3a5f"><tr>
    <td style="width: 18mm; vertical-align: middle; padding-bottom: 2mm">{!! $p->logo(13) !!}</td>
    <td style="vertical-align: middle; padding-bottom: 2mm">
        <div style="font-size: 14pt; font-weight: bold">{{ $h['name'] }}</div>
        {!! $p->companyMeta() !!}
    </td>
    <td style="text-align: right; vertical-align: middle; padding-bottom: 2mm">
        <div style="font-size: 12.5pt; font-weight: bold; color: #1f3a5f">{{ $p->isReceipt() ? $p->t('collection_receipt') : $v['type_label'] }}</div>
        {!! $p->duplicateMark() !!}
    </td>
</tr></table>
{!! $p->loudNotice() !!}
@if ($p->shows('meta'))<table style="width: 100%; margin: 3mm 0"><tr><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->counterpartyLabel() }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->counterparty() ?: '—' }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('point') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->d('point') ?: '—' }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('date') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->n($v['date']) }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('receipt_no') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $v['document_no'] }}</div></td></tr></table>@endif
{!! $p->lines('dc') !!}
{!! $p->narrationBox() !!}
@if ($p->billAdjustment() !== '')<div style="border: 0.25mm solid #1f3a5f; padding: 2mm 3mm; margin: 2mm 0"><b>{{ $p->t('bill_adjustment') }}</b><br>{{ $p->billAdjustment() }}</div>@endif
{!! $p->words() !!}
<table style="width: 100%; margin-top: 3mm"><tr>
    <td style="border: 0.4mm solid #1f3a5f; padding: 2mm; text-align: center; width: 33%"><div style="font-size: 7pt; color: #666">{{ $p->t('amount') }}</div><b>{{ $p->money($v['total_debit']) }}</b></td>
    <td style="width: 2mm"></td>
    <td style="border: 0.4mm solid #1f3a5f; padding: 2mm; text-align: center; width: 33%"><div style="font-size: 7pt; color: #666">{{ $p->t('mode') }}</div><b>{{ $p->method() ?: '—' }}</b></td>
    <td style="width: 2mm"></td>
    <td style="border: 0.4mm solid #1f3a5f; padding: 2mm; text-align: center; width: 33%"><div style="font-size: 7pt; color: #666">{{ $p->t('collected_by') }}</div><b>{{ $p->d('prepared_by') ?: '—' }}</b></td>
</tr></table>
{!! $p->signatures('line', null, 14) !!}
