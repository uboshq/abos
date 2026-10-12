{{--
    ভাউচার · নকশা 3 — ডেবিট/ক্রেডিট ভাউচার — বাংলাদেশি অফিসের প্রচলিত ধাঁচ: মাঝে নাম, গোল পিলে শিরোনাম, খাতের এক কলাম।
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
<div style="text-align: center">{!! $p->logo(11) !!}<div style="font-size: 13pt; font-weight: bold">{{ $h['name'] }}</div>{!! $p->companyMeta() !!}
    <div style="margin-top: 2mm"><span style="border: 0.4mm solid #111; padding: 0.8mm 6mm; font-weight: bold; {{ $p->ls('0.6mm') }}">{{ $v['type_label'] }}</span></div>{!! $p->duplicateMark() !!}
</div>
{!! $p->loudNotice() !!}
@if ($p->shows('meta'))
{!! $p->dottedPairs([[$p->t('voucher_no'), e($v['document_no'])], [$p->counterpartyLabel(), e($p->counterparty())]], [[$p->t('date'), e($p->n($v['date']))], [$p->t('mode'), e($p->method())]], 26, '8.8pt') !!}
@endif
{!! $p->lines('amount') !!}
{!! $p->narrationBox() !!}
{!! $p->words() !!}
{!! $p->signatures('line', null, 10) !!}
