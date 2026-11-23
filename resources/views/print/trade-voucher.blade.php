@extends('print.layout')

{{--
    ⭐ স্টক ছাড়া ভাউচারের কাগজ ([[TradeVoucherPrintController]]) — মালিকের নকশা v5, ২৩ নভেম্বর ২০২৬।
      SV  — মূসক-৬.৩ কর চালানপত্রের ধাঁচে: দুই পক্ষ + BIN, নম্বর, তারিখ ও সময়, রেফ/পিও, শোধের শেষ তারিখ, সারি ভ্যাটসহ, মোট, কথায়, সই।
      PUV — সাধারণ ক্রয় ভাউচার: সরবরাহকারী, তাঁর বিল নম্বর, মূসক-৬.৩ নম্বর/তারিখ, সারি, ভ্যাট, উৎসে কর, দেনা।
--}}

@section('body')
    @php($thermal = $paper->isThermal)
    @php($sale = $voucher['is_sale'])

    <div style="text-align: center; font-weight: bold; font-size: {{ $thermal ? 11 : 14 }}pt;" data-trade-voucher-kind>
        {{ $sale ? __('accounts::note_print.tax_invoice') : $voucher['doc_name'] }}
    </div>
    <div style="text-align: center; font-size: {{ $thermal ? 7.5 : 9 }}pt; margin-bottom: 2mm;">
        {{ $sale ? __('accounts::note_print.mushak_6_3') : __('accounts::trade.stock_does_not_move') }}
    </div>

    @if (! empty($notice))
        <div style="text-align: center; font-weight: bold; border: 0.4mm solid #000;
                    padding: {{ $thermal ? '1mm' : '2mm' }}; margin-bottom: {{ $thermal ? 2 : 4 }}mm;
                    font-size: {{ $thermal ? 8 : 11 }}pt;" data-trade-voucher-notice>
            {{ $notice }}
        </div>
    @endif

    @include('print.partials.tax-parties', [
        'companyBin' => $voucher['company_bin'],
        'party' => [
            'name' => $voucher['party_name'], 'code' => $voucher['party_code'],
            'address' => $voucher['party_address'], 'bin' => $voucher['party_bin'],
        ],
        'issuerLabel' => $sale ? __('accounts::note_print.issuer') : __('accounts::note_print.buyer'),
        'recipientLabel' => $sale ? __('accounts::note_print.recipient') : __('accounts::note_print.supplier'),
        'warnBin' => $sale,
    ])

    <table class="meta" data-trade-voucher-facts>
        <tr>
            <td class="label" style="width: {{ $thermal ? 22 : 34 }}mm">{{ __('accounts::note_print.voucher_no_'.$voucher['kind']) }}</td>
            <td><b>{{ $voucher['document_no'] }}</b></td>
            @unless ($thermal)
                <td class="label" style="width: 34mm">{{ __('accounts::note_print.issued_at') }}</td>
                <td>{{ $voucher['issued_at'] }}</td>
            @endunless
        </tr>
        @if ($thermal)
            <tr><td class="label">{{ __('accounts::note_print.issued_at') }}</td><td>{{ $voucher['issued_at'] }}</td></tr>
        @endif
        <tr>
            <td class="label">{{ __('accounts::note_list.col_ref_'.$voucher['kind']) }}</td>
            <td>{{ $voucher['ref_no'] !== '' ? $voucher['ref_no'] : '—' }}</td>
            @unless ($thermal)
                <td class="label">{{ __('accounts::note_list.col_due') }}</td>
                <td>{{ $voucher['due_on'] !== '' ? $voucher['due_on'] : '—' }}</td>
            @endunless
        </tr>
        @if ($thermal && $voucher['due_on'] !== '')
            <tr><td class="label">{{ __('accounts::note_list.col_due') }}</td><td>{{ $voucher['due_on'] }}</td></tr>
        @endif
        @if (! $sale)
            <tr>
                <td class="label">{{ __('accounts::note_list.col_mushak_63') }}</td>
                <td @unless($thermal) colspan="3" @endunless data-trade-voucher-mushak>
                    @if ($voucher['mushak_no'] !== ''){{ $voucher['mushak_no'] }}@if ($voucher['mushak_date'] !== '') · {{ $voucher['mushak_date'] }}@endif @else — @endif
                </td>
            </tr>
        @endif
        @if ($voucher['narration'] !== '')
            <tr>
                <td class="label">{{ __('core.table.narration') }}</td>
                <td @unless($thermal) colspan="3" @endunless>{{ $voucher['narration'] }}</td>
            </tr>
        @endif
    </table>

    @include('print.partials.tax-lines', [
        'lines' => $voucher['lines'],
        'sumAmount' => $voucher['amount'], 'sumVat' => $voucher['tax'], 'sumTotal' => $voucher['total'],
        'sumLabel' => __('accounts::note_print.subtotal'),
    ])

    <table style="width: {{ $thermal ? '100%' : '60%' }}; margin-left: auto; font-size: {{ $thermal ? 8 : 9.5 }}pt; border-collapse: collapse;" data-trade-voucher-totals>
        <tr><td>{{ __('accounts::note_print.value_total') }}</td><td class="num" style="text-align: right">{{ $voucher['amount'] }}</td></tr>
        <tr><td>{{ __('accounts::note_print.vat_total') }}</td><td class="num" style="text-align: right">{{ $voucher['tax'] }}</td></tr>
        <tr style="font-weight: bold;"><td>{{ __('accounts::note_print.grand_total') }}</td><td class="num" style="text-align: right">{{ $voucher['total'] }}</td></tr>
        @if (! $sale && $voucher['tds'] !== '')
            <tr><td>{{ __('accounts::note_list.col_tds') }}</td><td class="num" style="text-align: right">({{ $voucher['tds'] }})</td></tr>
        @endif
        @unless ($sale)
            <tr style="font-weight: bold; font-size: {{ $thermal ? 9.5 : 11.5 }}pt;">
                <td style="border-top: 0.3mm solid #000; padding-top: 1mm;">{{ __('accounts::note_list.col_party_total_purchase') }}</td>
                <td class="num" style="text-align: right; border-top: 0.3mm solid #000; padding-top: 1mm;" data-trade-voucher-payable>{{ $voucher['party_total'] }}</td>
            </tr>
        @endunless
    </table>
    <div style="font-size: {{ $thermal ? 7 : 8.5 }}pt; margin: 1.5mm 0 {{ $thermal ? 2 : 4 }}mm;" data-trade-voucher-words>
        {{ __('accounts::print.note_in_words') }}: {{ $voucher['in_words'] }}
    </div>

    @php($roles = $thermal ? array_slice($signatures, -1) : $signatures)
    <table class="signatures" data-trade-voucher-signatures>
        <tr>
            @foreach ($roles as $role)
                <td style="width: {{ (int) round(100 / max(count($roles), 1)) }}%">
                    <div class="sig-line">{{ $role }}</div>
                </td>
            @endforeach
        </tr>
    </table>
@endsection
