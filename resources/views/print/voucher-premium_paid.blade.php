{{--
    ভাউচার · নকশা 19 — প্রিমিয়াম — গাঢ় মাথা আর সোনালি লেখা; পাকা কাগজের গায়ে হালকা "পরিশোধিত" জলছাপ।
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
<table style="width: 100%; background: #1c1917"><tr>
    <td style="padding: 6mm; vertical-align: middle">@if ($p->logo(13) !== '')<div style="background: #fff; padding: 1mm; width: 16mm">{!! $p->logo(13) !!}</div>@endif
        <div style="font-size: 15pt; font-weight: bold; color: #e8c46b">{{ $h['name'] }}</div><div style="font-size: 7.5pt; color: #d6d3d1">{{ $h['address'] }}</div></td>
    <td style="padding: 6mm; text-align: right; vertical-align: middle">
        <div style="font-size: 7.5pt; {{ $p->ls('1.5mm') }} color: #e8c46b">{{ $v['type_label'] }}</div>
        <div style="font-size: 17pt; font-weight: bold; color: #fff; font-family: dejavusans">{{ $v['document_no'] }}</div>
        <div style="font-size: 8.5pt; color: #d6d3d1">{{ $p->n($v['date']) }}</div>{!! $p->duplicateMark() !!}
    </td>
</tr></table>
{!! $p->loudNotice() !!}
@if ($p->notice === null)
    <div style="text-align: right; margin-top: 3mm"><span style="border: 0.6mm solid #15803d; color: #15803d; font-weight: bold; font-size: 13pt; {{ $p->ls('1.5mm') }} padding: 1mm 5mm">{{ $p->t('paid_stamp') }}</span></div>
@endif
<table style="width: 100%; margin: 4mm 0"><tr>
    <td style="background: #f5f0e1; padding: 2.5mm 3mm; width: 33%"><div style="font-size: 7pt; color: #666">{{ $p->counterpartyLabel() }}</div><b>{{ $p->counterparty() ?: '—' }}</b></td>
    <td style="width: 2mm"></td>
    <td style="background: #f5f0e1; padding: 2.5mm 3mm; width: 33%"><div style="font-size: 7pt; color: #666">{{ $p->t('mode') }}</div><b>{{ $p->method() ?: '—' }}</b></td>
    <td style="width: 2mm"></td>
    <td style="background: #1c1917; padding: 2.5mm 3mm; width: 33%"><div style="font-size: 7pt; color: #d6d3d1">{{ $p->t('total') }}</div><b style="color: #e8c46b">{{ $p->money($v['total_debit']) }}</b></td>
</tr></table>
{!! $p->lines('dc', ['th' => 'background: #f5f0e1;']) !!}
{!! $p->narrationBox() !!}
{!! $p->words() !!}
{!! $p->signatures('line', null, 16) !!}
