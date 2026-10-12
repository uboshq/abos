{{--
    টাকা প্রাপ্তির রসিদের দেহ — A5 একবার, A4 এক বা দুই কপি ([[VoucherPaper::copies()]]; মালিক, ১২ অক্টোবর ২০২৬:
    *"Eta custo mize kore daw zate control penel e set kora zay ekta na duto"*)।
    চাই: $p (VoucherPaper), $v, $h, $copy ('customer_copy' | 'office_copy'), $first (প্রথম কপি কিনা)।
--}}
<table style="width: 100%"><tr>
    <td style="width: 26mm; vertical-align: top">{!! $p->logo(13) !!}</td>
    <td style="text-align: center; vertical-align: top">
        <div style="font-size: 15pt; font-weight: bold">{{ $h['name'] }}</div>
        {!! $p->companyMeta('#222') !!}
        <div style="font-size: 15pt; font-weight: bold; margin-top: 3mm">{{ $p->isReceipt() ? $p->t('receipt_title') : $v['type_label'] }}</div>
        @if ($first){!! $p->duplicateMark() !!}@endif
    </td>
    <td style="width: 26mm; text-align: right; vertical-align: top"><span style="border: 0.25mm solid #111; padding: 0.5mm 2mm; font-size: 7pt">{{ $p->t($copy) }}</span></td>
</tr></table>
@if ($first){!! $p->loudNotice() !!}@endif
<div style="border-top: 0.4mm solid #111; margin-top: 2mm"></div>
@if ($p->shows('meta'))
{!! $p->dottedPairs(
    [[$p->counterpartyLabel(), e($p->counterparty())], [$p->t('point'), e($p->d('point'))], [$p->t('payment_date'), e($p->n($v['date']))], [$p->t('pay_method'), e($p->method())]],
    [[$p->isReceipt() ? $p->t('receipt_no') : $p->t('voucher_no'), e($v['document_no'])], [$p->t('against_bill'), e($p->d('against'))], [$p->t('entered_on'), e($p->n($p->d('entered_on')))], [$p->t('amount'), '<b>'.e($p->money($v['total_debit'])).'</b>']],
) !!}
@endif
{!! $p->dottedRows(array_values(array_filter([
    $p->shows('narration') && $v['narration'] !== '' ? [$p->t('narration'), '<span data-narration>'.e($v['narration']).'</span>'] : null,
    $p->billAdjustment() !== '' ? [$p->t('bill_adjustment'), e($p->billAdjustment())] : null,
    $p->shows('words') ? [$p->t('in_words'), '<i>'.e($v['amount_in_words']).'</i>'] : null,
]))) !!}
<table style="width: 100%; margin-top: 2.5mm"><tr>
    <td style="width: 29mm; font-weight: bold; vertical-align: top">{{ $p->t('notice') }}</td>
    <td style="font-size: 8pt">1. {{ $p->t('notice_1') }}<br>2. {{ $p->t('notice_2') }}</td>
</tr></table>
{!! $p->signatures('line', [$p->t('customer_signature'), $p->t('authorized_signatory')], 9) !!}
<table style="width: 100%; margin-top: 3mm; border-top: 0.4mm solid #111; border-bottom: 0.4mm solid #111"><tr>
    <td style="padding: 1.2mm 0; width: 29mm; font-weight: bold">{{ $p->t('user') }}</td>
    <td style="padding: 1.2mm 0">{{ $p->d('prepared_by') }}</td>
    <td style="padding: 1.2mm 0; text-align: right; font-size: 7.5pt; color: #555">{{ $p->n($p->t('printed', ['at' => $p->d('printed_on')])) }}</td>
</tr></table>
