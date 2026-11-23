{{--
    ⭐ কর-কাগজের সারির টেবিল — মূসক-৬.৭/৬.৮ নোট আর স্টক ছাড়া ভাউচার ([[PaperTaxFacts::line()]])।
    কলাম: বিবরণ, পরিমাণ, একক, দর, মূল্য, ভ্যাটের হার, ভ্যাট, মোট। ⓘ থার্মালে আট কলাম ধরে না — প্রতিটা সারি দুই লাইনে
    (নাম; পরিমাণ × দর = মূল্য · ভ্যাট · মোট)।

    দেয়: $lines (list), $thermal (bool), $sumAmount, $sumVat, $sumTotal (লেখা, ঐচ্ছিক — দিলে নিচে যোগের সারি), $sumLabel
--}}
@php
    $cell = 'border: 0.2mm solid #555; padding: '.($thermal ? '0.6mm' : '1.2mm 1.5mm').';';
    $num = $cell.' text-align: right;';
@endphp

@if ($thermal)
    <table style="width: 100%; border-collapse: collapse; font-size: 7.5pt; margin-bottom: 2mm;" data-tax-lines>
        @foreach ($lines as $line)
            <tr>
                <td colspan="2" style="padding-top: 1mm; font-weight: bold;">
                    {{ $line['name'] }}@if (($line['return_no'] ?? '') !== '') ({{ $line['return_no'] }})@endif
                </td>
            </tr>
            <tr>
                <td style="border-bottom: 0.2mm dashed #000;">
                    @if ($line['qty'] !== ''){{ $line['qty'] }} {{ $line['unit'] }} × {{ $line['rate'] }} = @endif{{ $line['amount'] }}
                    · {{ __('accounts::note_print.vat') }} {{ $line['vat_rate'] }} {{ $line['vat'] }}
                </td>
                <td class="num" style="text-align: right; border-bottom: 0.2mm dashed #000;">{{ $line['total'] }}</td>
            </tr>
        @endforeach
        @isset($sumTotal)
            <tr>
                <td style="font-weight: bold;">{{ $sumLabel ?? __('accounts::note_print.subtotal') }}</td>
                <td class="num" style="text-align: right; font-weight: bold;">{{ $sumTotal }}</td>
            </tr>
        @endisset
    </table>
@else
    <table style="width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-bottom: 3mm;" data-tax-lines>
        <thead>
            <tr style="background: #eee;">
                <th style="{{ $cell }} width: 5%;">{{ __('accounts::note_print.sl') }}</th>
                <th style="{{ $cell }} text-align: left;">{{ __('accounts::note_print.description') }}</th>
                <th style="{{ $num }} width: 8%;">{{ __('accounts::note_print.qty') }}</th>
                <th style="{{ $cell }} width: 7%;">{{ __('accounts::note_print.unit') }}</th>
                <th style="{{ $num }} width: 10%;">{{ __('accounts::note_print.rate') }}</th>
                <th style="{{ $num }} width: 12%;">{{ __('accounts::note_print.value') }}</th>
                <th style="{{ $num }} width: 7%;">{{ __('accounts::note_print.vat_rate') }}</th>
                <th style="{{ $num }} width: 11%;">{{ __('accounts::note_print.vat') }}</th>
                <th style="{{ $num }} width: 12%;">{{ __('accounts::note_print.line_total') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td style="{{ $cell }} text-align: center;">{{ $loop->iteration }}</td>
                    <td style="{{ $cell }}">
                        {{ $line['name'] }}
                        @if (($line['return_no'] ?? '') !== '')
                            <span style="color: #333;">({{ __('accounts::note_print.return_paper') }}: {{ $line['return_no'] }})</span>
                        @endif
                    </td>
                    <td class="num" style="{{ $num }}">{{ $line['qty'] }}</td>
                    <td style="{{ $cell }}">{{ $line['unit'] }}</td>
                    <td class="num" style="{{ $num }}">{{ $line['rate'] }}</td>
                    <td class="num" style="{{ $num }}">{{ $line['amount'] }}</td>
                    <td class="num" style="{{ $num }}">{{ $line['vat_rate'] }}</td>
                    <td class="num" style="{{ $num }}">{{ $line['vat'] }}</td>
                    <td class="num" style="{{ $num }}">{{ $line['total'] }}</td>
                </tr>
            @endforeach
            @isset($sumTotal)
                <tr style="font-weight: bold;">
                    <td style="{{ $cell }}" colspan="5">{{ $sumLabel ?? __('accounts::note_print.subtotal') }}</td>
                    <td class="num" style="{{ $num }}">{{ $sumAmount ?? '' }}</td>
                    <td style="{{ $cell }}"></td>
                    <td class="num" style="{{ $num }}">{{ $sumVat ?? '' }}</td>
                    <td class="num" style="{{ $num }}">{{ $sumTotal }}</td>
                </tr>
            @endisset
        </tbody>
    </table>
@endif
