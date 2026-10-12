{{--
    ভাউচার · নকশা 8 — আধুনিক মিনিমাল — বাঁয়ে টিল রঙের দাগ, বড় নম্বর, চারটা সারাংশ কার্ড।
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
<table style="width: 100%"><tr>
    <td style="width: 3mm; background: #0f766e"></td>
    <td style="padding-left: 6mm; vertical-align: top">
        <table style="width: 100%"><tr>
            <td style="vertical-align: top">
                <div style="font-size: 8pt; {{ $p->ls('1.5mm') }} color: #0f766e; font-weight: bold">{{ $v['type_label'] }}</div>
                <div style="font-size: 22pt; font-weight: bold; font-family: dejavusans">{{ $v['document_no'] }}</div>
                <div style="font-size: 8.5pt; color: #555">{{ $h['name'] }}</div>{!! $p->duplicateMark() !!}
            </td>
            <td style="text-align: right; vertical-align: top">{!! $p->logo(16) !!}</td>
        </tr></table>
        {!! $p->loudNotice() !!}
        <table style="width: 100%; margin: 4mm 0"><tr>
            <td style="background: #f3f7f6; padding: 2.5mm 3mm; width: 25%"><div style="font-size: 7pt; color: #666">{{ $p->counterpartyLabel() }}</div><b>{{ $p->counterparty() ?: '—' }}</b></td>
            <td style="width: 2mm"></td>
            <td style="background: #f3f7f6; padding: 2.5mm 3mm; width: 25%"><div style="font-size: 7pt; color: #666">{{ $p->t('date') }}</div><b>{{ $p->n($v['date']) }}</b></td>
            <td style="width: 2mm"></td>
            <td style="background: #f3f7f6; padding: 2.5mm 3mm; width: 25%"><div style="font-size: 7pt; color: #666">{{ $p->t('money_account') }}</div><b>{{ $p->d('money_account') ?: '—' }}</b></td>
            <td style="width: 2mm"></td>
            <td style="background: #0f766e; color: #fff; padding: 2.5mm 3mm; width: 25%"><div style="font-size: 7pt; color: #d6f0ec">{{ $p->t('amount') }}</div><b style="color: #fff">{{ $p->money($v['total_debit']) }}</b></td>
        </tr></table>
        {!! $p->lines('dc', ['th' => 'background: #f3f7f6;', 'border' => '0.25mm solid #cfdcd9']) !!}
        {!! $p->narrationBox('#cfdcd9') !!}
        {!! $p->words() !!}
        {!! $p->signatures('line', null, 16, '#0f766e') !!}
    </td>
</tr></table>
