{{--
    ভাউচার · নকশা 5 — জাবেদা ছক — মাথায় চার ঘরের ছক, ডেবিট/ক্রেডিট দুই কলাম, নিচে পাতার পরিচয়।
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
@if ($p->shows('meta'))<table style="width: 100%; margin: 3mm 0"><tr><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('voucher_no') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $v['document_no'] }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('date') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->n($v['date']) }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('branch') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $v['branch'] ?: '—' }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('entered_on') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->n($p->d('entered_on')) ?: '—' }}</div></td></tr></table>@endif
{!! $p->lines('dc') !!}
{!! $p->narrationBox() !!}
{!! $p->words() !!}
{!! $p->signatures('line', [$p->t('prepared_by').($p->d('prepared_by') !== '' ? ' — '.$p->d('prepared_by') : ''), $p->t('checked_by'), $p->t('approved_by').($p->d('approved_by') !== '' ? ' — '.$p->d('approved_by') : '')], 16) !!}
<div style="text-align: center; font-size: 7.5pt; color: #777; margin-top: 8mm; border-top: 0.2mm solid #ddd; padding-top: 1.5mm">{{ $p->t('computer_generated') }}</div>
