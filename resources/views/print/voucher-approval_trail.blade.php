{{--
    ভাউচার · নকশা 16 — সইয়ের ধারাসহ — খাতের সারির নিচে কে কোন ধাপে কখন কী সিদ্ধান্ত দিলেন; সই না থাকলে প্রস্তুত ও অনুমোদনের নাম।
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
<table style="width: 100%; border-bottom: 0.6mm solid #15803d"><tr>
    <td style="width: 18mm; vertical-align: middle; padding-bottom: 2mm">{!! $p->logo(13) !!}</td>
    <td style="vertical-align: middle; padding-bottom: 2mm">
        <div style="font-size: 14pt; font-weight: bold">{{ $h['name'] }}</div>
        {!! $p->companyMeta() !!}
    </td>
    <td style="text-align: right; vertical-align: middle; padding-bottom: 2mm">
        <div style="font-size: 12.5pt; font-weight: bold; color: #15803d">{{ $v['type_label'] }}</div>
        {!! $p->duplicateMark() !!}
    </td>
</tr></table>
{!! $p->loudNotice() !!}
@if ($p->shows('meta'))<table style="width: 100%; margin: 3mm 0"><tr><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('voucher_no') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $v['document_no'] }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('date') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->n($v['date']) }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->counterpartyLabel() }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->counterparty() ?: '—' }}</div></td><td style="border: 0.25mm solid #ccd3dc; padding: 1.5mm 2mm; width: 25%; vertical-align: top"><div style="font-size: 7pt; color: #666">{{ $p->t('mode') }}</div><div style="font-size: 9.2pt; font-weight: bold">{{ $p->method() ?: '—' }}</div></td></tr></table>@endif
{!! $p->lines('dc') !!}
<div style="font-weight: bold; margin-top: 4mm">{{ $p->t('approval_trail') }}</div>
<table style="width: 100%; margin: 1.5mm 0; font-size: 8.5pt">
    <tr>
        <th style="border: 0.25mm solid #333; background: #eef1f5; padding: 1.2mm; width: 12mm">{{ $p->t('step') }}</th>
        <th style="border: 0.25mm solid #333; background: #eef1f5; padding: 1.2mm; text-align: left">{{ $p->t('name') }}</th>
        <th style="border: 0.25mm solid #333; background: #eef1f5; padding: 1.2mm; text-align: left">{{ $p->t('decision_head') }}</th>
        <th style="border: 0.25mm solid #333; background: #eef1f5; padding: 1.2mm">{{ $p->t('date_time') }}</th>
        <th style="border: 0.25mm solid #333; background: #eef1f5; padding: 1.2mm; text-align: left">{{ $p->t('comment') }}</th>
    </tr>
    @php
        $trail = $p->list('trail') ?: array_values(array_filter([
            $p->d('prepared_by') !== '' ? ['step' => '1', 'name' => $p->d('prepared_by'), 'decision' => $p->t('prepared_by'), 'at' => $p->d('entered_on'), 'remarks' => ''] : null,
            $p->d('approved_by') !== '' ? ['step' => '2', 'name' => $p->d('approved_by'), 'decision' => $p->t('decision.approved'), 'at' => $p->d('approved_on'), 'remarks' => ''] : null,
        ]));
    @endphp
    @forelse ($trail as $row)
        <tr>
            <td style="border: 0.25mm solid #333; padding: 1.2mm; text-align: center">{{ $p->n((string) $row['step']) }}</td>
            <td style="border: 0.25mm solid #333; padding: 1.2mm">{{ $row['name'] }}</td>
            <td style="border: 0.25mm solid #333; padding: 1.2mm">{{ $row['decision'] }}</td>
            <td style="border: 0.25mm solid #333; padding: 1.2mm; text-align: center">{{ $p->n((string) $row['at']) }}</td>
            <td style="border: 0.25mm solid #333; padding: 1.2mm">{{ $row['remarks'] }}</td>
        </tr>
    @empty
        <tr><td colspan="5" style="border: 0.25mm solid #333; padding: 1.2mm; text-align: center; color: #777">—</td></tr>
    @endforelse
</table>
{!! $p->narrationBox() !!}
{!! $p->words() !!}
{!! $p->signatures('line', null, 14) !!}
