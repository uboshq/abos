{{--
    ভাউচার · নকশা 7 — চেক পেমেন্ট — চেকের ছবির মতো ঘর (ব্যাংক, তারিখ, প্রাপক, কথায়, অঙ্ক, চেক নম্বর), নিচে প্রাপকের প্রাপ্তিস্বীকার।
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
@if ($p->shows('meta'))<table style="width: 100%; margin: 3mm 0"><tr><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('voucher_no') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $v['document_no'] }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('date') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->n($v['date']) }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->counterpartyLabel() }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->counterparty() ?: '—' }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('mode') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->method() ?: '—' }}</div></td></tr></table>@endif
<table style="width: 100%; margin: 3mm 0; border: 0.35mm solid #335; background: #f1f5fb"><tr><td style="padding: 3mm 4mm">
    <table style="width: 100%"><tr>
        <td style="font-weight: bold">{{ $p->d('money_account') ?: $p->d('from_bank') }}</td>
        <td style="text-align: right">{{ $p->t('cheque_date') }}: {{ $p->n($p->d('instrument_date') ?: $v['date']) }}</td>
    </tr></table>
    <div style="margin-top: 3mm">{{ $p->t('payee') }}: <u>{{ $p->counterparty() }}</u></div>
    <table style="width: 100%; margin-top: 2mm"><tr>
        <td><i>{{ $v['amount_in_words'] }}</i></td>
        <td style="width: 45mm; text-align: right"><span style="border: 0.4mm solid #111; background: #fff; padding: 1mm 3mm; font-weight: bold">{{ $p->money($v['total_debit']) }}</span></td>
    </tr></table>
    <div style="margin-top: 3mm; font-size: 8pt">{{ $p->t('cheque_no') }} <span style="font-family: dejavusansmono">{{ $p->d('instrument_no') ?: '—' }}</span></div>
</td></tr></table>
{!! $p->lines('amount') !!}
{!! $p->narrationBox() !!}
<table style="width: 100%; margin-top: 4mm; border: 0.25mm dashed #777"><tr>
    <td style="padding: 3mm; width: 40%">{{ $p->t('received_by') }} —</td>
    <td style="padding: 3mm; border-bottom: 0.25mm solid #111"></td>
    <td style="padding: 3mm; width: 22%; border-bottom: 0.25mm solid #111; color: #666; font-size: 8pt">{{ $p->t('date') }}</td>
</tr></table>
{!! $p->signatures('line', [$p->t('prepared_by'), $p->t('checked_by'), $p->t('approved_by')], 14) !!}
