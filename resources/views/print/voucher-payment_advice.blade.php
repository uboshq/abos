{{--
    ভাউচার · নকশা 12 — পেমেন্ট অ্যাডভাইস — Oracle ধাঁচ: কমলা শিরোনাম, বাঁয়ে প্রাপক, ডানে পরিশোধের ঘর।
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
<table style="width: 100%; border-bottom: 0.8mm solid #c2410c"><tr>
    <td style="padding-bottom: 2mm"><div style="font-size: 19pt; font-weight: bold; color: #c2410c">{{ $v['type_label'] }}</div><div style="font-size: 8pt; color: #555">{{ $h['name'] }}</div>{!! $p->duplicateMark() !!}</td>
    <td style="text-align: right; padding-bottom: 2mm">{!! $p->logo(14) !!}</td>
</tr></table>
{!! $p->loudNotice() !!}
<table style="width: 100%; margin: 4mm 0"><tr>
    <td style="width: 50%; vertical-align: top">
        <div style="font-size: 7.5pt; font-weight: bold; color: #c2410c; {{ $p->ls('0.5mm') }}">{{ mb_strtoupper($p->counterpartyLabel()) }}</div>
        <div style="font-weight: bold">{{ $p->counterparty() ?: '—' }}</div>
        @if ($p->d('from_bank') !== '')<div>{{ $p->d('from_bank') }}</div>@endif
    </td>
    <td style="width: 50%; vertical-align: top">
        <div style="font-size: 7.5pt; font-weight: bold; color: #c2410c; {{ $p->ls('0.5mm') }}">{{ mb_strtoupper($p->t('payment')) }}</div>
        <table style="font-size: 9pt">
            <tr><td style="padding-right: 4mm">{{ $p->t('payment_no') }}</td><td>{{ $v['document_no'] }}</td></tr>
            <tr><td style="padding-right: 4mm">{{ $p->t('date') }}</td><td>{{ $p->n($v['date']) }}</td></tr>
            <tr><td style="padding-right: 4mm">{{ $p->t('mode') }}</td><td>{{ $p->method() ?: '—' }}</td></tr>
            <tr><td style="padding-right: 4mm">{{ $p->t('amount') }}</td><td><b>{{ $p->money($v['total_debit']) }}</b></td></tr>
        </table>
    </td>
</tr></table>
{!! $p->lines('amount', ['th' => 'background: #fff4ed;']) !!}
{!! $p->narrationBox() !!}
{!! $p->words() !!}
<div style="font-size: 8pt; color: #555">{{ $p->t('quote_number') }}</div>
{!! $p->signatures('line', null, 16) !!}
