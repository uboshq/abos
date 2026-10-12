{{--
    ভাউচার · নকশা 13 — খরচের দাবি — দাবির নম্বর, কর্মী/প্রাপক, বিল নম্বর; খরচের সারি এক কলামে।
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
        <div style="font-size: 12.5pt; font-weight: bold; color: #1f3a5f">{{ $v['type_label'] }}</div>
        {!! $p->duplicateMark() !!}
    </td>
</tr></table>
{!! $p->loudNotice() !!}
@if ($p->shows('meta'))<table style="width: 100%; margin: 3mm 0"><tr><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('claim_no') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $v['document_no'] }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('date') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->n($v['date']) }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('payee') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->counterparty() ?: '—' }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('bill_no') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->d('bill_no') ?: '—' }}</div></td></tr></table>@endif
{!! $p->lines('amount') !!}
{!! $p->narrationBox() !!}
{!! $p->words() !!}
{!! $p->signatures('line', null, 16) !!}
