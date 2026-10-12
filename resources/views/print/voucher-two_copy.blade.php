{{--
    ভাউচার · নকশা 10 — দুই কপি — একই A4-এ অফিস কপি আর গ্রাহক কপি, মাঝে কাটার দাগ।
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
@foreach (['office_copy', 'customer_copy'] as $copy)
    @php $first = $loop->first; @endphp
    <div style="border: 0.35mm solid #111; padding: 4mm; height: 118mm">
    <table style="width: 100%"><tr>
        <td style="width: 15mm; vertical-align: middle">{!! $p->logo(10) !!}</td>
        <td style="vertical-align: middle"><div style="font-weight: bold; font-size: 11pt">{{ $h['name'] }}</div><div style="font-size: 8pt; color: #555">{{ $v['type_label'] }} · {{ $v['document_no'] }} · {{ $p->n($v['date']) }}</div></td>
        <td style="text-align: right; vertical-align: top"><span style="border: 0.25mm solid #111; padding: 0.5mm 2mm; font-size: 7pt">{{ $p->t($copy) }}</span></td>
    </tr></table>
    @if ($first){!! $p->duplicateMark() !!}{!! $p->loudNotice() !!}@endif
    {!! $p->dottedPairs([[$p->counterpartyLabel(), e($p->counterparty())], [$p->t('mode'), e($p->method())]], [[$p->t('amount'), '<b>'.e($p->money($v['total_debit'])).'</b>'], [$p->t('against_bill'), e($p->d('against'))]], 26, '8.8pt') !!}
    {!! $p->narrationBox() !!}
    {!! $p->words() !!}
    {!! $p->signatures('line', null, 10) !!}
</div>
    @if ($first)<div style="text-align: center; color: #666; font-size: 8pt; margin: 3mm 0">&#9986; - - - - - - - - - - - - - - - - - - - - {{ $p->t('cut_here') }} - - - - - - - - - - - - - - - - - - - - &#9986;</div>@endif
@endforeach
