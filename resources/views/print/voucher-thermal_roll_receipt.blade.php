{{--
    ভাউচার · নকশা 15 — থার্মাল রোল — মাঝে নাম, ড্যাশ দাগ, বাঁয়ে নাম ডানে মান; কেবল কালো।
    মালিক, ১২ অক্টোবর ২০২৬: *"ei gulo control panel e bosaw sobgulo"*; ভাগের কাজ [[VoucherPaper]]-এ।
--}}
@php
    $p = \App\Modules\Accounts\Support\VoucherPaper::from(get_defined_vars());
    $v = $p->v;
    $h = $p->head();
@endphp
<style @nonce>
    body { font-family: hindsiliguri, sans-serif; font-size: 9pt; color: #000; }
    table { border-collapse: collapse; }
    .num { text-align: right; white-space: nowrap; font-family: {{ $p->numFont() }}; }
</style>
<div style="text-align: center">
    <div style="font-weight: bold; font-size: 9.5pt">{{ $h['name'] }}</div>
    @if ($h['contact'] !== '')<div style="font-size: 7pt">{{ $h['contact'] }}</div>@endif
</div>
<div style="border-top: 0.3mm dashed #000; margin: 1.5mm 0"></div>
<div style="text-align: center; font-weight: bold">{{ $v['type_label'] }}</div>
{!! $p->duplicateMark() !!}{!! $p->loudNotice() !!}
<div style="border-top: 0.3mm dashed #000; margin: 1.5mm 0"></div>
<table style="width: 100%; font-size: 8pt">
    <tr><td>{{ $p->t('voucher_no') }}</td><td style="text-align: right">{{ $v['document_no'] }}</td></tr>
    <tr><td>{{ $p->t('date') }}</td><td style="text-align: right">{{ $p->n($v['date']) }}</td></tr>
    @if ($p->counterparty() !== '')<tr><td>{{ $p->counterpartyLabel() }}</td><td style="text-align: right">{{ $p->counterparty() }}</td></tr>@endif
    @if ($p->d('point') !== '')<tr><td>{{ $p->t('point') }}</td><td style="text-align: right">{{ $p->d('point') }}</td></tr>@endif
    @if ($p->d('against') !== '')<tr><td>{{ $p->t('against_bill') }}</td><td style="text-align: right">{{ $p->d('against') }}</td></tr>@endif
    @if ($p->method() !== '')<tr><td>{{ $p->t('mode') }}</td><td style="text-align: right">{{ $p->method() }}</td></tr>@endif
</table>
<div style="border-top: 0.3mm dashed #000; margin: 1.5mm 0"></div>
<table style="width: 100%"><tr><td style="font-weight: bold; font-size: 10pt">{{ $p->t('amount') }}</td><td style="text-align: right; font-weight: bold; font-size: 10pt">{{ $p->money($v['total_debit']) }}</td></tr></table>
@if ($p->shows('narration') && $v['narration'] !== '')<div data-narration style="font-size: 7.5pt; margin-top: 1mm">{{ $p->t('narration') }}: {{ $v['narration'] }}</div>@endif
@if ($p->shows('words'))<div style="font-size: 7.5pt; text-align: center; margin-top: 1mm">{{ $v['amount_in_words'] }}</div>@endif
{!! $p->signatures('line', null, 8, '#000') !!}
<div style="text-align: center; font-size: 7pt; margin-top: 2mm">{{ $p->d('prepared_by') }}</div>
