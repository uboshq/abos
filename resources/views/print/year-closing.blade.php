@extends('print.layout')

{{--
    বছরশেষের সমাপনী ভাউচার — ভাউচারের আন্তর্জাতিক পরিকল্পনা, অংশ ৩ঙ (৭ অক্টোবর ২০২৬; [[YearEndController::closingPrint()]])।

    ⓘ প্রতিটা দাখিলা নিজের অংশে: বন্ধের সমাপনী (আয়-ব্যয় শূন্য করে সঞ্চিত মুনাফায়, শাখা ধরে), আর বছর আবার খুললে তার উল্টো —
    একই নম্বরে। সারি: খাত · শাখা · ডেবিট · ক্রেডিট, শেষে যোগফল। সই দুটো — প্রস্তুতকারী আর অনুমোদক।
--}}

@section('body')
    @foreach ($papers as $doc)
        <div style="text-align: center; font-weight: bold; font-size: 14pt; margin: {{ $loop->first ? 0 : 8 }}mm 0 2mm;" data-closing-title>
            {{ __('accounts::voucher.closing_voucher') }}
        </div>

        <table class="meta">
            <tr>
                <td class="label" style="width: 28mm">{{ __('core.print.document_no') }}</td>
                <td data-closing-no>{{ $doc['document_no'] }}</td>
                <td class="label" style="width: 20mm">{{ __('core.print.date') }}</td>
                <td>{{ $doc['date'] }}</td>
            </tr>
            <tr>
                <td class="label">{{ __('accounts::voucher.closing_year') }}</td>
                <td>{{ $year }}</td>
                <td class="label">{{ __('accounts::field.state') }}</td>
                <td>{{ __('accounts::voucher.closing_kind_'.$doc['kind']) }}</td>
            </tr>
            @if ($doc['narration'] !== '')
                <tr>
                    <td class="label">{{ __('core.table.narration') }}</td>
                    <td colspan="3">{{ $doc['narration'] }}</td>
                </tr>
            @endif
        </table>

        <table class="lines">
            <thead>
                <tr>
                    <th>{{ __('core.print.account') }}</th>
                    <th style="width: 30mm">{{ __('accounts::voucher.closing_branch') }}</th>
                    <th class="num" style="width: 30mm">{{ __('core.table.debit') }}</th>
                    <th class="num" style="width: 30mm">{{ __('core.table.credit') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($doc['lines'] as $line)
                    <tr @if ($loop->even) class="alt" @endif>
                        <td>{{ $line['account'] }}</td>
                        <td>{{ $line['branch'] }}</td>
                        <td class="num">{{ bccomp($line['debit'], '0', 4) > 0 ? \App\Core\Support\Money::format($line['debit']) : '' }}</td>
                        <td class="num">{{ bccomp($line['credit'], '0', 4) > 0 ? \App\Core\Support\Money::format($line['credit']) : '' }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="2" style="font-weight: bold;">{{ __('core.print.total') }}</td>
                    <td class="num" style="font-weight: bold;" data-closing-debit>{{ \App\Core\Support\Money::format($doc['debit']) }}</td>
                    <td class="num" style="font-weight: bold;">{{ \App\Core\Support\Money::format($doc['credit']) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach

    <table class="signatures">
        <tr>
            @foreach ($signatures as $role)
                <td style="width: {{ (int) round(100 / max(count($signatures), 1)) }}%">
                    <div class="sig-line">{{ $role }}</div>
                </td>
            @endforeach
        </tr>
    </table>
@endsection
