{{--
    ভাউচার · নকশা 4 — ব্যাংক পট্টি — পুরো চওড়া নীল মাথা, ডানে শিরোনাম; ব্যাংকের জমা-স্লিপের মতো।
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
<table style="width: 100%; background: #1f3a5f; color: #fff"><tr>
    <td style="padding: 3mm 4mm; width: 16mm; vertical-align: middle">@if ($p->logo(10) !== '')<div style="background: #fff; padding: 1mm">{!! $p->logo(10) !!}</div>@endif</td>
    <td style="padding: 3mm 2mm; vertical-align: middle; color: #fff"><div style="font-weight: bold; font-size: 11pt; color: #fff">{{ $h['name'] }}</div>{!! $p->companyMeta('#dfe7f1') !!}</td>
    <td style="padding: 3mm 4mm; text-align: right; vertical-align: middle; color: #fff"><div style="font-weight: bold; font-size: 11pt; color: #fff">{{ $v['type_label'] }}</div>{!! $p->duplicateMark() !!}</td>
</tr></table>
{!! $p->loudNotice() !!}
@if ($p->shows('meta'))
{!! $p->dottedPairs([[$p->t('voucher_no'), e($v['document_no'])], [$p->counterpartyLabel(), e($p->counterparty())]], [[$p->t('date'), e($p->n($v['date']))], [$p->t('mode'), e($p->method())]], 26, '8.8pt') !!}
@endif
{!! $p->lines('dc', ['th' => 'background: #e8eef6;']) !!}
{!! $p->narrationBox() !!}
{!! $p->words() !!}
{!! $p->signatures('line', null, 9) !!}
