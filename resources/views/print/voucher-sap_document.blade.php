{{--
    ভাউচার · নকশা 11 — SAP ধাঁচের হিসাব-নথি — হালকা নীল মাথার ছক, ক্রম আর ডেবিট/ক্রেডিট চিহ্নসহ সারি।
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
<table style="width: 100%; background: #dfe7f1; border: 0.25mm solid #9fb1c7"><tr>
    <td style="padding: 2mm 3mm; font-weight: bold">{{ $v['type_label'] }}</td>
    <td style="padding: 2mm 3mm; text-align: right">{{ $h['name'] }}</td>
</tr></table>
{!! $p->duplicateMark() !!}{!! $p->loudNotice() !!}
@if ($p->shows('meta'))
<table style="width: 100%; margin: 2mm 0; font-size: 8.5pt">
    <tr>
        <td style="border: 0.25mm solid #c5d0dd; background: #f1f4f8; padding: 1mm 2mm; width: 17%">{{ $p->t('document_number') }}</td><td style="border: 0.25mm solid #c5d0dd; padding: 1mm 2mm; font-weight: bold">{{ $v['document_no'] }}</td>
        <td style="border: 0.25mm solid #c5d0dd; background: #f1f4f8; padding: 1mm 2mm; width: 17%">{{ $p->t('document_date') }}</td><td style="border: 0.25mm solid #c5d0dd; padding: 1mm 2mm">{{ $p->n($v['date']) }}</td>
    </tr>
    <tr>
        <td style="border: 0.25mm solid #c5d0dd; background: #f1f4f8; padding: 1mm 2mm">{{ $p->t('branch') }}</td><td style="border: 0.25mm solid #c5d0dd; padding: 1mm 2mm">{{ $v['branch'] ?: '—' }}</td>
        <td style="border: 0.25mm solid #c5d0dd; background: #f1f4f8; padding: 1mm 2mm">{{ $p->t('reference') }}</td><td style="border: 0.25mm solid #c5d0dd; padding: 1mm 2mm">{{ $p->d('instrument_no') ?: ($p->d('against') ?: '—') }}</td>
    </tr>
    @if ($p->shows('narration') && $v['narration'] !== '')
    <tr data-narration><td style="border: 0.25mm solid #c5d0dd; background: #f1f4f8; padding: 1mm 2mm">{{ $p->t('header_text') }}</td><td colspan="3" style="border: 0.25mm solid #c5d0dd; padding: 1mm 2mm">{{ $v['narration'] }}</td></tr>
    @endif
</table>
@endif
<table style="width: 100%; margin: 2mm 0; font-size: 8.5pt">
    <tr>
        <th style="border: 0.25mm solid #9fb1c7; background: #dfe7f1; padding: 1.2mm; width: 10mm">{{ $p->t('item') }}</th>
        <th style="border: 0.25mm solid #9fb1c7; background: #dfe7f1; padding: 1.2mm; width: 8mm">D/C</th>
        <th style="border: 0.25mm solid #9fb1c7; background: #dfe7f1; padding: 1.2mm; text-align: left">{{ $p->t('account') }}</th>
        <th style="border: 0.25mm solid #9fb1c7; background: #dfe7f1; padding: 1.2mm; text-align: right; width: 32mm">{{ $p->t('amount') }}</th>
        <th style="border: 0.25mm solid #9fb1c7; background: #dfe7f1; padding: 1.2mm; text-align: left">{{ $p->t('particulars') }}</th>
    </tr>
    @foreach ($v['lines'] as $i => $l)
        @php $isDebit = trim(str_replace([',', '0', '.'], '', (string) $l['debit'])) !== ''; @endphp
        <tr>
            <td style="border: 0.25mm solid #c5d0dd; padding: 1.2mm; text-align: center">{{ $p->n((string) ($i + 1)) }}</td>
            <td style="border: 0.25mm solid #c5d0dd; padding: 1.2mm; text-align: center">{{ $isDebit ? 'D' : 'C' }}</td>
            <td style="border: 0.25mm solid #c5d0dd; padding: 1.2mm">{{ $l['account'] }}</td>
            <td style="border: 0.25mm solid #c5d0dd; padding: 1.2mm" class="num">{{ $p->n($isDebit ? $l['debit'] : $l['credit'].'-') }}</td>
            <td style="border: 0.25mm solid #c5d0dd; padding: 1.2mm">{{ $l['narration'] }}</td>
        </tr>
    @endforeach
    @if ($p->shows('totals'))
        <tr data-totals><td colspan="3" style="border: 0.25mm solid #c5d0dd; padding: 1.2mm; text-align: right; font-weight: bold">{{ $p->t('total') }}</td><td style="border: 0.25mm solid #c5d0dd; padding: 1.2mm; font-weight: bold" class="num">{{ $p->n($v['total_debit']) }}</td><td style="border: 0.25mm solid #c5d0dd"></td></tr>
    @endif
</table>
{!! $p->words() !!}
<div style="font-size: 7.8pt; color: #555">{{ $p->t('entered_by') }}: {{ $p->d('prepared_by') ?: '—' }} · {{ $p->n($p->d('entered_on')) }}</div>
{!! $p->signatures('line', [$p->t('entered_by'), $p->t('reviewed_by'), $p->t('released_by')], 14) !!}
