{{--
    ভাউচার · নকশা 6 — কোথা থেকে কোথায় — বাঁয়ে ক্রেডিটের খাত, তীর, ডানে ডেবিটের খাত; নোটের গুনতি থাকলে ছক।
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
<div style="text-align: center"><div style="font-size: 13pt; font-weight: bold">{{ $h['name'] }}</div>
    <div style="margin-top: 1.5mm"><span style="background: #111; color: #fff; padding: 0.8mm 6mm; font-weight: bold; {{ $p->ls('0.6mm') }}">{{ $v['type_label'] }}</span></div>{!! $p->duplicateMark() !!}
</div>
{!! $p->loudNotice() !!}
<table style="width: 100%; font-size: 8.8pt; margin-top: 2mm"><tr>
    <td>{{ $p->t('voucher_no') }}: <b>{{ $v['document_no'] }}</b></td>
    <td style="text-align: right">{{ $p->t('date') }}: <b>{{ $p->n($v['date']) }}</b></td>
</tr></table>
@php
    $from = array_values(array_filter($v['lines'], fn ($l) => trim(str_replace([',', '0', '.'], '', (string) $l['credit'])) !== ''));
    $to = array_values(array_filter($v['lines'], fn ($l) => trim(str_replace([',', '0', '.'], '', (string) $l['debit'])) !== ''));
@endphp
<table style="width: 100%; margin: 3mm 0"><tr>
    <td style="width: 45%; border: 0.4mm solid #1f3a5f; padding: 2mm 3mm; vertical-align: top">
        <div style="font-size: 7pt; color: #666">{{ $p->t('credit') }}</div>
        @foreach ($from as $l)<div style="font-weight: bold">{{ $l['account'] }}</div><div class="num" style="text-align: left">{{ $p->money($l['credit']) }}</div>@endforeach
    </td>
    <td style="width: 10%; text-align: center; font-size: 18pt; color: #1f3a5f; font-family: dejavusans">&rarr;</td>
    <td style="width: 45%; border: 0.4mm solid #1f3a5f; padding: 2mm 3mm; vertical-align: top">
        <div style="font-size: 7pt; color: #666">{{ $p->t('debit') }}</div>
        @foreach ($to as $l)<div style="font-weight: bold">{{ $l['account'] }}</div><div class="num" style="text-align: left">{{ $p->money($l['debit']) }}</div>@endforeach
    </td>
</tr></table>
{!! $p->narrationBox() !!}
{!! $p->words() !!}
@if ($p->notes() !== [])
    <table style="width: 100%; margin-top: 2mm; font-size: 8pt">
        <tr><td style="border: 0.25mm solid #333; padding: 1mm; font-weight: bold">{{ $p->t('note') }}</td>@foreach ($p->notes() as $note => $count)<td style="border: 0.25mm solid #333; padding: 1mm; text-align: center; font-weight: bold">{{ $p->n((string) $note) }}</td>@endforeach</tr>
        <tr><td style="border: 0.25mm solid #333; padding: 1mm">{{ $p->t('pieces') }}</td>@foreach ($p->notes() as $count)<td style="border: 0.25mm solid #333; padding: 1mm; text-align: center">{{ $p->n((string) $count) }}</td>@endforeach</tr>
    </table>
@endif
{!! $p->signatures('line', null, 9) !!}
