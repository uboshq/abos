@extends('print.layout')

{{--
    ভাউচার — আদায়, পরিশোধ, খরচ, জাবেদা, কন্ট্রা।

    পাঁচটার জন্য একটাই টেমপ্লেট: কাঠামো এক, শুধু শিরোনাম আলাদা। আলাদা করে
    পাঁচটা লিখলে একটায় স্বাক্ষরের ঘর যোগ করে বাকি চারটায় ভুলে যাওয়া
    নিশ্চিত।

    থার্মালে বিবরণ কলাম বাদ যায় — ৫৮mm-এ হিসাব, ডেবিট ও ক্রেডিট তিনটাই
    কষ্টে ধরে, চারটা দিলে সংখ্যা কেটে যায়।
--}}

@section('body')
    @php
        $thermal = $paper->isThermal;
        $showNarration = $paper->maxColumns() >= 4;
    @endphp

    <table class="meta">
        <tr>
            <td class="label" style="width: 22mm">{{ __('core.print.document_no') }}</td>
            <td>{{ $voucher['document_no'] }}</td>
            @unless ($thermal)
                <td class="label" style="width: 22mm">{{ __('core.print.date') }}</td>
                <td>{{ $voucher['date'] }}</td>
            @endunless
        </tr>

        @if ($thermal)
            <tr>
                <td class="label">{{ __('core.print.date') }}</td>
                <td>{{ $voucher['date'] }}</td>
            </tr>
        @endif

        @if (! empty($voucher['party']))
            <tr>
                <td class="label">{{ __('core.print.party') }}</td>
                <td @unless($thermal) colspan="3" @endunless>{{ $voucher['party'] }}</td>
            </tr>
        @endif

        @if (! empty($voucher['branch']))
            <tr>
                <td class="label">{{ __('core.company.branch') }}</td>
                <td @unless($thermal) colspan="3" @endunless>{{ $voucher['branch'] }}</td>
            </tr>
        @endif
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ __('core.print.account') }}</th>
                @if ($showNarration)
                    <th>{{ __('core.table.narration') }}</th>
                @endif
                <th class="num" style="width: {{ $thermal ? '16mm' : '28mm' }}">{{ __('core.table.debit') }}</th>
                <th class="num" style="width: {{ $thermal ? '16mm' : '28mm' }}">{{ __('core.table.credit') }}</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($voucher['lines'] as $line)
                <tr>
                    <td>
                        {{ $line['account'] }}

                        {{-- থার্মালে আলাদা কলাম নেই, তাই বিবরণ হিসাবের নিচে
                             ছোট করে — তথ্যটা হারায় না, শুধু জায়গা বদলায়। --}}
                        @if (! $showNarration && ! empty($line['narration']))
                            <div style="font-size: {{ $paper->fontSize - 1 }}pt; color: #333">
                                {{ $line['narration'] }}
                            </div>
                        @endif
                    </td>

                    @if ($showNarration)
                        <td>{{ $line['narration'] ?? '' }}</td>
                    @endif

                    <td class="num">{{ $line['debit'] ?: '' }}</td>
                    <td class="num">{{ $line['credit'] ?: '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr class="grand">
            <td>{{ __('core.print.total') }}</td>
            <td class="num" style="width: {{ $thermal ? '16mm' : '28mm' }}">{{ $voucher['total_debit'] }}</td>
            <td class="num" style="width: {{ $thermal ? '16mm' : '28mm' }}">{{ $voucher['total_credit'] }}</td>
        </tr>
    </table>

    @if (! empty($voucher['amount_in_words']))
        <div class="words">
            <strong>{{ __('core.print.in_words') }}:</strong> {{ $voucher['amount_in_words'] }}
        </div>
    @endif

    @if (! empty($voucher['narration']))
        <div class="words">
            <strong>{{ __('core.table.narration') }}:</strong> {{ $voucher['narration'] }}
        </div>
    @endif

    @if ($settings->get('accounts.print_signature_lines', true))
        <table class="signatures">
            <tr>
                @foreach ($thermal ? ['received_by'] : ['prepared_by', 'approved_by', 'received_by'] as $role)
                    <td style="width: {{ $thermal ? '100%' : '33%' }}">
                        <div class="sig-line">{{ __('core.print.' . $role) }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif
@endsection
