{{--
    ভাউচার · নকশা 9 — কর্পোরেট লেটারহেড — উপরে রঙের পট্টিতে নাম, নিচে ঠিকানার পট্টি; অডিট ফাইলে রাখার মতো।
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
<table style="width: 100%; background: #1f3a5f"><tr>
    <td style="padding: 6mm; width: 22mm; vertical-align: middle">@if ($p->logo(13) !== '')<div style="background: #fff; padding: 1mm">{!! $p->logo(13) !!}</div>@endif</td>
    <td style="padding: 6mm 4mm; vertical-align: middle; color: #fff"><div style="font-size: 17pt; font-weight: bold; color: #fff">{{ $h['name'] }}</div></td>
</tr></table>
<div style="text-align: center; font-size: 13pt; font-weight: bold; {{ $p->ls('1.5mm') }} margin: 5mm 0 1mm">{{ $v['type_label'] }}</div>
<div style="text-align: center">{!! $p->duplicateMark() !!}</div>
{!! $p->loudNotice() !!}
@if ($p->shows('meta'))<table style="width: 100%; margin: 3mm 0"><tr><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('voucher_no') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $v['document_no'] }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('date') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->n($v['date']) }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->counterpartyLabel() }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->counterparty() ?: '—' }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('mode') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->method() ?: '—' }}</div></td></tr></table>@endif
{!! $p->lines('dc') !!}
{!! $p->narrationBox() !!}
{!! $p->words() !!}
{!! $p->signatures('line', null, 16) !!}
<div style="margin-top: 20mm; background: #1f3a5f; color: #fff; text-align: center; font-size: 7.5pt; padding: 2.5mm">{{ implode(' · ', array_filter([$h['address'], $h['contact'], $h['tax']])) }}</div>
