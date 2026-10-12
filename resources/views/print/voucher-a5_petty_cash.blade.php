{{--
    ভাউচার · নকশা 14 — পেটি ক্যাশ — ছোট ফ্রেম, ডট-দাগে ঘর, বড় অঙ্কের বাক্স।
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
    <table style="width: 100%; border-bottom: 0.3mm solid #111"><tr>
        <td style="font-weight: bold; padding-bottom: 1.5mm">{{ $p->t('petty_cash') }}</td>
        <td style="text-align: right; padding-bottom: 1.5mm">{{ $v['document_no'] }}</td>
    </tr></table>
    {!! $p->duplicateMark() !!}{!! $p->loudNotice() !!}
    {!! $p->dottedRows(array_values(array_filter([
        [$p->t('date'), e($p->n($v['date']))],
        [$p->counterpartyLabel(), e($p->counterparty())],
        $p->shows('narration') && $v['narration'] !== '' ? [$p->t('narration'), '<span data-narration>'.e($v['narration']).'</span>'] : null,
        [$p->t('account'), e(implode(', ', array_map(fn ($l) => $l['account'], array_filter($v['lines'], fn ($l) => trim(str_replace([',', '0', '.'], '', (string) $l['debit'])) !== ''))))],
    ])), 26, '9pt') !!}
    <div style="margin-top: 3mm"><span style="border: 0.5mm solid #111; padding: 1.5mm 4mm; font-weight: bold; font-size: 13pt">{{ $p->money($v['total_debit']) }}</span></div>
    {!! $p->words() !!}
    {!! $p->signatures('line', null, 12) !!}
    <div style="text-align: center; font-size: 7pt; color: #666; margin-top: 3mm">{{ $h['name'] }}</div>
</div>
