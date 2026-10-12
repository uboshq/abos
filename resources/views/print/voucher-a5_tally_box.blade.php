{{--
    ভাউচার · নকশা 2 — ক্লাসিক বক্স — Tally ধাঁচ: পুরো কাগজ এক ফ্রেমে, বিবরণের কলাম আর ডানে টাকা।
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
<div style="border: 0.45mm solid #111; padding: 4mm">
    <table style="width: 100%"><tr>
        <td style="width: 15mm; vertical-align: middle">{!! $p->logo(11) !!}</td>
        <td style="vertical-align: middle"><div style="font-weight: bold; font-size: 11.5pt">{{ $h['name'] }}</div>{!! $p->companyMeta() !!}</td>
    </tr></table>
    <div style="text-align: center; font-weight: bold; font-size: 12pt; {{ $p->ls('1.5mm') }} border-top: 0.3mm solid #111; border-bottom: 0.3mm solid #111; margin: 2mm 0; padding: 1mm">{{ $v['type_label'] }}</div>
    {!! $p->duplicateMark() !!}{!! $p->loudNotice() !!}
    <table style="width: 100%; font-size: 9pt"><tr>
        <td>{{ $p->t('voucher_no') }}: <b>{{ $v['document_no'] }}</b></td>
        <td style="text-align: right">{{ $p->t('date') }}: <b>{{ $p->n($v['date']) }}</b></td>
    </tr></table>
    {!! $p->lines('dc', ['th' => 'background: #fff;', 'border' => '0.3mm solid #111']) !!}
    {!! $p->narrationBox('#111') !!}
    {!! $p->words() !!}
    {!! $p->signatures('line', null, 10) !!}
</div>
